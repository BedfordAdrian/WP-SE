<?php
/**
 * Shared helper functions: security guards, formatting, small utilities.
 *
 * @package ABCMD
 */

namespace ABCMD\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless helper methods used across the plugin.
 */
final class Helpers {

	/**
	 * Require the plugin capability or die with a 403.
	 */
	public static function require_cap(): void {
		if ( ! current_user_can( ABCMD_CAP ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access Marketing Department.', 'abc-marketing-department' ),
				esc_html__( 'Permission denied', 'abc-marketing-department' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * True when the current user may use the plugin.
	 */
	public static function can(): bool {
		return current_user_can( ABCMD_CAP );
	}

	/**
	 * Verify a nonce for an admin-post/form action or die.
	 *
	 * @param string $action Nonce action.
	 * @param string $field  Request field holding the nonce.
	 */
	public static function verify_nonce( string $action, string $field = '_abcmd_nonce' ): void {
		self::require_cap();
		$nonce = isset( $_REQUEST[ $field ] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST[ $field ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die(
				esc_html__( 'Security check failed. Please reload the page and try again.', 'abc-marketing-department' ),
				esc_html__( 'Security check failed', 'abc-marketing-department' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Sanitise a decimal money value to a float with 2dp precision retained.
	 *
	 * @param mixed $value Raw input.
	 */
	public static function money( $value ): float {
		if ( is_string( $value ) ) {
			$value = preg_replace( '/[^0-9.\-]/', '', $value );
		}
		return round( (float) $value, 2 );
	}

	/**
	 * Format a money value for display using the configured currency symbol.
	 *
	 * @param float|int|string $value    Amount.
	 * @param string|null      $currency Optional ISO code override.
	 */
	public static function money_fmt( $value, ?string $currency = null ): string {
		$symbol = $currency ? self::symbol_for( $currency ) : Options::currency_symbol();
		return $symbol . number_format( (float) $value, 2 );
	}

	/**
	 * Symbol for a given ISO currency code.
	 */
	public static function symbol_for( string $code ): string {
		$map = array(
			'GBP' => '£',
			'USD' => '$',
			'EUR' => '€',
			'CAD' => 'CA$',
			'AUD' => 'A$',
		);
		$code = strtoupper( $code );
		return $map[ $code ] ?? ( $code . ' ' );
	}

	/**
	 * Current time in the configured plugin timezone as a DateTimeImmutable.
	 */
	public static function now(): \DateTimeImmutable {
		try {
			$tz = new \DateTimeZone( (string) Options::get( 'timezone', ABCMD_TIMEZONE ) );
		} catch ( \Exception $e ) {
			$tz = new \DateTimeZone( 'UTC' );
		}
		return new \DateTimeImmutable( 'now', $tz );
	}

	/**
	 * MySQL datetime (UTC) for "now".
	 */
	public static function now_mysql(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Encode a value as JSON for storage, never throwing.
	 *
	 * @param mixed $value Any serialisable value.
	 */
	public static function json( $value ): string {
		$json = wp_json_encode( $value );
		return false === $json ? '{}' : $json;
	}

	/**
	 * Decode a JSON string to an array, tolerant of nulls.
	 *
	 * @param string|null $json Raw JSON.
	 * @return array<mixed>
	 */
	public static function unjson( ?string $json ): array {
		if ( null === $json || '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Rough token estimate for a string (~4 characters per token).
	 *
	 * @param string $text Input text.
	 */
	public static function estimate_tokens( string $text ): int {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		return (int) ceil( $len / 4 );
	}

	/**
	 * Build an internal admin URL for a plugin page.
	 *
	 * @param string              $page Page slug (e.g. abcmd-books).
	 * @param array<string,mixed> $args Extra query args.
	 */
	public static function admin_url( string $page, array $args = array() ): string {
		$args = array_merge( array( 'page' => $page ), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Sanitise a comma/space separated list of handles or emails.
	 *
	 * @param string $raw Raw input.
	 * @return string[]
	 */
	public static function split_list( string $raw ): array {
		$parts = preg_split( '/[\s,]+/', trim( $raw ) ) ?: array();
		return array_values( array_filter( array_map( 'trim', $parts ) ) );
	}
}
