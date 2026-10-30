<?php
/**
 * Audit trail for Website Studio actions.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Persistence;

use AiSeoContentStudio\WebsiteStudio\StudioTables;

defined( 'ABSPATH' ) || exit;

/**
 * Records who did what to which object (analyses run, profiles created/changed/deleted,
 * and in later phases drafts created and revisions restored). Details are small
 * structured facts, never content or credentials.
 */
class AuditLog {

	/**
	 * Record an action.
	 *
	 * @param string               $action      Action (e.g. "brand_profile.create").
	 * @param string               $object_type Object type.
	 * @param int                  $object_id   Object ID.
	 * @param array<string, mixed> $details     Small scalar facts.
	 * @param int|null             $user_id     User (default: current).
	 */
	public function record( string $action, string $object_type, int $object_id, array $details = array(), ?int $user_id = null ): void {
		global $wpdb;
		$clean = array();
		foreach ( array_slice( $details, 0, 20, true ) as $key => $value ) {
			if ( is_string( $key ) && ( is_scalar( $value ) || null === $value ) ) {
				$clean[ substr( $key, 0, 40 ) ] = is_string( $value ) ? mb_substr( $value, 0, 200 ) : $value;
			} elseif ( is_string( $key ) && is_array( $value ) ) {
				$clean[ substr( $key, 0, 40 ) ] = array_slice( array_filter( $value, 'is_scalar' ), 0, 50 );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table insert.
		$wpdb->insert(
			StudioTables::audit(),
			array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => $user_id ?? get_current_user_id(),
				'action'      => substr( $action, 0, 64 ),
				'object_type' => substr( $object_type, 0, 32 ),
				'object_id'   => max( 0, $object_id ),
				'details'     => (string) wp_json_encode( $clean ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Remove entries older than a number of days.
	 *
	 * @param int $days Days.
	 */
	public function prune( int $days ): int {
		global $wpdb;
		$table = StudioTables::audit();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS ) ) );
	}
}
