<?php
/**
 * AI JSON extraction, threshold merging, data-sharing and mapping tests.
 *
 * @package ABCMD
 */

use ABCMD\AI\DataSharing;
use ABCMD\AI\Runner;
use ABCMD\Import\Templates;
use ABCMD\Repository\Books;
use ABCMD\Support\Helpers;

// --- Runner::extract_json ---
$text = "Here is my analysis.\n\n```json\n{\"recommendations\":[{\"title\":\"Run a BookBub deal\"}]}\n```\nThanks.";
$data = Runner::extract_json( $text );
ok( is_array( $data ) && isset( $data['recommendations'][0]['title'] ), 'Extracts fenced JSON block' );
eq( 'Run a BookBub deal', $data['recommendations'][0]['title'], 'Parsed recommendation title matches' );

eq( null, Runner::extract_json( 'no json here at all' ), 'Returns null when no JSON present' );

$bare = 'Prose then {"tasks":[{"title":"Email list"}]} end';
$bare_data = Runner::extract_json( $bare );
ok( is_array( $bare_data ) && isset( $bare_data['tasks'] ), 'Falls back to bare object extraction' );

// --- Books::thresholds merge ---
$defaults = Books::thresholds( array() );
eq( 200.0, $defaults['sales_spike_pct'], 'Default sales spike threshold' );
$overridden = Books::thresholds( array( 'anomaly_thresholds' => array( 'sales_spike_pct' => 150 ) ) );
eq( 150.0, $overridden['sales_spike_pct'], 'Per-workspace override applies' );
eq( 60.0, $overridden['sales_collapse_pct'], 'Non-overridden defaults remain' );

// --- DataSharing categories shape ---
$cats = DataSharing::categories();
ok( isset( $cats['full_manuscript'] ) && 'manuscript' === $cats['full_manuscript']['consent'], 'Full manuscript is gated by manuscript consent' );
ok( true === $cats['full_manuscript']['sensitive'], 'Full manuscript flagged sensitive' );
ok( null === $cats['book_profile']['consent'], 'Book profile is not consent-gated' );

// --- Templates::suggest_mapping ---
$headers = array( 'Reporting Period', 'Title', 'Format', 'Market', 'Net Qty', 'Pub Comp' );
$map     = Templates::suggest_mapping( 'ingramspark', $headers );
eq( 'Reporting Period', $map['date'], 'Auto-maps date from alias' );
eq( 'Net Qty', $map['units'], 'Auto-maps units from alias' );
eq( 'Pub Comp', $map['net_income'], 'Auto-maps net income from alias' );

// --- Helpers ---
eq( 3, Helpers::estimate_tokens( 'abcdefghijkl' ), '12 chars ~= 3 tokens' );
eq( 1234.57, Helpers::money( '£1,234.567' ), 'money() strips symbols and rounds to 2dp' );
$list = Helpers::split_list( "a, b\nc  d" );
eq( array( 'a', 'b', 'c', 'd' ), $list, 'split_list handles commas, newlines and spaces' );
