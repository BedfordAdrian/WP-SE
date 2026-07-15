<?php
/**
 * Demo workspace installer and data-purge tools.
 *
 * The demo data is fictionalised operational data for testing and can be
 * removed at any time. It is clearly labelled as demo in the UI.
 *
 * @package ABCMD
 */

namespace ABCMD\Demo;

use ABCMD\Database\Schema;
use ABCMD\Repository\Authors;
use ABCMD\Repository\Books;
use ABCMD\Repository\Consent;
use ABCMD\Repository\Metrics;
use ABCMD\Support\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Installs and removes the demo workspace, and purges plugin data.
 */
final class DemoData {

	private const BASELINE = '2026-07-13';

	/**
	 * Install the Mo Fanning / "Lisa Doyle is Absolutely Fine" demo workspace.
	 *
	 * @return array{author_id:int,book_id:int,author_name:string}
	 */
	public static function install(): array {
		$authors = new Authors();
		$books   = new Books();

		$author_id = $authors->insert(
			array(
				'name'            => 'Mo Fanning',
				'pen_name'        => 'Mo Fanning',
				'email'           => '',
				'website'         => 'https://mofanning.com',
				'biography'       => 'Author of uplifting, funny fiction. (Demo record — fictionalised operational data.)',
				'social_profiles' => array( '@mofanning (Instagram)', 'Bluesky', 'Substack' ),
				'email_platform'  => array( 'provider' => 'Generic', 'notes' => 'Demo list' ),
				'notes'           => 'DEMO author record.',
				'consent_status'  => 'active',
				'status'          => 'active',
			)
		);

		$book_id = $books->insert(
			array(
				'author_id'         => $author_id,
				'title'             => 'Lisa Doyle is Absolutely Fine',
				'subtitle'          => '',
				'publisher'         => 'Independent',
				'publication_date'  => '2026-06-18',
				'genre'             => 'Romantic comedy',
				'territory'         => 'UK-first',
				'audience'          => 'Readers of upmarket UK romantic comedy',
				'synopsis'          => 'A warm, funny UK-set romantic comedy. (Demo synopsis placeholder.)',
				'proposition'       => 'Feel-good British romcom with heart and wit.',
				'comparison_titles' => array( 'Comp title A', 'Comp title B' ),
				'formats'           => array( 'ebook', 'paperback', 'hardback', 'audiobook' ),
				'prices'            => array( 'ebook' => 4.99, 'paperback' => 12.99, 'hardback' => 16.99, 'audiobook' => 17.00 ),
				'net_income'        => array( 'ebook' => 2.00, 'paperback' => 0.30, 'hardback' => 1.00, 'audiobook' => 5.00 ),
				'distributors'      => array( 'Amazon', 'IngramSpark', 'Draft2Digital', 'ACX' ),
				'retailer_links'    => array( 'https://example.com/amazon', 'https://example.com/kobo' ),
				'universal_link'    => 'https://books2read.com/lisa-doyle',
				'campaign_start'    => '2026-06-18',
				'long_term_target'  => array( 'text' => 'Establish the author brand and build a repeatable launch playbook.' ),
				'targets_90day'     => array(
					'additional_sales'   => 1000,
					'mailing_list'       => 2000,
					'instagram'          => 1800,
					'amazon_uk_reviews'  => 100,
				),
				'weekly_hours'      => 20,
				'budget'            => 1000,
				'budget_max'        => 2000,
				'break_even'        => array( 'cost_per_sale' => 2.00 ),
				'excluded_channels' => array(),
				'currency'          => 'GBP',
				'status'            => 'active',
			)
		);

		// Consent: AI permitted, sales + web research allowed, manuscript NOT shared.
		( new Consent() )->insert(
			array(
				'book_id'            => $book_id,
				'author_id'          => $author_id,
				'allow_openai'       => 1,
				'allow_manuscript'   => 0,
				'allow_sales'        => 1,
				'allow_personal'     => 0,
				'allow_web_research' => 1,
				'data_categories'    => array( 'book_profile', 'sales_data', 'social_data', 'email_data', 'campaign_history' ),
				'granted_date'       => '2026-06-15',
				'method'             => 'Signed agency onboarding form (demo)',
				'notes'              => 'DEMO consent record.',
				'status'             => 'active',
				'updated_by'         => get_current_user_id(),
			)
		);

		self::seed_metrics( $book_id, $author_id );

		Audit::log( 'demo.install', 'Demo workspace installed (Mo Fanning / Lisa Doyle is Absolutely Fine).', array( 'book_id' => $book_id ), $book_id, $author_id );

		return array(
			'author_id'   => $author_id,
			'book_id'     => $book_id,
			'author_name' => 'Mo Fanning',
		);
	}

