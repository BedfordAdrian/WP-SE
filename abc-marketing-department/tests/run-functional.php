<?php
/**
 * Functional tests against the in-memory fake $wpdb.
 *
 * Exercises real integration paths: CSV import round-trip + de-duplication,
 * manual adjustment, consent enforcement, task dependency gating and CSV export
 * formatting. Correctness of SQL aggregates is out of scope here (covered on a
 * real DB by the WP-integration suite); these verify the surrounding logic.
 *
 * Usage: php tests/run-functional.php
 *
 * @package ABCMD
 */

require __DIR__ . '/bootstrap-wp-fake.php';

$GLOBALS['__abcmd_pass'] = 0;
$GLOBALS['__abcmd_fail'] = 0;
$GLOBALS['__abcmd_msgs'] = array();

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
function eq( $e, $a, string $label ): void {
	ok( $e === $a, $label . ' (expected ' . var_export( $e, true ) . ', got ' . var_export( $a, true ) . ')' );
}

use ABCMD\Import\CsvImporter;
use ABCMD\Repository\Books;
use ABCMD\Repository\Consent;
use ABCMD\Repository\Imports;
use ABCMD\Repository\Metrics;
use ABCMD\Repository\Tasks;
use ABCMD\Export\CsvExport;

// --- Set up a workspace -----------------------------------------------------
$books   = new Books();
$book_id = $books->insert( array( 'title' => 'Test Book', 'author_id' => 1, 'currency' => 'GBP', 'status' => 'active' ) );
ok( $book_id > 0, 'Book inserted, got id' );
$found = $books->find( $book_id );
ok( $found && 'Test Book' === $found['title'], 'Book round-trips through find()' );

// --- CSV import round-trip --------------------------------------------------
echo "\n\033[1mCSV import\033[0m\n";
$csv = "Date,ID,Format,Units,Revenue\n2026-07-13,A1,ebook,10,49.90\n2026-07-13,A2,paperback,4,51.96\nbad,A3,ebook,,\n";
$tmp = tempnam( sys_get_temp_dir(), 'abcmd' );
file_put_contents( $tmp, $csv );

$mapping = array(
	'date'       => 'Date',
	'identifier' => 'ID',
	'format'     => 'Format',
	'units'      => 'Units',
	'revenue'    => 'Revenue',
);
$res = CsvImporter::import( array(
	'book_id'  => $book_id,
	'template' => 'generic_sales',
	'path'     => $tmp,
	'mapping'  => $mapping,
	'currency' => 'GBP',
	'source_status' => 'live',
	'original_filename' => 'test.csv',
) );

ok( $res['ok'], 'Import reports ok' );
eq( 2, $res['imported'], 'Two valid rows imported' );
eq( 1, $res['rejected'], 'One row rejected (no date/values)' );

$metrics = new Metrics();
$rows    = $metrics->where( array( 'book_id' => $book_id ) );
ok( count( $rows ) >= 4, 'At least 4 metric rows created (2 sales + 2 revenue)' );

// --- De-duplication on re-import (same file) --------------------------------
$res2 = CsvImporter::import( array(
	'book_id'  => $book_id,
	'template' => 'generic_sales',
	'path'     => $tmp,
	'mapping'  => $mapping,
	'currency' => 'GBP',
	'source_status' => 'live',
	'original_filename' => 'test.csv',
	'confirm_duplicate' => true, // bypass whole-file guard to test ROW dedup
) );
eq( 0, $res2['imported'], 'Re-import imports zero new rows (row-level dedup)' );
ok( $res2['duplicate'] >= 2, 'Re-import counts rows as duplicate' );
@unlink( $tmp );

// --- Whole-file duplicate guard --------------------------------------------
$tmp2 = tempnam( sys_get_temp_dir(), 'abcmd' );
file_put_contents( $tmp2, $csv );
$res3 = CsvImporter::import( array(
	'book_id'  => $book_id,
	'template' => 'generic_sales',
	'path'     => $tmp2,
	'mapping'  => $mapping,
	'original_filename' => 'test.csv',
) );
ok( ! $res3['ok'] && -1 === $res3['duplicate'], 'Whole-file duplicate blocked without confirmation' );
@unlink( $tmp2 );

// --- Manual adjustment ------------------------------------------------------
echo "\n\033[1mManual adjustment\033[0m\n";
$adj_id = CsvImporter::manual_adjustment( array(
	'book_id'    => $book_id,
	'metric_key' => 'sales',
	'value'      => 25,
	'date'       => '2026-07-14',
	'note'       => 'Correction',
) );
ok( $adj_id > 0, 'Manual adjustment inserts a metric row' );
$adj = $metrics->find( $adj_id );
ok( $adj && 1 === (int) $adj['is_adjustment'], 'Adjustment row flagged is_adjustment' );

// --- Consent enforcement ----------------------------------------------------
echo "\n\033[1mConsent enforcement\033[0m\n";
$consent = new Consent();
ok( ! $consent->ai_allowed( $book_id ), 'AI blocked with no consent record (default deny)' );

$consent->insert( array(
	'book_id'       => $book_id,
	'author_id'     => 1,
	'allow_openai'  => 1,
	'allow_sales'   => 1,
	'allow_manuscript' => 0,
	'status'        => 'active',
) );
ok( $consent->ai_allowed( $book_id ), 'AI allowed once active consent grants it' );
ok( $consent->category_allowed( $book_id, 'sales' ), 'Sales category allowed under consent' );
ok( ! $consent->category_allowed( $book_id, 'manuscript' ), 'Manuscript category still blocked' );

use ABCMD\AI\DataSharing;
$enforced = DataSharing::enforce( $book_id, array( 'book_profile', 'sales_data', 'full_manuscript' ) );
ok( in_array( 'sales_data', $enforced['allowed'], true ), 'Sales data survives enforcement' );
ok( in_array( 'full_manuscript', $enforced['blocked'], true ), 'Full manuscript is blocked by enforcement' );

// --- Task dependency gating -------------------------------------------------
echo "\n\033[1mTask dependencies\033[0m\n";
$tasks = new Tasks();
$dep1  = $tasks->insert( array( 'book_id' => $book_id, 'title' => 'Prereq', 'status' => 'draft' ) );
$main  = $tasks->insert( array( 'book_id' => $book_id, 'title' => 'Dependent', 'status' => 'draft', 'dependencies' => array( $dep1 ) ) );
$main_task = $tasks->find( $main );
ok( ! $tasks->dependencies_met( $main_task ), 'Dependent task blocked while prereq incomplete' );
$tasks->update( $dep1, array( 'status' => 'completed' ) );
$main_task = $tasks->find( $main );
ok( $tasks->dependencies_met( $main_task ), 'Dependent task ready once prereq completed' );

// --- CSV export formatting --------------------------------------------------
echo "\n\033[1mCSV export\033[0m\n";
$out = CsvExport::to_csv( array( 'a', 'b' ), array( array( 'a' => '1', 'b' => 'x,y' ) ) );
ok( str_contains( $out, 'a,b' ) && str_contains( $out, '"x,y"' ), 'CSV encodes header and quotes embedded commas' );

// --- Summary ----------------------------------------------------------------
echo "\n----------------------------------------\n";
echo sprintf( "Passed: %d  Failed: %d\n", $GLOBALS['__abcmd_pass'], $GLOBALS['__abcmd_fail'] );
if ( $GLOBALS['__abcmd_fail'] > 0 ) {
	echo "Failures:\n - " . implode( "\n - ", $GLOBALS['__abcmd_msgs'] ) . "\n";
	exit( 1 );
}
echo "All functional tests passed.\n";
exit( 0 );
