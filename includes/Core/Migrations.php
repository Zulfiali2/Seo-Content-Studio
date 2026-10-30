<?php
/**
 * Database schema and versioned migrations.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Core;

use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\Settings\SettingsSchema;
use AiSeoContentStudio\Usage\UsageRepository;
use AiSeoContentStudio\WebsiteStudio\StudioTables;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades plugin tables with dbDelta().
 */
final class Migrations {

	/** Current schema version. Bump when the schema changes. */
	public const DB_VERSION = '6';

	/** Option storing the installed schema version. */
	public const OPTION = 'aiscs_db_version';

	/**
	 * Run migrations if the stored version is behind (called on every load so
	 * updates applied without re-activation are still migrated).
	 */
	public static function maybeMigrate(): void {
		if ( get_option( self::OPTION ) !== self::DB_VERSION ) {
			self::migrate();
		}
	}

	/**
	 * Create or update tables.
	 */
	public static function migrate(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = UsageRepository::tableName();
		$charset = $wpdb->get_charset_collate();

		// Version history: 1 = initial table; 2 = cost_source column ("provider" for
		// provider-reported charges, "estimate" for calculated ones).
		// dbDelta requires this exact formatting: two spaces before PRIMARY KEY, one field per line.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  operation varchar(32) NOT NULL,
  purpose varchar(64) NOT NULL DEFAULT '',
  provider varchar(32) NOT NULL,
  model varchar(191) NOT NULL DEFAULT '',
  input_tokens int(10) unsigned NOT NULL DEFAULT 0,
  output_tokens int(10) unsigned NOT NULL DEFAULT 0,
  units int(10) unsigned NOT NULL DEFAULT 0,
  estimated_cost decimal(12,6) DEFAULT NULL,
  currency char(3) NOT NULL DEFAULT 'USD',
  cost_source varchar(16) NOT NULL DEFAULT '',
  status varchar(16) NOT NULL,
  error_code varchar(32) NOT NULL DEFAULT '',
  request_hash char(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY operation_created (operation,created_at),
  KEY created_at (created_at),
  KEY request_hash (request_hash)
) {$charset};";

		$previous = (string) get_option( self::OPTION, '' );
		dbDelta( $sql );
		// 6: AI Website Studio tables (brand profiles, design analyses, audit log).
		foreach ( StudioTables::schema( $charset ) as $studio_sql ) {
			dbDelta( $studio_sql );
		}
		// First run on this site. On multisite, network activation only runs the activation
		// hook on the main site, and sites created later never run it, so roles are set here.
		if ( '' === $previous ) {
			Capabilities::install();
		}
		self::removeDemoSettings();
		// 4: competitor research covers the top 10 results; raise the old default of 5.
		if ( '' !== $previous && version_compare( $previous, '4', '<' ) ) {
			self::raiseCompetitorPages();
		}
		// 5: the default banned-words list no longer bans ordinary words.
		if ( '' !== $previous && version_compare( $previous, '5', '<' ) ) {
			self::replaceLegacyBannedWords();
		}

		update_option( self::OPTION, self::DB_VERSION, false );
	}

	/**
	 * Version 3: the offline demo mode was removed. Clear provider choices that pointed at
	 * it so the admin asks for a real provider instead of failing on an unknown one.
	 */
	private static function removeDemoSettings(): void {
		$stored = get_option( SettingsRepository::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return;
		}
		$changed = false;
		foreach ( array( 'default_text_provider', 'default_image_provider' ) as $key ) {
			if ( 'demo' === ( $stored['ai'][ $key ] ?? '' ) ) {
				$stored['ai'][ $key ] = '';
				$changed              = true;
			}
		}
		if ( 'demo' === ( $stored['research']['provider'] ?? '' ) ) {
			$stored['research']['provider'] = 'dataforseo';
			$changed                        = true;
		}
		unset( $stored['ai']['providers']['demo'] );
		if ( $changed ) {
			update_option( SettingsRepository::OPTION, $stored );
		}
	}

	/**
	 * Replace the banned-words list only when it is still the old default, untouched.
	 */
	private static function replaceLegacyBannedWords(): void {
		$stored = get_option( SettingsRepository::OPTION, array() );
		if ( is_array( $stored ) && SettingsSchema::LEGACY_BANNED_WORDS === (string) ( $stored['style']['banned_words'] ?? '' ) ) {
			$stored['style']['banned_words'] = SettingsSchema::DEFAULT_BANNED_WORDS;
			update_option( SettingsRepository::OPTION, $stored );
		}
	}

	/**
	 * Raise a saved "competitor pages" value that is still the old default (5) to 10.
	 */
	private static function raiseCompetitorPages(): void {
		$stored = get_option( SettingsRepository::OPTION, array() );
		if ( is_array( $stored ) && 5 === (int) ( $stored['research']['competitor_pages'] ?? 0 ) ) {
			$stored['research']['competitor_pages'] = 10;
			update_option( SettingsRepository::OPTION, $stored );
		}
	}
}
