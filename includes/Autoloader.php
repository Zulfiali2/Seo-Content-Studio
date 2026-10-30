<?php
/**
 * Minimal PSR-4 autoloader so the plugin has no runtime Composer dependency.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a PSR-4 autoloader for one namespace prefix.
 */
final class Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @param string $prefix   Namespace prefix including trailing backslash.
	 * @param string $base_dir Directory containing the namespace root, with trailing slash.
	 */
	public static function register( string $prefix, string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $prefix, $base_dir ): void {
				if ( ! str_starts_with( $class_name, $prefix ) ) {
					return;
				}
				$relative = substr( $class_name, strlen( $prefix ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}
}
