<?php
/**
 * Typed accessors for plugin settings stored in wp_options.
 *
 * @package ABCMD
 */

namespace ABCMD\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central configuration accessor with sane defaults.
 */
final class Options {

	/**
	 * General plugin settings with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		$defaults = array(
			'currency'                 => 'GBP',
			'timezone'                 => ABCMD_TIMEZONE,
			'weekly_audit_enabled'     => 1,
			'weekly_audit_weekday'     => 1,   // Monday.
			'weekly_audit_hour'        => 0,   // 00:00 Europe/London.
			'weekly_email_hour'        => 9,   // 09:00 Europe/London.
			'weekly_email_recipients'  => '',  // Comma separated; empty = site admin.
			'ai_monthly_budget'        => 50,  // Warning threshold in account currency.
			'ai_cost_confirm_threshold'=> 1.0, // Require confirmation above this per-run cost.
			'ai_rate_limit_per_hour'   => 60,
			'uninstall_purge'          => 0,   // Never purge on uninstall unless explicitly enabled.
			'setup_complete'           => 0,
		);

		$saved = get_option( ABCMD_OPT_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( $defaults, $saved );
	}

	/**
	 * Read one general setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$settings = self::settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Persist a partial settings update (merged over existing values).
	 *
	 * @param array<string,mixed> $patch Values to change.
	 */
	public static function update( array $patch ): void {
		$current = get_option( ABCMD_OPT_SETTINGS, array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		update_option( ABCMD_OPT_SETTINGS, array_merge( $current, $patch ), false );
	}

	/**
	 * Currency code used for reporting (ISO 4217).
	 */
	public static function currency(): string {
		$c = strtoupper( (string) self::get( 'currency', 'GBP' ) );
		return preg_match( '/^[A-Z]{3}$/', $c ) ? $c : 'GBP';
	}

	/**
	 * Currency symbol for display.
	 */
	public static function currency_symbol(): string {
		$map = array(
			'GBP' => '£',
			'USD' => '$',
			'EUR' => '€',
			'CAD' => 'CA$',
			'AUD' => 'A$',
		);
		$code = self::currency();
		return $map[ $code ] ?? ( $code . ' ' );
	}
}
