<?php
/**
 * Brand profile storage.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Persistence;

use AiSeoContentStudio\Core\Capabilities;
use AiSeoContentStudio\WebsiteStudio\Design\DesignTokens;
use AiSeoContentStudio\WebsiteStudio\StudioTables;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for brand profiles. Tokens are normalised on every write and every read, so a
 * profile edited in the database by hand still comes back in schema form.
 *
 * Ownership: a profile belongs to its creator; users who can manage settings can see and
 * change every profile.
 */
class BrandProfileRepository {

	public const MAX_PER_USER = 100;

	/**
	 * Create a profile.
	 *
	 * @param int                  $owner_id     Owner.
	 * @param string               $name         Name.
	 * @param array<string, mixed> $tokens       Tokens (normalised here).
	 * @param int[]                $source_pages Source page IDs.
	 * @param int                  $project_id   Project (0 = none).
	 * @return int New ID, 0 on failure.
	 */
	public function create( int $owner_id, string $name, array $tokens, array $source_pages = array(), int $project_id = 0 ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table insert.
		$ok = $wpdb->insert(
			StudioTables::profiles(),
			array(
				'owner_id'       => $owner_id,
				'project_id'     => $project_id,
				'name'           => mb_substr( $name, 0, 191 ),
				'schema_version' => DesignTokens::VERSION,
				'tokens'         => (string) wp_json_encode( DesignTokens::normalize( $tokens ) ),
				'source_pages'   => (string) wp_json_encode( self::ids( $source_pages ) ),
				'created_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update name and/or tokens.
	 *
	 * @param int                       $id     Profile.
	 * @param string|null               $name   New name.
	 * @param array<string, mixed>|null $tokens New tokens (normalised here).
	 */
	public function update( int $id, ?string $name, ?array $tokens ): bool {
		global $wpdb;
		$data    = array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		$formats = array( '%s' );
		if ( null !== $name ) {
			$data['name'] = mb_substr( $name, 0, 191 );
			$formats[]    = '%s';
		}
		if ( null !== $tokens ) {
			$data['tokens'] = (string) wp_json_encode( DesignTokens::normalize( $tokens ) );
			$formats[]      = '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		return false !== $wpdb->update( StudioTables::profiles(), $data, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/**
	 * Fetch one profile.
	 *
	 * @param int $id Profile.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = StudioTables::profiles();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row ) : null;
	}

	/**
	 * Whether a user may read and change a profile.
	 *
	 * @param int $id      Profile.
	 * @param int $user_id User.
	 */
	public function canAccess( int $id, int $user_id ): bool {
		if ( $user_id <= 0 || ! user_can( $user_id, Capabilities::GENERATE ) ) {
			return false;
		}
		$profile = $this->get( $id );
		return null !== $profile && ( (int) $profile['owner_id'] === $user_id || user_can( $user_id, Capabilities::MANAGE ) );
	}

	/**
	 * Profiles visible to a user, newest first.
	 *
	 * @param int $user_id User.
	 * @param int $page    Page (1-based).
	 * @param int $per     Per page.
	 * @return array{items: list<array<string, mixed>>, total: int, pages: int}
	 */
	public function listFor( int $user_id, int $page = 1, int $per = 20 ): array {
		global $wpdb;
		$table  = StudioTables::profiles();
		$per    = max( 1, min( 100, $per ) );
		$offset = ( max( 1, $page ) - 1 ) * $per;
		$all    = user_can( $user_id, Capabilities::MANAGE );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		if ( $all ) {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $per, $offset ), ARRAY_A );
		} else {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE owner_id = %d", $user_id ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_id = %d ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", $user_id, $per, $offset ), ARRAY_A );
		}
		// phpcs:enable
		return array(
			'items' => array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
			'pages' => (int) ceil( $total / $per ),
		);
	}

	/**
	 * Number of profiles a user owns.
	 *
	 * @param int $user_id User.
	 */
	public function countOwned( int $user_id ): int {
		global $wpdb;
		$table = StudioTables::profiles();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE owner_id = %d", $user_id ) );
	}

	/**
	 * Delete a profile.
	 *
	 * @param int $id Profile.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		return (bool) $wpdb->delete( StudioTables::profiles(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Decode a row.
	 *
	 * @param array<string, mixed> $row Row.
	 * @return array<string, mixed>
	 */
	public static function hydrate( array $row ): array {
		$tokens = json_decode( (string) ( $row['tokens'] ?? '' ), true );
		$pages  = json_decode( (string) ( $row['source_pages'] ?? '' ), true );
		return array(
			'id'           => (int) $row['id'],
			'owner_id'     => (int) $row['owner_id'],
			'project_id'   => (int) $row['project_id'],
			'name'         => (string) $row['name'],
			'tokens'       => DesignTokens::normalize( is_array( $tokens ) ? $tokens : array() ),
			'source_pages' => self::ids( is_array( $pages ) ? $pages : array() ),
			'created_at'   => mysql_to_rfc3339( (string) $row['created_at'] ),
			'updated_at'   => mysql_to_rfc3339( (string) $row['updated_at'] ),
		);
	}

	/**
	 * Positive, unique integer IDs (max 50).
	 *
	 * @param array<int, mixed> $ids IDs.
	 * @return int[]
	 */
	private static function ids( array $ids ): array {
		return array_slice( array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn ( int $i ): bool => $i > 0 ) ) ), 0, 50 );
	}
}
