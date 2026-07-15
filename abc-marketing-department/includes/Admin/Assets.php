<?php
/**
 * Admin CSS/JS enqueueing (only on plugin pages).
 *
 * @package ABCMD
 */

namespace ABCMD\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads plugin admin assets.
 */
final class Assets {

	/**
	 * Wire hooks.
	 */
	public function hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue assets on plugin screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( ! is_string( $hook ) || ! str_contains( $hook, 'abcmd' ) ) {
			return;
		}

		wp_enqueue_style(
			'abcmd-admin',
			ABCMD_PLUGIN_URL . 'admin/assets/css/admin.css',
			array(),
			ABCMD_VERSION
		);

		wp_enqueue_script(
			'abcmd-admin',
			ABCMD_PLUGIN_URL . 'admin/assets/js/admin.js',
			array( 'wp-api-fetch' ),
			ABCMD_VERSION,
			true
		);

		wp_localize_script(
			'abcmd-admin',
			'ABCMD',
			array(
				'restBase' => esc_url_raw( rest_url( 'abcmd/v1' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
