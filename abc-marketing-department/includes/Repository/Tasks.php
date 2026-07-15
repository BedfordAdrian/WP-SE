<?php
/**
 * Tasks repository with dependency logic.
 *
 * @package ABCMD
 */

namespace ABCMD\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD + dependency gating for campaign tasks.
 */
final class Tasks extends BaseRepository {

	protected function key(): string {
		return 'tasks';
	}

	protected function columns(): array {
		return array(
			'book_id'           => '%d',
			'author_id'         => '%d',
			'recommendation_id' => '%d',
			'title'             => '%s',
			'owner'             => '%s',
			'priority'          => '%s',
			'due_date'          => '%s',
			'effort'            => '%s',
			'budget'            => '%f',
			'actual_cost'       => '%f',
			'status'            => '%s',
			'dependencies'      => '%s',
			'recurring'         => '%s',
			'notes'             => '%s',
			'content_id'        => '%d',
			'completion_date'   => '%s',
			'override_reason'   => '%s',
		);
	}

	protected function json_columns(): array {
		return array( 'dependencies' );
	}

	/**
	 * Overdue tasks for a book (due before today, not completed/cancelled).
	 *
	 * @param int $book_id Book id.
	 * @return array<int,array<string,mixed>>
	 */
	public function overdue_for( int $book_id ): array {
		global $wpdb;
		$today = current_time( 'Y-m-d' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $this->table()
					. " WHERE book_id = %d AND due_date IS NOT NULL AND due_date < %s AND status NOT IN ('completed','cancelled') ORDER BY due_date ASC",
				$book_id,
				$today
			),
			ARRAY_A
		);
		return array_map( array( $this, 'decode_row' ), (array) $rows );
	}

	/**
	 * Whether all prerequisite tasks for a task are completed.
	 *
	 * @param array<string,mixed> $task Task row (dependencies decoded).
	 */
	public function dependencies_met( array $task ): bool {
		$deps = $task['dependencies'] ?? array();
		if ( ! is_array( $deps ) || empty( $deps ) ) {
			return true;
		}
		foreach ( $deps as $dep_id ) {
			$dep = $this->find( (int) $dep_id );
			if ( ! $dep || 'completed' !== $dep['status'] ) {
				return false;
			}
		}
		return true;
	}
}
