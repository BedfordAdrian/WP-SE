<?php
/**
 * Rule-based anomaly detection (phase one).
 *
 * Flags describe what changed and why it merits checking. Nothing is presented
 * as fraud or certainty. Thresholds are configurable per workspace.
 *
 * @package ABCMD
 */

namespace ABCMD\Anomaly;

use ABCMD\Repository\Anomalies;
use ABCMD\Repository\Books;
use ABCMD\Repository\Metrics;
use ABCMD\Repository\Recommendations;
use ABCMD\Repository\Tasks;
use ABCMD\Support\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates anomaly rules for a workspace.
 */
final class Detector {

	/**
	 * Run all rules for a book and record any new anomalies.
	 *
	 * @param int $book_id Book id.
	 * @return array<int,array<string,mixed>> Newly recorded anomalies.
	 */
	public static function detect( int $book_id ): array {
		$books = new Books();
		$book  = $books->find( $book_id );
		if ( ! $book ) {
			return array();
		}
		$thresholds = Books::thresholds( $book );
		$author_id  = (int) $book['author_id'];
		$metrics    = new Metrics();
		$repo       = new Anomalies();
		$found      = array();

		$week_tag = gmdate( 'oW' ); // ISO year+week for dedup within a week.

		$record = static function ( array $data ) use ( $repo, $book_id, $author_id, &$found ) {
			$data['book_id']   = $book_id;
			$data['author_id'] = $author_id;
			$id                = $repo->record_unique( $data );
			if ( $id ) {
				$data['id'] = $id;
				$found[]    = $data;
			}
		};

		// --- Sales spike / collapse (week over week) ---
		$series = $metrics->weekly_series( $book_id, 'sales', 4 );
		if ( count( $series ) >= 2 ) {
			$vals = array_values( $series );
			$prev = (float) $vals[ count( $vals ) - 2 ];
			$curr = (float) $vals[ count( $vals ) - 1 ];
			if ( $prev > 0 ) {
				$change = ( ( $curr - $prev ) / $prev ) * 100;
				if ( $change >= $thresholds['sales_spike_pct'] ) {
					$record(
						array(
							'rule'      => 'sales_spike',
							'severity'  => 'info',
							'metric_key'=> 'sales',
							'title'     => 'Sudden sales rise',
							'detail'    => sprintf( 'Weekly sales rose ~%d%% (from %s to %s). Worth confirming the source and whether it is sustainable.', round( $change ), round( $prev, 1 ), round( $curr, 1 ) ),
							'dedup_key' => "spike:$book_id:$week_tag",
						)
					);
				} elseif ( $change <= -$thresholds['sales_collapse_pct'] ) {
					$record(
						array(
							'rule'      => 'sales_collapse',
							'severity'  => 'warning',
							'metric_key'=> 'sales',
							'title'     => 'Sudden sales drop',
							'detail'    => sprintf( 'Weekly sales fell ~%d%% (from %s to %s). Check for a missing report, price change or ad pause.', round( abs( $change ) ), round( $prev, 1 ), round( $curr, 1 ) ),
							'dedup_key' => "collapse:$book_id:$week_tag",
						)
					);
				}
			}
		}

		// --- Missing expected report ---
		$last = $metrics->last_date( $book_id );
		if ( $last ) {
			$days = ( time() - strtotime( $last . ' 00:00:00' ) ) / DAY_IN_SECONDS;
			if ( $days > $thresholds['report_gap_days'] ) {
				$record(
					array(
						'rule'      => 'missing_report',
						'severity'  => 'warning',
						'title'     => 'Data may be missing',
						'detail'    => sprintf( 'No metrics recorded since %s (%d days). A scheduled report may be missing or delayed.', $last, (int) $days ),
						'dedup_key' => "missing:$book_id:$last",
					)
				);
			}
		}

		// --- Ad spend without recorded sales (last 30 days) ---
		$from  = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
		$spend = $metrics->sum( $book_id, 'ad_spend', $from );
		$sales = $metrics->sum( $book_id, 'sales', $from );
		if ( $spend > 0 && $sales <= 0 ) {
			$record(
				array(
					'rule'      => 'ad_spend_no_sales',
					'severity'  => 'warning',
					'metric_key'=> 'ad_spend',
					'title'     => 'Ad spend with no recorded sales',
					'detail'    => sprintf( 'Recorded %s ad spend in the last 30 days but no sales in the same window. Attribution may be delayed, or the campaign may be underperforming.', Helpers::money_fmt( $spend, (string) $book['currency'] ) ),
					'dedup_key' => "spendnosales:$book_id:$week_tag",
				)
			);
		}

		// --- Cost per sale above break-even ---
		if ( $spend > 0 && $sales > 0 ) {
			$cps        = $spend / $sales;
			$break_even = self::break_even_value( $book );
			if ( $break_even > 0 && $cps > $break_even ) {
				$record(
					array(
						'rule'      => 'cost_per_sale',
						'severity'  => 'warning',
						'metric_key'=> 'ad_spend',
						'title'     => 'Cost per sale above break-even',
						'detail'    => sprintf( 'Cost per sale is ~%s over the last 30 days, above the break-even of %s.', Helpers::money_fmt( $cps, (string) $book['currency'] ), Helpers::money_fmt( $break_even, (string) $book['currency'] ) ),
						'dedup_key' => "cps:$book_id:$week_tag",
					)
				);
			}
		}

		// --- Negative contribution ---
		$contribution = $metrics->sum( $book_id, 'contribution' );
		if ( $contribution < 0 ) {
			$record(
				array(
					'rule'      => 'negative_contribution',
					'severity'  => 'warning',
					'metric_key'=> 'contribution',
					'title'     => 'Negative contribution',
					'detail'    => sprintf( 'Recorded contribution is negative (%s). Review net income assumptions and ad efficiency.', Helpers::money_fmt( $contribution, (string) $book['currency'] ) ),
					'dedup_key' => "negcontrib:$book_id:$week_tag",
				)
			);
		}

		// --- Email open-rate collapse ---
		$open_rows = $metrics->weekly_series( $book_id, 'open_rate', 4 );
		if ( count( $open_rows ) >= 2 ) {
			$ov  = array_values( $open_rows );
			$op  = (float) $ov[ count( $ov ) - 2 ];
			$oc  = (float) $ov[ count( $ov ) - 1 ];
			if ( $op > 0 && ( ( $op - $oc ) / $op * 100 ) >= $thresholds['open_rate_drop_pct'] ) {
				$record(
					array(
						'rule'      => 'open_rate_collapse',
						'severity'  => 'warning',
						'metric_key'=> 'open_rate',
						'title'     => 'Email open rate dropped sharply',
						'detail'    => sprintf( 'Open rate fell from %s%% to %s%%. Check deliverability, subject lines or list health.', round( $op, 1 ), round( $oc, 1 ) ),
						'dedup_key' => "openrate:$book_id:$week_tag",
					)
				);
			}
		}

		// --- Unusually high review activity ---
		$rev_series = $metrics->weekly_series( $book_id, 'reviews', 4 );
		if ( count( $rev_series ) >= 2 ) {
			$rv = array_values( $rev_series );
			$rp = (float) $rv[ count( $rv ) - 2 ];
			$rc = (float) $rv[ count( $rv ) - 1 ];
			if ( $rc >= 10 && $rp > 0 && $rc >= 3 * $rp ) {
				$record(
					array(
						'rule'      => 'review_surge',
						'severity'  => 'info',
						'metric_key'=> 'reviews',
						'title'     => 'Unusually high review activity',
						'detail'    => sprintf( 'Reviews jumped from %s to %s in a week. Usually good news — worth a quick check it is organic.', round( $rp ), round( $rc ) ),
						'dedup_key' => "reviewsurge:$book_id:$week_tag",
					)
				);
			}
		}

		// --- Follower jump without traffic ---
		$fol = $metrics->weekly_series( $book_id, 'followers', 4 );
		$ulc = $metrics->sum( $book_id, 'universal_link_clicks', $from );
		if ( count( $fol ) >= 2 ) {
			$fv = array_values( $fol );
			$fp = (float) $fv[ count( $fv ) - 2 ];
			$fc = (float) $fv[ count( $fv ) - 1 ];
			if ( $fp > 0 && $fc >= 2 * $fp && $ulc <= 0 ) {
				$record(
					array(
						'rule'      => 'follower_jump_no_traffic',
						'severity'  => 'info',
						'metric_key'=> 'followers',
						'title'     => 'Follower jump without link traffic',
						'detail'    => 'Followers rose sharply but universal-link clicks did not. Could indicate low-quality follows or an untracked spike.',
						'dedup_key' => "followerjump:$book_id:$week_tag",
					)
				);
			}
		}

		// --- Campaign task overdue ---
		$overdue = ( new Tasks() )->overdue_for( $book_id );
		if ( ! empty( $overdue ) ) {
			$record(
				array(
					'rule'      => 'task_overdue',
					'severity'  => 'info',
					'title'     => count( $overdue ) . ' overdue task(s)',
					'detail'    => 'One or more campaign tasks are past their due date.',
					'dedup_key' => "overdue:$book_id:$week_tag",
				)
			);
		}

		// --- AI recommendation based on stale research ---
		$recs = ( new Recommendations() )->active_for( $book_id );
		foreach ( $recs as $r ) {
			$has_sources = ! empty( $r['source_links'] );
			$age_days    = ( time() - strtotime( (string) $r['created_at'] ) ) / DAY_IN_SECONDS;
			if ( $has_sources && $age_days > 60 && in_array( $r['status'], array( 'approved', 'scheduled', 'in_progress' ), true ) ) {
				$record(
					array(
						'rule'      => 'stale_research',
						'severity'  => 'info',
						'title'     => 'Recommendation may rely on stale research',
						'detail'    => sprintf( 'Recommendation "%s" cites web research from %d+ days ago. Consider re-checking before further spend.', wp_trim_words( (string) $r['title'], 12 ), (int) $age_days ),
						'dedup_key' => 'stale:' . $r['id'],
					)
				);
			}
		}

		// --- Distributor data delayed beyond expected period ---
		self::detect_distributor_delay( $metrics, $book, $thresholds, $record, $week_tag );

		return $found;
	}

