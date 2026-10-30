<?php
/**
 * AI Website Studio admin screens.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Admin;

use AiSeoContentStudio\Core\Capabilities;
use AiSeoContentStudio\Core\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "AI Website Studio" menu and loads its interface.
 *
 * The studio UI is a separate script built on WordPress's own React packages
 * (wp-element, wp-components, wp-api-fetch), so it needs no build step and does not
 * touch the existing content-studio bundle. Only screens whose features are implemented
 * are registered; later phases add theirs.
 */
class WebsiteStudioPage {

	public const SLUG = 'aiscs-website-studio';

	/**
	 * Screen hook suffixes.
	 *
	 * @var string[]
	 */
	private array $hooks = array();

	public function __construct( private readonly Environment $environment ) {}

	/**
	 * Hook into WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Implemented views: slug suffix => title.
	 *
	 * @return array<string, string>
	 */
	public function views(): array {
		return array(
			'dashboard' => __( 'Dashboard', 'ai-seo-content-studio' ),
			'brand'     => __( 'Website Brand Analyzer', 'ai-seo-content-studio' ),
			'profiles'  => __( 'Brand Profiles', 'ai-seo-content-studio' ),
			'clone'     => __( 'Clone Existing Page', 'ai-seo-content-studio' ),
			'pages'     => __( 'Generated Pages', 'ai-seo-content-studio' ),
		);
	}

	/**
	 * Add the menu.
	 */
	public function addMenu(): void {
		$this->hooks[] = (string) add_menu_page(
			__( 'AI Website Studio', 'ai-seo-content-studio' ),
			__( 'AI Website Studio', 'ai-seo-content-studio' ),
			Capabilities::GENERATE,
			self::SLUG,
			fn () => $this->render( 'dashboard' ),
			'dashicons-layout',
			59
		);
		foreach ( $this->views() as $view => $title ) {
			if ( 'dashboard' === $view ) {
				add_submenu_page( self::SLUG, $title, $title, Capabilities::GENERATE, self::SLUG );
				continue;
			}
			$this->hooks[] = (string) add_submenu_page( self::SLUG, $title, $title, Capabilities::GENERATE, self::SLUG . '-' . $view, fn () => $this->render( $view ) );
		}
	}

	/**
	 * Render the mount point.
	 *
	 * @param string $view View.
	 */
	public function render( string $view ): void {
		printf(
			'<div class="wrap"><div id="aiscs-studio" data-view="%s"><p>%s</p></div><noscript>%s</noscript></div>',
			esc_attr( $view ),
			esc_html__( 'Loading…', 'ai-seo-content-studio' ),
			esc_html__( 'AI Website Studio requires JavaScript.', 'ai-seo-content-studio' )
		);
	}

	/**
	 * Enqueue the studio script on studio screens only.
	 *
	 * @param string $hook_suffix Screen.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->hooks, true ) ) {
			return;
		}
		$file = AISCS_DIR . 'assets/website-studio/studio.js';
		$ver  = AISCS_VERSION . '-' . ( is_readable( $file ) ? (string) filemtime( $file ) : '0' );
		wp_enqueue_script( 'aiscs-studio', AISCS_URL . 'assets/website-studio/studio.js', array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-url' ), $ver, true );
		wp_enqueue_style( 'aiscs-studio', AISCS_URL . 'assets/website-studio/studio.css', array( 'wp-components' ), $ver );
		wp_set_script_translations( 'aiscs-studio', 'ai-seo-content-studio', AISCS_DIR . 'languages' );

		$urls = array();
		foreach ( array_keys( $this->views() ) as $view ) {
			$urls[ $view ] = admin_url( 'admin.php?page=' . ( 'dashboard' === $view ? self::SLUG : self::SLUG . '-' . $view ) );
		}
		// No secrets: the studio reads everything else through the REST API.
		wp_add_inline_script(
			'aiscs-studio',
			'window.aiscsStudio = ' . wp_json_encode(
				array(
					'version'   => AISCS_VERSION,
					'canManage' => current_user_can( Capabilities::MANAGE ),
					'elementor' => $this->environment->elementor(),
					'urls'      => $urls,
				)
			) . ';',
			'before'
		);
	}
}
