<?php
/**
 * Plugin activation.
 *
 * @package ABCMD
 */

namespace ABCMD;

use ABCMD\Database\Migrator;
use ABCMD\Support\Audit;
use ABCMD\Support\Capabilities;
use ABCMD\Cron\Scheduler;
use ABCMD\Files\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on register_activation_hook.
 */
final class Activator {

	/**
	 * Perform activation: verify environment, build tables, grant caps, schedule.
	 */
	public static function activate(): void {
		// Hard requirement gate — never fatal, always readable.
		if ( ! Requirements::passes() ) {
			$messages = Requirements::fatal_messages();
			deactivate_plugins( plugin_basename( ABCMD_PLUGIN_FILE ) );
			wp_die(
				'<h1>Marketing Department cannot activate</h1><p>The following requirements are not met:</p><ul><li>'
					. implode( '</li><li>', array_map( 'esc_html', $messages ) )
					. '</li></ul>',
				'Activation halted',
				array( 'back_link' => true )
			);
		}

		// 1) Database.
		$migration = Migrator::run();

		// 2) Capabilities.
		Capabilities::grant_to_admins();

		// 3) Protected upload storage.
		Storage::ensure_protected_dir();

		// 4) Scheduled events.
		Scheduler::schedule_all();

		// 5) Default options.
		self::seed_default_options();

		// 6) Record an activation flag for the setup wizard + admin notices.
		set_transient( 'abcmd_activation_report', $migration, 120 );
		update_option( 'abcmd_activated_at', gmdate( 'Y-m-d H:i:s' ), false );

		if ( class_exists( '\ABCMD\Support\Audit' ) ) {
			Audit::log(
				'plugin.activate',
				'Plugin activated.',
				array(
					'version'    => ABCMD_VERSION,
					'db_version' => ABCMD_DB_VERSION,
					'migration'  => $migration,
				)
			);
		}
	}

	/**
	 * Populate baseline options and editable model pricing (only if absent).
	 */
	private static function seed_default_options(): void {
		if ( false === get_option( ABCMD_OPT_SETTINGS, false ) ) {
			add_option( ABCMD_OPT_SETTINGS, array(), '', false );
		}

		if ( false === get_option( ABCMD_OPT_PRICES, false ) ) {
			add_option( ABCMD_OPT_PRICES, \ABCMD\AI\Cost::default_prices(), '', false );
		}

		if ( false === get_option( ABCMD_OPT_OPENAI, false ) ) {
			add_option(
				ABCMD_OPT_OPENAI,
				array(
					'api_key_enc'    => '',
					'organization'   => '',
					'project'        => '',
					'default_model'  => 'gpt-5.4',
					'allowed_models' => array( 'gpt-5.6-luna', 'gpt-5.4', 'gpt-5.4-nano', 'gpt-5' ),
					'temperature'    => 0.7,
					'web_search'     => 0,
					'timeout'        => 60,
					'retries'        => 2,
				),
				'',
				false
			);
		}

		if ( false === get_option( ABCMD_OPT_PRIVACY, false ) ) {
			add_option( ABCMD_OPT_PRIVACY, \ABCMD\Demo\PrivacyTemplate::default_text(), '', false );
		}
	}
}
