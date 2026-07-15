<?php
/**
 * Anomaly flags repository.
 *
 * @package ABCMD
 */

namespace ABCMD\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for detected anomalies with de-duplication.
 */
final class Anomalies extends BaseRepository {

	protected function key(): string {
		return 'anomalies';
	}

	protected function timestamp_columns(): array {
		return array(); // Uses detected_at explicitly.
	}

	protected function columns(): array {
		return array(
			'book_id'     => '%d',
			'author_id'   => '%d',
			'rule'        => '%s',
			'severity'    => '%s',
			'title'       => '%s',
			'detail'      => '%s',
			'metric_key'  => '%s',
			'dedup_key'   => '%s',
			'status'      => '%s',
			'detected_at' => '%s',
		);
	}

	/**
	 * Record an anomaly unless an open one with the same dedup key exists.
	 *
	 * @param array<string,mixed> $data Anomaly fields (dedup_key recommended).
	 * @return int Inserted id, or 0 if suppressed/failed.
	 */
	public function record_unique( array $data ): int {
		global $wpdb;
		$dedup = (string) ( $data['dedup_key'] ?? '' );
		if ( '' !== $dedup ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . $this->table() . ' WHERE dedup_key = %s AND status = %s LIMIT 1',
					$dedup,
					'open'
				)
			);
			if ( null !== $exists ) {
				return 0;
			}
		}
		if ( empty( $data['detected_at'] ) ) {
			$data['detected_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		return $this->insert( $data );
	}

	/**
	 * Open anomalies for a book.
	 *
	 * @param int $book_id Book id.
	 * @return array<int,array<string,mixed>>
	 */
	public function open_for( int $book_id ): array {
		return $this->where( array( 'book_id' => $book_id, 'status' => 'open' ), 'detected_at', 'DESC', 0, 0 );
	}
}
