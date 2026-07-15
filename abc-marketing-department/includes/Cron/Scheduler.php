<?php
/**
 * WP-Cron scheduling for the weekly audit and Monday summary email.
 *
 * Times are anchored to Europe/London and converted to UTC for WordPress.
 * Because WP-Cron is traffic-dependent, the plugin also detects missed runs and
 * documents configuring a real server cron (see Help).
 *
 * @package ABCMD
 */

namespace ABCMD\Cron;

use ABCMD\Support\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages recurring events.
 */
final class Scheduler {

	public const SCHEDULE = 'abcmd_weekly';

	/**
	 * Register cron filter for the custom weekly interval.
	 */
	public static function hooks(): void {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
	}

	/**
	 * Add a 7-day recurring interval.
	 *
	 * @param array<string,array<string,mixed>> $schedules Existing schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Weekly (Marketing Department)', 'abc-marketing-department' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the audit + email events based on current settings.
	 */
	public static function schedule_all(): void {
		self::unschedule_all();

		if ( ! Options::get( 'weekly_audit_enabled', 1 ) ) {
			return;
		}

		$weekday = (int) Options::get( 'weekly_audit_weekday', 1 );
		$audit_h = (int) Options::get( 'weekly_audit_hour', 0 );
		$email_h = (int) Options::get( 'weekly_email_hour', 9 );

		$audit_ts = self::next_weekly_timestamp( $weekday, $audit_h );
		$email_ts = self::next_weekly_timestamp( $weekday, $email_h );

		if ( ! wp_next_scheduled( ABCMD_CRON_WEEKLY_AUDIT ) ) {
			wp_schedule_event( $audit_ts, self::SCHEDULE, ABCMD_CRON_WEEKLY_AUDIT );
		}
		if ( ! wp_next_scheduled( ABCMD_CRON_WEEKLY_EMAIL ) ) {
			wp_schedule_event( $email_ts, self::SCHEDULE, ABCMD_CRON_WEEKLY_EMAIL );
		}
	}

	/**
	 * Remove all plugin cron events.
	 */
	public static function unschedule_all(): void {
		foreach ( array( ABCMD_CRON_WEEKLY_AUDIT, ABCMD_CRON_WEEKLY_EMAIL ) as $hook ) {
			$ts = wp_next_scheduled( $hook );
			while ( $ts ) {
				wp_unschedule_event( $ts, $hook );
				$ts = wp_next_scheduled( $hook );
			}
		}
	}

	/**
	 * Re-apply schedule after a settings change.
	 */
	public static function reschedule(): void {
		self::schedule_all();
	}

	/**
	 * Next UTC timestamp for a given London weekday+hour.
	 *
	 * @param int $weekday 1 (Mon) .. 7 (Sun), ISO-8601.
	 * @param int $hour    0..23 local hour.
	 */
	public static function next_weekly_timestamp( int $weekday, int $hour ): int {
		$weekday = min( 7, max( 1, $weekday ) );
		$hour    = min( 23, max( 0, $hour ) );

		try {
			$tz  = new \DateTimeZone( ABCMD_TIMEZONE );
			$now = new \DateTimeImmutable( 'now', $tz );
		} catch ( \Exception $e ) {
			return time() + WEEK_IN_SECONDS;
		}

		$days_ahead = ( $weekday - (int) $now->format( 'N' ) + 7 ) % 7;
		$candidate  = $now->setTime( $hour, 0, 0 )->modify( "+{$days_ahead} days" );

		if ( $candidate <= $now ) {
			$candidate = $candidate->modify( '+7 days' );
		}

		return $candidate->getTimestamp();
	}

	/**
	 * Timestamp (UTC) of the next scheduled audit, or 0.
	 */
	public static function next_audit(): int {
		return (int) wp_next_scheduled( ABCMD_CRON_WEEKLY_AUDIT );
	}

	/**
	 * Timestamp (UTC) of the next scheduled email, or 0.
	 */
	public static function next_email(): int {
		return (int) wp_next_scheduled( ABCMD_CRON_WEEKLY_EMAIL );
	}

	/**
	 * Whether a scheduled run appears to have been missed (overdue by >2h).
	 */
	public static function audit_overdue(): bool {
		$next = self::next_audit();
		return $next && ( $next < ( time() - 2 * HOUR_IN_SECONDS ) );
	}
}
