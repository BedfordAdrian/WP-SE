<?php
/**
 * Metrics repository — dated snapshots, never overwriting prior figures.
 *
 * @package ABCMD
 */

namespace ABCMD\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and aggregates dated metric snapshots.
 */
final class Metrics extends BaseRepository {

	protected function key(): string {
		return 'metrics';
	}

	protected function timestamp_columns(): array {
		return array( 'created_at' );
	}

	protected function columns(): array {
		return array(
			'book_id'       => '%d',
			'author_id'     => '%d',
			'metric_date'   => '%s',
			'metric_key'    => '%s',
			'format'        => '%s',
			'territory'     => '%s',
			'channel'       => '%s',
			'platform'      => '%s',
			'value_num'     => '%f',
			'value_text'    => '%s',
			'currency'      => '%s',
			'source'        => '%s',
			'source_status' => '%s',
			'import_id'     => '%d',
			'dedup_key'     => '%s',
			'is_adjustment' => '%d',
			'meta'          => '%s',
		);
	}

	protected function json_columns(): array {
		return array( 'meta' );
	}

	/**
	 * True when a row with the given dedup key already exists.
	 *
	 * @param string $dedup_key Deduplication key.
	 */
	public function dedup_exists( string $dedup_key ): bool {
		global $wpdb;
		if ( '' === $dedup_key ) {
			return false;
		}
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM ' . $this->table() . ' WHERE dedup_key = %s LIMIT 1', $dedup_key )
		);
		return null !== $found;
	}

	/**
	 * Sum of value_num for a metric over an inclusive date range.
	 *
	 * @param int         $book_id    Book id.
	 * @param string      $metric_key Metric key.
	 * @param string|null $from       YYYY-MM-DD or null.
	 * @param string|null $to         YYYY-MM-DD or null.
	 */
	public function sum( int $book_id, string $metric_key, ?string $from = null, ?string $to = null ): float {
		global $wpdb;
		$sql    = 'SELECT COALESCE(SUM(value_num),0) FROM ' . $this->table() . ' WHERE book_id = %d AND metric_key = %s';
		$params = array( $book_id, $metric_key );
		if ( $from ) {
			$sql     .= ' AND metric_date >= %s';
			$params[] = $from;
		}
		if ( $to ) {
			$sql     .= ' AND metric_date <= %s';
			$params[] = $to;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (float) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Latest single value for a metric (most recent metric_date).
	 *
	 * @param int    $book_id    Book id.
	 * @param string $metric_key Metric key.
	 */
	public function latest( int $book_id, string $metric_key ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE book_id = %d AND metric_key = %s ORDER BY metric_date DESC, id DESC LIMIT 1',
				$book_id,
				$metric_key
			),
			ARRAY_A
		);
		return $row ? $this->decode_row( $row ) : null;
	}

	/**
	 * Latest numeric value for a metric, or a default.
	 *
	 * @param int    $book_id    Book id.
	 * @param string $metric_key Metric key.
	 * @param float  $default    Fallback.
	 */
	public function latest_value( int $book_id, string $metric_key, float $default = 0.0 ): float {
		$row = $this->latest( $book_id, $metric_key );
		return $row ? (float) $row['value_num'] : $default;
	}

	/**
	 * Sum grouped by a column (format/territory/platform/channel/retailer).
	 *
	 * @param int    $book_id    Book id.
	 * @param string $metric_key Metric key.
	 * @param string $group_col  Column to group by (whitelisted).
	 * @return array<string,float>
	 */
	public function sum_by( int $book_id, string $metric_key, string $group_col ): array {
		global $wpdb;
		$allowed = array( 'format', 'territory', 'platform', 'channel', 'source' );
		if ( ! in_array( $group_col, $allowed, true ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ' . $group_col . ' AS grp, COALESCE(SUM(value_num),0) AS total FROM ' . $this->table()
					. ' WHERE book_id = %d AND metric_key = %s GROUP BY ' . $group_col . ' ORDER BY total DESC',
				$book_id,
				$metric_key
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$label         = '' !== (string) $r['grp'] ? (string) $r['grp'] : '(unspecified)';
			$out[ $label ] = (float) $r['total'];
		}
		return $out;
	}

	/**
	 * Weekly series (sum per ISO week) for a metric over the last N weeks.
	 *
	 * @param int    $book_id    Book id.
	 * @param string $metric_key Metric key.
	 * @param int    $weeks      Number of trailing weeks.
	 * @return array<string,float> Keyed by 'YYYY-WW'.
	 */
	public function weekly_series( int $book_id, string $metric_key, int $weeks = 8 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT YEARWEEK(metric_date, 3) AS yw, COALESCE(SUM(value_num),0) AS total FROM ' . $this->table()
					. ' WHERE book_id = %d AND metric_key = %s GROUP BY yw ORDER BY yw DESC LIMIT %d',
				$book_id,
				$metric_key,
				$weeks
			),
			ARRAY_A
		);
		$out = array();
		foreach ( array_reverse( (array) $rows ) as $r ) {
			$out[ (string) $r['yw'] ] = (float) $r['total'];
		}
		return $out;
	}

	/**
	 * Distinct metric keys recorded for a book.
	 *
	 * @param int $book_id Book id.
	 * @return string[]
	 */
	public function keys_for( int $book_id ): array {
		global $wpdb;
		$rows = $wpdb->get_col(
			$wpdb->prepare( 'SELECT DISTINCT metric_key FROM ' . $this->table() . ' WHERE book_id = %d ORDER BY metric_key', $book_id )
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Most recent metric_date recorded for a book (or null).
	 *
	 * @param int $book_id Book id.
	 */
	public function last_date( int $book_id ): ?string {
		global $wpdb;
		$d = $wpdb->get_var(
			$wpdb->prepare( 'SELECT MAX(metric_date) FROM ' . $this->table() . ' WHERE book_id = %d', $book_id )
		);
		return $d ? (string) $d : null;
	}

	/**
	 * Delete all metric rows created by a given import (for rollback).
	 *
	 * @param int $import_id Import id.
	 * @return int Rows deleted.
	 */
	public function delete_by_import( int $import_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( $this->table(), array( 'import_id' => $import_id ), array( '%d' ) );
	}
}
