<?php
/**
 * Plugin Name:       Marketing Department
 * Plugin URI:        https://github.com/BedfordAdrian/WP-SE
 * Description:       Admin-only marketing operating system for a small book-marketing agency. Manages authors and per-book client workspaces, collects sales &amp; campaign data via manual CSV imports, runs OpenAI-assisted audits, produces ranked weekly recommendations, tasks, content drafts and an approval queue, detects anomalies, and keeps a full audit trail. Phase one; later platform integrations plug into the same architecture.
 * Version:           1.0.2
 * Requires at least: 6.5
 * Tested up to:      7.0.1
 * Requires PHP:      8.1
 * Author:            ABC Book Marketing
 * Author URI:        https://github.com/BedfordAdrian/WP-SE
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       abc-marketing-department
 * Domain Path:       /languages
 *
 * Marketing Department is an internal agency tool. It never exposes client
 * workspaces, manuscripts, API settings or campaign data on the public site.
 * All access is gated behind the `manage_abc_marketing_department` capability.
 *
 * @package ABCMD
 */

// Do not allow direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Core constants.
// ---------------------------------------------------------------------------
define( 'ABCMD_VERSION', '1.0.2' );
define( 'ABCMD_DB_VERSION', '1.0.0' );
define( 'ABCMD_PLUGIN_FILE', __FILE__ );
define( 'ABCMD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ABCMD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ABCMD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Capability that governs the entire plugin.
define( 'ABCMD_CAP', 'manage_abc_marketing_department' );

// Option keys.
define( 'ABCMD_OPT_SETTINGS', 'abcmd_settings' );
define( 'ABCMD_OPT_OPENAI', 'abcmd_openai' );
define( 'ABCMD_OPT_PRICES', 'abcmd_model_prices' );
define( 'ABCMD_OPT_DB_VERSION', 'abcmd_db_version' );
define( 'ABCMD_OPT_PRIVACY', 'abcmd_privacy_template' );

// Cron hooks.
define( 'ABCMD_CRON_WEEKLY_AUDIT', 'abcmd_weekly_audit' );
define( 'ABCMD_CRON_WEEKLY_EMAIL', 'abcmd_weekly_email' );

// Scheduling defaults (Europe/London).
define( 'ABCMD_TIMEZONE', 'Europe/London' );

/**
 * Minimum PHP version. Checked BEFORE the autoloader is engaged so that older
 * PHP never parses namespaced/typed 8.1 syntax and fatals.
 */
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	require_once ABCMD_PLUGIN_DIR . 'includes/legacy-guard.php';
	return;
}

// ---------------------------------------------------------------------------
// Autoloader (PSR-4: ABCMD\ => includes/).
// ---------------------------------------------------------------------------
require_once ABCMD_PLUGIN_DIR . 'includes/Autoloader.php';
\ABCMD\Autoloader::register();

// ---------------------------------------------------------------------------
// Activation / deactivation / uninstall wiring.
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, array( '\ABCMD\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\ABCMD\Deactivator', 'deactivate' ) );

/**
 * Boot the plugin once WordPress has loaded the plugins layer.
 */
add_action(
	'plugins_loaded',
	static function () {
		\ABCMD\Plugin::instance()->boot();
	}
);
