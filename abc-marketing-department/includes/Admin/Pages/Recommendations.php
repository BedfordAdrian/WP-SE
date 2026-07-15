<?php
/**
 * Recommendations admin page: ranked list with inline edit/review.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ABCMD\Admin\View;
use ABCMD\Support\Helpers;
use ABCMD\Repository\Recommendations as RecRepo;
use ABCMD\Repository\Books as BooksRepo;

/**
 * Renders the AI recommendations review screen.
 */
final class Recommendations {

	/**
	 * Status choices shared by the inline edit form.
	 *
	 * @return array<string,string>
	 */
	private static function statuses(): array {
		return array(
			'draft'           => __( 'Draft', 'abc-marketing-department' ),
			'awaiting_review' => __( 'Awaiting review', 'abc-marketing-department' ),
			'approved'        => __( 'Approved', 'abc-marketing-department' ),
			'rejected'        => __( 'Rejected', 'abc-marketing-department' ),
			'scheduled'       => __( 'Scheduled', 'abc-marketing-department' ),
			'in_progress'     => __( 'In progress', 'abc-marketing-department' ),
			'completed'       => __( 'Completed', 'abc-marketing-department' ),
			'cancelled'       => __( 'Cancelled', 'abc-marketing-department' ),
		);
	}

	/**
	 * Render the recommendations list.
	 */
	public static function render(): void {
		\ABCMD\Support\Helpers::require_cap();

		$repo  = new RecRepo();
		$books = new BooksRepo();

		$book_id = isset( $_GET['book'] ) ? (int) $_GET['book'] : 0;

		View::open(
			__( 'Recommendations', 'abc-marketing-department' ),
			__( 'Ranked, evidence-tagged marketing actions. Approve to optionally generate a draft task.', 'abc-marketing-department' )
		);

		// Optional workspace filter.
		$options = $books->options();
		if ( ! empty( $options ) ) {
			echo '<form method="get" class="abcmd-filter">';
			echo '<input type="hidden" name="page" value="abcmd-recommendations" />';
			echo '<label for="abcmd-rec-book">' . esc_html__( 'Workspace:', 'abc-marketing-department' ) . ' </label>';
			echo '<select id="abcmd-rec-book" name="book" onchange="this.form.submit()">';
			echo '<option value="0">' . esc_html__( 'All workspaces', 'abc-marketing-department' ) . '</option>';
			foreach ( $options as $bid => $title ) {
				echo '<option value="' . esc_attr( (string) $bid ) . '" ' . selected( $book_id, (int) $bid, false ) . '>' . esc_html( $title ) . '</option>';
			}
			echo '</select>';
			echo '</form>';
		}

		$recs = $book_id
			? $repo->where( array( 'book_id' => $book_id ), 'rank_score', 'DESC' )
			: $repo->all( array(), 'rank_score', 'DESC' );

		if ( empty( $recs ) ) {
			View::empty_state( __( 'No recommendations yet. Run an AI analysis on a workspace to generate ranked actions.', 'abc-marketing-department' ) );
			View::close();
			return;
		}

		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Recommendation', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Evidence', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Confidence', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Est. cost', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Impact', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Rank', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'abc-marketing-department' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $recs as $rec ) {
			$id       = (int) $rec['id'];
			$title    = (string) ( $rec['title'] ?? '' );
			$rec_type = (string) ( $rec['rec_type'] ?? '' );
			$evidence = (string) ( $rec['evidence_label'] ?? '' );
			$conf     = (string) ( $rec['confidence'] ?? '' );
			$cost     = $rec['expected_cost'] ?? 0;
			$impact   = (string) ( $rec['expected_impact'] ?? '' );
			$rank     = (float) ( $rec['rank_score'] ?? 0 );
			$status   = (string) ( $rec['status'] ?? 'draft' );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $title ) . '</strong></td>';
			echo '<td>' . ( '' !== $rec_type ? esc_html( ucwords( str_replace( '_', ' ', $rec_type ) ) ) : '&mdash;' ) . '</td>';
			echo '<td>' . ( '' !== $evidence ? View::pill( $evidence ) : '&mdash;' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . ( '' !== $conf ? View::pill( $conf ) : '&mdash;' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . esc_html( Helpers::money_fmt( $cost ) ) . '</td>';
			echo '<td>' . ( '' !== $impact ? esc_html( $impact ) : '&mdash;' ) . '</td>';
			echo '<td>' . esc_html( number_format( $rank, 1 ) ) . '</td>';
			echo '<td>' . View::pill( $status ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tr>';

			// Expandable detail + edit row.
			echo '<tr class="abcmd-rec-detail"><td colspan="8">';
			echo '<details>';
			echo '<summary>' . esc_html__( 'Details & review', 'abc-marketing-department' ) . '</summary>';
			echo '<div class="abcmd-rec-detail-body">';

			$description    = (string) ( $rec['description'] ?? '' );
			$success_metric = (string) ( $rec['success_metric'] ?? '' );
			$stop_rule      = (string) ( $rec['stop_rule'] ?? '' );
			$scale_rule     = (string) ( $rec['scale_rule'] ?? '' );

			if ( '' !== $description ) {
				echo '<p>' . esc_html( $description ) . '</p>';
			}

			echo '<table class="widefat striped"><tbody>';
			if ( '' !== $success_metric ) {
				echo '<tr><th scope="row">' . esc_html__( 'Success metric', 'abc-marketing-department' ) . '</th><td>' . esc_html( $success_metric ) . '</td></tr>';
			}
			if ( '' !== $stop_rule ) {
				echo '<tr><th scope="row">' . esc_html__( 'Stop rule', 'abc-marketing-department' ) . '</th><td>' . esc_html( $stop_rule ) . '</td></tr>';
			}
			if ( '' !== $scale_rule ) {
				echo '<tr><th scope="row">' . esc_html__( 'Scale rule', 'abc-marketing-department' ) . '</th><td>' . esc_html( $scale_rule ) . '</td></tr>';
			}
			echo '</tbody></table>';

			// Source links, if any.
			$links = ( isset( $rec['source_links'] ) && is_array( $rec['source_links'] ) ) ? $rec['source_links'] : array();
			if ( ! empty( $links ) ) {
				echo '<p class="description">' . esc_html__( 'Sources:', 'abc-marketing-department' ) . '</p><ul class="abcmd-source-links">';
				foreach ( $links as $link ) {
					if ( ! is_array( $link ) ) {
						continue;
					}
					$url   = (string) ( $link['url'] ?? '' );
					$label = (string) ( $link['title'] ?? $url );
					if ( '' === $url ) {
						continue;
					}
					echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( '' !== $label ? $label : $url ) . '</a></li>';
				}
				echo '</ul>';
			}

			// Inline edit form.
			View::form_open( 'abcmd_save_recommendation', '', array( 'id' => (string) $id ) );
			echo '<table class="form-table" role="presentation"><tbody>';
			View::field( 'title', __( 'Title', 'abc-marketing-department' ), $title );
			View::textarea( 'description', __( 'Description', 'abc-marketing-department' ), $description, 4 );
			View::select( 'status', __( 'Status', 'abc-marketing-department' ), self::statuses(), $status );
			View::field( 'rank_score', __( 'Rank score', 'abc-marketing-department' ), number_format( $rank, 2, '.', '' ), 'number' );
			View::checkbox( 'create_task', __( 'Generate task', 'abc-marketing-department' ), false, __( 'Approving + this box generates a draft task.', 'abc-marketing-department' ) );
			echo '</tbody></table>';
			View::submit( __( 'Save recommendation', 'abc-marketing-department' ) );
			View::form_close();

			echo '</div>'; // .abcmd-rec-detail-body
			echo '</details>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		View::close();
	}
}
