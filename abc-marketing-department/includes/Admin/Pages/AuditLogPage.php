<?php
/**
 * Audit Log admin page: searchable, filterable, paginated activity log.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ABCMD\Admin\View;
use ABCMD\Support\Helpers;
use ABCMD\Support\Audit;
use ABCMD\Repository\Books as BooksRepo;

/**
 * Renders the audit log with filters and pagination.
 */
final class AuditLogPage {

	/**
	 * Render the audit log screen.
	 */
	public static function render(): void {
		\ABCMD\Support\Helpers::require_cap();

		$s             = ( isset( $_GET['s'] ) && is_string( $_GET['s'] ) ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$action_filter = ( isset( $_GET['action_filter'] ) && is_string( $_GET['action_filter'] ) ) ? sanitize_text_field( wp_unslash( $_GET['action_filter'] ) ) : '';
		$book          = isset( $_GET['book'] ) ? (int) $_GET['book'] : 0;
		$paged         = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page      = 50;

		$books_repo = new BooksRepo();
		$book_title = static function ( int $id ) use ( $books_repo ): string {
			if ( ! $id ) {
				return '—';
			}
			$b = $books_repo->find( $id );
			return $b ? (string) $b['title'] : '—';
		};

		$filters = array(
			'search'  => $s,
			'action'  => $action_filter,
			'book_id' => $book,
		);
		$rows  = Audit::query( array_merge( $filters, array( 'limit' => $per_page, 'offset' => ( $paged - 1 ) * $per_page ) ) );
		$total = Audit::count( $filters );
		$pages = (int) max( 1, (int) ceil( $total / $per_page ) );

		View::open(
			__( 'Audit Log', 'abc-marketing-department' ),
			__( 'A searchable record of sensitive actions taken in the plugin.', 'abc-marketing-department' )
		);

		// --- Filter form (GET) ----------------------------------------------
		echo '<form method="get" class="abcmd-filters" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="abcmd-audit" />';
		echo '<p class="search-box" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">';
		echo '<input type="search" name="s" value="' . esc_attr( $s ) . '" placeholder="' . esc_attr__( 'Search summary or action', 'abc-marketing-department' ) . '" />';

		echo '<select name="action_filter">';
		echo '<option value="">' . esc_html__( 'All actions', 'abc-marketing-department' ) . '</option>';
		foreach ( Audit::distinct_actions() as $a ) {
			$a = (string) $a;
			echo '<option value="' . esc_attr( $a ) . '" ' . selected( $action_filter, $a, false ) . '>' . esc_html( $a ) . '</option>';
		}
		echo '</select>';

		echo '<select name="book">';
		echo '<option value="0">' . esc_html__( 'All workspaces', 'abc-marketing-department' ) . '</option>';
		foreach ( $books_repo->options() as $bid => $btitle ) {
			echo '<option value="' . esc_attr( (string) $bid ) . '" ' . selected( $book, $bid, false ) . '>' . esc_html( $btitle ) . '</option>';
		}
		echo '</select>';

		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'abc-marketing-department' ) . '</button>';
		echo '</p></form>';

		if ( empty( $rows ) ) {
			View::empty_state( __( 'No audit entries match your filters.', 'abc-marketing-department' ) );
		} else {
			echo '<table class="wp-list-table widefat striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'When', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'User', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Action', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Summary', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Workspace', 'abc-marketing-department' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				$bid = (int) ( $row['book_id'] ?? 0 );
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $row['created_at'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['user_login'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $row['action'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) ( $row['summary'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( $bid ? $book_title( $bid ) : '—' ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';

			// --- Pagination -------------------------------------------------
			if ( $pages > 1 ) {
				$base = array(
					's'             => $s,
					'action_filter' => $action_filter,
					'book'          => $book,
				);
				echo '<div class="tablenav"><div class="tablenav-pages" style="margin:12px 0">';
				if ( $paged > 1 ) {
					$prev = Helpers::admin_url( 'abcmd-audit', array_merge( $base, array( 'paged' => $paged - 1 ) ) );
					echo View::button_link( $prev, __( '‹ Previous', 'abc-marketing-department' ) ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '<span class="displaying-num">' . esc_html( sprintf(
					/* translators: 1: current page, 2: total pages. */
					__( 'Page %1$d of %2$d', 'abc-marketing-department' ),
					$paged,
					$pages
				) ) . '</span> ';
				if ( $paged < $pages ) {
					$next = Helpers::admin_url( 'abcmd-audit', array_merge( $base, array( 'paged' => $paged + 1 ) ) );
					echo View::button_link( $next, __( 'Next ›', 'abc-marketing-department' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</div></div>';
			}
		}

		// --- Export ----------------------------------------------------------
		echo '<h2>' . esc_html__( 'Export', 'abc-marketing-department' ) . '</h2>';
		View::form_open( 'abcmd_export_csv', '', array( 'type' => 'audit_log', 'book_id' => (string) $book ) );
		View::submit( __( 'Export audit log (CSV)', 'abc-marketing-department' ), 'secondary' );
		View::form_close();

		View::close();
	}
}
