<?php
/**
 * Uninstall handler.
 *
 * Data is removed ONLY when "Delete plugin data on uninstall" is enabled in
 * General Settings. Posts, pages, Elementor documents and Media Library items
 * created with the plugin are never deleted: they belong to the site.
 *
 * @package AiSeoContentStudio
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove plugin data for the current site.
 */
function aiscs_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'aiscs_settings', array() );
	if ( empty( $settings['general']['delete_data_on_uninstall'] ) ) {
		return;
	}

	delete_option( 'aiscs_settings' );
	delete_option( 'aiscs_credentials' );
	delete_option( 'aiscs_db_version' );

	// Locks and plugin transients.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'aiscs_lock_' ) . '%', $wpdb->esc_like( '_transient_aiscs_' ) . '%', $wpdb->esc_like( '_transient_timeout_aiscs_' ) . '%' ) );

	// Plugin projects (planning data owned by the plugin, not site content).
	$aiscs_projects = get_posts(
		array(
			'post_type'      => 'aiscs_project',
			'post_status'    => array_keys( get_post_stati() ), // Includes trashed projects.
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $aiscs_projects as $aiscs_project_id ) {
		wp_delete_post( $aiscs_project_id, true );
	}

	// Plugin-owned post meta only (prefixed); post content is untouched.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_aiscs_' ) . '%' ) );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}aiscs_usage_log" );

	foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$role->remove_cap( 'aiscs_manage_settings' );
			$role->remove_cap( 'aiscs_generate_content' );
		}
	}

	wp_clear_scheduled_hook( 'aiscs_prune_usage_log' );
	wp_unschedule_hook( 'aiscs_run_job' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $aiscs_site_id ) {
		switch_to_blog( $aiscs_site_id );
		aiscs_uninstall_site();
		restore_current_blog();
	}
} else {
	aiscs_uninstall_site();
}
