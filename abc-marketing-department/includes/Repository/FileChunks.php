<?php
/**
 * File text-chunk repository (local index).
 *
 * @package ABCMD
 */

namespace ABCMD\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD + keyword search over extracted document chunks.
 */
final class FileChunks extends BaseRepository {

	protected function key(): string {
		return 'file_chunks';
	}

	protected function timestamp_columns(): array {
		return array( 'created_at' );
	}

	protected function columns(): array {
		return array(
			'file_id'        => '%d',
			'book_id'        => '%d',
			'chunk_index'    => '%d',
			'reference'      => '%s',
			'content'        => '%s',
			'token_estimate' => '%d',
		);
	}

	/**
	 * Remove all chunks for a file (re-extraction or deletion).
	 *
	 * @param int $file_id File id.
	 * @return int Rows deleted.
	 */
	public function delete_for_file( int $file_id ): int {
		global $wpdb;
		return (int) $wpdb->delete( $this->table(), array( 'file_id' => $file_id ), array( '%d' ) );
	}

	/**
	 * Local keyword search across a file's chunks.
	 *
	 * @param int    $file_id File id.
	 * @param string $term    Search term.
	 * @param int    $limit   Max results.
	 * @return array<int,array<string,mixed>>
	 */
	public function search( int $file_id, string $term, int $limit = 20 ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $term ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE file_id = %d AND content LIKE %s ORDER BY chunk_index ASC LIMIT %d',
				$file_id,
				$like,
				$limit
			),
			ARRAY_A
		);
		return (array) $rows;
	}
}
