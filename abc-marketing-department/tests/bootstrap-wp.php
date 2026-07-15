<?php
/**
 * PHPUnit bootstrap for the WordPress-integration suite.
 *
 * Requires the WordPress test library. Set WP_TESTS_DIR (or use
 * wp-phpunit/wp-phpunit via Composer). See docs/STAGING-CHECKLIST.md.
 *
 * @package ABCMD
 */

$tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $tests_dir ) {
	$tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found at {$tests_dir}.\n" .
		"Set WP_TESTS_DIR or install via bin/install-wp-tests.sh.\n" );
	exit( 1 );
}

require_once $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/abc-marketing-department.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
