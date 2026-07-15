<?php
/**
 * Imports page: upload → map → import, saved profiles, manual adjustments,
 * rollback, and per-workspace import history.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin\Pages;

use ABCMD\Admin\View;
use ABCMD\Import\Templates;
use ABCMD\Repository\Books as BooksRepo;
use ABCMD\Repository\Imports as ImportsRepo;
use ABCMD\Support\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV import workspace.
 */
final class Imports {

	public static function render(): void {
		Helpers::require_cap();

		$books   = new BooksRepo();
		$options = $books->options();
		$book_id = isset( $_GET['book'] ) ? (int) $_GET['book'] : 0;

		View::open( __( 'Imports', 'abc-marketing-department' ), __( 'Import sales, advertising and audience CSVs. Platforms change their export formats, so you always confirm the column mapping — nothing is hard-coded.', 'abc-marketing-department' ) );

		if ( empty( $options ) ) {
			View::empty_state( __( 'Create a book workspace first, then import data into it.', 'abc-marketing-department' ) );
			View::close();
			return;
		}

		// Workspace selector.
		echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="abcmd-imports" />';
		echo '<label>' . esc_html__( 'Workspace:', 'abc-marketing-department' ) . ' <select name="book" onchange="this.form.submit()">';
		echo '<option value="0">' . esc_html__( '— choose —', 'abc-marketing-department' ) . '</option>';
		foreach ( $options as $bid => $title ) {
			echo '<option value="' . esc_attr( (string) $bid ) . '" ' . selected( $book_id, $bid, false ) . '>' . esc_html( $title ) . '</option>';
		}
		echo '</select></label></form>';

		if ( ! $book_id ) {
			View::notice( __( 'Choose a workspace to import into.', 'abc-marketing-department' ), 'info' );
			self::history_all();
			View::close();
			return;
		}

		self::upload_form( $book_id );
		self::manual_adjustment_form( $book_id );
		self::history( $book_id );

		View::close();
	}

