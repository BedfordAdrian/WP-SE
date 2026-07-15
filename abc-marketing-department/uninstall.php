<?php
/**
 * Uninstall handler.
 *
 * Default behaviour: PRESERVE all agency data. Data is only removed when the
 * administrator has explicitly enabled the purge option in Settings → General.
 * Deactivating the plugin never triggers this file; only deletion does.
 *
 * @package ABCMD
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load constants used below without booting the whole plugin.
if ( ! defined( 'ABCMD_OPT_SETTINGS' ) ) {
	define( 'ABCMD_OPT_SETTINGS', 'abcmd_settings' );
}

$settings = get_option( ABCMD_OPT_SETTINGS, array() );
$purge    = is_array( $settings ) && ! empty( $settings['uninstall_purge'] );

if ( ! $purge ) {
	// Preserve everything. Nothing to do.
	return;
}

global $wpdb;

// Drop custom tables.
$keys = array(
	'authors', 'books', 'metrics', 'imports', 'ai_runs', 'recommendations',
	'tasks', 'content', 'files', 'file_chunks', 'consent', 'anomalies',
	'mapping_profiles', 'audit_log',
);
foreach ( $keys as $key ) {
	$table = $wpdb->prefix . 'abcmd_' . $key;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Remove options.
$options = array(
	'abcmd_settings', 'abcmd_openai', 'abcmd_model_prices', 'abcmd_db_version',
	'abcmd_privacy_template', 'abcmd_last_audit', 'abcmd_activated_at',
	'abcmd_model_quirks',
);
foreach ( $options as $opt ) {
	delete_option( $opt );
}

// Remove the capability from all roles.
if ( function_exists( 'wp_roles' ) ) {
	foreach ( wp_roles()->roles as $role_key => $_ ) {
		$role = get_role( $role_key );
		if ( $role && $role->has_cap( 'manage_abc_marketing_department' ) ) {
			$role->remove_cap( 'manage_abc_marketing_department' );
		}
	}
}

// Remove protected upload directory contents.
$uploads = wp_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'abcmd-secure';
if ( is_dir( $dir ) ) {
	$files = glob( trailingslashit( $dir ) . '*' );
	if ( is_array( $files ) ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}
