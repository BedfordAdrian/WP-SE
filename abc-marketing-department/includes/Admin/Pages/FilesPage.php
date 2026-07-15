<?php
/**
 * Files admin page: upload + manage source documents.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ABCMD\Admin\View;
use ABCMD\Support\Helpers;
use ABCMD\Repository\Books as BooksRepo;
use ABCMD\Repository\Files as FilesRepo;
use ABCMD\Files\Uploader;

/**
 * Renders the file library with upload and per-file actions.
 */
final class FilesPage {

	/**
	 * Render the files screen.
	 */
	public static function render(): void {
		\ABCMD\Support\Helpers::require_cap();

		$repo       = new FilesRepo();
		$books_repo = new BooksRepo();

		$book = isset( $_GET['book'] ) ? (int) $_GET['book'] : 0;

		$book_title = static function ( int $id ) use ( $books_repo ): string {
			if ( ! $id ) {
				return '—';
			}
			$b = $books_repo->find( $id );
			return $b ? (string) $b['title'] : '—';
		};

		View::open(
			__( 'Files', 'abc-marketing-department' ),
			__( 'Upload manuscripts, reviews, reports and creative assets for AI context.', 'abc-marketing-department' )
		);

		echo '<p class="description">' . esc_html__( 'Images are stored but not text-extracted; PDF text extraction is best-effort and may be incomplete.', 'abc-marketing-department' ) . '</p>';

		// --- Upload form -----------------------------------------------------
		$book_options = array( 0 => __( '— Select workspace —', 'abc-marketing-department' ) ) + $books_repo->options();

		echo '<h2>' . esc_html__( 'Upload a file', 'abc-marketing-department' ) . '</h2>';
		View::form_open( 'abcmd_upload_file', 'multipart/form-data' );
		echo '<table class="form-table" role="presentation"><tbody>';
		View::select( 'book_id', __( 'Workspace', 'abc-marketing-department' ), $book_options, $book );
		View::select( 'document_type', __( 'Document type', 'abc-marketing-department' ), Uploader::document_types(), 'other' );
		View::select( 'consent_class', __( 'Consent classification', 'abc-marketing-department' ), Uploader::consent_classes(), 'unclassified' );
		echo '<tr><th scope="row"><label for="abcmd_file">' . esc_html__( 'File', 'abc-marketing-department' ) . '</label></th><td>';
		echo '<input type="file" id="abcmd_file" name="file" />';
		echo '<p class="description">' . esc_html__( 'Allowed types:', 'abc-marketing-department' ) . ' ' . esc_html( implode( ', ', array_keys( Uploader::allowed_types() ) ) ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';
		View::submit( __( 'Upload file', 'abc-marketing-department' ) );
		View::form_close();

		// --- Workspace filter (GET) -----------------------------------------
		echo '<h2>' . esc_html__( 'Library', 'abc-marketing-department' ) . '</h2>';
		echo '<form method="get" class="abcmd-filters" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="abcmd-files" />';
		echo '<label for="abcmd-files-book">' . esc_html__( 'Workspace:', 'abc-marketing-department' ) . ' </label>';
		echo '<select id="abcmd-files-book" name="book">';
		echo '<option value="0">' . esc_html__( 'All workspaces', 'abc-marketing-department' ) . '</option>';
		foreach ( $books_repo->options() as $bid => $btitle ) {
			echo '<option value="' . esc_attr( (string) $bid ) . '" ' . selected( $book, $bid, false ) . '>' . esc_html( $btitle ) . '</option>';
		}
		echo '</select> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'abc-marketing-department' ) . '</button>';
		echo '</form>';

		$rows = $book ? $repo->where( array( 'book_id' => $book ), 'id', 'DESC' ) : $repo->all();

		if ( empty( $rows ) ) {
			View::empty_state( __( 'No files uploaded yet.', 'abc-marketing-department' ) );
			View::close();
			return;
		}

		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'File', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Workspace', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Size', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Extraction', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Chunks', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Consent', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Uploaded', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'abc-marketing-department' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$id       = (int) ( $row['id'] ?? 0 );
			$err      = (string) ( $row['extract_error'] ?? '' );
			$download = wp_nonce_url( admin_url( 'admin-post.php?action=abcmd_download_file&id=' . $id ), 'abcmd_download_file', '_abcmd_nonce' );

			echo '<tr>';
			echo '<td><strong>' . esc_html( (string) ( $row['original_name'] ?? '' ) ) . '</strong>';
			if ( '' !== $err ) {
				echo '<br /><span class="description" style="color:#8a1f11">' . esc_html( $err ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $book_title( (int) ( $row['book_id'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['document_type'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( size_format( (int) ( $row['size_bytes'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . View::pill( (string) ( $row['extract_status'] ?? 'pending' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . esc_html( number_format_i18n( (int) ( $row['chunk_count'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['consent_class'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['created_at'] ?? '' ) ) . '</td>';
			echo '<td>';

			// Download.
			echo '<a class="button" href="' . esc_url( $download ) . '">' . esc_html__( 'Download', 'abc-marketing-department' ) . '</a> ';

			// Re-extract.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
			echo '<input type="hidden" name="action" value="abcmd_extract_file" />';
			wp_nonce_field( 'abcmd_extract_file', '_abcmd_nonce' );
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Re-extract', 'abc-marketing-department' ) . '</button>';
			echo '</form> ';

			// Delete.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-confirm="' . esc_attr__( 'Delete this file and its extracted text? This cannot be undone.', 'abc-marketing-department' ) . '" class="abcmd-delete-form" style="display:inline">';
			echo '<input type="hidden" name="action" value="abcmd_delete_file" />';
			wp_nonce_field( 'abcmd_delete_file', '_abcmd_nonce' );
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '" />';
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Delete', 'abc-marketing-department' ) . '</button>';
			echo '</form>';

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		View::close();
	}
}
