<?php
/**
 * Versioned database migrations.
 *
 * dbDelta handles additive column/table changes idempotently. This migrator
 * records the installed schema version and runs any non-additive steps in order.
 *
 * @package ABCMD
 */

namespace ABCMD\Database;

use ABCMD\Support\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs schema creation and version-gated migration steps.
 */
final class Migrator {

	/**
	 * Ordered migration steps. Keys are target versions; callbacks run when the
	 * installed version is lower. dbDelta already applies the current schema, so
	 * these are for data backfills / non-additive changes only.
	 *
	 * @return array<string,callable>
	 */
	private static function steps(): array {
		return array(
			// '1.1.0' => static function (): void { /* future migration */ },
		);
	}

	/**
	 * Bring the database up to the current version. Safe to call repeatedly.
	 *
	 * @return array{from:string,to:string,ran:string[],errors:string[]}
	 */
	public static function run(): array {
		$installed = (string) get_option( ABCMD_OPT_DB_VERSION, '0' );
		$errors    = array();
		$ran       = array();

		// 1) Apply current schema (idempotent, additive).
		try {
			Schema::create_all();
		} catch ( \Throwable $e ) {
			$errors[] = 'Schema creation failed: ' . $e->getMessage();
		}

		// 2) Run ordered non-additive steps for versions newer than installed.
		foreach ( self::steps() as $version => $callback ) {
			if ( version_compare( $installed, $version, '<' ) ) {
				try {
					$callback();
					$ran[] = $version;
				} catch ( \Throwable $e ) {
					$errors[] = "Migration {$version} failed: " . $e->getMessage();
				}
			}
		}

		// 3) Record new version.
		update_option( ABCMD_OPT_DB_VERSION, ABCMD_DB_VERSION, false );

		if ( $errors && class_exists( '\ABCMD\Support\Audit' ) ) {
			Audit::log( 'migration.error', 'Database migration reported errors.', array( 'errors' => $errors ) );
		}

		return array(
			'from'   => $installed,
			'to'     => ABCMD_DB_VERSION,
			'ran'    => $ran,
			'errors' => $errors,
		);
	}

	/**
	 * True when the stored schema version matches the plugin's target.
	 */
	public static function is_current(): bool {
		return version_compare( (string) get_option( ABCMD_OPT_DB_VERSION, '0' ), ABCMD_DB_VERSION, '>=' );
	}
}
