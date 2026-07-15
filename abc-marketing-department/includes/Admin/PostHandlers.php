<?php
/**
 * admin-post.php action handlers.
 *
 * Every handler enforces the capability + a nonce, sanitises input, performs the
 * action, logs it where relevant, and redirects with a flash message.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin;

use ABCMD\AI\Runner;
use ABCMD\Anomaly\Detector;
use ABCMD\Cron\Scheduler;
use ABCMD\Cron\WeeklyAudit;
use ABCMD\Demo\DemoData;
use ABCMD\Export\CopyReady;
use ABCMD\Export\CsvExport;
use ABCMD\Export\IcalExport;
use ABCMD\Files\Uploader;
use ABCMD\Import\CsvImporter;
use ABCMD\Repository\Authors;
use ABCMD\Repository\Books;
use ABCMD\Repository\Consent;
use ABCMD\Repository\Content;
use ABCMD\Repository\Files as FilesRepo;
use ABCMD\Repository\MappingProfiles;
use ABCMD\Repository\Recommendations;
use ABCMD\Repository\Tasks;
use ABCMD\Support\Audit;
use ABCMD\Support\Encryption;
use ABCMD\Support\Helpers;
use ABCMD\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central dispatcher for form submissions.
 */
final class PostHandlers {

	/**
	 * Map of action => method.
	 *
	 * @return array<string,string>
	 */
	private function actions(): array {
		return array(
			'abcmd_save_author'         => 'save_author',
			'abcmd_delete_author'       => 'delete_author',
			'abcmd_save_book'           => 'save_book',
			'abcmd_delete_book'         => 'delete_book',
			'abcmd_save_consent'        => 'save_consent',
			'abcmd_run_ai'              => 'run_ai',
			'abcmd_import_run'          => 'import_run',
			'abcmd_import_rollback'     => 'import_rollback',
			'abcmd_save_mapping'        => 'save_mapping',
			'abcmd_manual_adjustment'   => 'manual_adjustment',
			'abcmd_upload_file'         => 'upload_file',
			'abcmd_delete_file'         => 'delete_file',
			'abcmd_extract_file'        => 'extract_file',
			'abcmd_save_task'           => 'save_task',
			'abcmd_save_recommendation' => 'save_recommendation',
			'abcmd_save_content'        => 'save_content',
			'abcmd_approval_decision'   => 'approval_decision',
			'abcmd_run_audit_now'       => 'run_audit_now',
			'abcmd_detect_anomalies'    => 'detect_anomalies',
			'abcmd_save_settings'       => 'save_settings',
			'abcmd_save_openai'         => 'save_openai',
			'abcmd_save_prices'         => 'save_prices',
			'abcmd_save_privacy'        => 'save_privacy',
			'abcmd_install_demo'        => 'install_demo',
			'abcmd_purge'               => 'purge',
			'abcmd_export_csv'          => 'export_csv',
			'abcmd_export_ical'         => 'export_ical',
			'abcmd_export_copy'         => 'export_copy',
			'abcmd_download_file'       => 'download_file',
			'abcmd_complete_setup'      => 'complete_setup',
		);
	}

	/**
	 * Register all admin-post handlers.
	 */
	public function hooks(): void {
		foreach ( $this->actions() as $action => $method ) {
			add_action( 'admin_post_' . $action, array( $this, $method ) );
		}
	}

	// --- Helpers ----------------------------------------------------------

	/**
	 * Verify the nonce for the given action and capability.
	 *
	 * @param string $action Action name (the nonce action).
	 */
	private function guard( string $action ): void {
		Helpers::verify_nonce( $action );
	}

	/**
	 * Redirect back to a plugin page with a flash message.
	 *
	 * @param string              $page  Page slug.
	 * @param string              $text  Message.
	 * @param string              $type  success|error|warning|info.
	 * @param array<string,mixed> $args  Extra query args.
	 */
	private function back( string $page, string $text, string $type = 'success', array $args = array() ): void {
		View::set_flash( $text, $type );
		wp_safe_redirect( Helpers::admin_url( $page, $args ) );
		exit;
	}

	/**
	 * Read a sanitised text request field.
	 */
	private function text( string $key, string $default = '' ): string {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : $default;
	}

	/**
	 * Read a sanitised textarea field (multiline, tags stripped-ish).
	 */
	private function area( string $key ): string {
		return isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	/**
	 * Read a float field.
	 */
	private function num( string $key ): float {
		return isset( $_POST[ $key ] ) ? Helpers::money( wp_unslash( (string) $_POST[ $key ] ) ) : 0.0;
	}

	/**
	 * Read an int field.
	 */
	private function int( string $key ): int {
		return isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : 0;
	}

	// --- Authors ----------------------------------------------------------

	public function save_author(): void {
		$this->guard( 'abcmd_save_author' );
		$repo = new Authors();
		$id   = $this->int( 'id' );

		$data = array(
			'name'            => $this->text( 'name' ),
			'pen_name'        => $this->text( 'pen_name' ),
			'email'           => sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) ),
			'website'         => esc_url_raw( wp_unslash( (string) ( $_POST['website'] ?? '' ) ) ),
			'biography'       => $this->area( 'biography' ),
			'social_profiles' => Helpers::split_list( $this->area( 'social_profiles' ) ),
			'email_platform'  => array( 'provider' => $this->text( 'email_provider' ), 'notes' => $this->text( 'email_notes' ) ),
			'notes'           => $this->area( 'notes' ),
			'consent_status'  => $this->text( 'consent_status', 'unknown' ),
			'status'          => $this->text( 'status', 'active' ),
		);

