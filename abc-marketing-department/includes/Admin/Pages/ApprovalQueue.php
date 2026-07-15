<?php
/**
 * Approval Queue admin page: a single review queue across object types.
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
use ABCMD\Repository\Recommendations as RecommendationsRepo;
use ABCMD\Repository\Content as ContentRepo;
use ABCMD\Repository\Tasks as TasksRepo;
use ABCMD\Repository\AiRuns as AiRunsRepo;

/**
 * Renders the cross-type approval queue.
 */
final class ApprovalQueue {

	/**
	 * Render the approval queue with per-type tabs and a bulk decision form.
	 */
	public static function render(): void {
		\ABCMD\Support\Helpers::require_cap();

		$tabs = array(
			'recommendations' => __( 'Recommendations', 'abc-marketing-department' ),
			'content'         => __( 'Content', 'abc-marketing-department' ),
			'tasks'           => __( 'Tasks', 'abc-marketing-department' ),
			'ai_runs'         => __( 'AI runs', 'abc-marketing-department' ),
		);

		$tab = ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'recommendations';
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'recommendations';
		}
		$book = isset( $_GET['book'] ) ? (int) $_GET['book'] : 0;

		// Per-tab configuration: repo, awaiting-review column, singular object type.
		switch ( $tab ) {
			case 'content':
				$repo        = new ContentRepo();
				$status_col  = 'approval_status';
				$object_type = 'content';
				break;
			case 'tasks':
				$repo        = new TasksRepo();
				$status_col  = 'status';
				$object_type = 'task';
				break;
			case 'ai_runs':
				$repo        = new AiRunsRepo();
				$status_col  = 'approval_status';
				$object_type = 'ai_run';
				break;
			case 'recommendations':
			default:
				$repo        = new RecommendationsRepo();
				$status_col  = 'status';
				$object_type = 'recommendation';
				break;
		}

		$books_repo = new BooksRepo();
		$book_title = static function ( int $id ) use ( $books_repo ): string {
			if ( ! $id ) {
				return '—';
			}
			$b = $books_repo->find( $id );
			return $b ? (string) $b['title'] : '—';
		};

		$where = array( $status_col => 'awaiting_review' );
		if ( $book ) {
			$where['book_id'] = $book;
		}
		$rows = $repo->where( $where, 'id', 'DESC' );

		View::open(
			__( 'Approval Queue', 'abc-marketing-department' ),
			__( 'Review AI-generated recommendations, content, tasks and runs before anything goes live.', 'abc-marketing-department' )
		);

		View::tabs( $tabs, $tab, 'abcmd-approvals', array( 'book' => $book ) );

		// --- Workspace filter (GET) -----------------------------------------
		echo '<form method="get" class="abcmd-filters" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="abcmd-approvals" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		echo '<label for="abcmd-approval-book">' . esc_html__( 'Workspace:', 'abc-marketing-department' ) . ' </label>';
		echo '<select id="abcmd-approval-book" name="book">';
		echo '<option value="0">' . esc_html__( 'All workspaces', 'abc-marketing-department' ) . '</option>';
		foreach ( $books_repo->options() as $bid => $btitle ) {
			echo '<option value="' . esc_attr( (string) $bid ) . '" ' . selected( $book, $bid, false ) . '>' . esc_html( $btitle ) . '</option>';
		}
		echo '</select> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'abc-marketing-department' ) . '</button>';
		echo '</form>';

		if ( empty( $rows ) ) {
			View::empty_state( __( 'Nothing is awaiting review in this queue.', 'abc-marketing-department' ) );
			View::close();
			return;
		}

