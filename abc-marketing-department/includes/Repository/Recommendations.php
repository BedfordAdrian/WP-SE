<?php
/**
 * Recommendations repository.
 *
 * @package ABCMD
 */

namespace ABCMD\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for ranked marketing recommendations.
 */
final class Recommendations extends BaseRepository {

	protected function key(): string {
		return 'recommendations';
	}

	protected function columns(): array {
		return array(
			'book_id'         => '%d',
			'author_id'       => '%d',
			'ai_run_id'       => '%d',
			'title'           => '%s',
			'description'     => '%s',
			'rec_type'        => '%s',
			'evidence_label'  => '%s',
			'confidence'      => '%s',
			'objective'       => '%s',
			'expected_cost'   => '%f',
			'expected_time'   => '%s',
			'expected_impact' => '%s',
			'success_metric'  => '%s',
			'stop_rule'       => '%s',
			'scale_rule'      => '%s',
			'dependencies'    => '%s',
			'source_links'    => '%s',
			'rank_score'      => '%f',
			'status'          => '%s',
		);
	}

	protected function json_columns(): array {
		return array( 'dependencies', 'source_links' );
	}

	/**
	 * Active (non-terminal) recommendations for a book, ranked.
	 *
	 * @param int $book_id Book id.
	 * @return array<int,array<string,mixed>>
	 */
	public function active_for( int $book_id ): array {
		$all = $this->where( array( 'book_id' => $book_id ), 'rank_score', 'DESC', 0, 0 );
		return array_values(
			array_filter(
				$all,
				static fn( $r ) => ! in_array( $r['status'], array( 'rejected', 'cancelled', 'completed' ), true )
			)
		);
	}
}
