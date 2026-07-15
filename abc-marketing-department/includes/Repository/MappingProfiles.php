<?php
/**
 * Saved CSV mapping profiles repository.
 *
 * @package ABCMD
 */

namespace ABCMD\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for reusable column-mapping profiles.
 */
final class MappingProfiles extends BaseRepository {

	protected function key(): string {
		return 'mapping_profiles';
	}

	protected function columns(): array {
		return array(
			'name'         => '%s',
			'template'     => '%s',
			'mapping'      => '%s',
			'date_format'  => '%s',
			'currency'     => '%s',
			'format_map'   => '%s',
			'retailer_map' => '%s',
			'user_id'      => '%d',
		);
	}

	protected function json_columns(): array {
		return array( 'mapping', 'format_map', 'retailer_map' );
	}

	/**
	 * Profiles for a given template.
	 *
	 * @param string $template Template key.
	 * @return array<int,array<string,mixed>>
	 */
	public function for_template( string $template ): array {
		return $this->all( array( 'template' => $template ), 'name', 'ASC' );
	}
}
