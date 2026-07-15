<?php
/**
 * Minimal standalone test runner.
 *
 * Usage: php tests/run-tests.php
 * Exit code 0 on success, 1 on any failure. No external dependencies.
 *
 * @package ABCMD
 */

require __DIR__ . '/bootstrap-standalone.php';

$GLOBALS['__abcmd_pass'] = 0;
$GLOBALS['__abcmd_fail'] = 0;
$GLOBALS['__abcmd_msgs'] = array();

/**
 * Assert truthiness.
 */
function ok( bool $cond, string $label ): void {
	if ( $cond ) {
		$GLOBALS['__abcmd_pass']++;
		echo "  \033[32mPASS\033[0m {$label}\n";
	} else {
		$GLOBALS['__abcmd_fail']++;
		$GLOBALS['__abcmd_msgs'][] = $label;
		echo "  \033[31mFAIL\033[0m {$label}\n";
	}
}

/**
 * Assert equality (loose) with a helpful message.
 *
 * @param mixed $expected Expected.
 * @param mixed $actual   Actual.
 */
function eq( $expected, $actual, string $label ): void {
	$cond = $expected === $actual;
	if ( ! $cond && is_float( $expected ) && is_float( $actual ) ) {
		$cond = abs( $expected - $actual ) < 1e-9;
	}
	ok( $cond, $label . ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
}

$tests = glob( __DIR__ . '/unit/test-*.php' );
foreach ( $tests as $file ) {
	echo "\n\033[1m" . basename( $file ) . "\033[0m\n";
	require $file;
}

echo "\n----------------------------------------\n";
echo sprintf( "Passed: %d  Failed: %d\n", $GLOBALS['__abcmd_pass'], $GLOBALS['__abcmd_fail'] );
if ( $GLOBALS['__abcmd_fail'] > 0 ) {
	echo "Failures:\n - " . implode( "\n - ", $GLOBALS['__abcmd_msgs'] ) . "\n";
	exit( 1 );
}
echo "All tests passed.\n";
exit( 0 );
