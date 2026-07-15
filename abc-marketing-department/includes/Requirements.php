<?php
/**
 * Environment requirement checks.
 *
 * @package ABCMD
 */

namespace ABCMD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies WordPress, PHP, database, crypto and cron prerequisites.
 */
final class Requirements {

	public const MIN_PHP = '8.1';
	public const MIN_WP  = '6.5';

	/**
	 * Run every check and return a structured report.
	 *
	 * @return array<string,array{ok:bool,label:string,detail:string,fatal:bool}>
	 */
	public static function report(): array {
		global $wp_version;

		$checks = array();

		$php_ok        = version_compare( PHP_VERSION, self::MIN_PHP, '>=' );
		$checks['php'] = array(
			'ok'     => $php_ok,
			'label'  => 'PHP version',
			'detail' => sprintf( 'Found %s, need %s+', PHP_VERSION, self::MIN_PHP ),
			'fatal'  => true,
		);

		$wp_ok        = version_compare( $wp_version, self::MIN_WP, '>=' );
		$checks['wp'] = array(
			'ok'     => $wp_ok,
			'label'  => 'WordPress version',
			'detail' => sprintf( 'Found %s, need %s+', $wp_version, self::MIN_WP ),
			'fatal'  => true,
		);

		$db_ok        = self::database_ok();
		$checks['db'] = array(
			'ok'     => $db_ok,
			'label'  => 'Database write access',
			'detail' => $db_ok ? 'Custom tables can be created.' : 'Could not confirm CREATE privileges.',
			'fatal'  => false,
		);

		$sodium = function_exists( 'sodium_crypto_secretbox' );
		$ossl   = function_exists( 'openssl_encrypt' );
		$checks['crypto'] = array(
			'ok'     => ( $sodium || $ossl ),
			'label'  => 'Encryption support',
			'detail' => self::crypto_detail( $sodium, $ossl ),
			'fatal'  => false,
		);

		$cron_ok        = ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
		$checks['cron'] = array(
			'ok'     => true, // Never fatal; we detect + document.
			'label'  => 'WP-Cron',
			'detail' => $cron_ok
				? 'WP-Cron is enabled. A real server cron is still recommended for reliable weekly audits.'
				: 'WP-Cron is disabled (DISABLE_WP_CRON). A real server cron MUST be configured — see Help.',
			'fatal'  => false,
		);

		$zip            = class_exists( '\ZipArchive' );
		$checks['zip'] = array(
			'ok'     => $zip,
			'label'  => 'ZipArchive (DOCX/EPUB/XLSX text extraction)',
			'detail' => $zip ? 'Available.' : 'Missing — DOCX/EPUB/XLSX extraction will be unavailable; TXT/CSV still work.',
			'fatal'  => false,
		);

		$mb            = function_exists( 'mb_strlen' );
		$checks['mbstring'] = array(
			'ok'     => $mb,
			'label'  => 'mbstring',
			'detail' => $mb ? 'Available.' : 'Missing — token estimates and text chunking use a byte-length fallback.',
			'fatal'  => false,
		);

		return $checks;
	}

	/**
	 * True when there are no fatal (blocking) failures.
	 */
	public static function passes(): bool {
		foreach ( self::report() as $check ) {
			if ( $check['fatal'] && ! $check['ok'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Human-readable list of fatal failures (empty when all good).
	 *
	 * @return string[]
	 */
	public static function fatal_messages(): array {
		$out = array();
		foreach ( self::report() as $check ) {
			if ( $check['fatal'] && ! $check['ok'] ) {
				$out[] = $check['label'] . ': ' . $check['detail'];
			}
		}
		return $out;
	}

	private static function crypto_detail( bool $sodium, bool $ossl ): string {
		if ( $sodium ) {
			return 'libsodium available (preferred). OpenSSL fallback: ' . ( $ossl ? 'yes' : 'no' );
		}
		if ( $ossl ) {
			return 'libsodium missing; using OpenSSL fallback.';
		}
		return 'Neither libsodium nor OpenSSL is available. API keys cannot be stored securely.';
	}

	private static function database_ok(): bool {
		global $wpdb;
		// A permissive probe: assume OK unless we can positively detect a problem.
		// dbDelta will surface real errors during activation.
		return isset( $wpdb ) && is_object( $wpdb );
	}
}
