<?php
/**
 * Standalone test bootstrap.
 *
 * Lets the pure-logic unit tests run without a full WordPress install by
 * defining the minimal constants and stubbing the handful of WordPress
 * functions the tested classes touch. The WP-integration tests
 * (tests/test-integration.php) still require the real WP test suite.
 *
 * @package ABCMD
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ABCMD_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'ABCMD_PLUGIN_URL', 'https://example.test/wp-content/plugins/abc-marketing-department/' );
define( 'ABCMD_PLUGIN_FILE', dirname( __DIR__ ) . '/abc-marketing-department.php' );
define( 'ABCMD_VERSION', '1.0.0' );
define( 'ABCMD_DB_VERSION', '1.0.0' );
define( 'ABCMD_CAP', 'manage_abc_marketing_department' );
define( 'ABCMD_OPT_SETTINGS', 'abcmd_settings' );
define( 'ABCMD_OPT_OPENAI', 'abcmd_openai' );
define( 'ABCMD_OPT_PRICES', 'abcmd_model_prices' );
define( 'ABCMD_OPT_DB_VERSION', 'abcmd_db_version' );
define( 'ABCMD_OPT_PRIVACY', 'abcmd_privacy_template' );
define( 'ABCMD_TIMEZONE', 'Europe/London' );

define( 'ABCMD_CRON_WEEKLY_AUDIT', 'abcmd_weekly_audit' );
define( 'ABCMD_CRON_WEEKLY_EMAIL', 'abcmd_weekly_email' );

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WEEK_IN_SECONDS', 604800 );
}

// WordPress result-type constants.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'ARRAY_N', 'ARRAY_N' );
	define( 'OBJECT', 'OBJECT' );
	define( 'OBJECT_K', 'OBJECT_K' );
}

// --- In-memory options store ------------------------------------------------
$GLOBALS['__abcmd_options'] = array();

function get_option( $key, $default = false ) {
	return $GLOBALS['__abcmd_options'][ $key ] ?? $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__abcmd_options'][ $key ] = $value;
	return true;
}
function add_option( $key, $value = '', $deprecated = '', $autoload = null ) {
	if ( ! isset( $GLOBALS['__abcmd_options'][ $key ] ) ) {
		$GLOBALS['__abcmd_options'][ $key ] = $value;
	}
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['__abcmd_options'][ $key ] );
	return true;
}

// --- Assorted WP function stubs --------------------------------------------
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}
function apply_filters( $tag, $value, ...$args ) {
	return $value;
}
function do_action( $tag, ...$args ) {}
function add_filter( ...$a ) { return true; }
function add_action( ...$a ) { return true; }
function wp_salt( $scheme = 'auth' ) {
	// Deterministic per-scheme salt for reproducible tests.
	return 'test-salt-' . $scheme . '-0123456789abcdef0123456789abcdef';
}
function __( $text, $domain = 'default' ) { return $text; }
function esc_html__( $text, $domain = 'default' ) { return $text; }
function sanitize_key( $key ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
}
function sanitize_text_field( $str ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $str ) ) );
}
function wp_strip_all_tags( $string, $remove_breaks = false ) {
	$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string );
	$string = strip_tags( $string );
	return trim( $string );
}
function wp_trim_words( $text, $num_words = 55, $more = '…' ) {
	$words = preg_split( '/\s+/', trim( (string) $text ) );
	if ( count( $words ) <= $num_words ) {
		return trim( (string) $text );
	}
	return implode( ' ', array_slice( $words, 0, $num_words ) ) . $more;
}
function current_time( $type = 'mysql' ) {
	return 'Y-m-d' === $type ? gmdate( 'Y-m-d' ) : gmdate( 'Y-m-d H:i:s' );
}
function absint( $v ) { return abs( (int) $v ); }
function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
}
function get_current_user_id() { return 1; }

// --- Real autoloader --------------------------------------------------------
require_once ABCMD_PLUGIN_DIR . 'includes/Autoloader.php';
\ABCMD\Autoloader::register();
