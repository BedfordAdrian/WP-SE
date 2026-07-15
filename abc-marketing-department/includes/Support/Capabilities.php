<?php
/**
 * Capability management.
 *
 * @package ABCMD
 */

namespace ABCMD\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Grants and revokes the plugin's dedicated capability.
 */
final class Capabilities {

	/**
	 * Grant the plugin capability to every administrator role.
	 */
	public static function grant_to_admins(): void {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( ABCMD_CAP ) ) {
			$role->add_cap( ABCMD_CAP );
		}

		// Also grant to the activating user directly, in case of custom roles.
		$user = wp_get_current_user();
		if ( $user && $user->exists() && ! $user->has_cap( ABCMD_CAP ) ) {
			$user->add_cap( ABCMD_CAP );
		}
	}

	/**
	 * Remove the capability from all roles (used only on explicit purge).
	 */
	public static function revoke_everywhere(): void {
		foreach ( wp_roles()->roles as $role_key => $_ ) {
			$role = get_role( $role_key );
			if ( $role && $role->has_cap( ABCMD_CAP ) ) {
				$role->remove_cap( ABCMD_CAP );
			}
		}
	}
}