	/**
	 * Seed dated metric snapshots for the demo book.
	 *
	 * @param int $book_id   Book id.
	 * @param int $author_id Author id.
	 */
	private static function seed_metrics( int $book_id, int $author_id ): void {
		$metrics = new Metrics();
		$prices  = array( 'ebook' => 4.99, 'paperback' => 12.99, 'audiobook' => 17.00 );
		$net     = array( 'ebook' => 2.00, 'paperback' => 0.30, 'audiobook' => 5.00 );

		// Weekly launch curve split by format (totals: ebook 515, pb 100, ab 85 = 700).
		$weeks = array(
			'2026-06-15' => array( 'ebook' => 184, 'paperback' => 36, 'audiobook' => 30 ),
			'2026-06-22' => array( 'ebook' => 132, 'paperback' => 26, 'audiobook' => 22 ),
			'2026-06-29' => array( 'ebook' => 88, 'paperback' => 17, 'audiobook' => 15 ),
			'2026-07-06' => array( 'ebook' => 66, 'paperback' => 13, 'audiobook' => 11 ),
			'2026-07-13' => array( 'ebook' => 45, 'paperback' => 8, 'audiobook' => 7 ),
		);

		$add = static function ( array $args ) use ( $metrics, $book_id, $author_id ) {
			$args = array_merge(
				array(
					'book_id'       => $book_id,
					'author_id'     => $author_id,
					'source'        => 'Demo seed',
					'source_status' => 'estimated',
					'dedup_key'     => '',
				),
				$args
			);
			$metrics->insert( $args );
		};

		foreach ( $weeks as $date => $split ) {
			foreach ( $split as $fmt => $units ) {
				$add( array( 'metric_date' => $date, 'metric_key' => 'sales', 'format' => $fmt, 'territory' => 'UK', 'value_num' => $units ) );
				$add( array( 'metric_date' => $date, 'metric_key' => 'revenue', 'format' => $fmt, 'value_num' => round( $units * $prices[ $fmt ], 2 ), 'currency' => 'GBP' ) );
				$add( array( 'metric_date' => $date, 'metric_key' => 'contribution', 'format' => $fmt, 'value_num' => round( $units * $net[ $fmt ], 2 ), 'currency' => 'GBP' ) );
			}
		}

		// Advertising (demo, within budget): BookBub featured deal + Amazon Ads.
		$add( array( 'metric_date' => '2026-06-15', 'metric_key' => 'ad_spend', 'channel' => 'BookBub', 'value_num' => 280, 'currency' => 'GBP' ) );
		$amazon = array( '2026-06-15' => 60, '2026-06-22' => 50, '2026-06-29' => 40, '2026-07-06' => 30, '2026-07-13' => 20 );
		foreach ( $amazon as $d => $spend ) {
			$add( array( 'metric_date' => $d, 'metric_key' => 'ad_spend', 'channel' => 'Amazon Ads', 'value_num' => $spend, 'currency' => 'GBP' ) );
		}
		$add( array( 'metric_date' => '2026-07-13', 'metric_key' => 'attributed_sales', 'platform' => 'BookBub', 'value_num' => 200 ) );

		// Reviews by platform.
		$add( array( 'metric_date' => self::BASELINE, 'metric_key' => 'reviews', 'platform' => 'Amazon UK', 'value_num' => 17 ) );
		$add( array( 'metric_date' => self::BASELINE, 'metric_key' => 'reviews', 'platform' => 'Amazon US', 'value_num' => 6 ) );
		$add( array( 'metric_date' => self::BASELINE, 'metric_key' => 'reviews', 'platform' => 'Goodreads', 'value_num' => 23 ) );
		$add( array( 'metric_date' => self::BASELINE, 'metric_key' => 'reviews', 'platform' => 'NetGalley', 'value_num' => 4 ) );
		$add( array( 'metric_date' => self::BASELINE, 'metric_key' => 'avg_rating', 'value_num' => 4.25 ) );

		// Audience.
		$add( array( 'metric_date' => self::BASELINE, 'metric_key' => 'mailing_list_size', 'value_num' => 512 ) );
		$add( array( 'metric_date' => self::BASELINE, 'metric_key' => 'open_rate', 'value_num' => 42 ) );
		$add( array( 'metric_date' => self::BASELINE, 'metric_key' => 'click_rate', 'value_num' => 6 ) );
		$followers = array( 'Instagram' => 582, 'Facebook' => 159, 'Bluesky' => 3100, 'Substack' => 29, 'BookBub' => 17 );
		foreach ( $followers as $platform => $count ) {
			$add( array( 'metric_date' => self::BASELINE, 'metric_key' => 'followers', 'platform' => $platform, 'value_num' => $count ) );
		}
		$add( array( 'metric_date' => self::BASELINE, 'metric_key' => 'netgalley_downloads', 'value_num' => 160 ) );
	}

