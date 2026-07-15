<?php
/**
 * Import records repository.
 *
 * @package ABCMD
 */

namespace ABCMD\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for CSV import runs.
 */
final class Imports extends BaseRepository {

	protected function key(): string {
		return 'imports';
	}

	protected function timestamp_columns(): array {
		return array( 'created_at' );
	}

	protected function columns(): array {
		return array(
			'book_id'           => '%d',
			'author_id'         => '%d',
			'source'            => '%s',
			'source_status'     => '%s',
			'template'          => '%s',
			'original_filename' => '%s',
			'checksum'          => '%s',
			'rows_total'        => '%d',
			'rows_imported'     => '%d',
			'rows_rejected'     => '%d',
			'rows_duplicate'    => '%d',
			'mapping'           => '%s',
			'errors'            => '%s',
			'user_id'           => '%d',
			'audit_id'          => '%d',
			'status'            => '%s',
		);
	}

	protected function json_columns(): array {
		return array( 'mapping', 'errors' );
	}

	/**
	 * Whether a file checksum has already been imported for a book.
	 *
	 * @param int    $book_id  Book id.
	 * @param string $checksum SHA-256 of the uploaded file.
	 */
	public function checksum_seen( int $book_id, string $checksum ): bool {
		global $wpdb;
		if ( '' === $checksum ) {
			return false;
		}
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $this->table() . ' WHERE book_id = %d AND checksum = %s AND status = %s LIMIT 1',
				$book_id,
				$checksum,
				'completed'
			)
		);
		return null !== $found;
	}
}
