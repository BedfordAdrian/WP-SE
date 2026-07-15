<?php
/**
 * Weekly Review: status of the automated weekly audit and summary email.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ABCMD\Admin\View;
use ABCMD\Cron\Scheduler;
use ABCMD\Cron\WeeklyAudit;
use ABCMD\Support\Helpers;

/**
 * Renders the weekly audit control and status page.
 */
final class WeeklyReview {

	/**
	 * Output the weekly review page.
	 */
	public static function render(): void {
		\ABCMD\Support\Helpers::require_cap();

		View::open(
			__( 'Weekly Review', 'abc-marketing-department' ),
			__( 'The plugin audits every active workspace once a week and emails a summary.', 'abc-marketing-department' )
		);

		// Overdue warning.
		if ( Scheduler::audit_overdue() ) {
			View::notice(
				esc_html__( 'The weekly audit appears to be overdue. WP-Cron only runs when the site receives traffic; see the help note below on configuring a real server cron.', 'abc-marketing-department' ),
				'warning'
			);
		}

		// WP-Cron disabled warning.
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			$help_url = Helpers::admin_url( 'abcmd-help' );
			View::notice(
				sprintf(
					/* translators: %s: help link */
					esc_html__( 'DISABLE_WP_CRON is set in wp-config.php. Scheduled audits will not run unless a real server cron is triggering wp-cron.php. %s', 'abc-marketing-department' ),
					'<a href="' . esc_url( $help_url ) . '">' . esc_html__( 'See the cron setup guide', 'abc-marketing-department' ) . '</a>'
				),
				'warning'
			);
		}

		// Schedule status stats.
		$next_audit = Scheduler::next_audit();
		$next_email = Scheduler::next_email();

		echo '<div class="abcmd-stats">';
		View::stat(
			__( 'Next Audit', 'abc-marketing-department' ),
			$next_audit > 0 ? wp_date( 'D j M Y H:i', $next_audit ) : __( 'Not scheduled', 'abc-marketing-department' )
		);
		View::stat(
			__( 'Next Summary Email', 'abc-marketing-department' ),
			$next_email > 0 ? wp_date( 'D j M Y H:i', $next_email ) : __( 'Not scheduled', 'abc-marketing-department' )
		);
		echo '</div>';

		// Run-now form.
		echo '<h2>' . esc_html__( 'Run an audit now', 'abc-marketing-department' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Runs anomaly detection and (where an OpenAI key and consent exist) the AI weekly-review task across all active workspaces immediately.', 'abc-marketing-department' ) . '</p>';
		View::form_open( 'abcmd_run_audit_now' );
		View::submit( __( 'Run audit now', 'abc-marketing-department' ), 'primary' );
		View::form_close();

		// Last run summary.
		$last = WeeklyAudit::last_run();
		echo '<h2>' . esc_html__( 'Last completed audit', 'abc-marketing-department' ) . '</h2>';

		if ( empty( $last ) || empty( $last['workspaces'] ) ) {
			View::empty_state( __( 'No audit has completed yet. Run one now, or wait for the next scheduled run.', 'abc-marketing-department' ) );
		} else {
			$started  = isset( $last['started_at'] ) ? (string) $last['started_at'] : '';
			$finished = isset( $last['finished_at'] ) ? (string) $last['finished_at'] : '';

			echo '<p class="description">';
			printf(
				/* translators: 1: start time, 2: finish time */
				esc_html__( 'Started: %1$s — Finished: %2$s (UTC).', 'abc-marketing-department' ),
				esc_html( '' !== $started ? $started : __( 'unknown', 'abc-marketing-department' ) ),
				esc_html( '' !== $finished ? $finished : __( 'unknown', 'abc-marketing-department' ) )
			);
			echo '</p>';

			echo '<table class="wp-list-table widefat striped">';
			echo '<thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Workspace', 'abc-marketing-department' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Open Anomalies', 'abc-marketing-department' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'New Recommendations', 'abc-marketing-department' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'AI', 'abc-marketing-department' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( (array) $last['workspaces'] as $ws ) {
				$ws = is_array( $ws ) ? $ws : array();
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $ws['title'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( (string) (int) ( $ws['anomalies'] ?? 0 ) ) . '</td>';
				echo '<td>' . esc_html( (string) (int) ( $ws['new_recs'] ?? 0 ) ) . '</td>';
				echo '<td>' . wp_kses_post( View::pill( (string) ( $ws['ai'] ?? 'skipped' ) ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		// WP-Cron explanation.
		$help_url = Helpers::admin_url( 'abcmd-help' );
		echo '<h2>' . esc_html__( 'About scheduling', 'abc-marketing-department' ) . '</h2>';
		echo '<p>' . esc_html__( 'WordPress schedules the weekly audit with WP-Cron, which is triggered by site traffic rather than a true system clock. On a quiet site, runs can be delayed until the next visitor arrives.', 'abc-marketing-department' ) . '</p>';
		echo '<p>' . sprintf(
			/* translators: %s: help link */
			esc_html__( 'For reliable timing, configure a real server cron to hit wp-cron.php. %s', 'abc-marketing-department' ),
			wp_kses_post( '<a href="' . esc_url( $help_url ) . '">' . esc_html__( 'See the Help page for setup instructions', 'abc-marketing-department' ) . '</a>' )
		) . '</p>';

		View::close();
	}
}
