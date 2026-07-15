<?php
/**
 * Plugin bootstrap / service wiring.
 *
 * @package ABCMD
 */

namespace ABCMD;

use ABCMD\Admin\Admin;
use ABCMD\Admin\Assets;
use ABCMD\Admin\PostHandlers;
use ABCMD\Cron\Scheduler;
use ABCMD\Cron\WeeklyAudit;
use ABCMD\Database\Migrator;
use ABCMD\Rest\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton that registers all runtime hooks.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks. Idempotent.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'abc-marketing-department', false, dirname( ABCMD_PLUGIN_BASENAME ) . '/languages' );

		// Keep schema current after plugin updates (no reactivation needed).
		add_action( 'admin_init', array( $this, 'maybe_migrate' ) );

		// Admin surface (menus, pages, assets) — only in wp-admin.
		if ( is_admin() ) {
			( new Admin() )->hooks();
			( new Assets() )->hooks();
			( new PostHandlers() )->hooks();
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		}

		// Cron: custom schedules + handlers.
		Scheduler::hooks();
		add_action( ABCMD_CRON_WEEKLY_AUDIT, array( WeeklyAudit::class, 'run_scheduled' ) );
		add_action( ABCMD_CRON_WEEKLY_EMAIL, array( WeeklyAudit::class, 'send_email' ) );

		// REST API (permission-gated).
		add_action( 'rest_api_init', static function () {
			( new Controller() )->register_routes();
		} );

		/**
		 * Fires after the plugin has registered its runtime hooks. Later phase
		 * integrations (Shopify, GA4, EmailOctopus, Meta/Amazon Ads, etc.) hook
		 * here to register importers, sync jobs and settings panels.
		 */
		do_action( 'abcmd_loaded', $this );
	}

	/**
	 * Run pending migrations when the DB version trails the plugin version.
	 */
	public function maybe_migrate(): void {
		if ( ! Migrator::is_current() ) {
			Migrator::run();
		}
	}

	/**
	 * Surface activation results and environment warnings in wp-admin.
	 */
	public function admin_notices(): void {
		if ( ! current_user_can( ABCMD_CAP ) ) {
			return;
		}

		$report = get_transient( 'abcmd_activation_report' );
		if ( is_array( $report ) ) {
			delete_transient( 'abcmd_activation_report' );
			$setup_url = esc_url( \ABCMD\Support\Helpers::admin_url( 'abcmd-setup' ) );
			echo '<div class="notice notice-success is-dismissible"><p><strong>Marketing Department</strong> is active. ';
			echo '<a href="' . $setup_url . '">Open the setup wizard</a> to add your OpenAI key and (optionally) load the demo workspace.</p></div>';

			if ( ! empty( $report['errors'] ) ) {
				echo '<div class="notice notice-error"><p><strong>Marketing Department:</strong> database setup reported issues: '
					. esc_html( implode( '; ', $report['errors'] ) ) . '</p></div>';
			}
		}
	}
}
