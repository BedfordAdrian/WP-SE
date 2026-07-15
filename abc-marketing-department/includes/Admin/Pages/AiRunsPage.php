<?php
/**
 * AI Runs admin page: cost/usage list + per-run detail.
 *
 * @package ABCMD
 */

namespace ABCMD\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ABCMD\Admin\View;
use ABCMD\Support\Helpers;
use ABCMD\Repository\Books as BooksRepo;
use ABCMD\Repository\AiRuns as AiRunsRepo;
use ABCMD\AI\DataSharing;

/**
 * Renders the AI runs ledger and detail view.
 */
final class AiRunsPage {

	/**
	 * Render the AI runs list or a single run detail.
	 */
	public static function render(): void {
		\ABCMD\Support\Helpers::require_cap();

		$repo       = new AiRunsRepo();
		$books_repo = new BooksRepo();

		$run  = isset( $_GET['run'] ) ? (int) $_GET['run'] : 0;
		$book = isset( $_GET['book'] ) ? (int) $_GET['book'] : 0;

		$book_title = static function ( int $id ) use ( $books_repo ): string {
			if ( ! $id ) {
				return '—';
			}
			$b = $books_repo->find( $id );
			return $b ? (string) $b['title'] : '—';
		};

		if ( $run ) {
			self::render_detail( $repo, $run, $book_title );
			return;
		}

		View::open(
			__( 'AI Runs', 'abc-marketing-department' ),
			__( 'Every AI request, its cost, tokens and approval state.', 'abc-marketing-department' )
		);

		// --- Workspace filter (GET) -----------------------------------------
		echo '<form method="get" class="abcmd-filters" style="margin:12px 0">';
		echo '<input type="hidden" name="page" value="abcmd-ai-runs" />';
		echo '<label for="abcmd-airuns-book">' . esc_html__( 'Workspace:', 'abc-marketing-department' ) . ' </label>';
		echo '<select id="abcmd-airuns-book" name="book">';
		echo '<option value="0">' . esc_html__( 'All workspaces', 'abc-marketing-department' ) . '</option>';
		foreach ( $books_repo->options() as $bid => $btitle ) {
			echo '<option value="' . esc_attr( (string) $bid ) . '" ' . selected( $book, $bid, false ) . '>' . esc_html( $btitle ) . '</option>';
		}
		echo '</select> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'abc-marketing-department' ) . '</button>';
		echo '</form>';

		$rows = $book ? $repo->where( array( 'book_id' => $book ), 'created_at', 'DESC' ) : $repo->all( array(), 'created_at', 'DESC' );

		if ( empty( $rows ) ) {
			View::empty_state( __( 'No AI runs recorded yet.', 'abc-marketing-department' ) );
			View::close();
			return;
		}

		$total_spend = 0.0;
		foreach ( $rows as $row ) {
			$total_spend += (float) ( $row['actual_cost'] ?? 0 );
		}

		echo '<p class="abcmd-total-spend"><strong>' . esc_html__( 'Total AI spend (shown runs):', 'abc-marketing-department' ) . '</strong> ' . esc_html( Helpers::money_fmt( $total_spend ) ) . '</p>';

		echo '<table class="wp-list-table widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Created', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Workspace', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Model', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Tokens (in/out)', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Est. cost', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Actual cost', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Web search', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'abc-marketing-department' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'abc-marketing-department' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$id       = (int) ( $row['id'] ?? 0 );
			$currency = isset( $row['currency'] ) ? (string) $row['currency'] : null;
			$error    = (string) ( $row['error_state'] ?? '' );
			$view_url = Helpers::admin_url( 'abcmd-ai-runs', array( 'run' => $id ) );

			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $row['created_at'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $book_title( (int) ( $row['book_id'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['run_type'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $row['model'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) ( $row['input_tokens'] ?? 0 ) ) . ' / ' . number_format_i18n( (int) ( $row['output_tokens'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( Helpers::money_fmt( $row['est_cost'] ?? 0, $currency ) ) . '</td>';
			echo '<td>' . esc_html( Helpers::money_fmt( $row['actual_cost'] ?? 0, $currency ) ) . '</td>';
			echo '<td>' . ( (int) ( $row['web_search'] ?? 0 ) ? esc_html__( 'Yes', 'abc-marketing-department' ) : esc_html__( 'No', 'abc-marketing-department' ) ) . '</td>';
			echo '<td>' . View::pill( (string) ( $row['approval_status'] ?? 'draft' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( '' !== $error ) {
				echo '<br /><span style="color:#8a1f11">' . esc_html( $error ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . View::button_link( $view_url, __( 'View', 'abc-marketing-department' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tr>';
		}

		echo '</tbody></table>';
		View::close();
	}

	/**
	 * Render the full detail of a single AI run.
	 *
	 * @param AiRunsRepo $repo       AI runs repository.
	 * @param int        $run        Run id.
	 * @param callable   $book_title Resolver for a book title.
	 */
	private static function render_detail( AiRunsRepo $repo, int $run, callable $book_title ): void {
		$r = $repo->find( $run );

		View::open(
			__( 'AI Run', 'abc-marketing-department' ),
			__( 'Full record of a single AI request.', 'abc-marketing-department' )
		);
		echo '<p><a href="' . esc_url( Helpers::admin_url( 'abcmd-ai-runs' ) ) . '">' . esc_html__( '← Back to all runs', 'abc-marketing-department' ) . '</a></p>';

		if ( ! is_array( $r ) ) {
			View::empty_state( __( 'That AI run could not be found.', 'abc-marketing-department' ) );
			View::close();
			return;
		}

		$currency = isset( $r['currency'] ) ? (string) $r['currency'] : null;

		echo '<p>';
		echo '<strong>' . esc_html__( 'Workspace:', 'abc-marketing-department' ) . '</strong> ' . esc_html( $book_title( (int) ( $r['book_id'] ?? 0 ) ) ) . ' &nbsp; ';
		echo '<strong>' . esc_html__( 'Type:', 'abc-marketing-department' ) . '</strong> ' . esc_html( (string) ( $r['run_type'] ?? '' ) ) . ' &nbsp; ';
		echo '<strong>' . esc_html__( 'Model:', 'abc-marketing-department' ) . '</strong> ' . esc_html( (string) ( $r['model'] ?? '' ) ) . ' &nbsp; ';
		echo '<strong>' . esc_html__( 'Created:', 'abc-marketing-department' ) . '</strong> ' . esc_html( (string) ( $r['created_at'] ?? '' ) ) . ' &nbsp; ';
		echo '<strong>' . esc_html__( 'Status:', 'abc-marketing-department' ) . '</strong> ' . View::pill( (string) ( $r['approval_status'] ?? 'draft' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</p>';

		$error = (string) ( $r['error_state'] ?? '' );
		if ( '' !== $error ) {
			echo '<p style="color:#8a1f11"><strong>' . esc_html__( 'Error:', 'abc-marketing-department' ) . '</strong> ' . esc_html( $error ) . '</p>';
		}

		// Cost summary card.
		echo '<div class="abcmd-stats">';
		View::stat( __( 'Estimated cost', 'abc-marketing-department' ), Helpers::money_fmt( $r['est_cost'] ?? 0, $currency ) );
		View::stat( __( 'Actual cost', 'abc-marketing-department' ), Helpers::money_fmt( $r['actual_cost'] ?? 0, $currency ) );
		View::stat( __( 'Input tokens', 'abc-marketing-department' ), number_format_i18n( (int) ( $r['input_tokens'] ?? 0 ) ) );
		View::stat( __( 'Output tokens', 'abc-marketing-department' ), number_format_i18n( (int) ( $r['output_tokens'] ?? 0 ) ) );
		View::stat( __( 'Web search', 'abc-marketing-department' ), (int) ( $r['web_search'] ?? 0 ) ? __( 'Yes', 'abc-marketing-department' ) : __( 'No', 'abc-marketing-department' ) );
		echo '</div>';

		// Prompt.
		echo '<h2>' . esc_html__( 'Prompt', 'abc-marketing-department' ) . '</h2>';
		echo '<pre class="abcmd-pre" style="max-height:320px;overflow:auto;white-space:pre-wrap;">' . esc_html( (string) ( $r['prompt'] ?? '' ) ) . '</pre>';

		// Response.
		echo '<h2>' . esc_html__( 'Response', 'abc-marketing-department' ) . '</h2>';
		echo '<pre class="abcmd-pre" style="max-height:420px;overflow:auto;white-space:pre-wrap;">' . esc_html( (string) ( $r['response'] ?? '' ) ) . '</pre>';

		// Data-sharing categories.
		$cats   = DataSharing::categories();
		$shared = ( isset( $r['data_sharing'] ) && is_array( $r['data_sharing'] ) ) ? $r['data_sharing'] : array();
		echo '<h2>' . esc_html__( 'Data shared with the model', 'abc-marketing-department' ) . '</h2>';
		if ( empty( $shared ) ) {
			echo '<p class="description">' . esc_html__( 'No data-sharing categories recorded.', 'abc-marketing-department' ) . '</p>';
		} else {
			echo '<ul class="ul-disc">';
			foreach ( $shared as $slug ) {
				$slug  = (string) $slug;
				$label = isset( $cats[ $slug ]['label'] ) ? (string) $cats[ $slug ]['label'] : $slug;
				echo '<li>' . esc_html( $label ) . '</li>';
			}
			echo '</ul>';
		}

		// Sources.
		$sources = ( isset( $r['sources'] ) && is_array( $r['sources'] ) ) ? $r['sources'] : array();
		echo '<h2>' . esc_html__( 'Sources', 'abc-marketing-department' ) . '</h2>';
		if ( empty( $sources ) ) {
			echo '<p class="description">' . esc_html__( 'No sources returned for this run.', 'abc-marketing-department' ) . '</p>';
		} else {
			echo '<ul class="ul-disc">';
			foreach ( $sources as $s ) {
				if ( ! is_array( $s ) ) {
					continue;
				}
				$url   = (string) ( $s['url'] ?? '' );
				$title = (string) ( $s['title'] ?? '' );
				if ( '' !== $url ) {
					echo '<li><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( '' !== $title ? $title : $url ) . '</a></li>';
				} elseif ( '' !== $title ) {
					echo '<li>' . esc_html( $title ) . '</li>';
				}
			}
			echo '</ul>';
		}

		// Delete (purge) form.
		echo '<h2>' . esc_html__( 'Danger zone', 'abc-marketing-department' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-confirm="' . esc_attr__( 'Permanently delete this AI run? This cannot be undone.', 'abc-marketing-department' ) . '" class="abcmd-delete-form">';
		echo '<input type="hidden" name="action" value="abcmd_purge" />';
		wp_nonce_field( 'abcmd_purge', '_abcmd_nonce' );
		echo '<input type="hidden" name="scope" value="ai_run" />';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $run ) . '" />';
		echo '<input type="hidden" name="confirm" value="PURGE" />';
		View::submit( __( 'Delete AI run', 'abc-marketing-department' ), 'delete' );
		echo '</form>';

		View::close();
	}
}
