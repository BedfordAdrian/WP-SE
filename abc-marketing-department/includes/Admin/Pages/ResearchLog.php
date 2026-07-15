<?php
/**
 * Research Log admin page: web-research sources returned by AI runs.
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

/**
 * Renders every web-research source captured across AI runs.
 */
final class ResearchLog {

	/**
	 * Render the research log table.
	 */
	public static function render(): void {
		\ABCMD\Support\Helpers::require_cap();

		$repo       = new AiRunsRepo();
		$books_repo = new BooksRepo();

		$book_title = static function ( int $id ) use ( $books_repo ): string {
			if ( ! $id ) {
				return '—';
			}
			$b = $books_repo->find( $id );
			return $b ? (string) $b['title'] : '—';
		};

		// web_search is stored as an int; filter in PHP to be safe.
		$runs = array_filter(
			$repo->all( array(), 'created_at', 'DESC' ),
			static fn( $r ) => (int) ( $r['web_search'] ?? 0 ) === 1
		);

		$rows        = array();
		$empty_runs  = array();
		foreach ( $runs as $r ) {
			$sources = ( isset( $r['sources'] ) && is_array( $r['sources'] ) ) ? $r['sources'] : array();
			if ( empty( $sources ) ) {
				$empty_runs[] = $r;
				continue;
			}
			foreach ( $sources as $s ) {
				if ( ! is_array( $s ) ) {
					continue;
				}
				$rows[] = array(
					'title'    => (string) ( $s['title'] ?? '' ),
					'url'      => (string) ( $s['url'] ?? '' ),
					'run_type' => (string) ( $r['run_type'] ?? '' ),
					'book_id'  => (int) ( $r['book_id'] ?? 0 ),
					'date'     => (string) ( $r['created_at'] ?? '' ),
				);
			}
		}

		View::open(
			__( 'Research Log', 'abc-marketing-department' ),
			__( 'Sources the model reported using during web-enabled runs.', 'abc-marketing-department' )
		);

		echo '<p class="description">' . esc_html__( 'These are the web sources returned by the model on runs where web search was enabled. They reflect what the model reported, not an independent audit of what it read.', 'abc-marketing-department' ) . '</p>';

		if ( empty( $rows ) && empty( $empty_runs ) ) {
			View::empty_state( __( 'No web-research sources have been captured yet.', 'abc-marketing-department' ) );
			View::close();
			return;
		}

		if ( ! empty( $rows ) ) {
			echo '<table class="wp-list-table widefat striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Source', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Used by (run type)', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Workspace', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Date', 'abc-marketing-department' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $rows as $row ) {
				$url   = $row['url'];
				$title = $row['title'];
				echo '<tr>';
				echo '<td>';
				if ( '' !== $url ) {
					echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( '' !== $title ? $title : $url ) . '</a>';
				} else {
					echo esc_html( '' !== $title ? $title : '—' );
				}
				echo '</td>';
				echo '<td>' . esc_html( '' !== $row['run_type'] ? $row['run_type'] : '—' ) . '</td>';
				echo '<td>' . esc_html( $book_title( $row['book_id'] ) ) . '</td>';
				echo '<td>' . esc_html( $row['date'] ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		if ( ! empty( $empty_runs ) ) {
			echo '<h2>' . esc_html__( 'Web-enabled runs with no sources', 'abc-marketing-department' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'These runs had web search switched on but returned no sources. The model may not have performed live research for them.', 'abc-marketing-department' ) . '</p>';
			echo '<table class="wp-list-table widefat striped">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Run type', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Workspace', 'abc-marketing-department' ) . '</th>';
			echo '<th>' . esc_html__( 'Date', 'abc-marketing-department' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $empty_runs as $r ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $r['run_type'] ?? '' ) ) . '</td>';
				echo '<td>' . esc_html( $book_title( (int) ( $r['book_id'] ?? 0 ) ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $r['created_at'] ?? '' ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		View::close();
	}
}
