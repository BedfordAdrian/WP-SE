<?php
/**
 * Book Workspaces page: list, per-workspace dashboard (with tabs) and the
 * aggregated author dashboard.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin\Pages;

use ABCMD\Admin\View;
use ABCMD\AI\DataSharing;
use ABCMD\AI\OpenAIClient;
use ABCMD\AI\TaskTypes;
use ABCMD\Repository\AiRuns;
use ABCMD\Repository\Anomalies;
use ABCMD\Repository\Authors as AuthorsRepo;
use ABCMD\Repository\Books as BooksRepo;
use ABCMD\Repository\Consent as ConsentRepo;
use ABCMD\Repository\Content as ContentRepo;
use ABCMD\Repository\Files as FilesRepo;
use ABCMD\Repository\Imports as ImportsRepo;
use ABCMD\Repository\Metrics;
use ABCMD\Repository\Recommendations as RecRepo;
use ABCMD\Repository\Tasks as TasksRepo;
use ABCMD\Support\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders workspace and author dashboards.
 */
final class Books {

	/**
	 * Route to list / workspace / author view.
	 */
	public static function render(): void {
		Helpers::require_cap();

		$author_id = isset( $_GET['author'] ) ? (int) $_GET['author'] : 0;
		$book_id   = isset( $_GET['book'] ) ? (int) $_GET['book'] : 0;

		if ( $author_id ) {
			self::author_dashboard( $author_id );
			return;
		}
		if ( $book_id ) {
			self::workspace( $book_id );
			return;
		}
		self::listing();
	}

	// --- Listing ----------------------------------------------------------

