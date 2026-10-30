<?php
/**
 * Deactivation routine.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin deactivation.
 *
 * Deactivation is not uninstall: settings, credentials, usage history, capabilities
 * and all generated content are deliberately left in place.
 */
final class Deactivator {

	/** Cron hooks owned by the plugin. */
	public const CRON_HOOKS = array( 'aiscs_prune_usage_log', 'aiscs_run_job' );

	/**
	 * Unschedule plugin cron events only.
	 */
	public static function deactivate(): void {
		foreach ( self::CRON_HOOKS as $hook ) {
			// Removes events regardless of their arguments.
			wp_unschedule_hook( $hook );
			// Job steps queued through Action Scheduler are not WP-Cron events.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), 'aiscs' );
			}
		}
	}
}
