<?php
/**
 * Plugin deactivation.
 *
 * Deactivation clears scheduled events only. It never deletes agency data —
 * data removal happens exclusively through the explicit purge tools or uninstall
 * with the purge option enabled.
 *
 * @package ABCMD
 */

namespace ABCMD;

use ABCMD\Cron\Scheduler;
use ABCMD\Support\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on register_deactivation_hook.
 */
final class Deactivator {

	/**
	 * Clear scheduled events; preserve all data.
	 */
	public static function deactivate(): void {
		Scheduler::unschedule_all();

		if ( class_exists( '\ABCMD\Support\Audit' ) ) {
			Audit::log( 'plugin.deactivate', 'Plugin deactivated. All data preserved.' );
		}
	}
}
