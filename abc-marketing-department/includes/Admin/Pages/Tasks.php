<?php
/**
 * Tasks admin page: list (overdue first) + add/edit form.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ABCMD\Admin\View;
use ABCMD\Support\Helpers;
use ABCMD\Repository\Tasks as TasksRepo;
use ABCMD\Repository\Books as BooksRepo;
use ABCMD\Repository\Authors as AuthorsRepo;

/**
 * Renders the campaign tasks screen.
 */
final class Tasks {

	/**
	 * Full task status choices.
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
	 * Whether a task row is currently overdue.
	 *
	 * @param array<string,mixed> $task  Task row.
	 * @param string              $today Y-m-d today.
	 */
	private static function is_overdue( array $task, string $today ): bool {
		$due = (string) ( $task['due_date'] ?? '' );
		if ( '' === $due ) {
			return false;
		}
		$status = (string) ( $task['status'] ?? '' );
		if ( in_array( $status, array( 'completed', 'cancelled' ), true ) ) {
			return false;
		}
		return $due < $today;
	}

	/**
	 * Render one task row.
	 *
	 * @param array<string,mixed> $task    Task row.
	 * @param bool                $overdue Highlight as overdue.
	 */
	private static function row( array $task, bool $overdue ): void {
		$id       = (int) $task['id'];
		$title    = (string) ( $task['title'] ?? '' );
		$owner    = (string) ( $task['owner'] ?? '' );
		$priority = (string) ( $task['priority'] ?? 'medium' );
		$due      = (string) ( $task['due_date'] ?? '' );
		$status   = (string) ( $task['status'] ?? 'draft' );
		$budget   = $task['budget'] ?? 0;
		$actual   = $task['actual_cost'] ?? 0;
		$edit_url = Helpers::admin_url( 'abcmd-tasks', array( 'task' => $id ) );

		echo '<tr' . ( $overdue ? ' class="abcmd-overdue" style="background:#fcf0f1;"' : '' ) . '>';
		echo '<td><strong>' . esc_html( $title ) . '</strong>';
		if ( $overdue ) {
			echo ' <span class="abcmd-pill abcmd-pill-overdue">' . esc_html__( 'Overdue', 'abc-marketing-department' ) . '</span>';
		}
		echo '</td>';
		echo '<td>' . ( '' !== $owner ? esc_html( $owner ) : '&mdash;' ) . '</td>';
		echo '<td>' . View::pill( $priority ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td>' . ( '' !== $due ? esc_html( $due ) : '&mdash;' ) . '</td>';
		echo '<td>' . View::pill( $status ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<td>' . esc_html( Helpers::money_fmt( $budget ) ) . '</td>';
		echo '<td>' . esc_html( Helpers::money_fmt( $actual ) ) . '</td>';
		echo '<td>' . View::button_link( $edit_url, __( 'Edit', 'abc-marketing-department' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</tr>';
	}

	/**
	 * Render the tasks list and add/edit form.
	 */
	public static function render(): void {
		\ABCMD\Support\Helpers::require_cap();

		$repo    = new TasksRepo();
		$books   = new BooksRepo();
		$authors = new AuthorsRepo();

		$book_id    = isset( $_GET['book'] ) ? (int) $_GET['book'] : 0;
		$editing_id = isset( $_GET['task'] ) ? (int) $_GET['task'] : 0;
		$editing    = $editing_id ? $repo->find( $editing_id ) : null;
		if ( ! is_array( $editing ) ) {
			$editing_id = 0;
			$editing    = null;
		}

		View::open(
			__( 'Tasks', 'abc-marketing-department' ),
			__( 'Campaign work items. Overdue tasks are highlighted and listed first.', 'abc-marketing-department' )
		);

		$book_options = $books->options();

		// Optional workspace filter.
		if ( ! empty( $book_options ) ) {
			echo '<form method="get" class="abcmd-filter">';
			echo '<input type="hidden" name="page" value="abcmd-tasks" />';
			echo '<label for="abcmd-task-book">' . esc_html__( 'Workspace:', 'abc-marketing-department' ) . ' </label>';
			echo '<select id="abcmd-task-book" name="book" onchange="this.form.submit()">';
			echo '<option value="0">' . esc_html__( 'All workspaces', 'abc-marketing-department' ) . '</option>';
			foreach ( $book_options as $bid => $title ) {
				echo '<option value="' . esc_attr( (string) $bid ) . '" ' . selected( $book_id, (int) $bid, false ) . '>' . esc_html( $title ) . '</option>';
			}
			echo '</select>';
			echo '</form>';
		}

		$tasks = $book_id
			? $repo->where( array( 'book_id' => $book_id ), 'due_date', 'ASC' )
			: $repo->all( array(), 'id', 'DESC' );

		$today   = current_time( 'Y-m-d' );
		$overdue = array();
		$rest    = array();
		foreach ( $tasks as $task ) {
			if ( self::is_overdue( $task, $today ) ) {
				$overdue[] = $task;
			} else {
				$rest[] = $task;
			}
		}

		if ( empty( $tasks ) ) {
			View::empty_state( __( 'No tasks yet. Create one with the form below, or approve a recommendation to generate a draft task.', 'abc-marketing-department' ) );
		} else {
			echo '<table class="wp-list-table widefat striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Task', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Owner', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Priority', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Due', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Budget', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Actual', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', 'abc-marketing-department' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $overdue as $task ) {
				self::row( $task, true );
			}
			foreach ( $rest as $task ) {
				self::row( $task, false );
			}

			echo '</tbody></table>';
		}

		// --- Add / edit form -------------------------------------------------
		$title_v      = $editing ? (string) ( $editing['title'] ?? '' ) : '';
		$owner_v      = $editing ? (string) ( $editing['owner'] ?? '' ) : '';
		$priority_v   = $editing ? (string) ( $editing['priority'] ?? 'medium' ) : 'medium';
		$due_v        = $editing ? (string) ( $editing['due_date'] ?? '' ) : '';
		$effort_v     = $editing ? (string) ( $editing['effort'] ?? '' ) : '';
		$budget_v     = $editing ? (string) ( $editing['budget'] ?? '' ) : '';
		$actual_v     = $editing ? (string) ( $editing['actual_cost'] ?? '' ) : '';
		$status_v     = $editing ? (string) ( $editing['status'] ?? 'draft' ) : 'draft';
		$recurring_v  = $editing ? (string) ( $editing['recurring'] ?? '' ) : '';
		$notes_v      = $editing ? (string) ( $editing['notes'] ?? '' ) : '';
		$completion_v = $editing ? (string) ( $editing['completion_date'] ?? '' ) : '';
		$rec_id_v     = $editing ? (string) ( $editing['recommendation_id'] ?? '' ) : '';
		$book_sel     = $editing ? (int) ( $editing['book_id'] ?? 0 ) : $book_id;
		$author_sel   = $editing ? (int) ( $editing['author_id'] ?? 0 ) : 0;

		$deps_arr = ( $editing && isset( $editing['dependencies'] ) && is_array( $editing['dependencies'] ) ) ? $editing['dependencies'] : array();
		$deps_v   = implode( ', ', array_map( 'strval', $deps_arr ) );

		// Dependency gate: warn + reveal override when editing an unmet task.
		$deps_met = true;
		if ( $editing ) {
			$deps_met = $repo->dependencies_met( $editing );
		}

		echo '<h2>' . ( $editing ? esc_html__( 'Edit task', 'abc-marketing-department' ) : esc_html__( 'Add task', 'abc-marketing-department' ) ) . '</h2>';

		if ( $editing ) {
			echo '<p><a href="' . esc_url( Helpers::admin_url( 'abcmd-tasks', $book_id ? array( 'book' => $book_id ) : array() ) ) . '">' . esc_html__( '&larr; Add a new task instead', 'abc-marketing-department' ) . '</a></p>';
		}

		if ( $editing && ! $deps_met ) {
			View::notice(
				__( 'This task has prerequisites that are not yet completed. To move it to scheduled, in progress or completed you must provide an override reason below.', 'abc-marketing-department' ),
				'warning'
			);
		}

		// Workspace + author options with a "none" placeholder.
		$book_choices = array( 0 => __( '— Select workspace —', 'abc-marketing-department' ) );
		foreach ( $book_options as $bid => $title ) {
			$book_choices[ (int) $bid ] = $title;
		}
		$author_choices = array( 0 => __( '— Select author —', 'abc-marketing-department' ) );
		foreach ( $authors->options() as $aid => $aname ) {
			$author_choices[ (int) $aid ] = $aname;
		}

		View::form_open( 'abcmd_save_task', '', array( 'id' => (string) $editing_id ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		View::field( 'title', __( 'Title', 'abc-marketing-department' ), $title_v );
		View::select( 'book_id', __( 'Workspace', 'abc-marketing-department' ), $book_choices, $book_sel );
		View::select( 'author_id', __( 'Author', 'abc-marketing-department' ), $author_choices, $author_sel, __( 'Usually the author behind the workspace above.', 'abc-marketing-department' ) );
		View::field( 'recommendation_id', __( 'Linked recommendation ID', 'abc-marketing-department' ), $rec_id_v, 'number', __( 'Optional. The recommendation this task delivers.', 'abc-marketing-department' ) );
		View::field( 'owner', __( 'Owner', 'abc-marketing-department' ), $owner_v );
		View::select(
			'priority',
			__( 'Priority', 'abc-marketing-department' ),
			array(
				'high'   => __( 'High', 'abc-marketing-department' ),
				'medium' => __( 'Medium', 'abc-marketing-department' ),
				'low'    => __( 'Low', 'abc-marketing-department' ),
			),
			$priority_v
		);
		View::field( 'due_date', __( 'Due date', 'abc-marketing-department' ), $due_v, 'date' );
		View::field( 'effort', __( 'Effort', 'abc-marketing-department' ), $effort_v, 'text', __( 'e.g. 2h, half a day.', 'abc-marketing-department' ) );
		View::field( 'budget', __( 'Budget', 'abc-marketing-department' ), $budget_v, 'number' );
		View::field( 'actual_cost', __( 'Actual cost', 'abc-marketing-department' ), $actual_v, 'number' );
		View::select( 'status', __( 'Status', 'abc-marketing-department' ), self::statuses(), $status_v );

		// Dependencies field + reference list of existing task ids/titles.
		$ref_source = $book_sel ? $repo->where( array( 'book_id' => $book_sel ), 'id', 'ASC' ) : $tasks;
		$ref_bits   = array();
		foreach ( $ref_source as $ref ) {
			if ( $editing && (int) $ref['id'] === $editing_id ) {
				continue;
			}
			$ref_bits[] = '#' . (int) $ref['id'] . ' ' . (string) ( $ref['title'] ?? '' );
		}
		$ref_hint = empty( $ref_bits )
			? __( 'Comma-separated task IDs this task depends on.', 'abc-marketing-department' )
			: __( 'Comma-separated task IDs. Available: ', 'abc-marketing-department' ) . implode( '; ', $ref_bits );
		View::field( 'dependencies', __( 'Dependencies', 'abc-marketing-department' ), $deps_v, 'text', $ref_hint );

		View::select(
			'recurring',
			__( 'Recurring', 'abc-marketing-department' ),
			array(
				''        => __( 'One-off', 'abc-marketing-department' ),
				'weekly'  => __( 'Weekly', 'abc-marketing-department' ),
				'monthly' => __( 'Monthly', 'abc-marketing-department' ),
			),
			$recurring_v
		);
		View::field( 'completion_date', __( 'Completion date', 'abc-marketing-department' ), $completion_v, 'date' );
		View::textarea( 'notes', __( 'Notes', 'abc-marketing-department' ), $notes_v, 3 );

		// Override reason only when editing a task with unmet dependencies.
		if ( $editing && ! $deps_met ) {
			$override_v = (string) ( $editing['override_reason'] ?? '' );
			View::textarea( 'override_reason', __( 'Override reason', 'abc-marketing-department' ), $override_v, 2, __( 'Required to advance this task while prerequisites are incomplete.', 'abc-marketing-department' ) );
		}

		echo '</tbody></table>';
		View::submit( $editing ? __( 'Update task', 'abc-marketing-department' ) : __( 'Add task', 'abc-marketing-department' ) );
		View::form_close();

		View::close();
	}
}
