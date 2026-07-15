<?php
/**
 * Content Calendar admin page: list + month grid + add/edit form.
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
use ABCMD\Repository\Content as ContentRepo;

/**
 * Renders the content calendar (list + calendar views) and the editor.
 */
final class ContentCalendar {

	/**
	 * Render the content calendar screen.
	 */
	public static function render(): void {
		\ABCMD\Support\Helpers::require_cap();

		$repo       = new ContentRepo();
		$books_repo = new BooksRepo();

		$view  = ( isset( $_GET['view'] ) && is_string( $_GET['view'] ) ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';
		if ( ! in_array( $view, array( 'list', 'calendar' ), true ) ) {
			$view = 'list';
		}
		$book  = isset( $_GET['book'] ) ? (int) $_GET['book'] : 0;
		$item  = isset( $_GET['item'] ) ? (int) $_GET['item'] : 0;
		$month = ( isset( $_GET['month'] ) && is_string( $_GET['month'] ) ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';

		$book_title = static function ( int $id ) use ( $books_repo ): string {
			if ( ! $id ) {
				return '—';
			}
			$b = $books_repo->find( $id );
			return $b ? (string) $b['title'] : '—';
		};

		$editing = $item ? $repo->find( $item ) : null;
		if ( ! is_array( $editing ) ) {
			$editing = null;
		}

		View::open(
			__( 'Content Calendar', 'abc-marketing-department' ),
			__( 'Plan, schedule and export campaign content across channels.', 'abc-marketing-department' )
		);

		// --- View switch (List / Calendar) ----------------------------------
		$views = array(
			'list'     => __( 'List', 'abc-marketing-department' ),
			'calendar' => __( 'Calendar', 'abc-marketing-department' ),
		);
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $views as $slug => $label ) {
			$url   = Helpers::admin_url( 'abcmd-content', array( 'view' => $slug, 'book' => $book ) );
			$class = $slug === $view ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		// --- Workspace filter (GET) -----------------------------------------
		echo '<form method="get" class="abcmd-filters" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="abcmd-content" />';
		echo '<input type="hidden" name="view" value="' . esc_attr( $view ) . '" />';
		echo '<label for="abcmd-content-book">' . esc_html__( 'Workspace:', 'abc-marketing-department' ) . ' </label>';
		echo '<select id="abcmd-content-book" name="book">';
		echo '<option value="0">' . esc_html__( 'All workspaces', 'abc-marketing-department' ) . '</option>';
		foreach ( $books_repo->options() as $bid => $btitle ) {
			echo '<option value="' . esc_attr( (string) $bid ) . '" ' . selected( $book, $bid, false ) . '>' . esc_html( $btitle ) . '</option>';
		}
		echo '</select> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'abc-marketing-department' ) . '</button>';
		echo '</form>';

		if ( 'calendar' === $view ) {
			self::render_calendar( $repo, $book, $month );
		} else {
			self::render_list( $repo, $book, $book_title );
		}

		// --- Add / edit form -------------------------------------------------
		self::render_editor( $repo, $books_repo, $editing, $book );

		// --- Exports ---------------------------------------------------------
		echo '<h2>' . esc_html__( 'Export', 'abc-marketing-department' ) . '</h2>';
		View::form_open( 'abcmd_export_ical', '', array( 'book_id' => (string) $book ) );
		View::submit( __( 'Export calendar (iCal)', 'abc-marketing-department' ), 'secondary' );
		View::form_close();
		echo '<p class="description">' . esc_html__( 'Copy-ready text export (with metadata) is available from the Approval Queue.', 'abc-marketing-department' ) . '</p>';

		View::close();
	}

	/**
	 * Render the tabular list view.
	 *
	 * @param ContentRepo $repo       Content repository.
	 * @param int         $book       Workspace filter (0 = all).
	 * @param callable    $book_title Resolver for a book title.
	 */
	private static function render_list( ContentRepo $repo, int $book, callable $book_title ): void {
		$rows = $book ? $repo->where( array( 'book_id' => $book ), 'publish_date', 'ASC' ) : $repo->all( array(), 'publish_date', 'ASC' );

		if ( empty( $rows ) ) {
			View::empty_state( __( 'No content items yet. Add one using the form below.', 'abc-marketing-department' ) );
			return;
		}

		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Publish date', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Workspace', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Channel', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Format', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Objective', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'CTA', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'abc-marketing-department' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$id       = (int) ( $row['id'] ?? 0 );
			$edit_url = Helpers::admin_url( 'abcmd-content', array( 'view' => 'list', 'item' => $id ) );

			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['publish_date'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $book_title( (int) ( $row['book_id'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['channel'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['format'] ?? '' ) ) . '</td>';
			echo '<td><strong>' . esc_html( (string) ( $row['title'] ?? '' ) ) . '</strong></td>';
			echo '<td>' . esc_html( (string) ( $row['objective'] ?? '' ) ) . '</td>';
			echo '<td>' . View::pill( (string) ( $row['approval_status'] ?? 'draft' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . esc_html( (string) ( $row['cta'] ?? '' ) ) . '</td>';
			echo '<td>' . View::button_link( $edit_url, __( 'Edit', 'abc-marketing-department' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render a simple month grid with content events in day cells.
	 *
	 * @param ContentRepo $repo  Content repository.
	 * @param int         $book  Workspace filter (0 = all).
	 * @param string      $month Requested month as Y-m (optional).
	 */
	private static function render_calendar( ContentRepo $repo, int $book, string $month ): void {
		if ( '' !== $month && preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			try {
				$first = new \DateTimeImmutable( $month . '-01' );
			} catch ( \Exception $e ) {
				$first = Helpers::now()->modify( 'first day of this month' );
			}
		} else {
			$first = Helpers::now()->modify( 'first day of this month' );
		}

		$ym        = $first->format( 'Y-m' );
		$first_day = $first->format( 'Y-m-01' );
		$last_day  = $first->format( 'Y-m-t' );
		$days      = (int) $first->format( 't' );
		$start_dow = (int) $first->format( 'w' ); // 0 = Sunday.

		// Bucket items by Y-m-d.
		$by_day = array();
		foreach ( $repo->calendar( $first_day, $last_day, $book ? $book : null ) as $c ) {
			$key = substr( (string) ( $c['publish_date'] ?? '' ), 0, 10 );
			if ( '' !== $key ) {
				$by_day[ $key ][] = $c;
			}
		}

		$prev = $first->modify( '-1 month' )->format( 'Y-m' );
		$next = $first->modify( '+1 month' )->format( 'Y-m' );

		echo '<p class="abcmd-calendar-nav">';
		echo View::button_link( Helpers::admin_url( 'abcmd-content', array( 'view' => 'calendar', 'book' => $book, 'month' => $prev ) ), __( '‹ Previous', 'abc-marketing-department' ) );
		echo ' <strong>' . esc_html( $first->format( 'F Y' ) ) . '</strong> ';
		echo View::button_link( Helpers::admin_url( 'abcmd-content', array( 'view' => 'calendar', 'book' => $book, 'month' => $next ) ), __( 'Next ›', 'abc-marketing-department' ) );
		echo '</p>';

		$dow_labels = array(
			__( 'Sun', 'abc-marketing-department' ),
			__( 'Mon', 'abc-marketing-department' ),
			__( 'Tue', 'abc-marketing-department' ),
			__( 'Wed', 'abc-marketing-department' ),
			__( 'Thu', 'abc-marketing-department' ),
			__( 'Fri', 'abc-marketing-department' ),
			__( 'Sat', 'abc-marketing-department' ),
		);

		echo '<div class="abcmd-calendar">';
		foreach ( $dow_labels as $label ) {
			echo '<div class="dow">' . esc_html( $label ) . '</div>';
		}
		for ( $i = 0; $i < $start_dow; $i++ ) {
			echo '<div class="day empty"></div>';
		}
		for ( $d = 1; $d <= $days; $d++ ) {
			$key = $ym . '-' . sprintf( '%02d', $d );
			echo '<div class="day">';
			echo '<div class="day-num">' . esc_html( (string) $d ) . '</div>';
			if ( ! empty( $by_day[ $key ] ) ) {
				foreach ( $by_day[ $key ] as $ev ) {
					$title   = (string) ( $ev['title'] ?? '' );
					$channel = (string) ( $ev['channel'] ?? '' );
					echo '<span class="evt">' . esc_html( '' !== $title ? $title : __( '(untitled)', 'abc-marketing-department' ) );
					if ( '' !== $channel ) {
						echo ' <em>' . esc_html( $channel ) . '</em>';
					}
					echo '</span>';
				}
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render the add/edit content form.
	 *
	 * @param ContentRepo               $repo       Content repository.
	 * @param BooksRepo                 $books_repo Books repository.
	 * @param array<string,mixed>|null  $editing    Row being edited, or null.
	 * @param int                       $book       Current workspace filter.
	 */
	private static function render_editor( ContentRepo $repo, BooksRepo $books_repo, ?array $editing, int $book ): void {
		$get = static function ( $key, $default = '' ) use ( $editing ) {
			return $editing && isset( $editing[ $key ] ) ? (string) $editing[ $key ] : (string) $default;
		};

		$publish = $get( 'publish_date' );
		$publish = ( '' !== $publish ) ? substr( $publish, 0, 10 ) : '';

		$book_options = array( 0 => __( '— Select workspace —', 'abc-marketing-department' ) ) + $books_repo->options();
		$status_opts  = array(
			'draft'           => __( 'Draft', 'abc-marketing-department' ),
			'awaiting_review' => __( 'Awaiting review', 'abc-marketing-department' ),
			'approved'        => __( 'Approved', 'abc-marketing-department' ),
			'rejected'        => __( 'Rejected', 'abc-marketing-department' ),
			'scheduled'       => __( 'Scheduled', 'abc-marketing-department' ),
		);

		echo '<h2>' . ( $editing ? esc_html__( 'Edit content', 'abc-marketing-department' ) : esc_html__( 'Add content', 'abc-marketing-department' ) ) . '</h2>';
		if ( $editing ) {
			echo '<p><a href="' . esc_url( Helpers::admin_url( 'abcmd-content' ) ) . '">' . esc_html__( '← Add new content instead', 'abc-marketing-department' ) . '</a></p>';
		}

		$hidden = $editing ? array( 'id' => (string) ( $editing['id'] ?? 0 ) ) : array();
		View::form_open( 'abcmd_save_content', '', $hidden );
		echo '<table class="form-table" role="presentation"><tbody>';
		View::select( 'book_id', __( 'Workspace', 'abc-marketing-department' ), $book_options, $editing ? (int) ( $editing['book_id'] ?? 0 ) : $book );
		View::field( 'campaign', __( 'Campaign', 'abc-marketing-department' ), $get( 'campaign' ) );
		View::field( 'channel', __( 'Channel', 'abc-marketing-department' ), $get( 'channel' ), 'text', __( 'e.g. Instagram, Newsletter, Amazon Ads.', 'abc-marketing-department' ) );
		View::field( 'format', __( 'Format', 'abc-marketing-department' ), $get( 'format' ), 'text', __( 'e.g. Reel, Carousel, Email.', 'abc-marketing-department' ) );
		View::field( 'title', __( 'Title', 'abc-marketing-department' ), $get( 'title' ) );
		View::textarea( 'copy', __( 'Copy', 'abc-marketing-department' ), $get( 'copy' ), 5 );
		View::textarea( 'caption', __( 'Caption', 'abc-marketing-department' ), $get( 'caption' ), 2 );
		View::field( 'hashtags', __( 'Hashtags', 'abc-marketing-department' ), $get( 'hashtags' ) );
		View::textarea( 'script', __( 'Script', 'abc-marketing-department' ), $get( 'script' ), 4 );
		View::textarea( 'design_direction', __( 'Design direction', 'abc-marketing-department' ), $get( 'design_direction' ), 3 );
		View::textarea( 'image_prompt', __( 'Image prompt', 'abc-marketing-department' ), $get( 'image_prompt' ), 3 );
		View::field( 'cta', __( 'Call to action', 'abc-marketing-department' ), $get( 'cta' ) );
		View::field( 'destination_link', __( 'Destination link', 'abc-marketing-department' ), $get( 'destination_link' ), 'url' );
		View::field( 'tracking_link', __( 'Tracking link', 'abc-marketing-department' ), $get( 'tracking_link' ), 'url' );
		View::field( 'publish_date', __( 'Publish date', 'abc-marketing-department' ), $publish, 'date' );
		View::field( 'objective', __( 'Objective', 'abc-marketing-department' ), $get( 'objective' ) );
		View::select( 'approval_status', __( 'Approval status', 'abc-marketing-department' ), $status_opts, $get( 'approval_status', 'draft' ) );
		echo '</tbody></table>';
		View::submit( $editing ? __( 'Update content', 'abc-marketing-department' ) : __( 'Add content', 'abc-marketing-department' ) );
		View::form_close();
	}
}
