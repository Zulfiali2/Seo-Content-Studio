<?php
/**
 * Admin menu and React app loader.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Admin;

use AiSeoContentStudio\Core\Capabilities;
use AiSeoContentStudio\Core\Environment;
use AiSeoContentStudio\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's admin pages. Every page mounts the same React bundle,
 * which renders the view named in data-view. Only implemented views are listed;
 * later phases add Templates and Image Settings.
 */
class AdminPage {

	public const SLUG = 'ai-seo-content-studio';

	/**
	 * Hook suffixes of registered pages.
	 *
	 * @var string[]
	 */
	private array $hooks = array();

	/**
	 * Constructor.
	 *
	 * @param Environment        $environment Environment probe.
	 * @param SettingsRepository $settings    Settings.
	 */
	public function __construct( private readonly Environment $environment, private readonly SettingsRepository $settings ) {}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AISCS_FILE ), array( $this, 'actionLinks' ) );
	}

	/**
	 * Views: slug suffix => [title, capability].
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function views(): array {
		return array(
			'dashboard' => array( __( 'Dashboard', 'ai-seo-content-studio' ), Capabilities::GENERATE ),
			'new'       => array( __( 'New Content', 'ai-seo-content-studio' ), Capabilities::GENERATE ),
			'optimizer' => array( __( 'Existing Content Optimizer', 'ai-seo-content-studio' ), Capabilities::GENERATE ),
			'projects'  => array( __( 'Projects and History', 'ai-seo-content-studio' ), Capabilities::GENERATE ),
			'providers' => array( __( 'AI Providers', 'ai-seo-content-studio' ), Capabilities::MANAGE ),
			'brand'     => array( __( 'Brand and Writing Style', 'ai-seo-content-studio' ), Capabilities::MANAGE ),
			'research'  => array( __( 'SEO Research Settings', 'ai-seo-content-studio' ), Capabilities::MANAGE ),
			'usage'     => array( __( 'Usage and Costs', 'ai-seo-content-studio' ), Capabilities::MANAGE ),
			'settings'  => array( __( 'General Settings', 'ai-seo-content-studio' ), Capabilities::MANAGE ),
		);
	}

	/**
	 * Add the menu and submenus.
	 */
	public function addMenu(): void {
		$this->hooks[] = (string) add_menu_page(
			__( 'AI SEO Content Studio', 'ai-seo-content-studio' ),
			__( 'AI SEO Studio', 'ai-seo-content-studio' ),
			Capabilities::GENERATE,
			self::SLUG,
			fn () => $this->render( 'dashboard' ),
			'dashicons-edit-page',
			58
		);

		foreach ( $this->views() as $view => [ $title, $cap ] ) {
			if ( 'dashboard' === $view ) {
				// Same slug as the top-level page: no second callback, or the page would render twice.
				add_submenu_page( self::SLUG, $title, $title, $cap, self::SLUG );
				continue;
			}
			$this->hooks[] = (string) add_submenu_page( self::SLUG, $title, $title, $cap, self::SLUG . '-' . $view, fn () => $this->render( $view ) );
		}
	}

	/**
	 * Output the mount point.
	 *
	 * @param string $view View id.
	 */
	public function render( string $view ): void {
		printf(
			'<div class="wrap"><div id="aiscs-app" data-view="%s"><p>%s</p></div><noscript>%s</noscript></div>',
			esc_attr( $view ),
			esc_html__( 'Loading…', 'ai-seo-content-studio' ),
			esc_html__( 'AI SEO Content Studio requires JavaScript.', 'ai-seo-content-studio' )
		);
	}

	/**
	 * Enqueue the bundle on plugin pages only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, $this->hooks, true ) ) {
			return;
		}

		$asset_file = AISCS_DIR . 'assets/build/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'AI SEO Content Studio: the admin interface has not been built. Run "npm run build" or install the release ZIP.', 'ai-seo-content-studio' ) . '</p></div>';
				}
			);
			return;
		}
		$asset = require $asset_file;

		wp_enqueue_script( 'aiscs-admin', AISCS_URL . 'assets/build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'aiscs-admin', AISCS_URL . 'assets/build/style-index.css', array( 'wp-components' ), $asset['version'] );
		wp_set_script_translations( 'aiscs-admin', 'ai-seo-content-studio', AISCS_DIR . 'languages' );

		// Bootstrap data contains no secrets; credentials are fetched as masked status via REST.
		wp_add_inline_script(
			'aiscs-admin',
			'window.aiscsBootstrap = ' . wp_json_encode(
				array(
					'version'   => AISCS_VERSION,
					'canManage' => current_user_can( Capabilities::MANAGE ),
					'adminUrl'  => admin_url( 'admin.php' ),
					'pageSlug'  => self::SLUG,
					'elementor' => $this->environment->elementor(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function actionLinks( array $links ): array {
		if ( current_user_can( Capabilities::MANAGE ) ) {
			array_unshift(
				$links,
				sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-providers' ) ), esc_html__( 'Settings', 'ai-seo-content-studio' ) )
			);
		}
		return $links;
	}
}
