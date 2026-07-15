<?php
/**
 * CSV exports for tasks, recommendations, metrics, imports, costs, content and
 * (authorised) the audit log.
 *
 * @package ABCMD
 */

namespace ABCMD\Export;

use ABCMD\Support\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds CSV documents from repository data.
 */
final class CsvExport {

	/**
	 * Supported export types => label.
	 *
	 * @return array<string,string>
	 */
	public static function types(): array {
		return array(
			'tasks'           => 'Tasks',
			'recommendations' => 'Recommendations',
			'metrics'         => 'Metrics',
			'imports'         => 'Imports',
			'costs'           => 'AI costs',
			'content'         => 'Content calendar',
			'audit_log'       => 'Audit log',
		);
	}

	/**
	 * Build a CSV document.
	 *
	 * @param string   $type    Export type.
	 * @param int|null $book_id Optional workspace filter.
	 * @return array{filename:string,content:string}
	 */
	public static function build( string $type, ?int $book_id = null ): array {
		global $wpdb;
		$prefix = $wpdb->prefix . 'abcmd_';
		$rows   = array();
		$header = array();

		$where  = $book_id ? $wpdb->prepare( ' WHERE book_id = %d', $book_id ) : '';

		switch ( $type ) {
			case 'tasks':
				$header = array( 'id', 'book_id', 'title', 'owner', 'priority', 'due_date', 'effort', 'budget', 'actual_cost', 'status', 'completion_date' );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results( 'SELECT ' . implode( ',', $header ) . " FROM {$prefix}tasks" . $where . ' ORDER BY id DESC', ARRAY_A );
				break;
			case 'recommendations':
				$header = array( 'id', 'book_id', 'title', 'rec_type', 'evidence_label', 'confidence', 'expected_cost', 'expected_impact', 'success_metric', 'rank_score', 'status' );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results( 'SELECT ' . implode( ',', $header ) . " FROM {$prefix}recommendations" . $where . ' ORDER BY rank_score DESC', ARRAY_A );
				break;
			case 'metrics':
				$header = array( 'id', 'book_id', 'metric_date', 'metric_key', 'format', 'territory', 'platform', 'value_num', 'currency', 'source', 'source_status' );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results( 'SELECT ' . implode( ',', $header ) . " FROM {$prefix}metrics" . $where . ' ORDER BY metric_date DESC LIMIT 50000', ARRAY_A );
				break;
			case 'imports':
				$header = array( 'id', 'book_id', 'source', 'source_status', 'template', 'original_filename', 'rows_imported', 'rows_rejected', 'rows_duplicate', 'status', 'created_at' );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results( 'SELECT ' . implode( ',', $header ) . " FROM {$prefix}imports" . $where . ' ORDER BY id DESC', ARRAY_A );
				break;
			case 'costs':
				$header = array( 'id', 'book_id', 'run_type', 'model', 'input_tokens', 'output_tokens', 'est_cost', 'actual_cost', 'currency', 'created_at' );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results( 'SELECT ' . implode( ',', $header ) . " FROM {$prefix}ai_runs" . $where . ' ORDER BY id DESC', ARRAY_A );
				break;
			case 'content':
				$header = array( 'id', 'book_id', 'campaign', 'channel', 'format', 'title', 'cta', 'destination_link', 'publish_date', 'approval_status' );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results( 'SELECT ' . implode( ',', $header ) . " FROM {$prefix}content" . $where . ' ORDER BY publish_date ASC', ARRAY_A );
				break;
			case 'audit_log':
				$header = array( 'id', 'created_at', 'user_login', 'action', 'summary', 'book_id' );
				$w      = $book_id ? $wpdb->prepare( ' WHERE book_id = %d', $book_id ) : '';
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows = $wpdb->get_results( 'SELECT ' . implode( ',', $header ) . " FROM {$prefix}audit_log" . $w . ' ORDER BY id DESC LIMIT 50000', ARRAY_A );
				break;
			default:
				return array( 'filename' => 'export.csv', 'content' => '' );
		}

		Audit::log( 'export.csv', sprintf( 'Exported %s as CSV.', $type ), array( 'type' => $type, 'book_id' => $book_id ), $book_id );

		return array(
			'filename' => 'abcmd-' . $type . '-' . gmdate( 'Ymd' ) . '.csv',
			'content'  => self::to_csv( $header, (array) $rows ),
		);
	}

	/**
	 * Render rows as RFC-4180-ish CSV.
	 *
	 * @param string[]                        $header Column names.
	 * @param array<int,array<string,mixed>>  $rows   Row maps.
	 */
	public static function to_csv( array $header, array $rows ): string {
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $fh, $header );
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $header as $col ) {
				$line[] = $row[ $col ] ?? '';
			}
			fputcsv( $fh, $line );
		}
		rewind( $fh );
		$out = stream_get_contents( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return (string) $out;
	}
}
