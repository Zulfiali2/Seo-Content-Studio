<?php
/**
 * Design library: reusable sections and components.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Persistence;

use AiSeoContentStudio\Core\Capabilities;
use AiSeoContentStudio\WebsiteStudio\StudioTables;

defined( 'ABSPATH' ) || exit;

/**
 * Each item holds one Elementor element tree (a top-level section, or a component such as
 * a card or a button) that was validated when it was saved. Items belong to their owner;
 * users with the manage capability can see and use everyone's.
 */
class LibraryRepository {

	public const KINDS = array( 'section', 'component' );

	public const SECTION_CATEGORIES = array( 'hero', 'services', 'features', 'about', 'cta', 'testimonials', 'faq', 'contact', 'footer', 'content', 'other' );

	public const COMPONENT_CATEGORIES = array( 'button', 'card', 'image_grid', 'feature_box', 'statistics', 'pricing', 'other' );

	public const MAX_BYTES = 1048576;

	/**
	 * Insert an item.
	 *
	 * @param array<string, mixed> $row owner_id, project_id, name, kind, category, profile_id, source_page_id,
	 *                                  elements, summary, uses_containers.
	 * @return int ID, 0 on failure.
	 */
	public function insert( array $row ): int {
		global $wpdb;
		$now  = gmdate( 'Y-m-d H:i:s' );
		$kind = in_array( $row['kind'] ?? '', self::KINDS, true ) ? (string) $row['kind'] : 'section';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table insert.
		$ok = $wpdb->insert(
			StudioTables::library(),
			array(
				'owner_id'        => (int) $row['owner_id'],
				'project_id'      => (int) ( $row['project_id'] ?? 0 ),
				'name'            => mb_substr( sanitize_text_field( (string) $row['name'] ), 0, 150 ),
				'kind'            => $kind,
				'category'        => self::category( $kind, (string) ( $row['category'] ?? '' ) ),
				'profile_id'      => (int) ( $row['profile_id'] ?? 0 ),
				'source_page_id'  => (int) ( $row['source_page_id'] ?? 0 ),
				'elements'        => (string) wp_json_encode( array_values( (array) $row['elements'] ) ),
				'summary'         => (string) wp_json_encode( (array) ( $row['summary'] ?? array() ) ),
				'uses_containers' => empty( $row['uses_containers'] ) ? 0 : 1,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Rename or recategorise.
	 *
	 * @param int                  $id     Item.
	 * @param array<string, mixed> $fields name, category, profile_id.
	 */
	public function update( int $id, array $fields ): bool {
		global $wpdb;
		$item = $this->get( $id );
		if ( null === $item ) {
			return false;
		}
		$data = array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		if ( isset( $fields['name'] ) ) {
			$data['name'] = mb_substr( sanitize_text_field( (string) $fields['name'] ), 0, 150 );
		}
		if ( isset( $fields['category'] ) ) {
			$data['category'] = self::category( $item['kind'], (string) $fields['category'] );
		}
		if ( isset( $fields['profile_id'] ) ) {
			$data['profile_id'] = (int) $fields['profile_id'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		return false !== $wpdb->update( StudioTables::library(), $data, array( 'id' => $id ) );
	}

	/**
	 * One item.
	 *
	 * @param int $id Item.
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = StudioTables::library();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, prepared.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? self::hydrate( $row, true ) : null;
	}

	/**
	 * Items visible to a user, newest first, without element trees.
	 *
	 * @param int    $user_id  User.
	 * @param string $kind     "" or a kind.
	 * @param string $category "" or a category.
	 * @return list<array<string, mixed>>
	 */
	public function listFor( int $user_id, string $kind = '', string $category = '' ): array {
		global $wpdb;
		$table = StudioTables::library();
		$where = array( '1=1' );
		$args  = array();
		if ( ! user_can( $user_id, Capabilities::MANAGE ) ) {
			$where[] = 'owner_id = %d';
			$args[]  = $user_id;
		}
		if ( in_array( $kind, self::KINDS, true ) ) {
			$where[] = 'kind = %s';
			$args[]  = $kind;
		}
		if ( '' !== $category ) {
			$where[] = 'category = %s';
			$args[]  = sanitize_key( $category );
		}
		$sql = "SELECT id, owner_id, project_id, name, kind, category, profile_id, source_page_id, summary, uses_containers, created_at, updated_at FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC, id DESC LIMIT 200';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom table; placeholders built above.
		$rows = $wpdb->get_results( array() === $args ? $sql : $wpdb->prepare( $sql, ...$args ), ARRAY_A );
		return array_map( static fn ( array $r ): array => self::hydrate( $r, false ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Delete an item.
	 *
	 * @param int $id Item.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		return (bool) $wpdb->delete( StudioTables::library(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Whether a user may use an item.
	 *
	 * @param int $id      Item.
	 * @param int $user_id User.
	 */
	public function canAccess( int $id, int $user_id ): bool {
		$item = $this->get( $id );
		return null !== $item && ( $item['owner_id'] === $user_id || user_can( $user_id, Capabilities::MANAGE ) );
	}

	/**
	 * Valid category for a kind.
	 *
	 * @param string $kind     Kind.
	 * @param string $category Category.
	 */
	public static function category( string $kind, string $category ): string {
		$allowed = 'component' === $kind ? self::COMPONENT_CATEGORIES : self::SECTION_CATEGORIES;
		return in_array( $category, $allowed, true ) ? $category : 'other';
	}

	/**
	 * Decode a row.
	 *
	 * @param array<string, mixed> $row      Row.
	 * @param bool                 $elements Include the element tree.
	 * @return array<string, mixed>
	 */
	private static function hydrate( array $row, bool $elements ): array {
		$summary = json_decode( (string) ( $row['summary'] ?? '' ), true );
		$out     = array(
			'id'              => (int) $row['id'],
			'owner_id'        => (int) $row['owner_id'],
			'project_id'      => (int) $row['project_id'],
			'name'            => (string) $row['name'],
			'kind'            => (string) $row['kind'],
			'category'        => (string) $row['category'],
			'profile_id'      => (int) $row['profile_id'],
			'source_page_id'  => (int) $row['source_page_id'],
			'summary'         => is_array( $summary ) ? $summary : array(),
			'uses_containers' => (bool) (int) $row['uses_containers'],
			'created_at'      => mysql_to_rfc3339( (string) $row['created_at'] ),
			'updated_at'      => mysql_to_rfc3339( (string) $row['updated_at'] ),
		);
		if ( $elements ) {
			$tree            = json_decode( (string) $row['elements'], true );
			$out['elements'] = is_array( $tree ) ? $tree : array();
		}
		return $out;
	}
}
