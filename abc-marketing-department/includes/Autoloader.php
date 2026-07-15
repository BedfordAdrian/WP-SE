<?php
/**
 * PSR-4 style autoloader for the ABCMD namespace.
 *
 * Maps \ABCMD\Sub\ClassName to includes/Sub/ClassName.php.
 *
 * @package ABCMD
 */

namespace ABCMD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight autoloader.
 */
final class Autoloader {

	/**
	 * Root namespace handled by this loader.
	 */
	private const PREFIX = 'ABCMD\\';

	/**
	 * Register the autoloader with SPL.
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Resolve and require a class file.
	 *
	 * @param string $class Fully-qualified class name.
	 */
	public static function load( string $class ): void {
		if ( 0 !== strpos( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$relative = str_replace( '\\', '/', $relative );
		$path     = ABCMD_PLUGIN_DIR . 'includes/' . $relative . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
