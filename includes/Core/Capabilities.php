<?php
/**
 * Custom capabilities.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Defines and installs the plugin's capabilities.
 *
 * A nonce never replaces these checks: every protected REST route calls
 * current_user_can() with one of the constants below.
 */
final class Capabilities {

	/** Change settings, API keys, pricing and limits. */
	public const MANAGE = 'aiscs_manage_settings';

	/** Run research, generate content and save drafts. */
	public const GENERATE = 'aiscs_generate_content';

	/**
	 * Role => capabilities granted on activation.
	 *
	 * @return array<string, string[]>
	 */
	public static function defaultGrants(): array {
		return array(
			'administrator' => array( self::MANAGE, self::GENERATE ),
			'editor'        => array( self::GENERATE ),
		);
	}

	/**
	 * Add capabilities to roles. Safe to run repeatedly.
	 */
	public static function install(): void {
		foreach ( self::defaultGrants() as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove capabilities from every role (uninstall only).
	 */
	public static function uninstall(): void {
		$roles = wp_roles();
		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			$role->remove_cap( self::MANAGE );
			$role->remove_cap( self::GENERATE );
		}
	}
}
