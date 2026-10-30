<?php
/**
 * AI Website Studio database tables.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio;

defined( 'ABSPATH' ) || exit;

/**
 * Table names and dbDelta schema for the Website Studio module.
 *
 * Projects, usage and credentials keep their existing storage; these tables only hold
 * data that has no home there: reusable brand profiles, cached page analyses and an
 * audit trail of studio actions.
 */
final class StudioTables {

	/**
	 * Brand profiles table.
	 */
	public static function profiles(): string {
		global $wpdb;
		return $wpdb->prefix . 'aiscs_brand_profiles';
	}

	/**
	 * Design analyses table.
	 */
	public static function analyses(): string {
		global $wpdb;
		return $wpdb->prefix . 'aiscs_design_analyses';
	}

	/**
	 * Generated pages table.
	 */
	public static function pages(): string {
		global $wpdb;
		return $wpdb->prefix . 'aiscs_generated_pages';
	}

	/**
	 * Audit log table.
	 */
	public static function audit(): string {
		global $wpdb;
		return $wpdb->prefix . 'aiscs_audit_log';
	}

	/**
	 * Page revisions table.
	 */
	public static function revisions(): string {
		global $wpdb;
		return $wpdb->prefix . 'aiscs_page_revisions';
	}

	/**
	 * Design library table.
	 */
	public static function library(): string {
		global $wpdb;
		return $wpdb->prefix . 'aiscs_design_library';
	}

	/**
	 * All table names (uninstall).
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::profiles(), self::analyses(), self::pages(), self::audit(), self::revisions(), self::library() );
	}

	/**
	 * CREATE TABLE statements in dbDelta format (two spaces before PRIMARY KEY, one field per line).
	 *
	 * @param string $charset Charset/collation clause.
	 * @return string[]
	 */
	public static function schema( string $charset ): array {
		$profiles = self::profiles();
		$analyses = self::analyses();
		$audit    = self::audit();
		$pages     = self::pages();
		$revisions = self::revisions();
		$library   = self::library();
		return array(
			"CREATE TABLE {$revisions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  page_id bigint(20) unsigned NOT NULL DEFAULT 0,
  project_id bigint(20) unsigned NOT NULL DEFAULT 0,
  revision int(10) unsigned NOT NULL DEFAULT 1,
  action varchar(32) NOT NULL,
  summary varchar(255) NOT NULL DEFAULT '',
  section_id varchar(64) NOT NULL DEFAULT '',
  meta text NOT NULL,
  elements_hash char(32) NOT NULL DEFAULT '',
  snapshot longtext NOT NULL,
  snapshot_bytes int(10) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY page_revision (page_id,revision),
  KEY created_at (created_at)
) {$charset};",
			"CREATE TABLE {$library} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
  project_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(191) NOT NULL,
  kind varchar(16) NOT NULL DEFAULT 'section',
  category varchar(32) NOT NULL DEFAULT 'other',
  profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_page_id bigint(20) unsigned NOT NULL DEFAULT 0,
  elements longtext NOT NULL,
  summary text NOT NULL,
  uses_containers tinyint(1) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY owner_updated (owner_id,updated_at),
  KEY category (category)
) {$charset};",
			"CREATE TABLE {$pages} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  project_id bigint(20) unsigned NOT NULL DEFAULT 0,
  page_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_page_id bigint(20) unsigned NOT NULL DEFAULT 0,
  generation_type varchar(32) NOT NULL,
  profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'draft',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  section_map text NOT NULL,
  document longtext NOT NULL,
  notes text NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY page_id (page_id),
  KEY created_by (created_by,created_at),
  KEY project_id (project_id)
) {$charset};",
			"CREATE TABLE {$profiles} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
  project_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(191) NOT NULL,
  schema_version smallint(5) unsigned NOT NULL DEFAULT 1,
  tokens longtext NOT NULL,
  source_pages text NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY owner_updated (owner_id,updated_at),
  KEY project_id (project_id)
) {$charset};",
			"CREATE TABLE {$analyses} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
  project_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_page_id bigint(20) unsigned NOT NULL DEFAULT 0,
  source_hash char(64) NOT NULL DEFAULT '',
  analyzer_version smallint(5) unsigned NOT NULL DEFAULT 1,
  profile_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'complete',
  analysis longtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY source_lookup (source_page_id,source_hash),
  KEY owner_created (owner_id,created_at)
) {$charset};",
			"CREATE TABLE {$audit} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(64) NOT NULL,
  object_type varchar(32) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  details text NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY object_lookup (object_type,object_id)
) {$charset};",
		);
	}
}
