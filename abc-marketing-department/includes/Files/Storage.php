<?php
/**
 * Protected file storage for manuscripts and campaign assets.
 *
 * Files live under wp-content/uploads/abcmd-secure/ with .htaccess + index.php +
 * web.config guards so they are not directly web-accessible. Downloads are only
 * ever served through capability-checked admin handlers.
 *
 * @package ABCMD
 */

namespace ABCMD\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage location management and hardening.
 */
final class Storage {

	private const DIRNAME = 'abcmd-secure';

	/**
	 * Absolute path to the protected directory (no trailing slash).
	 */
	public static function dir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::DIRNAME;
	}

	/**
	 * Ensure the protected directory exists and is hardened. Returns true on ok.
	 */
	public static function ensure_protected_dir(): bool {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Deny direct web access (Apache).
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "# Marketing Department: deny direct access to client files.\n"
				. "Require all denied\n"
				. "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
			@file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// Deny direct web access (IIS).
		$webconfig = trailingslashit( $dir ) . 'web.config';
		if ( ! file_exists( $webconfig ) ) {
			$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n";
			@file_put_contents( $webconfig, $xml ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// Directory listing guard.
		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		return true;
	}

	/**
	 * Build a collision-resistant, safe stored filename.
	 *
	 * @param string $original Original client filename.
	 */
	public static function safe_name( string $original ): string {
		$ext  = strtolower( pathinfo( $original, PATHINFO_EXTENSION ) );
		$base = sanitize_file_name( pathinfo( $original, PATHINFO_FILENAME ) );
		$base = substr( $base, 0, 60 );
		$rand = substr( wp_generate_password( 12, false, false ), 0, 8 );
		$stamp = gmdate( 'Ymd-His' );
		return $base . '-' . $stamp . '-' . $rand . ( $ext ? ( '.' . preg_replace( '/[^a-z0-9]/', '', $ext ) ) : '' );
	}
}
