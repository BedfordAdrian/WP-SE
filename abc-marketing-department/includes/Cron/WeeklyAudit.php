<?php
/**
 * Weekly automated audit and Monday summary email.
 *
 * @package ABCMD
 */

namespace ABCMD\Cron;

use ABCMD\AI\OpenAIClient;
use ABCMD\AI\Runner;
use ABCMD\AI\TaskTypes;
use ABCMD\Anomaly\Detector;
use ABCMD\Repository\AiRuns;
use ABCMD\Repository\Anomalies;
use ABCMD\Repository\Books;
use ABCMD\Repository\Consent;
use ABCMD\Repository\Recommendations;
use ABCMD\Support\Audit;
use ABCMD\Support\Helpers;
use ABCMD\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the scheduled audit pipeline and composes the summary email.
 */
final class WeeklyAudit {

	private const OPT_LAST = 'abcmd_last_audit';

	/**
	 * Cron entry point for the weekly audit.
	 */
	public static function run_scheduled(): void {
		self::run( true );
	}

	/**
	 * Execute the audit for all active workspaces.
	 *
	 * @param bool $scheduled Whether triggered by cron (vs manual "Run Now").
	 * @return array<string,mixed> Summary.
	 */
	public static function run( bool $scheduled = false ): array {
		$books = ( new Books() )->all( array( 'status' => 'active' ), 'title', 'ASC' );
		$has_ai = OpenAIClient::has_key();
		$consent = new Consent();

		$summary = array(
			'started_at'  => Helpers::now_mysql(),
			'scheduled'   => $scheduled,
			'workspaces'  => array(),
			'anomalies'   => 0,
			'recs'        => 0,
			'ai_errors'   => array(),
		);

		foreach ( $books as $book ) {
			$book_id = (int) $book['id'];

			// 1-6) Collect data + detect anomalies + note gaps.
			$new_anoms = Detector::detect( $book_id );
			$open      = ( new Anomalies() )->open_for( $book_id );

			$ws = array(
				'book_id'   => $book_id,
				'title'     => (string) $book['title'],
				'anomalies' => count( $open ),
				'new_recs'  => 0,
				'ai'        => 'skipped',
			);
			$summary['anomalies'] += count( $new_anoms );

			// 7-11) Run the AI weekly-audit task where permitted.
			if ( $has_ai && $consent->ai_allowed( $book_id ) ) {
				$settings  = OpenAIClient::settings();
				$model     = (string) $settings['default_model'];
				$web       = ! empty( $settings['web_search'] ) && $consent->category_allowed( $book_id, 'web_research' );
				$type      = TaskTypes::get( 'weekly_review' );
				$min_cats  = $type ? (array) $type['min_data'] : array();

				$run = Runner::run(
					array(
						'book_id'    => $book_id,
						'run_type'   => 'weekly_review',
						'model'      => $model,
						'web_search' => $web,
						'categories' => $min_cats,
						'scheduled'  => true,
						'confirmed'  => true,
					)
				);
				if ( $run['ok'] ) {
					$ws['ai']       = 'ok';
					$ws['new_recs'] = $run['created']['recommendations'];
					$summary['recs'] += $run['created']['recommendations'];
				} else {
					$ws['ai'] = 'error';
					$summary['ai_errors'][] = $book['title'] . ': ' . $run['error_code'];
				}
			} elseif ( $has_ai ) {
				$ws['ai'] = 'no_consent';
			} else {
				$ws['ai'] = 'no_key';
			}

			$summary['workspaces'][] = $ws;
		}

		$summary['finished_at'] = Helpers::now_mysql();
		update_option( self::OPT_LAST, $summary, false );

		Audit::log(
			'cron.weekly_audit',
			sprintf( 'Weekly audit ran over %d workspace(s): %d new anomalies, %d new recommendations.', count( $books ), $summary['anomalies'], $summary['recs'] ),
			array( 'scheduled' => $scheduled )
		);

		return $summary;
	}

	/**
	 * Cron entry point for the Monday summary email.
	 */
	public static function send_email(): void {
		$last = get_option( self::OPT_LAST, array() );
		if ( ! is_array( $last ) || empty( $last['workspaces'] ) ) {
			// No audit has run yet this cycle; run one so the email has content.
			$last = self::run( true );
		}

		$recipients = self::recipients();
		if ( empty( $recipients ) ) {
			return;
		}

		$dash = esc_url_raw( Helpers::admin_url( 'abcmd' ) );
		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$lines   = array();
		$lines[] = 'Marketing Department — weekly summary';
		$lines[] = str_repeat( '=', 38 );
		$lines[] = '';
		$lines[] = sprintf( 'Workspaces reviewed: %d', count( $last['workspaces'] ) );
		$lines[] = sprintf( 'New anomalies flagged: %d', (int) ( $last['anomalies'] ?? 0 ) );
		$lines[] = sprintf( 'New recommendations awaiting review: %d', (int) ( $last['recs'] ?? 0 ) );
		$lines[] = '';
		foreach ( (array) $last['workspaces'] as $ws ) {
			$lines[] = sprintf(
				'- %s: %d open anomaly flag(s), %d new recommendation(s) [AI: %s]',
				$ws['title'],
				(int) $ws['anomalies'],
				(int) $ws['new_recs'],
				$ws['ai']
			);
		}
		if ( ! empty( $last['ai_errors'] ) ) {
			$lines[] = '';
			$lines[] = 'AI issues: ' . implode( '; ', array_map( 'strval', $last['ai_errors'] ) );
		}
		$lines[] = '';
		$lines[] = 'Review and approve in the dashboard (secure, staff login required):';
		$lines[] = $dash;
		$lines[] = '';
		$lines[] = 'This email intentionally contains no manuscript or sensitive client data.';

		$subject = sprintf( '[%s] Marketing Department weekly summary', $site );
		$body    = implode( "\n", $lines );

		$sent = wp_mail( $recipients, $subject, $body );

		Audit::log(
			'cron.weekly_email',
			$sent ? 'Weekly summary email sent.' : 'Weekly summary email FAILED to send.',
			array( 'recipients' => count( $recipients ), 'sent' => (bool) $sent )
		);
	}

	/**
	 * Resolve email recipients (configured list or the site admin).
	 *
	 * @return string[]
	 */
	private static function recipients(): array {
		$raw = trim( (string) Options::get( 'weekly_email_recipients', '' ) );
		if ( '' === $raw ) {
			$admin = get_option( 'admin_email' );
			return $admin ? array( (string) $admin ) : array();
		}
		$emails = array();
		foreach ( Helpers::split_list( $raw ) as $candidate ) {
			if ( is_email( $candidate ) ) {
				$emails[] = $candidate;
			}
		}
		return $emails;
	}

	/**
	 * Details of the last completed audit (for the admin UI).
	 *
	 * @return array<string,mixed>
	 */
	public static function last_run(): array {
		$last = get_option( self::OPT_LAST, array() );
		return is_array( $last ) ? $last : array();
	}
}
