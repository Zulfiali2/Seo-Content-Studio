<?php
/**
 * Detects optional integrations and runtime capabilities.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only environment probe. Never loads or modifies other plugins.
 */
final class Environment {

	/**
	 * Elementor status. Elementor Pro is reported but never required.
	 *
	 * @return array{active: bool, version: string|null, pro_active: bool}
	 */
	public function elementor(): array {
		$active = defined( 'ELEMENTOR_VERSION' ) && did_action( 'elementor/loaded' ) > 0;
		return array(
			'active'     => $active,
			'version'    => defined( 'ELEMENTOR_VERSION' ) ? (string) constant( 'ELEMENTOR_VERSION' ) : null,
			'pro_active' => defined( 'ELEMENTOR_PRO_VERSION' ),
		);
	}

	/**
	 * Known SEO plugins that may own title/meta/schema output.
	 *
	 * Detection uses each plugin's version constant. Later phases use this to
	 * avoid emitting duplicate meta tags or schema.
	 *
	 * @return array<int, array{id: string, name: string, version: string}>
	 */
	public function seoPlugins(): array {
		$known = array(
			'yoast'        => array( 'WPSEO_VERSION', 'Yoast SEO' ),
			'rankmath'     => array( 'RANK_MATH_VERSION', 'Rank Math' ),
			'aioseo'       => array( 'AIOSEO_VERSION', 'All in One SEO' ),
			'seopress'     => array( 'SEOPRESS_VERSION', 'SEOPress' ),
			'seoframework' => array( 'THE_SEO_FRAMEWORK_VERSION', 'The SEO Framework' ),
		);

		$found = array();
		foreach ( $known as $id => [ $constant, $name ] ) {
			if ( defined( $constant ) ) {
				$found[] = array(
					'id'      => $id,
					'name'    => $name,
					'version' => (string) constant( $constant ),
				);
			}
		}
		return $found;
	}

	/**
	 * Whether Action Scheduler is available for background jobs.
	 */
	public function hasActionScheduler(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Diagnostics summary for the dashboard.
	 *
	 * @return array<string, mixed>
	 */
	public function diagnostics(): array {
		global $wpdb;

		return array(
			'plugin_version'   => AISCS_VERSION,
			'php_version'      => PHP_VERSION,
			'wp_version'       => get_bloginfo( 'version' ),
			'db_version'       => (string) get_option( Migrations::OPTION, '' ),
			'db_expected'      => Migrations::DB_VERSION,
			'db_server'        => method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '',
			'sodium'           => function_exists( 'sodium_crypto_secretbox' ),
			'encryption_key'   => defined( 'AISCS_ENCRYPTION_KEY' ) ? 'constant' : 'salts',
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'action_scheduler' => $this->hasActionScheduler(),
			'max_execution'    => (int) ini_get( 'max_execution_time' ),
			'memory_limit'     => (string) ini_get( 'memory_limit' ),
			'elementor'        => $this->elementor(),
			'seo_plugins'      => $this->seoPlugins(),
		);
	}
}
