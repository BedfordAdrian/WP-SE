<?php
/**
 * Extended fake-WordPress bootstrap for render smoke tests.
 *
 * Adds admin/escaping stubs and a small in-memory $wpdb so page renderers and
 * repositories can be exercised end-to-end without a real WordPress install.
 * This is a developer smoke test, not a substitute for the WP-integration suite.
 *
 * @package ABCMD
 */

require_once __DIR__ . '/bootstrap-standalone.php';

// --- Escaping / i18n --------------------------------------------------------
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url_raw( $t ) { return (string) $t; }
function esc_attr__( $t, $d = 'default' ) { return esc_attr( $t ); }
function esc_html_e( $t, $d = 'default' ) { echo esc_html( $t ); }
function esc_attr_e( $t, $d = 'default' ) { echo esc_attr( $t ); }
function _e( $t, $d = 'default' ) { echo $t; }
function _n( $s, $p, $n, $d = 'default' ) { return 1 === $n ? $s : $p; }
function wp_kses_post( $t ) { return (string) $t; }
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $c ); }
function esc_html_x( $t, $c, $d = 'default' ) { return esc_html( $t ); }

// --- Form helpers -----------------------------------------------------------
function checked( $a, $b = true, $echo = true ) {
	$r = ( (string) $a === (string) $b ) ? ' checked="checked"' : '';
	if ( $echo ) { echo $r; }
	return $r;
}
function selected( $a, $b = true, $echo = true ) {
	$r = ( (string) $a === (string) $b ) ? ' selected="selected"' : '';
	if ( $echo ) { echo $r; }
	return $r;
}
function wp_nonce_field( $a = -1, $n = '_wpnonce', $ref = true, $echo = true ) {
	$f = '<input type="hidden" name="' . esc_attr( $n ) . '" value="testnonce" />';
	if ( $echo ) { echo $f; }
	return $f;
}
function wp_create_nonce( $a = -1 ) { return 'testnonce'; }
function wp_nonce_url( $url, $a = -1, $n = '_wpnonce' ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $n . '=testnonce'; }
function wp_verify_nonce( $n, $a = -1 ) { return 1; }

// --- URLs -------------------------------------------------------------------
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function home_url( $path = '' ) { return 'https://example.test/' . ltrim( (string) $path, '/' ); }
function add_query_arg( $args, $url = '' ) {
	if ( ! is_array( $args ) ) { $args = array( $args => $url ); $url = ''; }
	$base = $url ?: 'https://example.test/wp-admin/admin.php';
	$sep  = str_contains( $base, '?' ) ? '&' : '?';
	return $base . $sep . http_build_query( $args );
}
function wp_parse_url( $url, $c = -1 ) { return parse_url( (string) $url, $c ); }
function get_bloginfo( $k = '' ) { return 'Test Site'; }

// --- Users / caps -----------------------------------------------------------
function current_user_can( $cap ) { return true; }
function wp_get_current_user() {
	return (object) array( 'ID' => 1, 'user_login' => 'tester', 'user_email' => 'test@example.test', 'exists' => fn() => true );
}

// --- Transients -------------------------------------------------------------
$GLOBALS['__abcmd_transients'] = array();
function get_transient( $k ) { return $GLOBALS['__abcmd_transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['__abcmd_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__abcmd_transients'][ $k ] ); return true; }

// --- Misc -------------------------------------------------------------------
function size_format( $bytes, $decimals = 0 ) { return number_format( (float) $bytes / 1024, $decimals ) . ' KB'; }
function wp_date( $format, $ts = null, $tz = null ) { return gmdate( $format, $ts ?? time() ); }
function date_i18n( $format, $ts = null ) { return gmdate( $format, $ts ?? time() ); }
function wp_next_scheduled( $hook ) { return 0; }
function sanitize_email( $e ) { return filter_var( (string) $e, FILTER_SANITIZE_EMAIL ) ?: ''; }
function is_email( $e ) { return (bool) filter_var( (string) $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_file_name( $f ) { return preg_replace( '/[^A-Za-z0-9._\-]/', '-', (string) $f ); }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function absint_or( $v ) { return abs( (int) $v ); }

// --- Fake $wpdb -------------------------------------------------------------
require_once __DIR__ . '/FakeWpdb.php';
$GLOBALS['wpdb'] = new \ABCMD\Tests\FakeWpdb();
