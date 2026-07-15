<?php
/**
 * Legacy PHP guard.
 *
 * Loaded only when the site runs PHP < 8.1. Contains NO PHP 8.1 syntax so it is
 * safe to parse on old interpreters. It self-deactivates the plugin on
 * activation and shows a clear admin notice instead of causing a fatal error.
 *
 * @package ABCMD
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuse activation on unsupported PHP and surface a readable message.
 */
function abcmd_legacy_activation_halt() {
	if ( function_exists( 'deactivate_plugins' ) ) {
		deactivate_plugins( plugin_basename( ABCMD_PLUGIN_FILE ) );
	}
	wp_die(
		esc_html(
			sprintf(
				/* translators: %s: current PHP version */
				'Marketing Department requires PHP 8.1 or newer. This server runs PHP %s. Please upgrade PHP and try again.',
				PHP_VERSION
			)
		),
		'Plugin activation halted',
		array( 'back_link' => true )
	);
}
register_activation_hook( ABCMD_PLUGIN_FILE, 'abcmd_legacy_activation_halt' );

add_action(
	'admin_notices',
	function () {
		echo '<div class="notice notice-error"><p><strong>Marketing Department</strong> is inactive: it requires PHP 8.1+ but this server runs PHP ' . esc_html( PHP_VERSION ) . '.</p></div>';
	}
);
