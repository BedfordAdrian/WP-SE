<?php
/**
 * AI cost calculation tests.
 *
 * @package ABCMD
 */

use ABCMD\AI\Cost;

// Seed a known price table.
update_option( ABCMD_OPT_PRICES, array(
	'currency'          => 'USD',
	'web_search_per_1k' => 10.0,
	'models'            => array(
		'test-model' => array( 'in' => 2.00, 'out' => 10.00 ),
	),
) );

$calc = Cost::calculate( 'test-model', 1_000_000, 1_000_000, 0 );
eq( 12.0, round( $calc['cost'], 6 ), '1M in + 1M out at 2/10 = 12.00' );
eq( 'USD', $calc['currency'], 'Currency propagates from price table' );

$calc2 = Cost::calculate( 'test-model', 500_000, 200_000, 1 );
// 0.5*2 + 0.2*10 + (1/1000)*10 = 1 + 2 + 0.01 = 3.01
eq( 3.01, round( $calc2['cost'], 6 ), 'Partial tokens + one web call = 3.01' );

$unknown = Cost::calculate( 'no-such-model', 1_000_000, 1_000_000, 0 );
eq( 0.0, round( $unknown['cost'], 6 ), 'Unknown model prices at 0 (no crash)' );

$est = Cost::estimate( 'test-model', 1000, 1000, false );
ok( $est['cost'] > 0, 'Estimate returns a positive cost for known model' );

ok( in_array( 'test-model', Cost::known_models(), true ), 'known_models lists configured models' );
