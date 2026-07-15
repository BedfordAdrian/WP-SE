<?php
/**
 * Book (client workspace) repository.
 *
 * @package ABCMD
 */

namespace ABCMD\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD + helpers for book workspaces.
 */
final class Books extends BaseRepository {

	protected function key(): string {
		return 'books';
	}

	protected function columns(): array {
		return array(
			'author_id'          => '%d',
			'title'              => '%s',
			'subtitle'           => '%s',
			'publisher'          => '%s',
			'publication_date'   => '%s',
			'genre'              => '%s',
			'territory'          => '%s',
			'audience'           => '%s',
			'synopsis'           => '%s',
			'proposition'        => '%s',
			'comparison_titles'  => '%s',
			'formats'            => '%s',
			'prices'             => '%s',
			'net_income'         => '%s',
			'distributors'       => '%s',
			'retailer_links'     => '%s',
			'universal_link'     => '%s',
			'campaign_start'     => '%s',
			'long_term_target'   => '%s',
			'targets_90day'      => '%s',
			'weekly_hours'       => '%f',
			'budget'             => '%f',
			'budget_max'         => '%f',
			'break_even'         => '%s',
			'excluded_channels'  => '%s',
			'currency'           => '%s',
			'anomaly_thresholds' => '%s',
			'status'             => '%s',
		);
	}

	protected function json_columns(): array {
		return array(
			'comparison_titles',
			'formats',
			'prices',
			'net_income',
			'distributors',
			'retailer_links',
			'long_term_target',
			'targets_90day',
			'break_even',
			'excluded_channels',
			'anomaly_thresholds',
		);
	}

	/**
	 * Books belonging to an author.
	 *
	 * @param int $author_id Author id.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_author( int $author_id ): array {
		return $this->all( array( 'author_id' => $author_id ), 'title', 'ASC' );
	}

	/**
	 * Options list (id => "Title" ) for dropdowns.
	 *
	 * @return array<int,string>
	 */
	public function options(): array {
		$out = array();
		foreach ( $this->all( array(), 'title', 'ASC' ) as $b ) {
			$out[ (int) $b['id'] ] = (string) $b['title'];
		}
		return $out;
	}

	/**
	 * Default per-workspace anomaly thresholds merged with any saved overrides.
	 *
	 * @param array<string,mixed> $book Book row.
	 * @return array<string,float>
	 */
	public static function thresholds( array $book ): array {
		$defaults = array(
			'sales_spike_pct'    => 200.0, // % week-over-week rise to flag.
			'sales_collapse_pct' => 60.0,  // % week-over-week fall to flag.
			'open_rate_drop_pct' => 40.0,  // % relative drop in email open rate.
			'report_gap_days'    => 10,    // Days without a metric before "missing report".
			'distributor_gap_days' => 45,  // Days a distributor feed may lag.
		);
		$saved = $book['anomaly_thresholds'] ?? array();
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( $defaults, array_map( 'floatval', $saved ) );
	}
}
