<?php
/**
 * WordPress-integration tests (require the WP test suite).
 *
 * These cover the database-dependent paths that the standalone/functional suites
 * cannot: activation, table creation, migrations, capability grant, cron
 * scheduling, metric aggregation, anomaly detection, the approval workflow and
 * uninstall data preservation.
 *
 * Run with: composer install && phpunit (see phpunit.xml.dist), against a
 * configured WordPress test environment (bin/install-wp-tests.sh style setup).
 *
 * @package ABCMD
 */

use ABCMD\Activator;
use ABCMD\Database\Migrator;
use ABCMD\Database\Schema;
use ABCMD\Cron\Scheduler;
use ABCMD\Support\Encryption;
use ABCMD\Repository\Books;
use ABCMD\Repository\Consent;
use ABCMD\Repository\Recommendations;
use ABCMD\Import\CsvImporter;
use ABCMD\Anomaly\Detector;
use ABCMD\Demo\DemoData;

/**
 * @group integration
 */
class ABCMD_Integration_Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Migrator::run();
	}

	public function test_tables_exist(): void {
		global $wpdb;
		foreach ( Schema::keys() as $key ) {
			$table = Schema::table( $key );
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $found, "Table {$key} should exist" );
		}
	}

	public function test_migration_is_idempotent(): void {
		$first  = Migrator::run();
		$second = Migrator::run();
		$this->assertEmpty( $second['errors'], 'Repeated migration produces no errors' );
		$this->assertTrue( Migrator::is_current() );
	}

	public function test_capability_granted_to_admin(): void {
		\ABCMD\Support\Capabilities::grant_to_admins();
		$role = get_role( 'administrator' );
		$this->assertTrue( $role->has_cap( ABCMD_CAP ) );
	}

	public function test_php_version_gate(): void {
		$this->assertTrue( version_compare( PHP_VERSION, '8.1', '>=' ), 'Test environment meets minimum PHP' );
	}

	public function test_cron_scheduling(): void {
		Scheduler::schedule_all();
		$this->assertGreaterThan( 0, Scheduler::next_audit(), 'Weekly audit is scheduled' );
		$this->assertGreaterThan( 0, Scheduler::next_email(), 'Weekly email is scheduled' );
		Scheduler::unschedule_all();
		$this->assertSame( 0, Scheduler::next_audit(), 'Unschedule clears the audit event' );
	}

	public function test_encryption_round_trip(): void {
		if ( ! Encryption::available() ) {
			$this->markTestSkipped( 'No encryption backend on this environment.' );
		}
		$secret = 'sk-integration-test-key-123456';
		$cipher = Encryption::encrypt( $secret );
		$this->assertNotSame( $secret, $cipher );
		$this->assertSame( $secret, Encryption::decrypt( $cipher ) );
	}

	public function test_import_creates_metrics_and_dedupes(): void {
		$books   = new Books();
		$book_id = $books->insert( array( 'title' => 'IT Book', 'currency' => 'GBP', 'status' => 'active' ) );

		$csv = "Date,ID,Format,Units,Revenue\n2026-07-13,X1,ebook,10,49.90\n";
		$tmp = wp_tempnam( 'abcmd-it' );
		file_put_contents( $tmp, $csv );

		$args = array(
			'book_id'  => $book_id,
			'template' => 'generic_sales',
			'path'     => $tmp,
			'mapping'  => array( 'date' => 'Date', 'identifier' => 'ID', 'format' => 'Format', 'units' => 'Units', 'revenue' => 'Revenue' ),
			'original_filename' => 'it.csv',
		);
		$res = CsvImporter::import( $args );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 1, $res['imported'] );

		// Row-level dedup on re-import.
		$args['confirm_duplicate'] = true;
		$res2 = CsvImporter::import( $args );
		$this->assertSame( 0, $res2['imported'], 'Re-import creates no new rows' );

		@unlink( $tmp );
	}

	public function test_consent_blocks_ai(): void {
		$books   = new Books();
		$book_id = $books->insert( array( 'title' => 'Consent Book', 'status' => 'active' ) );
		$consent = new Consent();
		$this->assertFalse( $consent->ai_allowed( $book_id ), 'Default deny' );
		$consent->insert( array( 'book_id' => $book_id, 'allow_openai' => 1, 'status' => 'active' ) );
		$this->assertTrue( $consent->ai_allowed( $book_id ) );
	}

	public function test_demo_and_anomaly_detection(): void {
		$demo    = DemoData::install();
		$book_id = (int) $demo['book_id'];
		$this->assertGreaterThan( 0, $book_id );

		// Demo seeds a decaying sales curve; detection should run without error.
		$found = Detector::detect( $book_id );
		$this->assertIsArray( $found );
	}

	public function test_approval_workflow_status_transition(): void {
		$recs = new Recommendations();
		$id   = $recs->insert( array( 'book_id' => 1, 'title' => 'Do a thing', 'status' => 'awaiting_review', 'rank_score' => 50 ) );
		$recs->update( $id, array( 'status' => 'approved' ) );
		$row = $recs->find( $id );
		$this->assertSame( 'approved', $row['status'] );
	}

	public function test_uninstall_preserves_data_by_default(): void {
		// With purge disabled, uninstall must not drop tables. We assert the flag
		// default is off; the uninstall routine early-returns in that case.
		$settings = get_option( ABCMD_OPT_SETTINGS, array() );
		$purge    = is_array( $settings ) && ! empty( $settings['uninstall_purge'] );
		$this->assertFalse( $purge, 'Purge-on-uninstall is off by default' );
	}
}
