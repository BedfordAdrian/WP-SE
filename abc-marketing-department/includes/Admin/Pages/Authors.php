<?php
/**
 * Authors admin page: list + add/edit form.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ABCMD\Admin\View;
use ABCMD\Support\Helpers;
use ABCMD\Repository\Authors as AuthorsRepo;
use ABCMD\Repository\Books as BooksRepo;

/**
 * Renders the Authors management screen.
 */
final class Authors {

	/**
	 * Render the authors list and the add/edit form.
	 */
	public static function render(): void {
		\ABCMD\Support\Helpers::require_cap();

		$repo  = new AuthorsRepo();
		$books = new BooksRepo();

		$editing_id = isset( $_GET['author'] ) ? (int) $_GET['author'] : 0;
		$editing    = $editing_id ? $repo->find( $editing_id ) : null;
		if ( ! is_array( $editing ) ) {
			$editing_id = 0;
			$editing    = null;
		}

		View::open(
			__( 'Authors', 'abc-marketing-department' ),
			__( 'People whose books you promote. Manage contact details, consent and email platform notes.', 'abc-marketing-department' )
		);

		$authors = $repo->all( array(), 'name', 'ASC' );

		if ( empty( $authors ) ) {
			View::empty_state( __( 'No authors yet. Add your first author using the form below.', 'abc-marketing-department' ) );
		} else {
			echo '<table class="wp-list-table widefat striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Author', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Email', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Website', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Books', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Consent', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'abc-marketing-department' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $authors as $author ) {
				$id         = (int) $author['id'];
				$name       = AuthorsRepo::display_name( $author );
				$email      = (string) ( $author['email'] ?? '' );
				$website    = (string) ( $author['website'] ?? '' );
				$book_count = count( $books->for_author( $id ) );
				$consent    = (string) ( $author['consent_status'] ?? 'unknown' );
				$edit_url   = Helpers::admin_url( 'abcmd-authors', array( 'author' => $id ) );

				echo '<tr>';
				echo '<td><strong>' . esc_html( $name ) . '</strong></td>';
				echo '<td>' . ( '' !== $email ? '<a href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>' : '&mdash;' ) . '</td>';
				echo '<td>' . ( '' !== $website ? '<a href="' . esc_url( $website ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $website ) . '</a>' : '&mdash;' ) . '</td>';
				echo '<td>' . esc_html( (string) $book_count ) . '</td>';
				echo '<td>' . View::pill( $consent ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<td>' . View::button_link( $edit_url, __( 'Edit', 'abc-marketing-department' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		// --- Add / edit form -------------------------------------------------
		$name_v     = $editing ? (string) ( $editing['name'] ?? '' ) : '';
		$pen_v      = $editing ? (string) ( $editing['pen_name'] ?? '' ) : '';
		$email_v    = $editing ? (string) ( $editing['email'] ?? '' ) : '';
		$website_v  = $editing ? (string) ( $editing['website'] ?? '' ) : '';
		$bio_v      = $editing ? (string) ( $editing['biography'] ?? '' ) : '';
		$notes_v    = $editing ? (string) ( $editing['notes'] ?? '' ) : '';
		$consent_v  = $editing ? (string) ( $editing['consent_status'] ?? 'unknown' ) : 'unknown';
		$status_v   = $editing ? (string) ( $editing['status'] ?? 'active' ) : 'active';

		$social_arr = ( $editing && isset( $editing['social_profiles'] ) && is_array( $editing['social_profiles'] ) ) ? $editing['social_profiles'] : array();
		$social_v   = implode( "\n", array_map( 'strval', $social_arr ) );

		$platform   = ( $editing && isset( $editing['email_platform'] ) && is_array( $editing['email_platform'] ) ) ? $editing['email_platform'] : array();
		$provider_v = (string) ( $platform['provider'] ?? '' );
		$em_notes_v = (string) ( $platform['notes'] ?? '' );

		echo '<h2>' . ( $editing ? esc_html__( 'Edit author', 'abc-marketing-department' ) : esc_html__( 'Add author', 'abc-marketing-department' ) ) . '</h2>';

		if ( $editing ) {
			echo '<p><a href="' . esc_url( Helpers::admin_url( 'abcmd-authors' ) ) . '">' . esc_html__( '&larr; Add a new author instead', 'abc-marketing-department' ) . '</a></p>';
		}

		View::form_open( 'abcmd_save_author', '', array( 'id' => (string) $editing_id ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		View::field( 'name', __( 'Legal name', 'abc-marketing-department' ), $name_v );
		View::field( 'pen_name', __( 'Pen name', 'abc-marketing-department' ), $pen_v, 'text', __( 'Used as the display name when set.', 'abc-marketing-department' ) );
		View::field( 'email', __( 'Email', 'abc-marketing-department' ), $email_v, 'email' );
		View::field( 'website', __( 'Website', 'abc-marketing-department' ), $website_v, 'url' );
		View::textarea( 'biography', __( 'Biography', 'abc-marketing-department' ), $bio_v, 4 );
		View::textarea( 'social_profiles', __( 'Social profiles', 'abc-marketing-department' ), $social_v, 4, __( 'One profile URL or handle per line.', 'abc-marketing-department' ) );
		View::field( 'email_provider', __( 'Email platform', 'abc-marketing-department' ), $provider_v, 'text', __( 'e.g. Mailchimp, ConvertKit.', 'abc-marketing-department' ) );
		View::field( 'email_notes', __( 'Email platform notes', 'abc-marketing-department' ), $em_notes_v );
		View::textarea( 'notes', __( 'Internal notes', 'abc-marketing-department' ), $notes_v, 3 );
		View::select(
			'consent_status',
			__( 'Consent status', 'abc-marketing-department' ),
			array(
				'unknown' => __( 'Unknown', 'abc-marketing-department' ),
				'active'  => __( 'Active', 'abc-marketing-department' ),
				'revoked' => __( 'Revoked', 'abc-marketing-department' ),
			),
			$consent_v
		);
		View::select(
			'status',
			__( 'Record status', 'abc-marketing-department' ),
			array(
				'active'   => __( 'Active', 'abc-marketing-department' ),
				'archived' => __( 'Archived', 'abc-marketing-department' ),
			),
			$status_v
		);
		echo '</tbody></table>';
		View::submit( $editing ? __( 'Update author', 'abc-marketing-department' ) : __( 'Add author', 'abc-marketing-department' ) );
		View::form_close();

		// --- Delete form (edit mode only) ------------------------------------
		if ( $editing ) {
			$confirm = esc_attr__( 'Delete this author? Authors with book workspaces cannot be deleted.', 'abc-marketing-department' );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-confirm="' . $confirm . '" class="abcmd-delete-form">';
			echo '<input type="hidden" name="action" value="abcmd_delete_author" />';
			wp_nonce_field( 'abcmd_delete_author', '_abcmd_nonce' );
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $editing_id ) . '" />';
			View::submit( __( 'Delete author', 'abc-marketing-department' ), 'delete' );
			echo '</form>';
		}

		View::close();
	}
}
