<?php
/**
 * Consent records repository — one current record per workspace.
 *
 * @package ABCMD
 */

namespace ABCMD\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD + enforcement helpers for client consent.
 */
final class Consent extends BaseRepository {

	protected function key(): string {
		return 'consent';
	}

	protected function columns(): array {
		return array(
			'book_id'           => '%d',
			'author_id'         => '%d',
			'allow_openai'      => '%d',
			'data_categories'   => '%s',
			'allow_manuscript'  => '%d',
			'allow_sales'       => '%d',
			'allow_personal'    => '%d',
			'allow_web_research'=> '%d',
			'granted_date'      => '%s',
			'method'            => '%s',
			'notes'             => '%s',
			'revoked_date'      => '%s',
			'status'            => '%s',
			'updated_by'        => '%d',
		);
	}

	protected function json_columns(): array {
		return array( 'data_categories' );
	}

	/**
	 * Current consent record for a workspace (or null).
	 *
	 * @param int $book_id Book id.
	 */
	public function for_book( int $book_id ): ?array {
		$rows = $this->where( array( 'book_id' => $book_id ), 'id', 'DESC', 1, 0 );
		return $rows[0] ?? null;
	}

	/**
	 * Whether AI processing is currently permitted for a workspace.
	 * Defaults to NO until an active consent record with allow_openai exists.
	 *
	 * @param int $book_id Book id.
	 */
	public function ai_allowed( int $book_id ): bool {
		$c = $this->for_book( $book_id );
		if ( ! $c ) {
			return false;
		}
		return 'active' === $c['status'] && (int) $c['allow_openai'] === 1;
	}

	/**
	 * Whether a specific data category may be shared with AI under consent.
	 *
	 * @param int    $book_id  Book id.
	 * @param string $category One of: manuscript, sales, personal, web_research.
	 */
	public function category_allowed( int $book_id, string $category ): bool {
		$c = $this->for_book( $book_id );
		if ( ! $c || 'active' !== $c['status'] || (int) $c['allow_openai'] !== 1 ) {
			return false;
		}
		$map = array(
			'manuscript'   => 'allow_manuscript',
			'sales'        => 'allow_sales',
			'personal'     => 'allow_personal',
			'web_research' => 'allow_web_research',
		);
		if ( ! isset( $map[ $category ] ) ) {
			return true; // Non-restricted categories allowed once AI is permitted.
		}
		return (int) $c[ $map[ $category ] ] === 1;
	}
}