		View::form_open( 'abcmd_approval_decision', '', array( 'object_type' => $object_type ) );

		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<td class="check-column"><input type="checkbox" onclick="var b=this.checked;Array.prototype.forEach.call(this.closest(\'table\').querySelectorAll(\'input[name=&quot;ids[]&quot;]\'),function(c){c.checked=b;});" /></td>';
		echo '<th>' . esc_html__( 'Item', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Workspace', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Details', 'abc-marketing-department' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$rid = (int) ( $row['id'] ?? 0 );
			$bid = (int) ( $row['book_id'] ?? 0 );

			$summary = '';
			$details = array();

			switch ( $tab ) {
				case 'content':
					$title   = (string) ( $row['title'] ?? '' );
					$summary = (string) ( $row['objective'] ?? '' );
					$details = array(
						__( 'Channel', 'abc-marketing-department' )      => (string) ( $row['channel'] ?? '' ),
						__( 'Format', 'abc-marketing-department' )       => (string) ( $row['format'] ?? '' ),
						__( 'Publish date', 'abc-marketing-department' ) => (string) ( $row['publish_date'] ?? '' ),
						__( 'CTA', 'abc-marketing-department' )          => (string) ( $row['cta'] ?? '' ),
					);
					break;
				case 'tasks':
					$title   = (string) ( $row['title'] ?? '' );
					$summary = (string) ( $row['notes'] ?? '' );
					$details = array(
						__( 'Owner', 'abc-marketing-department' )    => (string) ( $row['owner'] ?? '' ),
						__( 'Priority', 'abc-marketing-department' ) => (string) ( $row['priority'] ?? '' ),
						__( 'Due date', 'abc-marketing-department' ) => (string) ( $row['due_date'] ?? '' ),
					);
					break;
				case 'ai_runs':
					$title   = (string) ( $row['run_type'] ?? __( 'AI run', 'abc-marketing-department' ) );
					$resp    = (string) ( $row['response'] ?? '' );
					$summary = strlen( $resp ) > 200 ? substr( $resp, 0, 200 ) . '…' : $resp;
					$details = array(
						__( 'Model', 'abc-marketing-department' )     => (string) ( $row['model'] ?? '' ),
						__( 'Created', 'abc-marketing-department' )   => (string) ( $row['created_at'] ?? '' ),
						__( 'Est. cost', 'abc-marketing-department' ) => Helpers::money_fmt( $row['est_cost'] ?? 0, isset( $row['currency'] ) ? (string) $row['currency'] : null ),
					);
					break;
				case 'recommendations':
				default:
					$title   = (string) ( $row['title'] ?? '' );
					$summary = (string) ( $row['description'] ?? '' );
					$details = array(
						__( 'Type', 'abc-marketing-department' )       => (string) ( $row['rec_type'] ?? '' ),
						__( 'Objective', 'abc-marketing-department' )  => (string) ( $row['objective'] ?? '' ),
						__( 'Confidence', 'abc-marketing-department' ) => (string) ( $row['confidence'] ?? '' ),
						__( 'Impact', 'abc-marketing-department' )     => (string) ( $row['expected_impact'] ?? '' ),
					);
					break;
			}

			echo '<tr>';
			echo '<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="' . esc_attr( (string) $rid ) . '" /></th>';
			echo '<td><strong>' . esc_html( '' !== $title ? $title : ( '#' . $rid ) ) . '</strong>';
			if ( '' !== $summary ) {
				echo '<p class="description">' . esc_html( $summary ) . '</p>';
			}
			echo '</td>';
			echo '<td>' . esc_html( $book_title( $bid ) ) . '</td>';
			echo '<td>';
			foreach ( $details as $label => $value ) {
				if ( '' === $value ) {
					continue;
				}
				echo '<div><span class="description">' . esc_html( $label ) . ':</span> ' . esc_html( $value ) . '</div>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		// --- Decision controls ----------------------------------------------
		echo '<table class="form-table" role="presentation"><tbody>';
		View::select(
			'decision',
			__( 'Decision', 'abc-marketing-department' ),
			array(
				'approve'    => __( 'Approve', 'abc-marketing-department' ),
				'reject'     => __( 'Reject', 'abc-marketing-department' ),
				'regenerate' => __( 'Regenerate', 'abc-marketing-department' ),
			),
			'approve'
		);
		View::textarea( 'note', __( 'Note (optional)', 'abc-marketing-department' ), '', 3, __( 'Stored with the decision as an audit note.', 'abc-marketing-department' ) );
		echo '</tbody></table>';
		View::submit( __( 'Apply decision to selected', 'abc-marketing-department' ) );
		View::form_close();

		View::close();
	}
}
