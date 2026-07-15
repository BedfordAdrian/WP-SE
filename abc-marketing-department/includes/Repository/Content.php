<?php
/**
 * Content drafts repository.
 *
 * @package ABCMD
 */

namespace ABCMD\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for content drafts / calendar items.
 */
final class Content extends BaseRepository {

	protected function key(): string {
		return 'content';
	}

	protected function columns(): array {
		return array(
			'book_id'          => '%d',
			'author_id'        => '%d',
			'campaign'         => '%s',
			'channel'          => '%s',
			'format'           => '%s',
			'title'            => '%s',
			'copy'             => '%s',
			'caption'          => '%s',
			'hashtags'         => '%s',
			'script'           => '%s',
			'design_direction' => '%s',
			'image_prompt'     => '%s',
			'cta'              => '%s',
			'destination_link' => '%s',
			'tracking_link'    => '%s',
			'publish_date'     => '%s',
			'objective'        => '%s',
			'approval_status'  => '%s',
			'export_status'    => '%s',
			'version_history'  => '%s',
			'edit_history'     => '%s',
			'ai_run_id'        => '%d',
		);
	}

	protected function json_columns(): array {
		return array( 'version_history', 'edit_history' );
	}

	/**
	 * Calendar items within a date range (by publish_date).
	 *
	 * @param string   $from    YYYY-MM-DD.
	 * @param string   $to      YYYY-MM-DD.
	 * @param int|null $book_id Optional workspace filter.
	 * @return array<int,array<string,mixed>>
	 */
	public function calendar( string $from, string $to, ?int $book_id = null ): array {
		global $wpdb;
		$sql    = 'SELECT * FROM ' . $this->table() . ' WHERE publish_date IS NOT NULL AND publish_date BETWEEN %s AND %s';
		$params = array( $from . ' 00:00:00', $to . ' 23:59:59' );
		if ( $book_id ) {
			$sql     .= ' AND book_id = %d';
			$params[] = $book_id;
		}
		$sql .= ' ORDER BY publish_date ASC';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return array_map( array( $this, 'decode_row' ), (array) $rows );
	}
}
