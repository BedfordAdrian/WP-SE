<?php
/**
 * AI run records repository.
 *
 * @package ABCMD
 */

namespace ABCMD\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD + cost roll-ups for AI runs.
 */
final class AiRuns extends BaseRepository {

	protected function key(): string {
		return 'ai_runs';
	}

	protected function timestamp_columns(): array {
		return array( 'created_at' );
	}

	protected function columns(): array {
		return array(
			'book_id'          => '%d',
			'author_id'        => '%d',
			'run_type'         => '%s',
			'user_id'          => '%d',
			'model'            => '%s',
			'source_materials' => '%s',
			'data_sharing'     => '%s',
			'prompt'           => '%s',
			'response'         => '%s',
			'web_search'       => '%d',
			'sources'          => '%s',
			'input_tokens'     => '%d',
			'output_tokens'    => '%d',
			'est_cost'         => '%f',
			'actual_cost'      => '%f',
			'currency'         => '%s',
			'approval_status'  => '%s',
			'error_state'      => '%s',
		);
	}

	protected function json_columns(): array {
		return array( 'source_materials', 'data_sharing', 'sources' );
	}

	/**
	 * Total actual AI spend since a given datetime (UTC) — for budget warnings.
	 *
	 * @param string $since_utc MySQL datetime.
	 */
	public function spend_since( string $since_utc ): float {
		global $wpdb;
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(actual_cost),0) FROM ' . $this->table() . ' WHERE created_at >= %s',
				$since_utc
			)
		);
	}

	/**
	 * Count runs by the current user in the last hour (rate limiting).
	 *
	 * @param int $user_id User id.
	 */
	public function count_recent_for_user( int $user_id ): int {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE user_id = %d AND created_at >= %s',
				$user_id,
				$since
			)
		);
	}
}
