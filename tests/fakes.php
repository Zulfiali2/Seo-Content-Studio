<?php
/**
 * Test doubles for the persistence and REST permission tests: an in-memory SQLite $wpdb,
 * a users/posts store with capability checks, and the few REST classes the controller uses.
 *
 * @package AiSeoContentStudio
 */

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/** Minimal wpdb over PDO SQLite (prepare uses the same %d/%s/%f placeholders as core). */
class FakeWpdb {
	public string $prefix = 'wp_';
	public string $posts = 'wp_posts';
	public int $insert_id = 0;
	public PDO $pdo;

	public function __construct() {
		$this->pdo = new PDO( 'sqlite::memory:' );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'CREATE TABLE wp_aiscs_brand_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_id INT, project_id INT, name TEXT, schema_version INT, tokens TEXT, source_pages TEXT, created_at TEXT, updated_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_aiscs_design_analyses (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_id INT, project_id INT DEFAULT 0, source_page_id INT, source_hash TEXT, analyzer_version INT, profile_id INT DEFAULT 0, status TEXT, analysis TEXT, created_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_aiscs_generated_pages (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INT, page_id INT UNIQUE, source_page_id INT, generation_type TEXT, profile_id INT, status TEXT, created_by INT, section_map TEXT, document TEXT, notes TEXT, created_at TEXT, updated_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY)' );
		$this->pdo->exec( 'CREATE TABLE wp_aiscs_page_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INT, project_id INT, revision INT, action TEXT, summary TEXT, section_id TEXT, meta TEXT, elements_hash TEXT, snapshot TEXT, snapshot_bytes INT, created_by INT, created_at TEXT, UNIQUE (page_id, revision))' );
		$this->pdo->exec( 'CREATE TABLE wp_aiscs_design_library (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_id INT, project_id INT, name TEXT, kind TEXT, category TEXT, profile_id INT, source_page_id INT, elements TEXT, summary TEXT, uses_containers INT, created_at TEXT, updated_at TEXT)' );
		$this->pdo->exec( 'CREATE TABLE wp_aiscs_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, user_id INT, action TEXT, object_type TEXT, object_id INT, details TEXT)' );
	}

	public function prepare( string $sql, ...$args ): string {
		$i = 0;
		return (string) preg_replace_callback(
			'/%[dsf]/',
			function ( array $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ] ?? '';
				return match ( $m[0] ) {
					'%d' => (string) (int) $v,
					'%f' => (string) (float) $v,
					default => $this->pdo->quote( (string) $v ),
				};
			},
			$sql
		);
	}

	public function insert( string $table, array $data, $formats = null ): int|false {
		$cols = implode( ',', array_keys( $data ) );
		$qs   = implode( ',', array_fill( 0, count( $data ), '?' ) );
		$st   = $this->pdo->prepare( "INSERT INTO $table ($cols) VALUES ($qs)" );
		$st->execute( array_values( $data ) );
		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}

	public function update( string $table, array $data, array $where, $f = null, $wf = null ): int|false {
		$set = implode( ',', array_map( fn ( $k ) => "$k = ?", array_keys( $data ) ) );
		$wh  = implode( ' AND ', array_map( fn ( $k ) => "$k = ?", array_keys( $where ) ) );
		$st  = $this->pdo->prepare( "UPDATE $table SET $set WHERE $wh" );
		$st->execute( array_merge( array_values( $data ), array_values( $where ) ) );
		return $st->rowCount();
	}

	public function delete( string $table, array $where, $f = null ): int|false {
		$wh = implode( ' AND ', array_map( fn ( $k ) => "$k = ?", array_keys( $where ) ) );
		$st = $this->pdo->prepare( "DELETE FROM $table WHERE $wh" );
		$st->execute( array_values( $where ) );
		return $st->rowCount();
	}

	public function query( string $sql ): int|false {
		return (int) $this->pdo->exec( $sql );
	}

	public function get_row( string $sql, $output = null ): ?array {
		$row = $this->pdo->query( $sql )->fetch( PDO::FETCH_ASSOC );
		return false === $row ? null : $row;
	}

	public function get_results( string $sql, $output = null ): array {
		return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC );
	}

	public function get_var( string $sql ) {
		$v = $this->pdo->query( $sql )->fetchColumn();
		return false === $v ? null : $v;
	}

	public function get_col( string $sql ): array {
		return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_COLUMN );
	}
}

/** Users, posts and the current user for capability checks. */
final class FakeSite {
	/** @var array<int, string[]> user => capabilities */
	public static array $users = array();
	/** @var array<int, object> */
	public static array $posts = array();
	/** @var array<int, int[]> user => post IDs they may edit */
	public static array $editable = array();
	public static int $current = 0;

	public static function reset(): void {
		self::$users    = array();
		self::$posts    = array();
		self::$editable = array();
		self::$current  = 0;
		$GLOBALS['wpdb'] = new FakeWpdb();
	}
}

function user_can( $user_id, $cap, ...$args ) {
	if ( 'edit_post' === $cap ) {
		return in_array( (int) ( $args[0] ?? 0 ), FakeSite::$editable[ (int) $user_id ] ?? array(), true );
	}
	return in_array( $cap, FakeSite::$users[ (int) $user_id ] ?? array(), true );
}
function current_user_can( $cap, ...$args ) {
	return user_can( FakeSite::$current, $cap, ...$args );
}
function get_current_user_id() {
	return FakeSite::$current;
}
function get_post( $id ) {
	return FakeSite::$posts[ (int) $id ] ?? null;
}
function rest_authorization_required_code() {
	return FakeSite::$current > 0 ? 403 : 401;
}
function sanitize_text_field( $s ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) );
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '', public array $data = array() ) {}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_data() {
		return $this->data;
	}
}
class WP_REST_Request {
	public function __construct( private array $params = array() ) {}
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}
class WP_REST_Response {
	public function __construct( public mixed $data = null, public int $status = 200 ) {}
	public function get_data() {
		return $this->data;
	}
}

/** In-memory options. */
$GLOBALS['fake_options'] = array();
function get_option( $name, $default = false ) {
	return $GLOBALS['fake_options'][ $name ] ?? $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['fake_options'][ $name ] = $value;
	return true;
}

/** Post meta and post fields for cloner tests. */
$GLOBALS['fake_meta'] = array();
function get_post_meta( $id, $key = '', $single = false ) {
	return $GLOBALS['fake_meta'][ (int) $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['fake_meta'][ (int) $id ][ $key ] = $value;
	return true;
}
function get_post_field( $field, $id ) {
	return FakeSite::$posts[ (int) $id ]->$field ?? '';
}
function get_post_thumbnail_id( $post ) {
	return (int) ( $GLOBALS['fake_meta'][ is_object( $post ) ? $post->ID : (int) $post ]['_thumbnail_id'] ?? 0 );
}
function get_the_title( $post ) {
	return is_object( $post ) ? (string) ( $post->post_title ?? '' ) : '';
}
function wp_slash( $value ) {
	return $value;
}

#[\AllowDynamicProperties]
final class WP_Post {
	public function __construct( array $fields = array() ) {
		foreach ( $fields as $k => $v ) {
			$this->$k = $v;
		}
	}
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
