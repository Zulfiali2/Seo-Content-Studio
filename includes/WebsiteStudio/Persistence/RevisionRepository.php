<?php
/**
 * Snapshot history of studio-made pages.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Persistence;

use AiSeoContentStudio\WebsiteStudio\StudioTables;

defined( 'ABSPATH' ) || exit;

/**
 * Every change the studio makes to a page stores a full snapshot of the page's Elementor
 * data (plus the design document and section map) as it is after the change, so any
 * revision can be compared, restored, or used to undo one section. WordPress post revisions
 * are not relied on: they do not reliably carry Elementor metadata.
 *
 * Retention: at most MAX_PER_PAGE revisions per page; revision 1 (the page as created) is
 * always kept and the oldest others are removed. Snapshots are compressed; a page larger than
 * MAX_SNAPSHOT_BYTES is recorded without a snapshot (it can then not be restored).
 */
class RevisionRepository {

	public const MAX_PER_PAGE = 25;

	public const MAX_SNAPSHOT_BYTES = 4194304;

	public const ACTIONS = array( 'create', 'update', 'regenerate', 'fix', 'images', 'insert', 'restore', 'external' );

	/**
	 * Store a revision.
	 *
	 * @param array<string, mixed> $row page_id, project_id, action, summary, section_id, meta, created_by,
	 *                                  elements, settings, document, section_map.
	 * @return int Revision number, 0 on failure.
	 */
	public function add( array $row ): int {
		global $wpdb;
		$table   = StudioTables::revisions();
		$page_id = (int) $row['page_id'];
		$json    = (string) wp_json_encode(
			array(
				'elements'    => array_values( (array) ( $row['elements'] ?? array() ) ),
				'settings'    => (array) ( $row['settings'] ?? array() ),
				'document'    => (array) ( $row['document'] ?? array() ),
				'section_map' => (array) ( $row['section_map'] ?? array() ),
			)
		);
		$bytes    = strlen( $json );
		$snapshot = $bytes <= self::MAX_SNAPSHOT_BYTES ? self::pack( $json ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		$next = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(revision) FROM {$table} WHERE page_id = %d", $page_id ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table insert.
		$ok = $wpdb->insert(
			$table,
			array(
				'page_id'        => $page_id,
				'project_id'     => (int) ( $row['project_id'] ?? 0 ),
				'revision'       => $next,
				'action'         => in_array( $row['action'] ?? '', self::ACTIONS, true ) ? (string) $row['action'] : 'update',
				'summary'        => mb_substr( sanitize_text_field( (string) ( $row['summary'] ?? '' ) ), 0, 250 ),
				'section_id'     => mb_substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $row['section_id'] ?? '' ) ), 0, 64 ),
				'meta'           => (string) wp_json_encode( (array) ( $row['meta'] ?? array() ) ),
				'elements_hash'  => self::hash( (array) ( $row['elements'] ?? array() ) ),
				'snapshot'       => $snapshot,
				'snapshot_bytes' => $bytes,
				'created_by'     => (int) ( $row['created_by'] ?? 0 ),
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		if ( ! $ok ) {
			return 0;
		}
		$this->trim( $page_id );
		return $next;
	}