		if ( '' === $data['name'] && '' === $data['pen_name'] ) {
			$this->back( 'abcmd-authors', __( 'An author needs a name or pen name.', 'abc-marketing-department' ), 'error' );
		}

		if ( $id ) {
			$repo->update( $id, $data );
			Audit::log( 'author.update', 'Author updated.', array( 'author_id' => $id ), null, $id );
			$this->back( 'abcmd-authors', __( 'Author updated.', 'abc-marketing-department' ) );
		} else {
			$id = $repo->insert( $data );
			Audit::log( 'author.create', 'Author created.', array( 'author_id' => $id ), null, $id );
			$this->back( 'abcmd-authors', __( 'Author created.', 'abc-marketing-department' ), 'success', array( 'author' => $id ) );
		}
	}

	public function delete_author(): void {
		$this->guard( 'abcmd_delete_author' );
		$id    = $this->int( 'id' );
		$books = ( new Books() )->for_author( $id );
		if ( ! empty( $books ) ) {
			$this->back( 'abcmd-authors', __( 'Cannot delete an author who still has book workspaces. Reassign or delete the books first.', 'abc-marketing-department' ), 'error' );
		}
		( new Authors() )->delete( $id );
		Audit::log( 'author.delete', 'Author deleted.', array( 'author_id' => $id ), null, $id );
		$this->back( 'abcmd-authors', __( 'Author deleted.', 'abc-marketing-department' ) );
	}

	// --- Books ------------------------------------------------------------

	public function save_book(): void {
		$this->guard( 'abcmd_save_book' );
		$repo = new Books();
		$id   = $this->int( 'id' );

		$data = array(
			'author_id'         => $this->int( 'author_id' ),
			'title'             => $this->text( 'title' ),
			'subtitle'          => $this->text( 'subtitle' ),
			'publisher'         => $this->text( 'publisher' ),
			'publication_date'  => $this->text( 'publication_date' ) ?: null,
			'genre'             => $this->text( 'genre' ),
			'territory'         => $this->text( 'territory' ),
			'audience'          => $this->text( 'audience' ),
			'synopsis'          => $this->area( 'synopsis' ),
			'proposition'       => $this->area( 'proposition' ),
			'comparison_titles' => Helpers::split_list( $this->area( 'comparison_titles' ) ),
			'formats'           => Helpers::split_list( $this->text( 'formats' ) ),
			'prices'            => $this->kv_from_post( 'price' ),
			'net_income'        => $this->kv_from_post( 'net' ),
			'distributors'      => Helpers::split_list( $this->area( 'distributors' ) ),
			'retailer_links'    => Helpers::split_list( $this->area( 'retailer_links' ) ),
			'universal_link'    => esc_url_raw( wp_unslash( (string) ( $_POST['universal_link'] ?? '' ) ) ),
			'campaign_start'    => $this->text( 'campaign_start' ) ?: null,
			'long_term_target'  => array( 'text' => $this->area( 'long_term_target' ) ),
			'targets_90day'     => $this->kv_from_post( 'target' ),
			'weekly_hours'      => $this->num( 'weekly_hours' ),
			'budget'            => $this->num( 'budget' ),
			'budget_max'        => $this->num( 'budget_max' ),
			'break_even'        => array( 'cost_per_sale' => $this->num( 'break_even_cps' ) ),
			'excluded_channels' => Helpers::split_list( $this->text( 'excluded_channels' ) ),
			'currency'          => strtoupper( $this->text( 'currency', 'GBP' ) ),
			'status'            => $this->text( 'status', 'active' ),
		);

		// Optional per-workspace anomaly thresholds.
		$thresholds = array();
		foreach ( array( 'sales_spike_pct', 'sales_collapse_pct', 'open_rate_drop_pct', 'report_gap_days', 'distributor_gap_days' ) as $tk ) {
			if ( isset( $_POST[ 'th_' . $tk ] ) && '' !== $_POST[ 'th_' . $tk ] ) {
				$thresholds[ $tk ] = (float) $_POST[ 'th_' . $tk ];
			}
		}
		if ( $thresholds ) {
			$data['anomaly_thresholds'] = $thresholds;
		}

		if ( '' === $data['title'] ) {
			$this->back( 'abcmd-books', __( 'A workspace needs a book title.', 'abc-marketing-department' ), 'error' );
		}

		if ( $id ) {
			$repo->update( $id, $data );
			Audit::log( 'book.update', 'Workspace updated.', array( 'book_id' => $id ), $id, $data['author_id'] );
			$this->back( 'abcmd-books', __( 'Workspace saved.', 'abc-marketing-department' ), 'success', array( 'book' => $id ) );
		} else {
			$id = $repo->insert( $data );
			Audit::log( 'book.create', 'Workspace created.', array( 'book_id' => $id ), $id, $data['author_id'] );
			$this->back( 'abcmd-books', __( 'Workspace created.', 'abc-marketing-department' ), 'success', array( 'book' => $id ) );
		}
	}

	public function delete_book(): void {
		$this->guard( 'abcmd_delete_book' );
		$id   = $this->int( 'id' );
		if ( $this->text( 'confirm' ) !== 'DELETE' ) {
			$this->back( 'abcmd-books', __( 'Type DELETE to confirm workspace deletion.', 'abc-marketing-department' ), 'error', array( 'book' => $id ) );
		}
		DemoData::delete_workspace( $id );
		$this->back( 'abcmd-books', __( 'Workspace and its data were deleted.', 'abc-marketing-department' ) );
	}

	// --- Consent ----------------------------------------------------------

	public function save_consent(): void {
		$this->guard( 'abcmd_save_consent' );
		$repo    = new Consent();
		$book_id = $this->int( 'book_id' );
		$book    = ( new Books() )->find( $book_id );
		if ( ! $book ) {
			$this->back( 'abcmd-books', __( 'Workspace not found.', 'abc-marketing-department' ), 'error' );
		}

		$existing = $repo->for_book( $book_id );
		$data     = array(
			'book_id'            => $book_id,
			'author_id'          => (int) $book['author_id'],
			'allow_openai'       => isset( $_POST['allow_openai'] ) ? 1 : 0,
			'allow_manuscript'   => isset( $_POST['allow_manuscript'] ) ? 1 : 0,
			'allow_sales'        => isset( $_POST['allow_sales'] ) ? 1 : 0,
			'allow_personal'     => isset( $_POST['allow_personal'] ) ? 1 : 0,
			'allow_web_research' => isset( $_POST['allow_web_research'] ) ? 1 : 0,
			'data_categories'    => array_map( 'sanitize_key', (array) ( $_POST['data_categories'] ?? array() ) ),
			'granted_date'       => $this->text( 'granted_date' ) ?: null,
			'method'             => $this->text( 'method' ),
			'notes'              => $this->area( 'notes' ),
			'revoked_date'       => $this->text( 'revoked_date' ) ?: null,
			'status'             => $this->text( 'status', 'active' ),
			'updated_by'         => get_current_user_id(),
		);

		if ( $existing ) {
			$repo->update( (int) $existing['id'], $data );
		} else {
			$repo->insert( $data );
		}
		Audit::log( 'consent.update', 'Consent record updated.', array( 'status' => $data['status'], 'allow_openai' => $data['allow_openai'] ), $book_id, (int) $book['author_id'] );
		$this->back( 'abcmd-books', __( 'Consent record saved.', 'abc-marketing-department' ), 'success', array( 'book' => $book_id, 'tab' => 'consent' ) );
	}

	// --- AI runs ----------------------------------------------------------

	public function run_ai(): void {
		$this->guard( 'abcmd_run_ai' );
		$book_id  = $this->int( 'book_id' );
		$run_type = sanitize_key( $this->text( 'run_type', 'custom' ) );
		$model    = $this->text( 'model' );
		$web      = isset( $_POST['web_search'] );
		$cats     = array_map( 'sanitize_key', (array) ( $_POST['categories'] ?? array() ) );
		$extra    = array(
			'custom_instruction' => $this->area( 'custom_instruction' ),
			'manuscript_summary' => $this->area( 'manuscript_summary' ),
			'passages'           => $this->area( 'passages' ),
			'file_ids'           => array_map( 'intval', (array) ( $_POST['file_ids'] ?? array() ) ),
		);

		$result = Runner::run(
			array(
				'book_id'    => $book_id,
				'run_type'   => $run_type,
				'model'      => $model,
				'web_search' => $web,
				'categories' => $cats,
				'extra'      => $extra,
				'confirmed'  => isset( $_POST['confirmed'] ),
			)
		);

		if ( $result['ok'] ) {
			$msg = sprintf(
				__( 'AI run complete. Created %d recommendation(s), %d task(s), %d content item(s) in the approval queue.', 'abc-marketing-department' ),
				$result['created']['recommendations'],
				$result['created']['tasks'],
				$result['created']['content']
			);
			$this->back( 'abcmd-ai-runs', $msg, 'success', array( 'run' => $result['ai_run_id'] ) );
		} else {
			$this->back( 'abcmd-books', __( 'AI run failed: ', 'abc-marketing-department' ) . $result['error'], 'error', array( 'book' => $book_id, 'tab' => 'ai' ) );
		}
	}

	// --- Imports ----------------------------------------------------------

	public function import_run(): void {
		$this->guard( 'abcmd_import_run' );
		$book_id  = $this->int( 'book_id' );

		if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
			$this->back( 'abcmd-imports', __( 'Please choose a CSV file to import.', 'abc-marketing-department' ), 'error', array( 'book' => $book_id ) );
		}

		$mapping = array();
		foreach ( (array) ( $_POST['map'] ?? array() ) as $target => $header ) {
			$mapping[ sanitize_key( (string) $target ) ] = sanitize_text_field( wp_unslash( (string) $header ) );
		}

		$path = $_FILES['csv']['tmp_name'];
		$result = CsvImporter::import(
			array(
				'book_id'           => $book_id,
				'template'          => sanitize_key( $this->text( 'template', 'generic_sales' ) ),
				'source_status'     => sanitize_key( $this->text( 'source_status', 'live' ) ),
				'mapping'           => $mapping,
				'date_format'       => $this->text( 'date_format', 'auto' ),
				'currency'          => strtoupper( $this->text( 'currency', 'GBP' ) ),
				'path'              => $path,
				'original_filename' => sanitize_file_name( (string) $_FILES['csv']['name'] ),
				'confirm_duplicate' => isset( $_POST['confirm_duplicate'] ),
			)
		);

		if ( $result['ok'] ) {
			$msg = sprintf(
				__( 'Import complete: %d rows imported, %d rejected, %d duplicate.', 'abc-marketing-department' ),
				$result['imported'],
				$result['rejected'],
				max( 0, $result['duplicate'] )
			);
			// Run anomaly detection after fresh data lands.
			Detector::detect( $book_id );
			$this->back( 'abcmd-imports', $msg, 'success', array( 'book' => $book_id, 'import' => $result['import_id'] ) );
		} else {
			$this->back( 'abcmd-imports', __( 'Import problem: ', 'abc-marketing-department' ) . $result['message'], 'error', array( 'book' => $book_id ) );
		}
	}

	public function import_rollback(): void {
		$this->guard( 'abcmd_import_rollback' );
		$import_id = $this->int( 'import_id' );
		$removed   = CsvImporter::rollback( $import_id );
		$this->back( 'abcmd-imports', sprintf( __( 'Rolled back import — removed %d metric rows.', 'abc-marketing-department' ), $removed ) );
	}

	public function save_mapping(): void {
		$this->guard( 'abcmd_save_mapping' );
		$repo    = new MappingProfiles();
		$mapping = array();
		foreach ( (array) ( $_POST['map'] ?? array() ) as $target => $header ) {
			$mapping[ sanitize_key( (string) $target ) ] = sanitize_text_field( wp_unslash( (string) $header ) );
		}
		$repo->insert(
			array(
				'name'        => $this->text( 'profile_name' ),
				'template'    => sanitize_key( $this->text( 'template' ) ),
				'mapping'     => $mapping,
				'date_format' => $this->text( 'date_format', 'auto' ),
				'currency'    => strtoupper( $this->text( 'currency', 'GBP' ) ),
				'user_id'     => get_current_user_id(),
			)
		);
		$this->back( 'abcmd-imports', __( 'Mapping profile saved.', 'abc-marketing-department' ), 'success', array( 'book' => $this->int( 'book_id' ) ) );
	}

	public function manual_adjustment(): void {
		$this->guard( 'abcmd_manual_adjustment' );
		$book_id = $this->int( 'book_id' );
		CsvImporter::manual_adjustment(
			array(
				'book_id'    => $book_id,
				'metric_key' => sanitize_key( $this->text( 'metric_key', 'sales' ) ),
				'value'      => $this->num( 'value' ),
				'date'       => $this->text( 'date' ),
				'format'     => $this->text( 'format' ),
				'territory'  => $this->text( 'territory' ),
				'currency'   => strtoupper( $this->text( 'currency', 'GBP' ) ),
				'note'       => $this->text( 'note' ),
			)
		);
		$this->back( 'abcmd-imports', __( 'Manual adjustment recorded.', 'abc-marketing-department' ), 'success', array( 'book' => $book_id ) );
	}

	// --- Files ------------------------------------------------------------

	public function upload_file(): void {
		$this->guard( 'abcmd_upload_file' );
		$book_id = $this->int( 'book_id' );
		if ( empty( $_FILES['file'] ) ) {
			$this->back( 'abcmd-files', __( 'No file selected.', 'abc-marketing-department' ), 'error', array( 'book' => $book_id ) );
		}
		$res = Uploader::handle(
			(array) $_FILES['file'],
			array(
				'book_id'       => $book_id,
				'document_type' => sanitize_key( $this->text( 'document_type', 'other' ) ),
				'consent_class' => sanitize_key( $this->text( 'consent_class', 'unclassified' ) ),
			)
		);
		if ( $res['ok'] ) {
			$extract = $res['extract'];
			$note    = isset( $extract['status'] ) ? ( ' Extraction: ' . $extract['status'] . ( ! empty( $extract['error'] ) ? ' — ' . $extract['error'] : '' ) ) : '';
			$this->back( 'abcmd-files', __( 'File uploaded.', 'abc-marketing-department' ) . $note, 'success', array( 'book' => $book_id ) );
		} else {
			$this->back( 'abcmd-files', __( 'Upload failed: ', 'abc-marketing-department' ) . $res['error'], 'error', array( 'book' => $book_id ) );
		}
	}

	public function delete_file(): void {
		$this->guard( 'abcmd_delete_file' );
		$id = $this->int( 'id' );
		Uploader::delete( $id );
		$this->back( 'abcmd-files', __( 'File deleted.', 'abc-marketing-department' ) );
	}

	public function extract_file(): void {
		$this->guard( 'abcmd_extract_file' );
		$id  = $this->int( 'id' );
		$res = \ABCMD\Files\Extractor::process( $id );
		$this->back( 'abcmd-files', sprintf( __( 'Extraction: %s (%d chunks).', 'abc-marketing-department' ), $res['status'], $res['chunks'] ), 'success', array( 'file' => $id ) );
	}

	public function download_file(): void {
		Helpers::verify_nonce( 'abcmd_download_file' );
		$id   = $this->int( 'id' );
		$file = ( new FilesRepo() )->find( $id );
		if ( ! $file || empty( $file['stored_path'] ) || ! file_exists( $file['stored_path'] ) ) {
			wp_die( esc_html__( 'File not found.', 'abc-marketing-department' ), '', array( 'response' => 404 ) );
		}
		Audit::log( 'file.download', sprintf( 'Downloaded file "%s".', $file['original_name'] ), array( 'file_id' => $id ), (int) $file['book_id'] );
		nocache_headers();
		header( 'Content-Type: ' . ( $file['mime_type'] ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( (string) $file['original_name'] ) . '"' );
		header( 'Content-Length: ' . (int) $file['size_bytes'] );
		readfile( $file['stored_path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	// --- Tasks / Recommendations / Content --------------------------------

	public function save_task(): void {
		$this->guard( 'abcmd_save_task' );
		$repo = new Tasks();
		$id   = $this->int( 'id' );
		$deps = array_map( 'intval', Helpers::split_list( $this->text( 'dependencies' ) ) );

		$data = array(
			'book_id'         => $this->int( 'book_id' ),
			'author_id'       => $this->int( 'author_id' ),
			'recommendation_id' => $this->int( 'recommendation_id' ),
			'title'           => $this->text( 'title' ),
			'owner'           => $this->text( 'owner' ),
			'priority'        => sanitize_key( $this->text( 'priority', 'medium' ) ),
			'due_date'        => $this->text( 'due_date' ) ?: null,
			'effort'          => $this->text( 'effort' ),
			'budget'          => $this->num( 'budget' ),
			'actual_cost'     => $this->num( 'actual_cost' ),
			'status'          => sanitize_key( $this->text( 'status', 'draft' ) ),
			'dependencies'    => $deps,
			'recurring'       => sanitize_key( $this->text( 'recurring' ) ),
			'notes'           => $this->area( 'notes' ),
			'completion_date' => $this->text( 'completion_date' ) ?: null,
		);

		// Dependency gate: block marking ready/in_progress/completed unless deps met or overridden.
		if ( in_array( $data['status'], array( 'in_progress', 'completed', 'scheduled' ), true ) && $id ) {
			$task = $repo->find( $id );
			if ( $task ) {
				$task['dependencies'] = $deps;
				if ( ! $repo->dependencies_met( $task ) ) {
					$override = $this->text( 'override_reason' );
					if ( '' === $override ) {
						$this->back( 'abcmd-tasks', __( 'This task has incomplete prerequisites. Provide an override reason to proceed.', 'abc-marketing-department' ), 'error', array( 'task' => $id ) );
					}
					$data['override_reason'] = $override;
					Audit::log( 'task.override', 'Task dependency override.', array( 'task_id' => $id, 'reason' => $override ), $data['book_id'] );
				}
			}
		}

		if ( 'completed' === $data['status'] && empty( $data['completion_date'] ) ) {
			$data['completion_date'] = current_time( 'Y-m-d' );
		}

		if ( $id ) {
			$repo->update( $id, $data );
			Audit::log( 'task.update', 'Task updated.', array( 'task_id' => $id, 'status' => $data['status'] ), $data['book_id'] );
		} else {
			$id = $repo->insert( $data );
			Audit::log( 'task.create', 'Task created.', array( 'task_id' => $id ), $data['book_id'] );
		}
		$this->back( 'abcmd-tasks', __( 'Task saved.', 'abc-marketing-department' ), 'success', array( 'task' => $id ) );
	}

	public function save_recommendation(): void {
		$this->guard( 'abcmd_save_recommendation' );
		$repo = new Recommendations();
		$id   = $this->int( 'id' );
		if ( ! $id ) {
			$this->back( 'abcmd-recommendations', __( 'Recommendation not found.', 'abc-marketing-department' ), 'error' );
		}
		$repo->update(
			$id,
			array(
				'title'       => $this->text( 'title' ),
				'description' => $this->area( 'description' ),
				'status'      => sanitize_key( $this->text( 'status', 'draft' ) ),
				'rank_score'  => $this->num( 'rank_score' ),
			)
		);
		$rec = $repo->find( $id );

		// Approved recommendation may generate a task.
		if ( 'approved' === $this->text( 'status' ) && isset( $_POST['create_task'] ) && $rec ) {
			( new Tasks() )->insert(
				array(
					'book_id'           => (int) $rec['book_id'],
					'author_id'         => (int) $rec['author_id'],
					'recommendation_id' => $id,
					'title'             => (string) $rec['title'],
					'priority'          => 'medium',
					'budget'            => (float) $rec['expected_cost'],
					'status'            => 'draft',
					'notes'             => (string) $rec['success_metric'],
				)
			);
			Audit::log( 'task.from_recommendation', 'Task generated from approved recommendation.', array( 'recommendation_id' => $id ), (int) $rec['book_id'] );
		}
		Audit::log( 'recommendation.update', 'Recommendation updated.', array( 'recommendation_id' => $id, 'status' => $this->text( 'status' ) ), $rec ? (int) $rec['book_id'] : null );
		$this->back( 'abcmd-recommendations', __( 'Recommendation saved.', 'abc-marketing-department' ) );
	}

	public function save_content(): void {
		$this->guard( 'abcmd_save_content' );
		$repo = new Content();
		$id   = $this->int( 'id' );
		$data = array(
			'book_id'          => $this->int( 'book_id' ),
			'author_id'        => $this->int( 'author_id' ),
			'campaign'         => $this->text( 'campaign' ),
			'channel'          => $this->text( 'channel' ),
			'format'           => $this->text( 'format' ),
			'title'            => $this->text( 'title' ),
			'copy'             => $this->area( 'copy' ),
			'caption'          => $this->area( 'caption' ),
			'hashtags'         => $this->text( 'hashtags' ),
			'script'           => $this->area( 'script' ),
			'design_direction' => $this->area( 'design_direction' ),
			'image_prompt'     => $this->area( 'image_prompt' ),
			'cta'              => $this->text( 'cta' ),
			'destination_link' => esc_url_raw( wp_unslash( (string) ( $_POST['destination_link'] ?? '' ) ) ),
			'tracking_link'    => esc_url_raw( wp_unslash( (string) ( $_POST['tracking_link'] ?? '' ) ) ),
			'publish_date'     => $this->text( 'publish_date' ) ? gmdate( 'Y-m-d H:i:s', strtotime( $this->text( 'publish_date' ) ) ) : null,
			'objective'        => $this->text( 'objective' ),
			'approval_status'  => sanitize_key( $this->text( 'approval_status', 'draft' ) ),
		);
		if ( $id ) {
			$repo->update( $id, $data );
		} else {
			$id = $repo->insert( $data );
		}
		Audit::log( 'content.save', 'Content item saved.', array( 'content_id' => $id ), $data['book_id'] );
		$this->back( 'abcmd-content', __( 'Content saved.', 'abc-marketing-department' ), 'success', array( 'item' => $id ) );
	}

	// --- Approval queue ---------------------------------------------------

	public function approval_decision(): void {
		$this->guard( 'abcmd_approval_decision' );
		$type     = sanitize_key( $this->text( 'object_type' ) );
		$decision = sanitize_key( $this->text( 'decision' ) );
		$ids      = array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) );
		$note     = $this->area( 'note' );

		$map = array(
			'recommendation' => array( new Recommendations(), 'status' ),
			'content'        => array( new Content(), 'approval_status' ),
			'task'           => array( new Tasks(), 'status' ),
			'ai_run'         => array( new \ABCMD\Repository\AiRuns(), 'approval_status' ),
		);
		if ( ! isset( $map[ $type ] ) ) {
			$this->back( 'abcmd-approvals', __( 'Unknown item type.', 'abc-marketing-department' ), 'error' );
		}
		list( $repo, $col ) = $map[ $type ];

		$new_status = 'approve' === $decision ? 'approved' : ( 'reject' === $decision ? 'rejected' : ( 'regenerate' === $decision ? 'draft' : 'awaiting_review' ) );

		$count = 0;
		foreach ( $ids as $id ) {
			$repo->update( $id, array( $col => $new_status ) );
			$count++;
			Audit::log( 'approval.' . $decision, sprintf( '%s #%d %s.', $type, $id, $new_status ), array( 'note' => $note ) );
		}
		$this->back( 'abcmd-approvals', sprintf( __( '%d item(s) %s.', 'abc-marketing-department' ), $count, $new_status ) );
	}

	// --- Cron / anomalies -------------------------------------------------

	public function run_audit_now(): void {
		$this->guard( 'abcmd_run_audit_now' );
		$summary = WeeklyAudit::run( false );
		$this->back( 'abcmd-weekly', sprintf( __( 'Audit run complete: %d anomalies, %d recommendations across %d workspaces.', 'abc-marketing-department' ), (int) $summary['anomalies'], (int) $summary['recs'], count( $summary['workspaces'] ) ) );
	}

	public function detect_anomalies(): void {
		$this->guard( 'abcmd_detect_anomalies' );
		$book_id = $this->int( 'book_id' );
		$found   = Detector::detect( $book_id );
		$this->back( 'abcmd-books', sprintf( __( 'Anomaly scan complete: %d new flag(s).', 'abc-marketing-department' ), count( $found ) ), 'success', array( 'book' => $book_id, 'tab' => 'anomalies' ) );
	}

	// --- Settings ---------------------------------------------------------

	public function save_settings(): void {
		$this->guard( 'abcmd_save_settings' );
		Options::update(
			array(
				'currency'                  => strtoupper( $this->text( 'currency', 'GBP' ) ),
				'timezone'                  => $this->text( 'timezone', ABCMD_TIMEZONE ),
				'weekly_audit_enabled'      => isset( $_POST['weekly_audit_enabled'] ) ? 1 : 0,
				'weekly_audit_weekday'      => max( 1, min( 7, $this->int( 'weekly_audit_weekday' ) ) ),
				'weekly_audit_hour'         => max( 0, min( 23, $this->int( 'weekly_audit_hour' ) ) ),
				'weekly_email_hour'         => max( 0, min( 23, $this->int( 'weekly_email_hour' ) ) ),
				'weekly_email_recipients'   => $this->text( 'weekly_email_recipients' ),
				'ai_monthly_budget'         => $this->num( 'ai_monthly_budget' ),
				'ai_cost_confirm_threshold' => $this->num( 'ai_cost_confirm_threshold' ),
				'ai_rate_limit_per_hour'    => max( 1, $this->int( 'ai_rate_limit_per_hour' ) ),
				'uninstall_purge'           => isset( $_POST['uninstall_purge'] ) ? 1 : 0,
			)
		);
		Scheduler::reschedule();
		Audit::log( 'settings.update', 'General settings updated.' );
		$this->back( 'abcmd-settings', __( 'Settings saved and schedule updated.', 'abc-marketing-department' ) );
	}

	public function save_openai(): void {
		$this->guard( 'abcmd_save_openai' );
		$current = get_option( ABCMD_OPT_OPENAI, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		// Only replace the key if a new one was entered.
		$new_key = trim( (string) ( $_POST['api_key'] ?? '' ) );
		if ( '' !== $new_key && ! str_starts_with( $new_key, '•' ) ) {
			try {
				$current['api_key_enc'] = Encryption::encrypt( $new_key );
			} catch ( \Throwable $e ) {
				$this->back( 'abcmd-settings', __( 'Could not encrypt the API key: ', 'abc-marketing-department' ) . $e->getMessage(), 'error', array( 'tab' => 'openai' ) );
			}
		}

		$current['organization']   = $this->text( 'organization' );
		$current['project']        = $this->text( 'project' );
		$current['default_model']  = $this->text( 'default_model', 'gpt-5.4' );
		$current['allowed_models'] = array_map( 'sanitize_text_field', (array) ( $_POST['allowed_models'] ?? array() ) );
		$current['temperature']    = ( '' === (string) ( $_POST['temperature'] ?? '' ) ) ? null : (float) $_POST['temperature'];
		$current['web_search']     = isset( $_POST['web_search'] ) ? 1 : 0;
		$current['timeout']        = max( 5, $this->int( 'timeout' ) );
		$current['retries']        = max( 0, min( 5, $this->int( 'retries' ) ) );

		update_option( ABCMD_OPT_OPENAI, $current, false );
		Audit::log( 'settings.openai', 'OpenAI settings updated.', array( 'default_model' => $current['default_model'], 'web_search' => $current['web_search'] ) );
		$this->back( 'abcmd-settings', __( 'OpenAI settings saved.', 'abc-marketing-department' ), 'success', array( 'tab' => 'openai' ) );
	}

	public function save_prices(): void {
		$this->guard( 'abcmd_save_prices' );
		$prices = \ABCMD\AI\Cost::prices();
		foreach ( (array) ( $_POST['price_in'] ?? array() ) as $model => $val ) {
			$model = sanitize_text_field( wp_unslash( (string) $model ) );
			$prices['models'][ $model ]['in'] = (float) $val;
		}
		foreach ( (array) ( $_POST['price_out'] ?? array() ) as $model => $val ) {
			$model = sanitize_text_field( wp_unslash( (string) $model ) );
			$prices['models'][ $model ]['out'] = (float) $val;
		}
		if ( isset( $_POST['web_search_per_1k'] ) ) {
			$prices['web_search_per_1k'] = (float) $_POST['web_search_per_1k'];
		}
		if ( isset( $_POST['price_currency'] ) ) {
			$prices['currency'] = strtoupper( $this->text( 'price_currency', 'USD' ) );
		}

		// Optional: add a new model row.
		$new_model = sanitize_text_field( wp_unslash( (string) ( $_POST['new_model'] ?? '' ) ) );
		if ( '' !== $new_model ) {
			$prices['models'][ $new_model ] = array( 'in' => (float) ( $_POST['new_in'] ?? 0 ), 'out' => (float) ( $_POST['new_out'] ?? 0 ) );
		}

		update_option( ABCMD_OPT_PRICES, $prices, false );
		Audit::log( 'settings.prices', 'Model pricing updated.' );
		$this->back( 'abcmd-settings', __( 'Model pricing saved.', 'abc-marketing-department' ), 'success', array( 'tab' => 'pricing' ) );
	}

	public function save_privacy(): void {
		$this->guard( 'abcmd_save_privacy' );
		$text = wp_kses_post( wp_unslash( (string) ( $_POST['privacy_template'] ?? '' ) ) );
		update_option( ABCMD_OPT_PRIVACY, $text, false );
		Audit::log( 'settings.privacy', 'Privacy template updated.' );
		$this->back( 'abcmd-settings', __( 'Privacy template saved.', 'abc-marketing-department' ), 'success', array( 'tab' => 'privacy' ) );
	}

	// --- Demo / purge -----------------------------------------------------

	public function install_demo(): void {
		$this->guard( 'abcmd_install_demo' );
		$res = DemoData::install();
		$this->back( 'abcmd-books', sprintf( __( 'Demo workspace installed for %s.', 'abc-marketing-department' ), $res['author_name'] ), 'success', array( 'book' => $res['book_id'] ) );
	}

	public function purge(): void {
		$this->guard( 'abcmd_purge' );
		$scope = sanitize_key( $this->text( 'scope' ) );
		if ( $this->text( 'confirm' ) !== 'PURGE' ) {
			$this->back( 'abcmd-settings', __( 'Type PURGE to confirm.', 'abc-marketing-department' ), 'error', array( 'tab' => 'data' ) );
		}
		$n = DemoData::purge( $scope, $this->int( 'id' ) );
		$this->back( 'abcmd-settings', sprintf( __( 'Purge complete (%s): %d records removed.', 'abc-marketing-department' ), $scope, $n ), 'success', array( 'tab' => 'data' ) );
	}

	// --- Exports ----------------------------------------------------------

	public function export_csv(): void {
		Helpers::verify_nonce( 'abcmd_export_csv' );
		$type    = sanitize_key( $this->text( 'type', 'tasks' ) );
		$book_id = $this->int( 'book_id' ) ?: null;
		$doc     = CsvExport::build( $type, $book_id );
		$this->send_download( $doc['filename'], 'text/csv', $doc['content'] );
	}

	public function export_ical(): void {
		Helpers::verify_nonce( 'abcmd_export_ical' );
		$book_id = $this->int( 'book_id' ) ?: null;
		$doc     = IcalExport::build( $book_id );
		$this->send_download( $doc['filename'], 'text/calendar', $doc['content'] );
	}

	public function export_copy(): void {
		Helpers::verify_nonce( 'abcmd_export_copy' );
		$ids = array_map( 'intval', (array) ( $_REQUEST['ids'] ?? array() ) );
		$doc = CopyReady::build( $ids, isset( $_REQUEST['with_metadata'] ) );
		$this->send_download( $doc['filename'], 'text/plain', $doc['content'] );
	}

	private function send_download( string $filename, string $mime, string $content ): void {
		nocache_headers();
		header( 'Content-Type: ' . $mime . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $content ) );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- file body, not HTML.
		exit;
	}

	// --- Setup wizard -----------------------------------------------------

	public function complete_setup(): void {
		$this->guard( 'abcmd_complete_setup' );
		Options::update( array( 'setup_complete' => 1 ) );
		$this->back( 'abcmd', __( 'Setup complete. Welcome to Marketing Department.', 'abc-marketing-department' ) );
	}

	/**
	 * Build a key/value map from indexed POST fields like price[ebook]=4.99.
	 *
	 * @param string $prefix POST array key.
	 * @return array<string,float|string>
	 */
	private function kv_from_post( string $prefix ): array {
		$out = array();
		$src = (array) ( $_POST[ $prefix ] ?? array() );
		foreach ( $src as $k => $v ) {
			$key = sanitize_text_field( wp_unslash( (string) $k ) );
			if ( '' === $key ) {
				continue;
			}
			$val         = is_numeric( $v ) ? (float) $v : sanitize_text_field( wp_unslash( (string) $v ) );
			$out[ $key ] = $val;
		}
		return $out;
	}
}
