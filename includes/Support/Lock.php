<?php
/**
 * Atomic, expiring lock backed by the options table.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Prevents duplicate execution of billable operations across concurrent requests.
 *
 * The add_option() call is an INSERT on a unique key, so only one request can create the
 * lock. Transients are not used because they are not atomic with object caches.
 */
final class Lock {

	private const PREFIX = 'aiscs_lock_';

	/**
	 * Locks this request holds: option name => stored value ("expiry:token"). Release deletes a
	 * lock only while it still has this value, so a request whose lock expired and was taken
	 * over cannot remove the newer owner's lock.
	 *
	 * @var array<string, string>
	 */
	private static array $held = array();

	/**
	 * Try to acquire a lock.
	 *
	 * @param string $key Lock key (hashed internally).
	 * @param int    $ttl Seconds after which a stale lock may be taken over.
	 */
	public static function acquire( string $key, int $ttl ): bool {
		$name  = self::name( $key );
		$value = ( time() + $ttl ) . ':' . bin2hex( random_bytes( 8 ) );
		global $wpdb;
		if ( $wpdb instanceof \wpdb ) {
			// Atomic: the unique option_name index lets exactly one INSERT IGNORE succeed.
			// The expiry is the part before ":" (older plain-number values read the same way).
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d", $name, time() ) );
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
			// phpcs:enable
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			if ( 1 === (int) $inserted ) {
				self::$held[ $name ] = $value;
				return true;
			}
			return false;
		}
		// Fallback without a database handle (unit tests).
		if ( ! add_option( $name, $value, '', false ) ) {
			$current = (int) strtok( (string) get_option( $name, '0' ), ':' );
			if ( $current <= 0 || $current >= time() ) {
				return false;
			}
			delete_option( $name );
			if ( ! add_option( $name, $value, '', false ) ) {
				return false;
			}
		}
		self::$held[ $name ] = $value;
		return true;
	}

	/**
	 * Release a lock this request holds. A lock it does not hold (never acquired, or expired
	 * and taken over by another request) is left alone.
	 *
	 * @param string $key Lock key.
	 */
	public static function release( string $key ): void {
		$name  = self::name( $key );
		$value = self::$held[ $name ] ?? null;
		unset( self::$held[ $name ] );
		if ( null === $value ) {
			return;
		}
		global $wpdb;
		if ( $wpdb instanceof \wpdb ) {
			// Compare-and-delete in one statement.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$wpdb->options,
				array(
					'option_name'  => $name,
					'option_value' => $value,
				)
			);
			wp_cache_delete( $name, 'options' );
			return;
		}
		if ( get_option( $name ) === $value ) {
			delete_option( $name );
		}
	}

	/**
	 * Option name for a key.
	 *
	 * @param string $key Lock key.
	 */
	private static function name( string $key ): string {
		return self::PREFIX . substr( hash( 'sha256', $key ), 0, 40 );
	}
}