	/**
	 * Revisions of a page, newest first, without snapshots.
	 *
	 * @param int $page_id Page.
	 * @return list<array<string, mixed>>
	 */
	public function listFor( int $page_id ): array {
		global $wpdb;
		$table = StudioTables::revisions();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, page_id, project_id, revision, action, summary, section_id, meta, elements_hash, snapshot_bytes, created_by, created_at, CASE WHEN snapshot = '' THEN 0 ELSE 1 END AS restorable FROM {$table} WHERE page_id = %d ORDER BY revision DESC", $page_id ), ARRAY_A );
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * One revision with its snapshot.
	 *
	 * @param int $page_id  Page.
	 * @param int $revision Revision number.
	 * @return array<string, mixed>|null With "snapshot" {elements, settings, document, section_map} or null when not stored.
	 */
	public function get( int $page_id, int $revision ): ?array {
		global $wpdb;
		$table = StudioTables::revisions();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE page_id = %d AND revision = %d", $page_id, $revision ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$out             = self::hydrate( $row );
		$out['snapshot'] = self::unpack( (string) $row['snapshot'] );
		return $out;
	}

	/**
	 * The newest revision (with snapshot), or null.
	 *
	 * @param int $page_id Page.
	 * @return array<string, mixed>|null
	 */
	public function latest( int $page_id ): ?array {
		global $wpdb;
		$table = StudioTables::revisions();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		$number = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(revision) FROM {$table} WHERE page_id = %d", $page_id ) );
		return $number > 0 ? $this->get( $page_id, $number ) : null;
	}

	/**
	 * Delete a page's history (the page was deleted).
	 *
	 * @param int $page_id Page.
	 */
	public function deleteForPage( int $page_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->delete( StudioTables::revisions(), array( 'page_id' => $page_id ), array( '%d' ) );
	}

	/**
	 * Remove history of pages that no longer exist (daily maintenance).
	 *
	 * @return int Rows removed.
	 */
	public function pruneOrphans(): int {
		global $wpdb;
		$table = StudioTables::revisions();
		// Two portable statements (multi-table DELETE is MySQL-only), in batches.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, no user input.
		$pages = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT r.page_id FROM {$table} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.page_id WHERE p.ID IS NULL LIMIT 200" ) );
		$count = 0;
		foreach ( $pages as $page_id ) {
			$count += (int) $wpdb->delete( $table, array( 'page_id' => $page_id ), array( '%d' ) );
		}
		// phpcs:enable
		return $count;
	}

	/**
	 * Stable hash of an element tree (detects edits made in Elementor between studio changes).
	 *
	 * @param array<int, mixed> $elements Elements.
	 */
	public static function hash( array $elements ): string {
		return md5( (string) wp_json_encode( array_values( $elements ) ) );
	}

	/**
	 * Keep revision 1 and the newest others.
	 *
	 * @param int $page_id Page.
	 */
	private function trim( int $page_id ): void {
		global $wpdb;
		$table = StudioTables::revisions();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		$numbers = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT revision FROM {$table} WHERE page_id = %d AND revision > 1 ORDER BY revision DESC", $page_id ) ) );
		foreach ( array_slice( $numbers, self::MAX_PER_PAGE - 1 ) as $old ) {
			$wpdb->delete(
				$table,
				array(
					'page_id'  => $page_id,
					'revision' => $old,
				),
				array( '%d', '%d' )
			);
		}
		// phpcs:enable
	}

	/**
	 * Compress a snapshot.
	 *
	 * @param string $json JSON.
	 */
	private static function pack( string $json ): string {
		if ( function_exists( 'gzdeflate' ) ) {
			$z = gzdeflate( $json, 6 );
			if ( false !== $z ) {
				return 'z:' . base64_encode( $z ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe storage of compressed data.
			}
		}
		return 'j:' . $json;
	}

	/**
	 * Decompress a snapshot.
	 *
	 * @param string $stored Stored value.
	 * @return array<string, mixed>|null
	 */
	private static function unpack( string $stored ): ?array {
		if ( str_starts_with( $stored, 'z:' ) ) {
			$raw  = base64_decode( substr( $stored, 2 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see pack().
			$json = false === $raw ? false : gzinflate( $raw );
		} elseif ( str_starts_with( $stored, 'j:' ) ) {
			$json = substr( $stored, 2 );
		} else {
			return null;
		}
		$data = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		return array(
			'elements'    => array_values( (array) ( $data['elements'] ?? array() ) ),
			'settings'    => (array) ( $data['settings'] ?? array() ),
			'document'    => (array) ( $data['document'] ?? array() ),
			'section_map' => (array) ( $data['section_map'] ?? array() ),
		);
	}

	/**
	 * Decode a row (without the snapshot).
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$meta = json_decode( (string) ( $row['meta'] ?? '' ), true );
		return array(
			'id'         => (int) $row['id'],
			'page_id'    => (int) $row['page_id'],
			'project_id' => (int) $row['project_id'],
			'revision'   => (int) $row['revision'],
			'action'     => (string) $row['action'],
			'summary'    => (string) $row['summary'],
			'section_id' => (string) $row['section_id'],
			'meta'       => is_array( $meta ) ? $meta : array(),
			'hash'       => (string) $row['elements_hash'],
			'bytes'      => (int) $row['snapshot_bytes'],
			'restorable' => isset( $row['restorable'] ) ? (bool) (int) $row['restorable'] : '' !== (string) ( $row['snapshot'] ?? '' ),
			'created_by' => (int) $row['created_by'],
			'created_at' => mysql_to_rfc3339( (string) $row['created_at'] ),
		);
	}
}
