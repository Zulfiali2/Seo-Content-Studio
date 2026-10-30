<?php
/**
 * Design analysis storage and cache.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Persistence;

use AiSeoContentStudio\Core\Capabilities;
use AiSeoContentStudio\WebsiteStudio\StudioTables;

defined( 'ABSPATH' ) || exit;

/**
 * Stores page analyses. An analysis is keyed by the source page and a hash of everything
 * it was computed from, so an unchanged page is never analyzed twice.
 */
class AnalysisRepository {

	/** Analyses kept per source page; older ones are removed on insert. */
	public const KEEP_PER_PAGE = 5;

	/**
	 * Latest analysis of a page computed from the given inputs.
	 *
	 * @param int    $page_id          Page.
	 * @param string $hash             Source hash.
	 * @param int    $analyzer_version Analyzer version.
	 * @return array<string, mixed>|null
	 */
	public function findCached( int $page_id, string $hash, int $analyzer_version ): ?array {
		global $wpdb;
		$table = StudioTables::analyses();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_page_id = %d AND source_hash = %s AND analyzer_version = %d AND status = 'complete' ORDER BY id DESC LIMIT 1", $page_id, $hash, $analyzer_version ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Store an analysis.
	 *
	 * @param int                  $owner_id         User who ran it.
	 * @param int                  $page_id          Page.
	 * @param string               $hash             Source hash.
	 * @param int                  $analyzer_version Analyzer version.
	 * @param array<string, mixed> $analysis         Analysis data.
	 * @return int New ID, 0 on failure.
	 */
	public function insert( int $owner_id, int $page_id, string $hash, int $analyzer_version, array $analysis ): int {
		global $wpdb;
		$table = StudioTables::analyses();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table insert.
		$ok = $wpdb->insert(
			$table,
			array(
				'owner_id'         => $owner_id,
				'source_page_id'   => $page_id,
				'source_hash'      => $hash,
				'analyzer_version' => $analyzer_version,
				'status'           => 'complete',
				'analysis'         => (string) wp_json_encode( $analysis ),
				'created_at'       => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		// Retention: keep the newest few per page.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		$old = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_page_id = %d ORDER BY id DESC LIMIT 100 OFFSET %d", $page_id, self::KEEP_PER_PAGE ) );
		foreach ( (array) $old as $old_id ) {
			$wpdb->delete( $table, array( 'id' => (int) $old_id ), array( '%d' ) );
		}
		// phpcs:enable
		return $id;
	}

	/**
	 * Fetch one analysis.
	 *
	 * @param int $id Analysis.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = StudioTables::analyses();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Whether a user may read an analysis: they ran it, or can manage settings, and they can
	 * still edit the page it describes.
	 *
	 * @param int $id      Analysis.
	 * @param int $user_id User.
	 */
	public function canAccess( int $id, int $user_id ): bool {
		if ( $user_id <= 0 || ! user_can( $user_id, Capabilities::GENERATE ) ) {
			return false;
		}
		$row = $this->get( $id );
		if ( null === $row ) {
			return false;
		}
		$owner = (int) $row['owner_id'] === $user_id || user_can( $user_id, Capabilities::MANAGE );
		return $owner && user_can( $user_id, 'edit_post', (int) $row['source_page_id'] );
	}

	/**
	 * Remove analyses older than a number of days.
	 *
	 * @param int $days Days.
	 */
	public function prune( int $days ): int {
		global $wpdb;
		$table = StudioTables::analyses();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Decode a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row ): array {
		$analysis = json_decode( (string) ( $row['analysis'] ?? '' ), true );
		return array(
			'id'               => (int) $row['id'],
			'owner_id'         => (int) $row['owner_id'],
			'source_page_id'   => (int) $row['source_page_id'],
			'source_hash'      => (string) $row['source_hash'],
			'analyzer_version' => (int) $row['analyzer_version'],
			'status'           => (string) $row['status'],
			'analysis'         => is_array( $analysis ) ? $analysis : array(),
			'created_at'       => mysql_to_rfc3339( (string) $row['created_at'] ),
		);
	}
}