	/**
	 * Delete a workspace and every record tied to it.
	 *
	 * @param int $book_id Book id.
	 * @return int Rows removed across tables.
	 */
	public static function delete_workspace( int $book_id ): int {
		global $wpdb;
		$removed = 0;
		$book    = ( new Books() )->find( $book_id );
		$author  = $book ? (int) $book['author_id'] : 0;

		foreach ( array( 'metrics', 'imports', 'ai_runs', 'recommendations', 'tasks', 'content', 'files', 'file_chunks', 'consent', 'anomalies' ) as $key ) {
			$removed += (int) $wpdb->delete( Schema::table( $key ), array( 'book_id' => $book_id ), array( '%d' ) );
		}
		$removed += (int) $wpdb->delete( Schema::table( 'books' ), array( 'id' => $book_id ), array( '%d' ) );

		Audit::log( 'book.delete', sprintf( 'Workspace #%d deleted with %d related records.', $book_id, $removed ), array( 'book_id' => $book_id ), $book_id, $author );
		return $removed;
	}

	/**
	 * Purge data by scope.
	 *
	 * @param string $scope workspace|author|file|ai_run|all.
	 * @param int    $id    Target id (for scoped purges).
	 * @return int Records removed.
	 */
	public static function purge( string $scope, int $id = 0 ): int {
		global $wpdb;

		switch ( $scope ) {
			case 'workspace':
				return self::delete_workspace( $id );

			case 'author':
				$removed = 0;
				foreach ( ( new Books() )->for_author( $id ) as $b ) {
					$removed += self::delete_workspace( (int) $b['id'] );
				}
				$removed += (int) $wpdb->delete( Schema::table( 'authors' ), array( 'id' => $id ), array( '%d' ) );
				Audit::log( 'author.purge', sprintf( 'Author #%d fully purged.', $id ), array( 'author_id' => $id ), null, $id );
				return $removed;

			case 'file':
				\ABCMD\Files\Uploader::delete( $id );
				return 1;

			case 'ai_run':
				Audit::log( 'ai_run.delete', sprintf( 'AI run #%d deleted.', $id ) );
				return (int) $wpdb->delete( Schema::table( 'ai_runs' ), array( 'id' => $id ), array( '%d' ) );

			case 'all':
				$removed = 0;
				foreach ( Schema::keys() as $key ) {
					if ( 'audit_log' === $key ) {
						continue; // Keep the audit trail of the purge itself.
					}
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$removed += (int) $wpdb->query( 'DELETE FROM ' . Schema::table( $key ) );
				}
				Audit::log( 'data.purge_all', sprintf( 'ALL agency data purged (%d records). Audit log retained.', $removed ) );
				return $removed;

			default:
				return 0;
		}
	}
}