	/**
	 * Flag distributor/retailer sources whose latest data lags the expected window.
	 *
	 * @param Metrics             $metrics    Metrics repo.
	 * @param array<string,mixed> $book       Book row.
	 * @param array<string,float> $thresholds Thresholds.
	 * @param callable            $record     Recording closure.
	 * @param string              $week_tag   Week dedup tag.
	 */
	private static function detect_distributor_delay( Metrics $metrics, array $book, array $thresholds, callable $record, string $week_tag ): void {
		global $wpdb;
		$table = $metrics->table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT source, MAX(metric_date) AS last_date FROM ' . $table . ' WHERE book_id = %d AND source_status IN (%s,%s) GROUP BY source',
				(int) $book['id'],
				'delayed',
				'live'
			),
			ARRAY_A
		);
		$gap = (float) $thresholds['distributor_gap_days'];
		foreach ( (array) $rows as $r ) {
			$src  = (string) $r['source'];
			$days = ( time() - strtotime( (string) $r['last_date'] . ' 00:00:00' ) ) / DAY_IN_SECONDS;
			if ( '' !== $src && $days > $gap ) {
				$record(
					array(
						'rule'      => 'distributor_delay',
						'severity'  => 'info',
						'title'     => 'Distributor data may be delayed',
						'detail'    => sprintf( '%s has no new data for %d days (expected within %d). Distributor feeds often lag — confirm before drawing conclusions.', $src, (int) $days, (int) $gap ),
						'dedup_key' => 'distdelay:' . md5( $src ) . ":$week_tag",
					)
				);
			}
		}
	}

	/**
	 * Extract a numeric break-even (cost per sale) value from the book, if set.
	 *
	 * @param array<string,mixed> $book Book row.
	 */
	private static function break_even_value( array $book ): float {
		$be = $book['break_even'] ?? array();
		if ( is_array( $be ) ) {
			foreach ( array( 'cost_per_sale', 'cps', 'value', 'max_cps' ) as $k ) {
				if ( isset( $be[ $k ] ) && is_numeric( $be[ $k ] ) ) {
					return (float) $be[ $k ];
				}
			}
		}
		return 0.0;
	}
}
