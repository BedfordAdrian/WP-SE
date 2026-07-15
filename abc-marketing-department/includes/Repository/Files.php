<?php
/**
 * Files repository.
 *
 * @package ABCMD
 */

namespace ABCMD\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for uploaded source files.
 */
final class Files extends BaseRepository {

	protected function key(): string {
		return 'files';
	}

	protected function timestamp_columns(): array {
		return array( 'created_at' );
	}

	protected function columns(): array {
		return array(
			'book_id'        => '%d',
			'author_id'      => '%d',
			'original_name'  => '%s',
			'safe_name'      => '%s',
			'stored_path'    => '%s',
			'mime_type'      => '%s',
			'size_bytes'     => '%d',
			'document_type'  => '%s',
			'extract_status' => '%s',
			'extract_error'  => '%s',
			'chunk_count'    => '%d',
			'extracted_text' => '%s',
			'summary'        => '%s',
			'consent_class'  => '%s',
			'checksum'       => '%s',
			'user_id'        => '%d',
		);
	}
}
