<?php
/**
 * Front-end styles and approved scripts of imported pages.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * An imported page carries its own scoped CSS, allowed web-font stylesheets and (optionally)
 * the project's scripts in post meta. They are printed only on that page:
 *
 * - CSS and stylesheets on the page's front end and in Elementor's editor preview, so the design
 *   is visible while editing. The CSS only applies inside the page root (CssScoper), so other
 *   pages, the theme and the admin are not affected.
 * - Scripts only on the front end, only those a user with `unfiltered_html` approved, and only
 *   while their code is exactly what was approved (SHA-256). Never in the admin, the Elementor
 *   editor or its preview, the Customizer, or a REST/AJAX request. External script URLs are never
 *   loaded; only code that came from the ZIP.
 */
class ImportedPage {

	public const META_KEY         = '_aiscs_import_key';
	public const META_CSS         = '_aiscs_import_css';
	public const META_STYLESHEETS = '_aiscs_import_stylesheets';
	public const META_ROOT_FONT   = '_aiscs_import_root_font';
	public const META_SCRIPTS     = '_aiscs_import_scripts';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	/**
	 * Enqueue the current page's imported styles and approved scripts.
	 */
	public function enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}
		$id  = (int) get_queried_object_id();
		$css = (string) get_post_meta( $id, self::META_CSS, true );
		if ( $id <= 0 || '' === $css ) {
			return;
		}
		$handle = 'aiscs-import-' . $id;
		$i      = 0;
		foreach ( (array) get_post_meta( $id, self::META_STYLESHEETS, true ) as $url ) {
			if ( is_string( $url ) && self::allowedStylesheet( $url ) ) {
				wp_enqueue_style( $handle . '-font-' . ( ++$i ), esc_url_raw( $url ), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party font URL carries its own version.
			}
		}
		wp_register_style( $handle, false, array(), (string) get_post_modified_time( 'U', true, $id ) );
		wp_enqueue_style( $handle );
		$root_font = (string) get_post_meta( $id, self::META_ROOT_FONT, true );
		$prefix    = preg_match( '/^[0-9.]+(%|px|em|rem)$/', $root_font ) ? 'html{font-size:' . $root_font . '}' : '';
		wp_add_inline_style( $handle, self::safeCss( $prefix . $css ) );

		if ( self::scriptsAllowedHere() ) {
			$j = 0;
			foreach ( self::approved( (array) get_post_meta( $id, self::META_SCRIPTS, true ) ) as $script ) {
				$script_handle = $handle . '-js-' . ( ++$j );
				wp_register_script( $script_handle, false, array(), (string) get_post_modified_time( 'U', true, $id ), true );
				wp_enqueue_script( $script_handle );
				wp_add_inline_script( $script_handle, self::safeScript( (string) $script['code'] ) );
			}
		}
	}

	/**
	 * Approved scripts whose code still matches the approved hash.
	 *
	 * @param array<int, mixed> $scripts Stored scripts.
	 * @return array<int, array<string, mixed>>
	 */
	public static function approved( array $scripts ): array {
		return array_values(
			array_filter(
				$scripts,
				static fn ( $s ): bool => is_array( $s ) && ! empty( $s['approved'] ) && is_string( $s['code'] ?? null ) && hash_equals( (string) ( $s['hash'] ?? '' ), hash( 'sha256', (string) $s['code'] ) )
			)
		);
	}

	/**
	 * New scripts keep the approval of an identical previously approved script (same hash).
	 *
	 * @param array<int, mixed>                $old Stored scripts.
	 * @param array<int, array<string, mixed>> $new New scripts.
	 * @return array<int, array<string, mixed>>
	 */
	public static function keepApprovals( array $old, array $new ): array {
		$approved = array();
		foreach ( self::approved( $old ) as $s ) {
			$approved[ $s['hash'] ] = $s;
		}
		foreach ( $new as &$s ) {
			if ( isset( $approved[ $s['hash'] ] ) ) {
				$s['approved']    = true;
				$s['approved_by'] = (int) ( $approved[ $s['hash'] ]['approved_by'] ?? 0 );
				$s['approved_at'] = (int) ( $approved[ $s['hash'] ]['approved_at'] ?? 0 );
			}
		}
		return $new;
	}

	/**
	 * Set approvals (by hash) on stored scripts.
	 *
	 * @param array<int, mixed> $scripts Stored scripts.
	 * @param string[]          $hashes  Hashes to approve (all others are unapproved).
	 * @param int               $user    Approving user.
	 * @return array<int, array<string, mixed>>
	 */
	public static function setApprovals( array $scripts, array $hashes, int $user ): array {
		$out = array();
		foreach ( $scripts as $s ) {
			if ( ! is_array( $s ) || ! is_string( $s['hash'] ?? null ) ) {
				continue;
			}
			$on            = in_array( $s['hash'], $hashes, true ) && hash_equals( $s['hash'], hash( 'sha256', (string) ( $s['code'] ?? '' ) ) );
			$s['approved'] = $on;
			if ( $on ) {
				$s['approved_by'] = $user;
				$s['approved_at'] = time();
			} else {
				unset( $s['approved_by'], $s['approved_at'] );
			}
			$out[] = $s;
		}
		return $out;
	}

	/**
	 * Whether imported scripts may run in the current request.
	 */
	public static function scriptsAllowedHere(): bool {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_customize_preview() ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of Elementor's preview flag.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) {
			return false;
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->preview ) && \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
			return false;
		}
		return true;
	}

	/**
	 * Stylesheet URL allowed on the front end.
	 *
	 * @param string $url URL.
	 */
	public static function allowedStylesheet( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return str_starts_with( $url, 'https://' ) && in_array( $host, AssetRefs::STYLESHEET_HOSTS, true );
	}

	/**
	 * CSS that cannot close the <style> element.
	 *
	 * @param string $css CSS.
	 */
	public static function safeCss( string $css ): string {
		return PreviewRenderer::safeCss( $css );
	}

	/**
	 * Script text that cannot close the <script> element.
	 *
	 * @param string $code Code.
	 */
	public static function safeScript( string $code ): string {
		return (string) preg_replace( '#</(script)#i', '<\\\\/$1', $code );
	}
}
