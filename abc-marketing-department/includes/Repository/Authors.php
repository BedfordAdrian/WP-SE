<?php
/**
 * Authors repository.
 *
 * @package ABCMD
 */

namespace ABCMD\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD + helpers for author records.
 */
final class Authors extends BaseRepository {

	protected function key(): string {
		return 'authors';
	}

	protected function columns(): array {
		return array(
			'name'           => '%s',
			'pen_name'       => '%s',
			'email'          => '%s',
			'website'        => '%s',
			'biography'      => '%s',
			'social_profiles'=> '%s',
			'email_platform' => '%s',
			'notes'          => '%s',
			'consent_status' => '%s',
			'status'         => '%s',
		);
	}

	protected function json_columns(): array {
		return array( 'social_profiles', 'email_platform' );
	}

	/**
	 * Display name (pen name preferred, falling back to legal name).
	 *
	 * @param array<string,mixed> $author Author row.
	 */
	public static function display_name( array $author ): string {
		$pen = trim( (string) ( $author['pen_name'] ?? '' ) );
		return '' !== $pen ? $pen : (string) ( $author['name'] ?? '' );
	}

	/**
	 * Options list for select dropdowns (id => display name).
	 *
	 * @return array<int,string>
	 */
	public function options(): array {
		$out = array();
		foreach ( $this->all( array(), 'name', 'ASC' ) as $a ) {
			$out[ (int) $a['id'] ] = self::display_name( $a );
		}
		return $out;
	}
}