	private static function upload_form( int $book_id ): void {
		echo '<div class="abcmd-card"><h2>' . esc_html__( 'Import a CSV', 'abc-marketing-department' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Pick the source template, upload the CSV, then map columns to fields. Mark whether the data is live, delayed, estimated, reconciled or manually adjusted.', 'abc-marketing-department' ) . '</p>';

		$template = isset( $_GET['template'] ) ? sanitize_key( wp_unslash( (string) $_GET['template'] ) ) : 'generic_sales';

		// Template chooser (GET so we can show its target fields for mapping).
		echo '<form method="get" style="margin-bottom:12px"><input type="hidden" name="page" value="abcmd-imports" /><input type="hidden" name="book" value="' . esc_attr( (string) $book_id ) . '" />';
		echo '<label>' . esc_html__( 'Source template:', 'abc-marketing-department' ) . ' <select name="template" onchange="this.form.submit()">';
		foreach ( Templates::options() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $template, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></form>';

		$tpl = Templates::get( $template );
		if ( ! empty( $tpl['note'] ) ) {
			View::notice( (string) $tpl['note'], 'info' );
		}

		// Import form (multipart). The operator maps each target to a CSV header name.
		View::form_open( 'abcmd_import_run', 'multipart/form-data', array( 'book_id' => (string) $book_id, 'template' => $template ) );
		echo '<table class="form-table">';
		echo '<tr><th scope="row"><label for="abcmd_csv">' . esc_html__( 'CSV file', 'abc-marketing-department' ) . '</label></th><td><input type="file" id="abcmd_csv" name="csv" accept=".csv,text/csv" required /></td></tr>';
		View::select( 'source_status', __( 'Source status', 'abc-marketing-department' ), Templates::source_statuses(), 'live' );
		View::select( 'date_format', __( 'Date format', 'abc-marketing-department' ), array( 'auto' => 'Auto-detect', 'ymd' => 'YYYY-MM-DD', 'dmy' => 'DD/MM/YYYY', 'mdy' => 'MM/DD/YYYY' ), 'auto' );
		View::select( 'currency', __( 'Currency of money columns', 'abc-marketing-department' ), array( 'GBP' => 'GBP £', 'USD' => 'USD $', 'EUR' => 'EUR €' ), 'GBP' );
		echo '</table>';

		echo '<h4>' . esc_html__( 'Column mapping', 'abc-marketing-department' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'Enter the exact CSV header name for each field you want to import. Leave blank to skip. A date column is required.', 'abc-marketing-department' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Field', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'CSV header (type exactly)', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Suggested headers', 'abc-marketing-department' ) . '</th></tr></thead><tbody>';
		$fields = Templates::fields();
		foreach ( (array) ( $tpl['targets'] ?? array() ) as $target => $aliases ) {
			$label = $fields[ $target ]['label'] ?? ucfirst( $target );
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td>';
			echo '<td><input type="text" name="map[' . esc_attr( $target ) . ']" value="" class="regular-text" /></td>';
			echo '<td class="description">' . esc_html( implode( ', ', (array) $aliases ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<p><label><input type="checkbox" name="confirm_duplicate" value="1" /> ' . esc_html__( 'Import even if this exact file was already imported', 'abc-marketing-department' ) . '</label></p>';
		View::submit( __( 'Import CSV', 'abc-marketing-department' ) );
		View::form_close();

		echo '<p class="description">' . esc_html__( 'Rows are de-duplicated by transaction/row identifier where present, otherwise by a content hash. Duplicate rows are skipped, not double-counted. You can roll back any import below.', 'abc-marketing-department' ) . '</p>';
		echo '<p>';
		echo self::sample_link();
		echo '</p>';
		echo '</div>';
	}

	private static function manual_adjustment_form( int $book_id ): void {
		echo '<div class="abcmd-card"><h2>' . esc_html__( 'Manual adjustment', 'abc-marketing-department' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Record a single figure manually — for example a correction, or data from a source without a CSV export.', 'abc-marketing-department' ) . '</p>';
		View::form_open( 'abcmd_manual_adjustment', '', array( 'book_id' => (string) $book_id ) );
		echo '<table class="form-table">';
		View::select( 'metric_key', __( 'Metric', 'abc-marketing-department' ), array(
			'sales'             => 'Sales (units)',
			'revenue'           => 'Revenue',
			'contribution'      => 'Contribution',
			'ad_spend'          => 'Ad spend',
			'mailing_list_size' => 'Mailing-list size',
			'followers'         => 'Followers',
			'reviews'           => 'Reviews',
			'open_rate'         => 'Open rate %',
		), 'sales' );
		View::field( 'value', __( 'Value', 'abc-marketing-department' ), '', 'number' );
		View::field( 'date', __( 'Date', 'abc-marketing-department' ), current_time( 'Y-m-d' ), 'date' );
		View::field( 'format', __( 'Format (optional)', 'abc-marketing-department' ) );
		View::field( 'territory', __( 'Territory (optional)', 'abc-marketing-department' ) );
		View::field( 'note', __( 'Note', 'abc-marketing-department' ) );
		echo '</table>';
		View::submit( __( 'Record adjustment', 'abc-marketing-department' ), 'secondary' );
		View::form_close();
		echo '</div>';
	}

	private static function history( int $book_id ): void {
		$imports = ( new ImportsRepo() )->where( array( 'book_id' => $book_id ), 'id', 'DESC', 50, 0 );
		echo '<h2>' . esc_html__( 'Import history', 'abc-marketing-department' ) . '</h2>';
		if ( empty( $imports ) ) {
			View::empty_state( __( 'No imports for this workspace yet.', 'abc-marketing-department' ) );
			return;
		}
		echo '<table class="wp-list-table widefat striped abcmd-table"><thead><tr><th>' . esc_html__( 'When', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Source', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'File', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Status', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'In / Rejected / Dup', 'abc-marketing-department' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $imports as $imp ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $imp['created_at'] ) . '</td>';
			echo '<td>' . esc_html( (string) $imp['source'] ) . ' ' . View::pill( (string) $imp['source_status'] ) . '</td>';
			echo '<td>' . esc_html( (string) $imp['original_filename'] ) . '</td>';
			echo '<td>' . View::pill( (string) $imp['status'] ) . '</td>';
			echo '<td>' . esc_html( sprintf( '%d / %d / %d', (int) $imp['rows_imported'], (int) $imp['rows_rejected'], (int) $imp['rows_duplicate'] ) ) . '</td>';
			echo '<td>';
			if ( 'completed' === $imp['status'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-confirm="' . esc_attr__( 'Roll back this import and delete its metric rows?', 'abc-marketing-department' ) . '" style="display:inline">';
				echo '<input type="hidden" name="action" value="abcmd_import_rollback" />';
				wp_nonce_field( 'abcmd_import_rollback', '_abcmd_nonce' );
				echo '<input type="hidden" name="import_id" value="' . esc_attr( (string) $imp['id'] ) . '" />';
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Roll back', 'abc-marketing-department' ) . '</button>';
				echo '</form>';
			}
			$errs = is_array( $imp['errors'] ?? null ) ? $imp['errors'] : array();
			if ( $errs ) {
				echo ' <details style="display:inline"><summary>' . esc_html( sprintf( __( '%d rejected', 'abc-marketing-department' ), count( $errs ) ) ) . '</summary><div class="abcmd-mono">';
				foreach ( array_slice( $errs, 0, 20 ) as $e ) {
					echo esc_html( sprintf( 'row %s: %s', $e['row'] ?? '?', $e['reason'] ?? '' ) ) . '<br />';
				}
				echo '</div></details>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function history_all(): void {
		$imports = ( new ImportsRepo() )->where( array(), 'id', 'DESC', 25, 0 );
		if ( empty( $imports ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Recent imports (all workspaces)', 'abc-marketing-department' ) . '</h2><table class="wp-list-table widefat striped"><thead><tr><th>' . esc_html__( 'When', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Source', 'abc-marketing-department' ) . '</th><th>' . esc_html__( 'Rows', 'abc-marketing-department' ) . '</th></tr></thead><tbody>';
		foreach ( $imports as $imp ) {
			echo '<tr><td>' . esc_html( (string) $imp['created_at'] ) . '</td><td>' . esc_html( (string) $imp['source'] ) . '</td><td>' . esc_html( (string) (int) $imp['rows_imported'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function sample_link(): string {
		$dir = ABCMD_PLUGIN_URL . 'sample-data/';
		return '<a href="' . esc_url( $dir ) . '" class="button button-small" target="_blank" rel="noopener">' . esc_html__( 'Sample CSV files (in plugin folder /sample-data/)', 'abc-marketing-department' ) . '</a>';
	}
}