	private static function listing(): void {
		$books   = new BooksRepo();
		$authors = new AuthorsRepo();
		$metrics = new Metrics();
		$all     = $books->all( array(), 'title', 'ASC' );

		View::open( __( 'Book Workspaces', 'abc-marketing-department' ), __( 'Each book is its own client workspace. Books by the same author roll up into the author dashboard.', 'abc-marketing-department' ) );

		if ( empty( $all ) ) {
			View::empty_state( __( 'No workspaces yet. Create one below, or install the demo workspace to explore the plugin.', 'abc-marketing-department' ) );
			echo '<p>';
			View::form_open( 'abcmd_install_demo' );
			View::submit( __( 'Install demo workspace (Lisa Doyle is Absolutely Fine)', 'abc-marketing-department' ), 'secondary' );
			View::form_close();
			echo '</p>';
		} else {
			echo '<table class="wp-list-table widefat striped abcmd-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Title', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Author', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Status', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Total sales', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Last data', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Anomalies', 'abc-marketing-department' ) . '</th></tr></thead><tbody>';
			foreach ( $all as $b ) {
				$author = $b['author_id'] ? $authors->find( (int) $b['author_id'] ) : null;
				$anoms  = ( new Anomalies() )->open_for( (int) $b['id'] );
				$url    = Helpers::admin_url( 'abcmd-books', array( 'book' => $b['id'] ) );
				echo '<tr>';
				echo '<td><strong><a href="' . esc_url( $url ) . '">' . esc_html( $b['title'] ) . '</a></strong></td>';
				echo '<td>' . ( $author ? '<a href="' . esc_url( Helpers::admin_url( 'abcmd-books', array( 'author' => $author['id'] ) ) ) . '">' . esc_html( AuthorsRepo::display_name( $author ) ) . '</a>' : '—' ) . '</td>';
				echo '<td>' . View::pill( (string) $b['status'] ) . '</td>';
				echo '<td>' . esc_html( (string) (int) $metrics->sum( (int) $b['id'], 'sales' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $metrics->last_date( (int) $b['id'] ) ?: '—' ) ) . '</td>';
				echo '<td>' . ( $anoms ? '<span class="abcmd-pill abcmd-pill-warning">' . count( $anoms ) . '</span>' : '0' ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '<hr /><h2>' . esc_html__( 'Add a workspace', 'abc-marketing-department' ) . '</h2>';
		self::edit_form( null );
		View::close();
	}

	// --- Single workspace -------------------------------------------------

	private static function workspace( int $book_id ): void {
		$books = new BooksRepo();
		$book  = $books->find( $book_id );
		if ( ! $book ) {
			View::open( __( 'Workspace', 'abc-marketing-department' ) );
			View::notice( __( 'Workspace not found.', 'abc-marketing-department' ), 'error' );
			View::close();
			return;
		}
		$author = $book['author_id'] ? ( new AuthorsRepo() )->find( (int) $book['author_id'] ) : null;

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'overview';
		$tabs = array(
			'overview'  => __( 'Dashboard', 'abc-marketing-department' ),
			'edit'      => __( 'Edit details', 'abc-marketing-department' ),
			'consent'   => __( 'Consent', 'abc-marketing-department' ),
			'ai'        => __( 'Run AI', 'abc-marketing-department' ),
			'anomalies' => __( 'Anomalies', 'abc-marketing-department' ),
		);

		$subtitle = $author ? sprintf( __( 'by %s', 'abc-marketing-department' ), AuthorsRepo::display_name( $author ) ) : '';
		View::open( $book['title'], $subtitle );
		if ( $author ) {
			echo '<p>' . View::button_link( Helpers::admin_url( 'abcmd-books', array( 'author' => $author['id'] ) ), __( 'View author dashboard', 'abc-marketing-department' ) ) . '</p>';
		}
		View::tabs( $tabs, $tab, 'abcmd-books', array( 'book' => $book_id ) );

		switch ( $tab ) {
			case 'edit':
				self::edit_form( $book );
				break;
			case 'consent':
				self::consent_tab( $book );
				break;
			case 'ai':
				self::ai_tab( $book );
				break;
			case 'anomalies':
				self::anomalies_tab( $book );
				break;
			default:
				self::overview_tab( $book );
		}
		View::close();
	}

	private static function overview_tab( array $book ): void {
		$id      = (int) $book['id'];
		$metrics = new Metrics();
		$sym     = Helpers::symbol_for( (string) $book['currency'] );

		$sales        = $metrics->sum( $id, 'sales' );
		$contribution = $metrics->sum( $id, 'contribution' );
		$spend        = $metrics->sum( $id, 'ad_spend' );
		$budget       = (float) $book['budget'];
		$reviews      = array_sum( $metrics->sum_by( $id, 'reviews', 'platform' ) );
		$list         = $metrics->latest_value( $id, 'mailing_list_size' );

		echo '<div class="abcmd-stats">';
		View::stat( __( 'Total sales', 'abc-marketing-department' ), (string) (int) $sales );
		View::stat( __( 'Est. contribution', 'abc-marketing-department' ), $sym . number_format( $contribution, 2 ) );
		View::stat( __( 'Ad spend', 'abc-marketing-department' ), $sym . number_format( $spend, 2 ) );
		View::stat( __( 'Budget remaining', 'abc-marketing-department' ), $sym . number_format( max( 0, $budget - $spend ), 2 ), sprintf( __( 'of %s', 'abc-marketing-department' ), $sym . number_format( $budget, 2 ) ) );
		View::stat( __( 'Reviews', 'abc-marketing-department' ), (string) (int) $reviews );
		View::stat( __( 'Mailing list', 'abc-marketing-department' ), (string) (int) $list );
		echo '</div>';

		echo '<div class="abcmd-grid">';

		// Format mix.
		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Format mix (sales)', 'abc-marketing-department' ) . '</h3>';
		self::kv_table( $metrics->sum_by( $id, 'sales', 'format' ) );
		echo '</div>';

		// Targets & progress.
		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Targets (90 day)', 'abc-marketing-department' ) . '</h3>';
		$targets = is_array( $book['targets_90day'] ) ? $book['targets_90day'] : array();
		if ( $targets ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $targets as $k => $v ) {
				$progress = self::target_progress( $metrics, $id, (string) $k );
				echo '<tr><td>' . esc_html( ucwords( str_replace( '_', ' ', (string) $k ) ) ) . '</td><td>' . esc_html( (string) $v ) . '</td><td>' . esc_html( $progress ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">' . esc_html__( 'No targets set.', 'abc-marketing-department' ) . '</p>';
		}
		echo '</div>';

		// Email performance.
		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Email performance', 'abc-marketing-department' ) . '</h3>';
		echo '<p>' . esc_html__( 'Open rate', 'abc-marketing-department' ) . ': ' . esc_html( round( $metrics->latest_value( $id, 'open_rate' ), 1 ) ) . '% &middot; ';
		echo esc_html__( 'Click rate', 'abc-marketing-department' ) . ': ' . esc_html( round( $metrics->latest_value( $id, 'click_rate' ), 1 ) ) . '%</p>';
		echo '<h4>' . esc_html__( 'Audience (followers)', 'abc-marketing-department' ) . '</h4>';
		self::kv_table( $metrics->sum_by( $id, 'followers', 'platform' ) );
		echo '</div>';

		// Active recommendations.
		$recs = ( new RecRepo() )->active_for( $id );
		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Active recommendations', 'abc-marketing-department' ) . '</h3>';
		if ( $recs ) {
			echo '<ul>';
			foreach ( array_slice( $recs, 0, 8 ) as $r ) {
				echo '<li>' . View::pill( (string) $r['status'] ) . ' ' . esc_html( $r['title'] ) . ' ' . View::pill( (string) $r['evidence_label'] ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="description">' . esc_html__( 'None yet. Run an AI audit from the “Run AI” tab.', 'abc-marketing-department' ) . '</p>';
		}
		echo '<p>' . View::button_link( Helpers::admin_url( 'abcmd-recommendations', array( 'book' => $id ) ), __( 'All recommendations', 'abc-marketing-department' ) ) . '</p>';
		echo '</div>';

		// Overdue tasks + pending approvals.
		$overdue  = ( new TasksRepo() )->overdue_for( $id );
		$pending  = ( new RecRepo() )->count( array( 'book_id' => $id, 'status' => 'awaiting_review' ) )
			+ ( new ContentRepo() )->count( array( 'book_id' => $id, 'approval_status' => 'awaiting_review' ) );
		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Work queue', 'abc-marketing-department' ) . '</h3>';
		echo '<p>' . esc_html__( 'Overdue tasks', 'abc-marketing-department' ) . ': <strong>' . count( $overdue ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Pending approvals', 'abc-marketing-department' ) . ': <strong>' . (int) $pending . '</strong></p>';
		echo '<p>' . View::button_link( Helpers::admin_url( 'abcmd-approvals', array( 'book' => $id ) ), __( 'Approval queue', 'abc-marketing-department' ) ) . '</p>';
		echo '</div>';

		// Anomalies.
		$anoms = ( new Anomalies() )->open_for( $id );
		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Anomaly flags', 'abc-marketing-department' ) . '</h3>';
		if ( $anoms ) {
			foreach ( array_slice( $anoms, 0, 6 ) as $a ) {
				echo '<div class="abcmd-anomaly severity-' . esc_attr( $a['severity'] ) . '"><strong>' . esc_html( $a['title'] ) . '</strong><br />' . esc_html( wp_trim_words( (string) $a['detail'], 30 ) ) . '</div>';
			}
		} else {
			echo '<p class="description">' . esc_html__( 'No open anomaly flags.', 'abc-marketing-department' ) . '</p>';
		}
		echo '</div>';

		// Latest AI audit.
		$latest_run = ( new AiRuns() )->where( array( 'book_id' => $id ), 'id', 'DESC', 1, 0 );
		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Latest AI audit', 'abc-marketing-department' ) . '</h3>';
		if ( ! empty( $latest_run ) ) {
			$lr = $latest_run[0];
			echo '<p>' . esc_html( $lr['run_type'] ) . ' &middot; ' . esc_html( $lr['model'] ) . ' &middot; ' . esc_html( $lr['created_at'] ) . '</p>';
			echo '<p>' . esc_html( wp_trim_words( (string) $lr['response'], 40 ) ) . '</p>';
			echo '<p>' . View::button_link( Helpers::admin_url( 'abcmd-ai-runs', array( 'run' => $lr['id'] ) ), __( 'View run', 'abc-marketing-department' ) ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'No AI runs yet.', 'abc-marketing-department' ) . '</p>';
		}
		echo '</div>';

		// Recent imports / files / missing data.
		$imports = ( new ImportsRepo() )->where( array( 'book_id' => $id ), 'id', 'DESC', 5, 0 );
		$files   = ( new FilesRepo() )->where( array( 'book_id' => $id ), 'id', 'DESC', 5, 0 );
		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Recent imports', 'abc-marketing-department' ) . '</h3>';
		if ( $imports ) {
			echo '<ul>';
			foreach ( $imports as $imp ) {
				echo '<li>' . esc_html( $imp['source'] ) . ' — ' . esc_html( (string) $imp['rows_imported'] ) . ' rows (' . esc_html( $imp['source_status'] ) . '), ' . esc_html( $imp['created_at'] ) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="description">' . esc_html__( 'No imports yet.', 'abc-marketing-department' ) . '</p>';
		}
		$last = $metrics->last_date( $id );
		echo '<p class="description">' . esc_html__( 'Most recent metric date', 'abc-marketing-department' ) . ': ' . esc_html( $last ?: __( 'none', 'abc-marketing-department' ) ) . '</p>';
		echo '<p>' . View::button_link( Helpers::admin_url( 'abcmd-imports', array( 'book' => $id ) ), __( 'Import data', 'abc-marketing-department' ) ) . ' ' . View::button_link( Helpers::admin_url( 'abcmd-files', array( 'book' => $id ) ), __( 'Files', 'abc-marketing-department' ) ) . '</p>';
		echo '</div>';

		// Campaign history (weekly sales trend).
		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Campaign history (weekly sales)', 'abc-marketing-department' ) . '</h3>';
		self::kv_table( $metrics->weekly_series( $id, 'sales', 8 ) );
		echo '</div>';

		echo '</div>'; // grid.
	}

	private static function anomalies_tab( array $book ): void {
		$id     = (int) $book['id'];
		$anoms  = ( new Anomalies() )->open_for( $id );

		echo '<p>';
		View::form_open( 'abcmd_detect_anomalies', '', array( 'book_id' => (string) $id ) );
		View::submit( __( 'Run anomaly scan now', 'abc-marketing-department' ), 'secondary' );
		View::form_close();
		echo '</p>';

		echo '<p class="description">' . esc_html__( 'Anomaly flags describe what changed and why it merits checking — they are not accusations of fraud or statements of certainty. Adjust thresholds on the “Edit details” tab.', 'abc-marketing-department' ) . '</p>';

		if ( empty( $anoms ) ) {
			View::empty_state( __( 'No open anomaly flags for this workspace.', 'abc-marketing-department' ) );
			return;
		}
		echo '<table class="wp-list-table widefat striped abcmd-table"><thead><tr><th>' . esc_html__( 'Severity', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Flag', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Detail', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Detected', 'abc-marketing-department' ) . '</th></tr></thead><tbody>';
		foreach ( $anoms as $a ) {
			echo '<tr><td>' . View::pill( (string) $a['severity'] ) . '</td><td><strong>' . esc_html( $a['title'] ) . '</strong></td><td>' . esc_html( (string) $a['detail'] ) . '</td><td>' . esc_html( (string) $a['detected_at'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function consent_tab( array $book ): void {
		$id      = (int) $book['id'];
		$consent = ( new ConsentRepo() )->for_book( $id );
		$c       = $consent ?: array();

		echo '<p class="description">' . esc_html__( 'AI runs are blocked until an active consent record permits OpenAI use. Default is no sharing. Consent can be revoked at any time.', 'abc-marketing-department' ) . '</p>';

		View::form_open( 'abcmd_save_consent', '', array( 'book_id' => (string) $id ) );
		echo '<table class="form-table">';
		View::checkbox( 'allow_openai', __( 'Permit OpenAI use', 'abc-marketing-department' ), ! empty( $c['allow_openai'] ), __( 'Master switch — required for any AI run.', 'abc-marketing-department' ) );
		View::checkbox( 'allow_sales', __( 'Permit sales/advertising/email data sharing', 'abc-marketing-department' ), ! empty( $c['allow_sales'] ) );
		View::checkbox( 'allow_manuscript', __( 'Permit manuscript sharing', 'abc-marketing-department' ), ! empty( $c['allow_manuscript'] ), __( 'Off by default. Full manuscripts are never sent unless this is on and explicitly selected.', 'abc-marketing-department' ) );
		View::checkbox( 'allow_personal', __( 'Permit personal client data sharing', 'abc-marketing-department' ), ! empty( $c['allow_personal'] ) );
		View::checkbox( 'allow_web_research', __( 'Permit AI web research', 'abc-marketing-department' ), ! empty( $c['allow_web_research'] ) );
		View::field( 'granted_date', __( 'Date granted', 'abc-marketing-department' ), (string) ( $c['granted_date'] ?? '' ), 'date' );
		View::field( 'method', __( 'Method', 'abc-marketing-department' ), (string) ( $c['method'] ?? '' ), 'text', __( 'e.g. signed onboarding form, email confirmation.', 'abc-marketing-department' ) );
		View::field( 'revoked_date', __( 'Revocation date (if any)', 'abc-marketing-department' ), (string) ( $c['revoked_date'] ?? '' ), 'date' );
		View::select( 'status', __( 'Status', 'abc-marketing-department' ), array( 'active' => 'Active', 'revoked' => 'Revoked', 'none' => 'None' ), (string) ( $c['status'] ?? 'none' ) );
		View::textarea( 'notes', __( 'Notes', 'abc-marketing-department' ), (string) ( $c['notes'] ?? '' ) );
		echo '</table>';
		View::submit( __( 'Save consent record', 'abc-marketing-department' ) );
		View::form_close();

		echo '<hr /><p class="description">' . esc_html__( 'The client-facing UK privacy / AI-processing template is on the Settings → Privacy tab. It is a starting point requiring legal review, not legal advice.', 'abc-marketing-department' ) . '</p>';
	}

	private static function ai_tab( array $book ): void {
		$id      = (int) $book['id'];
		$consent = new ConsentRepo();
		$allowed = $consent->ai_allowed( $id );

		if ( ! OpenAIClient::has_key() ) {
			View::notice( __( 'No OpenAI API key configured. Add one in Settings → OpenAI before running AI tasks.', 'abc-marketing-department' ) . ' ' . View::button_link( Helpers::admin_url( 'abcmd-settings', array( 'tab' => 'openai' ) ), __( 'OpenAI settings', 'abc-marketing-department' ) ), 'warning' );
		}
		if ( ! $allowed ) {
			View::notice( __( 'This workspace has no active consent to use OpenAI. Record consent on the Consent tab first — AI runs are blocked until then.', 'abc-marketing-department' ), 'warning' );
		}

		$models = OpenAIClient::allowed_models();
		$model_opts = array();
		foreach ( $models as $m ) {
			$model_opts[ $m ] = $m;
		}
		if ( ! $model_opts ) {
			$model_opts = array( OpenAIClient::settings()['default_model'] => OpenAIClient::settings()['default_model'] );
		}

		View::form_open( 'abcmd_run_ai', '', array( 'book_id' => (string) $id ) );
		echo '<table class="form-table">';
		View::select( 'run_type', __( 'Task', 'abc-marketing-department' ), TaskTypes::options(), 'weekly_review' );
		View::select( 'model', __( 'Model', 'abc-marketing-department' ), $model_opts, OpenAIClient::settings()['default_model'] );
		View::checkbox( 'web_search', __( 'Web research', 'abc-marketing-department' ), (bool) OpenAIClient::settings()['web_search'], __( 'Only used if the model supports it and consent permits web research.', 'abc-marketing-department' ) );
		echo '</table>';

		echo '<h3>' . esc_html__( 'Data-sharing checklist', 'abc-marketing-department' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Choose exactly what to send. Defaults to the minimum for the task. Sensitive categories are marked and are gated by the consent record.', 'abc-marketing-department' ) . '</p>';
		$type_def = TaskTypes::get( 'weekly_review' );
		$min      = $type_def ? (array) $type_def['min_data'] : array();
		echo '<div class="abcmd-checklist">';
		foreach ( DataSharing::categories() as $slug => $cat ) {
			$checked = in_array( $slug, $min, true );
			$sens    = $cat['sensitive'] ? ' <span class="sensitive">(' . esc_html__( 'sensitive', 'abc-marketing-department' ) . ')</span>' : '';
			echo '<label><input type="checkbox" name="categories[]" value="' . esc_attr( $slug ) . '" ' . checked( $checked, true, false ) . ' /> ' . esc_html( $cat['label'] ) . $sens . '</label>';
		}
		echo '</div>';

		echo '<table class="form-table">';
		View::textarea( 'custom_instruction', __( 'Extra instruction (optional)', 'abc-marketing-department' ), '', 3 );
		View::textarea( 'manuscript_summary', __( 'Locally-generated manuscript summary (optional)', 'abc-marketing-department' ), '', 3, __( 'Paste a summary you generated locally. Only sent if the summary category is ticked and consent allows manuscript sharing.', 'abc-marketing-department' ) );
		View::textarea( 'passages', __( 'Selected manuscript passages (optional)', 'abc-marketing-department' ), '', 3 );
		echo '</table>';

		// Files to attach.
		$files = ( new FilesRepo() )->where( array( 'book_id' => $id ), 'id', 'DESC', 0, 0 );
		if ( $files ) {
			echo '<h4>' . esc_html__( 'Attach extracted file text (optional)', 'abc-marketing-department' ) . '</h4>';
			foreach ( $files as $f ) {
				if ( 'extracted' !== $f['extract_status'] ) {
					continue;
				}
				echo '<label style="display:block"><input type="checkbox" name="file_ids[]" value="' . esc_attr( (string) $f['id'] ) . '" /> ' . esc_html( $f['original_name'] ) . ' (' . esc_html( (string) $f['chunk_count'] ) . ' chunks)</label>';
			}
		}

		echo '<div class="abcmd-cost-box"><div id="abcmd-live-estimate">' . esc_html__( 'Cost estimate appears here.', 'abc-marketing-department' ) . '</div></div>';
		echo '<p><label><input type="checkbox" name="confirmed" value="1" /> ' . esc_html__( 'I confirm running this even if it exceeds my cost threshold.', 'abc-marketing-department' ) . '</label></p>';

		$disabled = ( $allowed && OpenAIClient::has_key() ) ? '' : ' disabled="disabled"';
		echo '<p><button type="submit" class="button button-primary"' . $disabled . '>' . esc_html__( 'Run AI task', 'abc-marketing-department' ) . '</button></p>';
		View::form_close();
	}

	// --- Author dashboard -------------------------------------------------

	private static function author_dashboard( int $author_id ): void {
		$authors = new AuthorsRepo();
		$author  = $authors->find( $author_id );
		if ( ! $author ) {
			View::open( __( 'Author', 'abc-marketing-department' ) );
			View::notice( __( 'Author not found.', 'abc-marketing-department' ), 'error' );
			View::close();
			return;
		}
		$books   = ( new BooksRepo() )->for_author( $author_id );
		$metrics = new Metrics();

		View::open( sprintf( __( 'Author dashboard: %s', 'abc-marketing-department' ), AuthorsRepo::display_name( $author ) ), __( 'Aggregated across all of this author’s books — designed to surface oddities, not just totals.', 'abc-marketing-department' ) );

		$total_sales = 0.0;
		$total_spend = 0.0;
		$total_contrib = 0.0;
		$per_title   = array();
		foreach ( $books as $b ) {
			$s = $metrics->sum( (int) $b['id'], 'sales' );
			$total_sales   += $s;
			$total_spend   += $metrics->sum( (int) $b['id'], 'ad_spend' );
			$total_contrib += $metrics->sum( (int) $b['id'], 'contribution' );
			$per_title[ $b['title'] ] = $s;
		}

		echo '<div class="abcmd-stats">';
		View::stat( __( 'Active campaigns', 'abc-marketing-department' ), (string) count( array_filter( $books, static fn( $b ) => 'active' === $b['status'] ) ) );
		View::stat( __( 'Total sales', 'abc-marketing-department' ), (string) (int) $total_sales );
		View::stat( __( 'Total campaign spend', 'abc-marketing-department' ), Helpers::money_fmt( $total_spend ) );
		View::stat( __( 'Total contribution', 'abc-marketing-department' ), Helpers::money_fmt( $total_contrib ) );
		echo '</div>';

		echo '<div class="abcmd-grid">';

		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Sales by title', 'abc-marketing-department' ) . '</h3>';
		self::kv_table( $per_title );
		echo '</div>';

		// Performance differences between titles (contribution per sale).
		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Performance differences', 'abc-marketing-department' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Title', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Sales', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Contribution/sale', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Cost/sale', 'abc-marketing-department' ) . '</th></tr></thead><tbody>';
		foreach ( $books as $b ) {
			$s   = $metrics->sum( (int) $b['id'], 'sales' );
			$con = $metrics->sum( (int) $b['id'], 'contribution' );
			$sp  = $metrics->sum( (int) $b['id'], 'ad_spend' );
			$cps = $s > 0 ? $sp / $s : 0;
			$cpr = $s > 0 ? $con / $s : 0;
			echo '<tr><td>' . esc_html( $b['title'] ) . '</td><td>' . esc_html( (string) (int) $s ) . '</td><td>' . esc_html( Helpers::money_fmt( $cpr, (string) $b['currency'] ) ) . '</td><td>' . esc_html( Helpers::money_fmt( $cps, (string) $b['currency'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		// Audience / mailing list across titles.
		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Audience & mailing list', 'abc-marketing-department' ) . '</h3><table class="widefat striped"><tbody>';
		foreach ( $books as $b ) {
			echo '<tr><td>' . esc_html( $b['title'] ) . '</td><td>' . esc_html__( 'List', 'abc-marketing-department' ) . ': ' . esc_html( (string) (int) $metrics->latest_value( (int) $b['id'], 'mailing_list_size' ) ) . '</td><td>' . esc_html__( 'Open', 'abc-marketing-department' ) . ': ' . esc_html( round( $metrics->latest_value( (int) $b['id'], 'open_rate' ), 1 ) ) . '%</td></tr>';
		}
		echo '</tbody></table></div>';

		// Cross-promotion opportunities + unusual changes + data gaps.
		echo '<div class="abcmd-card"><h3>' . esc_html__( 'Things to check', 'abc-marketing-department' ) . '</h3><ul>';
		if ( count( $books ) > 1 ) {
			echo '<li>' . esc_html__( 'Cross-promotion: link the author’s titles in back-matter and newsletters — multiple active books present.', 'abc-marketing-department' ) . '</li>';
		}
		foreach ( $books as $b ) {
			$anoms = ( new Anomalies() )->open_for( (int) $b['id'] );
			if ( $anoms ) {
				echo '<li>' . esc_html( $b['title'] ) . ': ' . esc_html( (string) count( $anoms ) ) . ' ' . esc_html__( 'open anomaly flag(s).', 'abc-marketing-department' ) . '</li>';
			}
			$last = $metrics->last_date( (int) $b['id'] );
			if ( ! $last ) {
				echo '<li>' . esc_html( $b['title'] ) . ': ' . esc_html__( 'no metrics recorded — data gap.', 'abc-marketing-department' ) . '</li>';
			}
		}
		echo '</ul></div>';

		echo '</div>'; // grid.

		echo '<p>' . View::button_link( Helpers::admin_url( 'abcmd-authors', array( 'author' => $author_id ) ), __( 'Edit author', 'abc-marketing-department' ) ) . '</p>';
		View::close();
	}

	// --- Shared edit form -------------------------------------------------

	private static function edit_form( ?array $book ): void {
		$b       = $book ?: array();
		$id      = (int) ( $b['id'] ?? 0 );
		$authors = ( new AuthorsRepo() )->options();
		$prices  = is_array( $b['prices'] ?? null ) ? $b['prices'] : array();
		$nets    = is_array( $b['net_income'] ?? null ) ? $b['net_income'] : array();
		$targets = is_array( $b['targets_90day'] ?? null ) ? $b['targets_90day'] : array();
		$be      = is_array( $b['break_even'] ?? null ) ? $b['break_even'] : array();
		$th      = is_array( $b['anomaly_thresholds'] ?? null ) ? $b['anomaly_thresholds'] : array();

		View::form_open( 'abcmd_save_book', '', array( 'id' => (string) $id ) );
		echo '<table class="form-table">';
		if ( empty( $authors ) ) {
			echo '<tr><td colspan="2">' . esc_html__( 'Tip: create an author first for the author dashboard to work.', 'abc-marketing-department' ) . '</td></tr>';
		}
		View::select( 'author_id', __( 'Author', 'abc-marketing-department' ), array( 0 => '— none —' ) + $authors, (string) ( $b['author_id'] ?? 0 ) );
		View::field( 'title', __( 'Title', 'abc-marketing-department' ), (string) ( $b['title'] ?? '' ) );
		View::field( 'subtitle', __( 'Subtitle', 'abc-marketing-department' ), (string) ( $b['subtitle'] ?? '' ) );
		View::field( 'publisher', __( 'Publisher / imprint', 'abc-marketing-department' ), (string) ( $b['publisher'] ?? '' ) );
		View::field( 'publication_date', __( 'Publication date', 'abc-marketing-department' ), (string) ( $b['publication_date'] ?? '' ), 'date' );
		View::field( 'genre', __( 'Genre', 'abc-marketing-department' ), (string) ( $b['genre'] ?? '' ) );
		View::field( 'territory', __( 'Territory', 'abc-marketing-department' ), (string) ( $b['territory'] ?? '' ) );
		View::field( 'audience', __( 'Audience', 'abc-marketing-department' ), (string) ( $b['audience'] ?? '' ) );
		View::textarea( 'synopsis', __( 'Synopsis', 'abc-marketing-department' ), (string) ( $b['synopsis'] ?? '' ) );
		View::textarea( 'proposition', __( 'Proposition', 'abc-marketing-department' ), (string) ( $b['proposition'] ?? '' ), 2 );
		View::textarea( 'comparison_titles', __( 'Comparison titles (one per line)', 'abc-marketing-department' ), self::lines( $b['comparison_titles'] ?? array() ), 3 );
		View::field( 'formats', __( 'Formats (comma separated)', 'abc-marketing-department' ), implode( ', ', (array) ( $b['formats'] ?? array() ) ) );

		// Prices + net per format.
		echo '<tr><th scope="row">' . esc_html__( 'Prices & net income', 'abc-marketing-department' ) . '</th><td>';
		foreach ( array( 'ebook', 'paperback', 'hardback', 'audiobook' ) as $fmt ) {
			echo '<p><label style="display:inline-block;width:90px">' . esc_html( ucfirst( $fmt ) ) . '</label> ';
			echo esc_html__( 'price', 'abc-marketing-department' ) . ' <input type="number" step="0.01" name="price[' . esc_attr( $fmt ) . ']" value="' . esc_attr( (string) ( $prices[ $fmt ] ?? '' ) ) . '" style="width:90px" /> ';
			echo esc_html__( 'net', 'abc-marketing-department' ) . ' <input type="number" step="0.01" name="net[' . esc_attr( $fmt ) . ']" value="' . esc_attr( (string) ( $nets[ $fmt ] ?? '' ) ) . '" style="width:90px" /></p>';
		}
		echo '</td></tr>';

		View::textarea( 'distributors', __( 'Distributors (one per line)', 'abc-marketing-department' ), self::lines( $b['distributors'] ?? array() ), 2 );
		View::textarea( 'retailer_links', __( 'Retailer links (one per line)', 'abc-marketing-department' ), self::lines( $b['retailer_links'] ?? array() ), 2 );
		View::field( 'universal_link', __( 'Universal book link', 'abc-marketing-department' ), (string) ( $b['universal_link'] ?? '' ), 'url' );
		View::field( 'campaign_start', __( 'Campaign start', 'abc-marketing-department' ), (string) ( $b['campaign_start'] ?? '' ), 'date' );
		View::textarea( 'long_term_target', __( 'Long-term target', 'abc-marketing-department' ), (string) ( $targets_text = ( is_array( $b['long_term_target'] ?? null ) ? (string) ( $b['long_term_target']['text'] ?? '' ) : '' ) ), 2 );

		// 90-day targets.
		echo '<tr><th scope="row">' . esc_html__( '90-day targets', 'abc-marketing-department' ) . '</th><td>';
		foreach ( array( 'additional_sales' => 'Additional sales', 'mailing_list' => 'Mailing list', 'instagram' => 'Instagram', 'amazon_uk_reviews' => 'Amazon UK reviews' ) as $tk => $tl ) {
			echo '<p><label style="display:inline-block;width:150px">' . esc_html( $tl ) . '</label> <input type="number" name="target[' . esc_attr( $tk ) . ']" value="' . esc_attr( (string) ( $targets[ $tk ] ?? '' ) ) . '" style="width:120px" /></p>';
		}
		echo '</td></tr>';

		View::field( 'weekly_hours', __( 'Weekly hours available', 'abc-marketing-department' ), (string) ( $b['weekly_hours'] ?? '' ), 'number' );
		View::field( 'budget', __( 'Budget', 'abc-marketing-department' ), (string) ( $b['budget'] ?? '' ), 'number' );
		View::field( 'budget_max', __( 'Maximum conditional budget', 'abc-marketing-department' ), (string) ( $b['budget_max'] ?? '' ), 'number' );
		View::field( 'break_even_cps', __( 'Break-even cost per sale', 'abc-marketing-department' ), (string) ( $be['cost_per_sale'] ?? '' ), 'number', __( 'Used by anomaly rules (cost per sale above break-even).', 'abc-marketing-department' ) );
		View::field( 'excluded_channels', __( 'Excluded channels (comma separated)', 'abc-marketing-department' ), implode( ', ', (array) ( $b['excluded_channels'] ?? array() ) ) );
		View::select( 'currency', __( 'Currency', 'abc-marketing-department' ), array( 'GBP' => 'GBP £', 'USD' => 'USD $', 'EUR' => 'EUR €' ), (string) ( $b['currency'] ?? 'GBP' ) );
		View::select( 'status', __( 'Workspace status', 'abc-marketing-department' ), array( 'active' => 'Active', 'paused' => 'Paused', 'archived' => 'Archived' ), (string) ( $b['status'] ?? 'active' ) );

		// Threshold overrides.
		echo '<tr><th scope="row">' . esc_html__( 'Anomaly thresholds (optional)', 'abc-marketing-department' ) . '</th><td>';
		foreach ( array(
			'sales_spike_pct'      => 'Sales spike %',
			'sales_collapse_pct'   => 'Sales collapse %',
			'open_rate_drop_pct'   => 'Open-rate drop %',
			'report_gap_days'      => 'Report gap (days)',
			'distributor_gap_days' => 'Distributor gap (days)',
		) as $tk => $tl ) {
			echo '<p><label style="display:inline-block;width:180px">' . esc_html( $tl ) . '</label> <input type="number" step="0.1" name="th_' . esc_attr( $tk ) . '" value="' . esc_attr( (string) ( $th[ $tk ] ?? '' ) ) . '" style="width:100px" /></p>';
		}
		echo '<p class="description">' . esc_html__( 'Leave blank to use defaults.', 'abc-marketing-department' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
		View::submit( $id ? __( 'Save workspace', 'abc-marketing-department' ) : __( 'Create workspace', 'abc-marketing-department' ) );
		View::form_close();

		if ( $id ) {
			echo '<hr /><h3>' . esc_html__( 'Danger zone', 'abc-marketing-department' ) . '</h3>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-confirm="' . esc_attr__( 'Delete this workspace and ALL its data? This cannot be undone.', 'abc-marketing-department' ) . '">';
			echo '<input type="hidden" name="action" value="abcmd_delete_book" />';
			wp_nonce_field( 'abcmd_delete_book', '_abcmd_nonce' );
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
			echo '<p>' . esc_html__( 'Type DELETE to confirm:', 'abc-marketing-department' ) . ' <input type="text" name="confirm" value="" /> ';
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Delete workspace', 'abc-marketing-department' ) . '</button></p>';
			echo '</form>';
		}
	}

	// --- Small helpers ----------------------------------------------------

	private static function kv_table( array $map ): void {
		if ( empty( $map ) ) {
			echo '<p class="description">' . esc_html__( 'No data yet.', 'abc-marketing-department' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( $map as $k => $v ) {
			echo '<tr><td>' . esc_html( (string) $k ) . '</td><td style="text-align:right">' . esc_html( is_float( $v ) ? (string) round( $v, 2 ) : (string) $v ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function target_progress( Metrics $metrics, int $book_id, string $target_key ): string {
		$map = array(
			'additional_sales'  => 'sales',
			'mailing_list'      => 'mailing_list_size',
			'instagram'         => 'followers',
			'amazon_uk_reviews' => 'reviews',
		);
		if ( ! isset( $map[ $target_key ] ) ) {
			return '—';
		}
		$metric = $map[ $target_key ];
		if ( 'sales' === $metric ) {
			return (string) (int) $metrics->sum( $book_id, 'sales' ) . ' ' . __( 'so far', 'abc-marketing-department' );
		}
		$val = 'followers' === $metric
			? ( $metrics->sum_by( $book_id, 'followers', 'platform' )['Instagram'] ?? 0 )
			: $metrics->latest_value( $book_id, $metric );
		return (string) (int) $val . ' ' . __( 'current', 'abc-marketing-department' );
	}

	private static function lines( $value ): string {
		if ( is_array( $value ) ) {
			return implode( "\n", array_map( 'strval', $value ) );
		}
		return (string) $value;
	}
}
