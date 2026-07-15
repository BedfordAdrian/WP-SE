<?php
/**
 * Secure file upload handling.
 *
 * @package ABCMD
 */

namespace ABCMD\Files;

use ABCMD\Repository\Books;
use ABCMD\Repository\Files;
use ABCMD\Support\Audit;
use ABCMD\Support\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and stores uploaded source files.
 */
final class Uploader {

	/**
	 * Allowed extension => MIME type.
	 *
	 * @return array<string,string>
	 */
	public static function allowed_types(): array {
		return array(
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'pdf'  => 'application/pdf',
			'epub' => 'application/epub+zip',
			'txt'  => 'text/plain',
			'csv'  => 'text/csv',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'webp' => 'image/webp',
		);
	}

	/**
	 * Document-type options for classification.
	 *
	 * @return array<string,string>
	 */
	public static function document_types(): array {
		return array(
			'manuscript' => 'Manuscript',
			'reviews'    => 'Reviews',
			'press'      => 'Press coverage',
			'report'     => 'Sales/advertising report',
			'asset'      => 'Creative asset',
			'other'      => 'Other',
		);
	}

	/**
	 * Consent classification options.
	 *
	 * @return array<string,string>
	 */
	public static function consent_classes(): array {
		return array(
			'unclassified'  => 'Unclassified',
			'non_sensitive' => 'Non-sensitive',
			'contains_pii'  => 'Contains personal data',
			'manuscript'    => 'Manuscript (confidential)',
		);
	}

	/**
	 * Handle an uploaded file from $_FILES.
	 *
	 * @param array<string,mixed> $file  A single $_FILES entry.
	 * @param array<string,mixed> $meta  book_id, author_id, document_type, consent_class.
	 * @return array{ok:bool,file_id:int,error:string,extract:array<string,mixed>}
	 */
	public static function handle( array $file, array $meta ): array {
		$out = array( 'ok' => false, 'file_id' => 0, 'error' => '', 'extract' => array() );

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$out['error'] = self::upload_error_message( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) );
			return $out;
		}

		$original = (string) ( $file['name'] ?? 'upload' );
		$ext      = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
		$allowed  = self::allowed_types();

		if ( ! isset( $allowed[ $ext ] ) ) {
			$out['error'] = sprintf( __( 'File type ".%s" is not allowed.', 'abc-marketing-department' ), $ext );
			return $out;
		}

		// Validate the real type against the extension.
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $original, self::wp_mime_map() );
		$size  = (int) ( $file['size'] ?? filesize( $file['tmp_name'] ) );
		$max   = wp_max_upload_size();
		if ( $max && $size > $max ) {
			$out['error'] = __( 'File exceeds the maximum upload size.', 'abc-marketing-department' );
			return $out;
		}

		// For non-images, wp_check_filetype_and_ext may not confirm ext; fall back
		// to extension allow-list which we already enforced, plus a magic-byte
		// sanity check for zip-based office formats.
		if ( in_array( $ext, array( 'docx', 'xlsx', 'epub' ), true ) && ! self::is_zip( $file['tmp_name'] ) ) {
			$out['error'] = __( 'This file does not look like a valid Office/EPUB document.', 'abc-marketing-department' );
			return $out;
		}
		if ( 'pdf' === $ext && ! self::has_prefix( $file['tmp_name'], '%PDF' ) ) {
			$out['error'] = __( 'This file does not look like a valid PDF.', 'abc-marketing-department' );
			return $out;
		}

		Storage::ensure_protected_dir();
		$safe   = Storage::safe_name( $original );
		$dest   = trailingslashit( Storage::dir() ) . $safe;

		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			$out['error'] = __( 'Could not store the uploaded file.', 'abc-marketing-department' );
			return $out;
		}
		@chmod( $dest, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$files   = new Files();
		$book_id = (int) ( $meta['book_id'] ?? 0 );
		$author_id = (int) ( $meta['author_id'] ?? 0 );
		if ( ! $author_id && $book_id ) {
			$book = ( new Books() )->find( $book_id );
			$author_id = $book ? (int) $book['author_id'] : 0;
		}

		$file_id = $files->insert(
			array(
				'book_id'        => $book_id,
				'author_id'      => $author_id,
				'original_name'  => sanitize_file_name( $original ),
				'safe_name'      => $safe,
				'stored_path'    => $dest,
				'mime_type'      => (string) ( $check['type'] ?: $allowed[ $ext ] ),
				'size_bytes'     => $size,
				'document_type'  => sanitize_key( (string) ( $meta['document_type'] ?? 'other' ) ),
				'extract_status' => 'pending',
				'consent_class'  => sanitize_key( (string) ( $meta['consent_class'] ?? 'unclassified' ) ),
				'checksum'       => hash_file( 'sha256', $dest ) ?: '',
				'user_id'        => get_current_user_id(),
			)
		);

		Audit::log(
			'file.upload',
			sprintf( 'Uploaded "%s" (%s).', $original, size_format( $size ) ),
			array( 'file_id' => $file_id, 'document_type' => $meta['document_type'] ?? '' ),
			$book_id,
			$author_id
		);

		// Extract text for text-bearing types.
		$out['extract'] = Extractor::process( $file_id );
		$out['ok']      = true;
		$out['file_id'] = $file_id;
		return $out;
	}

	/**
	 * Delete a file record and its stored bytes + chunks.
	 *
	 * @param int $file_id File id.
	 */
	public static function delete( int $file_id ): bool {
		$files = new Files();
		$file  = $files->find( $file_id );
		if ( ! $file ) {
			return false;
		}
		$path = (string) $file['stored_path'];
		if ( $path && str_starts_with( $path, Storage::dir() ) && file_exists( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		( new \ABCMD\Repository\FileChunks() )->delete_for_file( $file_id );
		$files->delete( $file_id );
		Audit::log( 'file.delete', sprintf( 'Deleted file "%s".', $file['original_name'] ), array( 'file_id' => $file_id ), (int) $file['book_id'], (int) $file['author_id'] );
		return true;
	}

	private static function wp_mime_map(): array {
		$map = array();
		foreach ( self::allowed_types() as $ext => $mime ) {
			$map[ $ext ] = $mime;
		}
		// Combined keys WordPress expects.
		$map['jpg|jpeg'] = 'image/jpeg';
		return $map;
	}

	private static function is_zip( string $path ): bool {
		return self::has_prefix( $path, "PK\x03\x04" ) || self::has_prefix( $path, "PK\x05\x06" );
	}

	private static function has_prefix( string $path, string $prefix ): bool {
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return false;
		}
		$head = fread( $fh, strlen( $prefix ) );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $head === $prefix;
	}

	private static function upload_error_message( int $code ): string {
		$map = array(
			UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload limit.',
			UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form upload limit.',
			UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
			UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
			UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temp folder.',
			UPLOAD_ERR_CANT_WRITE => 'Server could not write the file.',
			UPLOAD_ERR_EXTENSION  => 'Upload blocked by a server extension.',
		);
		return $map[ $code ] ?? 'Unknown upload error.';
	}
}
