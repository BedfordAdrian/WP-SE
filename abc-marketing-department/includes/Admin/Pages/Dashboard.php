<?php
/**
 * Dashboard: agency-wide overview across all authors and book workspaces.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ABCMD\Admin\View;
use ABCMD\AI\OpenAIClient;
use ABCMD\Cron\Scheduler;
use ABCMD\Repository\AiRuns;
use ABCMD\Repository\Anomalies;
use ABCMD\Repository\Authors as AuthorsRepo;
use ABCMD\Repository\Books as BooksRepo;
use ABCMD\Repository\Metrics;
use ABCMD\Repository\Recommendations as RecommendationsRepo;
use ABCMD\Repository\Tasks as TasksRepo;
use ABCMD\Support\Helpers;

/**
 * Renders the top-level dashboard.
 */
final class Dashboard {

	/**
	 * Output the dashboard page.
	 */
	public static function render(): void {
		\ABCMD\Support\Helpers::require_cap();

		$authors_repo = new AuthorsRepo();
		$books_repo   = new BooksRepo();
		$anoms_repo   = new Anomalies();
		$metrics_repo = new Metrics();
		$tasks_repo   = new TasksRepo();
		$recs_repo    = new RecommendationsRepo();
		$runs_repo    = new AiRuns();

		$books        = $books_repo->all( array(), 'title', 'ASC' );
		$author_names = $authors_repo->options();

		// Aggregate open anomalies and overdue tasks across every workspace.
		$open_by_book  = array();
		$open_total    = 0;
		$overdue_total = 0;
		foreach ( $books as $book ) {
			$book_id                 = (int) $book['id'];
			$open_count              = count( $anoms_repo->open_for( $book_id ) );
			$open_by_book[ $book_id ] = $open_count;
			$open_total             += $open_count;
			$overdue_total          += count( $tasks_repo->overdue_for( $book_id ) );
		}

		$recs_pending = $recs_repo->count( array( 'status' => 'awaiting_review' ) );
		$ai_spend     = $runs_repo->spend_since( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) );

		View::open(
			__( 'Marketing Department', 'abc-marketing-department' ),
			__( 'Agency-wide overview of authors, workspaces and this week\'s priorities.', 'abc-marketing-department' )
		);

		// Warn when no OpenAI key is configured.
		if ( ! OpenAIClient::has_key() ) {
			$settings_url = Helpers::admin_url( 'abcmd-settings' );
			View::notice(
				sprintf(
					/* translators: %s: settings link */
					esc_html__( 'No OpenAI API key is configured, so AI audits and recommendations are disabled. %s', 'abc-marketing-department' ),
					'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Add a key in Settings', 'abc-marketing-department' ) . '</a>'
				),
				'warning'
			);
		}

		// Headline stats.
		echo '<div class="abcmd-stats">';
		View::stat( __( 'Authors', 'abc-marketing-department' ), (string) $authors_repo->count() );
		View::stat( __( 'Book Workspaces', 'abc-marketing-department' ), (string) count( $books ) );
		View::stat( __( 'Open Anomalies', 'abc-marketing-department' ), (string) $open_total );
		View::stat( __( 'Recommendations to Review', 'abc-marketing-department' ), (string) $recs_pending );
		View::stat( __( 'Overdue Tasks', 'abc-marketing-department' ), (string) $overdue_total );
		View::stat(
			__( 'AI Spend (30 days)', 'abc-marketing-department' ),
			Helpers::money_fmt( $ai_spend ),
			__( 'Actual cost, last 30 days', 'abc-marketing-department' )
		);
		echo '</div>';

		// Next scheduled audit.
		$next_audit = Scheduler::next_audit();
		echo '<p class="description">';
		if ( $next_audit > 0 ) {
			printf(
				/* translators: %s: formatted date/time */
				esc_html__( 'Next scheduled weekly audit: %s.', 'abc-marketing-department' ),
				esc_html( wp_date( 'D j M Y H:i', $next_audit ) )
			);
		} else {
			esc_html_e( 'No weekly audit is currently scheduled.', 'abc-marketing-department' );
		}
		echo ' ' . wp_kses_post( View::button_link( Helpers::admin_url( 'abcmd-weekly' ), __( 'Weekly Review', 'abc-marketing-department' ) ) );
		echo '</p>';

		// Empty state: offer demo data or workspace creation.
		if ( empty( $books ) ) {
			View::empty_state( __( 'No book workspaces yet. Install demo data to explore the plugin, or create your first workspace.', 'abc-marketing-department' ) );

			echo '<p>';
			View::form_open( 'abcmd_install_demo' );
			View::submit( __( 'Install demo data', 'abc-marketing-department' ), 'secondary' );
			View::form_close();
			echo '</p>';

			echo '<p>' . wp_kses_post( View::button_link( Helpers::admin_url( 'abcmd-books' ), __( 'Create a workspace', 'abc-marketing-department' ), 'primary' ) ) . '</p>';

			View::close();
			return;
		}

		// Workspaces table.
		echo '<h2>' . esc_html__( 'Workspaces', 'abc-marketing-department' ) . '</h2>';
		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Workspace', 'abc-marketing-department' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Author', 'abc-marketing-department' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'abc-marketing-department' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Last Metric', 'abc-marketing-department' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Open Anomalies', 'abc-marketing-department' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $books as $book ) {
			$book_id     = (int) $book['id'];
			$url         = Helpers::admin_url( 'abcmd-books', array( 'book' => $book_id ) );
			$author_name = $author_names[ (int) $book['author_id'] ] ?? '—';
			$last_metric = $metrics_repo->last_date( $book_id );
			$open_count  = $open_by_book[ $book_id ] ?? 0;

			echo '<tr>';
			echo '<td><a href="' . esc_url( $url ) . '"><strong>' . esc_html( (string) $book['title'] ) . '</strong></a></td>';
			echo '<td>' . esc_html( (string) $author_name ) . '</td>';
			echo '<td>' . wp_kses_post( View::pill( (string) ( $book['status'] ?? 'active' ) ) ) . '</td>';
			echo '<td>' . esc_html( $last_metric ? $last_metric : __( 'No data yet', 'abc-marketing-department' ) ) . '</td>';
			echo '<td>' . esc_html( (string) $open_count ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		View::close();
	}
}
