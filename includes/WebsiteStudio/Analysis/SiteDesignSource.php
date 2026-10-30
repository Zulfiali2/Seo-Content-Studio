<?php
/**
 * Reads the site's own design data from WordPress and Elementor.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Analysis;

use AiSeoContentStudio\Elementor\ElementorBridgeInterface;

defined( 'ABSPATH' ) || exit;

/**
 * The only place the analyzers touch WordPress. Everything is read locally (post data,
 * Elementor documents and kit, theme.json, theme and Elementor stylesheets on disk), so
 * analysing the site's own pages makes no HTTP requests at all.
 */
class SiteDesignSource {

	/** Largest stylesheet read from disk. */
	public const MAX_CSS_BYTES = 524288;

	public function __construct( private readonly ElementorBridgeInterface $elementor ) {}

	/**
	 * Builder of a post: elementor, blocks or classic.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function builder( \WP_Post $post ): string {
		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			return 'elementor';
		}
		return has_blocks( $post->post_content ) ? 'blocks' : 'classic';
	}

	/**
	 * Whether Elementor is active and the post is built with it.
	 *
	 * @param int $post_id Post.
	 */
	public function isElementorDocument( int $post_id ): bool {
		return $this->elementor->isActive() && $this->elementor->isBuiltWithElementor( $post_id );
	}

	/**
	 * Elementor element tree.
	 *
	 * @param int $post_id Post.
	 * @return array<int, mixed>
	 */
	public function elementorElements( int $post_id ): array {
		return $this->elementor->elements( $post_id );
	}

	/**
	 * Active kit.
	 *
	 * @return array{id: int, modified: string, settings: array<string, mixed>}
	 */
	public function kit(): array {
		return $this->elementor->activeKit();
	}

	/**
	 * Registered Elementor widget types ([] when Elementor is inactive).
	 *
	 * @return string[]
	 */
	public function widgetTypes(): array {
		return $this->elementor->widgetTypes();
	}

	/**
	 * Theme global settings (theme.json presets).
	 *
	 * @return array<string, mixed>
	 */
	public function globalSettings(): array {
		return function_exists( 'wp_get_global_settings' ) ? (array) wp_get_global_settings() : array();
	}

	/**
	 * Theme global styles (theme.json styles, including user customisations).
	 *
	 * @return array<string, mixed>
	 */
	public function globalStyles(): array {
		return function_exists( 'wp_get_global_styles' ) ? (array) wp_get_global_styles() : array();
	}

	/**
	 * Theme stylesheets (child first, then parent), read from disk.
	 *
	 * @return array<string, string> Label => CSS.
	 */
	public function themeCss(): array {
		$out   = array();
		$paths = array_unique( array( get_stylesheet_directory() . '/style.css', get_template_directory() . '/style.css' ) );
		foreach ( $paths as $path ) {
			$css = self::readFile( $path, array( get_theme_root() ) );
			if ( '' !== $css ) {
				$out[ 'theme:' . basename( dirname( $path ) ) ] = $css;
			}
		}
		return $out;
	}

	/**
	 * Elementor's generated stylesheets for a post and the active kit (they contain the
	 * final CSS of every widget, including custom CSS), read from the uploads directory.
	 *
	 * @param int $post_id Post.
	 * @param int $kit_id  Kit.
	 * @return array<string, string> Label => CSS.
	 */
	public function elementorCss( int $post_id, int $kit_id ): array {
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return array();
		}
		$base = trailingslashit( (string) $uploads['basedir'] ) . 'elementor/css/';
		$out  = array();
		foreach ( array_filter( array( 'kit' => $kit_id, 'page' => $post_id ) ) as $label => $id ) {
			$css = self::readFile( $base . 'post-' . (int) $id . '.css', array( (string) $uploads['basedir'] ) );
			if ( '' !== $css ) {
				$out[ 'elementor:' . $label ] = $css;
			}
		}
		return $out;
	}

	/**
	 * Inputs fingerprint for caching: changes when the page, kit, theme or global styles change.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $kit_modified_marker Kit identity and modification marker.
	 */
	public function fingerprint( \WP_Post $post, string $kit_modified_marker ): string {
		$theme = wp_get_theme();
		$parts = array(
			$post->ID,
			$post->post_modified_gmt,
			md5( (string) get_post_meta( $post->ID, '_elementor_data', true ) ),
			$kit_modified_marker,
			$theme->get_stylesheet() . '@' . $theme->get( 'Version' ),
			md5( (string) wp_json_encode( $this->globalStyles() ) ),
		);
		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Read a stylesheet that resolves inside one of the allowed directories.
	 *
	 * @param string   $path    Path.
	 * @param string[] $allowed Allowed base directories.
	 */
	private static function readFile( string $path, array $allowed ): string {
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) || 'css' !== strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ) ) {
			return '';
		}
		$inside = false;
		foreach ( $allowed as $base ) {
			$base_real = realpath( $base );
			if ( false !== $base_real && str_starts_with( $real, trailingslashit( $base_real ) ) ) {
				$inside = true;
				break;
			}
		}
		if ( ! $inside ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local stylesheet, size-bounded.
		$css = file_get_contents( $real, false, null, 0, self::MAX_CSS_BYTES );
		return is_string( $css ) ? $css : '';
	}
}
