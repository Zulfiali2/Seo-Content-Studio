<?php
/**
 * Records of pages the Website Studio created.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Persistence;

use AiSeoContentStudio\Core\Capabilities;
use AiSeoContentStudio\WebsiteStudio\StudioTables;

defined( 'ABSPATH' ) || exit;

/**
 * One row per page the studio created (generated from a design document, or cloned).
 * The row is the proof of ownership the DraftManager checks before it ever writes to a
 * page: pages without a row are never modified.
 */
class GeneratedPageRepository {

	public const TYPES = array( 'generated', 'clone', 'clone_generated' );

	/**
	 * Insert a record.
	 *
	 * @param array<string, mixed> $row Fields: project_id, page_id, source_page_id, generation_type, profile_id, created_by, section_map, document, notes.
	 * @return int ID, 0 on failure.
	 */
	public function insert( array $row ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table insert.
		$ok = $wpdb->insert(
			StudioTables::pages(),
			array(
				'project_id'      => (int) ( $row['project_id'] ?? 0 ),
				'page_id'         => (int) $row['page_id'],
				'source_page_id'  => (int) ( $row['source_page_id'] ?? 0 ),
				'generation_type' => in_array( $row['generation_type'] ?? '', self::TYPES, true ) ? (string) $row['generation_type'] : 'generated',
				'profile_id'      => (int) ( $row['profile_id'] ?? 0 ),
				'status'          => 'draft',
				'created_by'      => (int) $row['created_by'],
				'section_map'     => (string) wp_json_encode( (array) ( $row['section_map'] ?? array() ) ),
				'document'        => (string) wp_json_encode( (array) ( $row['document'] ?? array() ) ),
				'notes'           => (string) wp_json_encode( array_values( (array) ( $row['notes'] ?? array() ) ) ),
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update the stored section map and document (after a regeneration).
	 *
	 * @param int                  $page_id     Page.
	 * @param array<string, mixed> $section_map Section map.
	 * @param array<string, mixed> $document    Document.
	 */
	public function updateDocument( int $page_id, array $section_map, array $document ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		return false !== $wpdb->update(
			StudioTables::pages(),
			array(
				'section_map' => (string) wp_json_encode( $section_map ),
				'document'    => (string) wp_json_encode( $document ),
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'page_id' => $page_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Record for a page.
	 *
	 * @param int $page_id Page.
	 * @return array<string, mixed>|null
	 */
	public function forPage( int $page_id ): ?array {
		global $wpdb;
		$table = StudioTables::pages();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE page_id = %d", $page_id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Records visible to a user (own; all for managers), newest first.
	 *
	 * @param int $user_id User.
	 * @param int $page    Page.
	 * @param int $per     Per page.
	 * @return array{items: list<array<string, mixed>>, total: int, pages: int}
	 */
	public function listFor( int $user_id, int $page = 1, int $per = 20 ): array {
		global $wpdb;
		$table  = StudioTables::pages();
		$per    = max( 1, min( 50, $per ) );
		$offset = ( max( 1, $page ) - 1 ) * $per;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		if ( user_can( $user_id, Capabilities::MANAGE ) ) {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per, $offset ), ARRAY_A );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_by = %d", $user_id ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_by = %d ORDER BY id DESC LIMIT %d OFFSET %d", $user_id, $per, $offset ), ARRAY_A );
		}
		// phpcs:enable
		return array(
			'items' => array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
			'pages' => (int) ceil( $total / $per ),
		);
	}

	/**
	 * Delete the record of a page (the page itself was deleted).
	 *
	 * @param int $page_id Page.
	 */
	public function deleteForPage( int $page_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->delete( StudioTables::pages(), array( 'page_id' => $page_id ), array( '%d' ) );
	}

	/**
	 * Decode a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$decode = static function ( mixed $json ): array {
			$v = json_decode( (string) $json, true );
			return is_array( $v ) ? $v : array();
		};
		return array(
			'id'              => (int) $row['id'],
			'project_id'      => (int) $row['project_id'],
			'page_id'         => (int) $row['page_id'],
			'source_page_id'  => (int) $row['source_page_id'],
			'generation_type' => (string) $row['generation_type'],
			'profile_id'      => (int) $row['profile_id'],
			'status'          => (string) $row['status'],
			'created_by'      => (int) $row['created_by'],
			'section_map'     => $decode( $row['section_map'] ),
			'document'        => $decode( $row['document'] ),
			'notes'           => $decode( $row['notes'] ),
			'created_at'      => mysql_to_rfc3339( (string) $row['created_at'] ),
			'updated_at'      => mysql_to_rfc3339( (string) $row['updated_at'] ),
		);
	}
}
