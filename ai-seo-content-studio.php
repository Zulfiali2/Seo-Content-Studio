<?php
/**
 * Plugin Name:       AI SEO Content Studio
 * Description:       SEO research, AI content generation, content optimisation and Elementor Free page building.
 * Version:           1.6.8
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            AQF Digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-seo-content-studio
 * Domain Path:       /languages
 *
 * @package AiSeoContentStudio
 */

defined( 'ABSPATH' ) || exit;

define( 'AISCS_VERSION', '1.6.8' );
define( 'AISCS_FILE', __FILE__ );
define( 'AISCS_DIR', plugin_dir_path( __FILE__ ) );
define( 'AISCS_URL', plugin_dir_url( __FILE__ ) );
define( 'AISCS_MIN_PHP', '8.2' );
define( 'AISCS_MIN_WP', '6.6' );

/*
 * The requirement gate below must stay parseable by old PHP versions, so it only
 * uses syntax available since PHP 5.6. Nothing namespaced is loaded until it passes.
 * Messages are translated inside the notice callback because translations must not
 * be loaded before the `init` action.
 */
$aiscs_requirement_errors = array();

if ( version_compare( PHP_VERSION, AISCS_MIN_PHP, '<' ) ) {
	$aiscs_requirement_errors[] = 'php';
}
if ( version_compare( get_bloginfo( 'version' ), AISCS_MIN_WP, '<' ) ) {
	$aiscs_requirement_errors[] = 'wp';
}
if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
	$aiscs_requirement_errors[] = 'sodium';
}

if ( ! empty( $aiscs_requirement_errors ) ) {
	add_action(
		'admin_notices',
		function () use ( $aiscs_requirement_errors ) {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			foreach ( $aiscs_requirement_errors as $aiscs_error ) {
				if ( 'php' === $aiscs_error ) {
					/* translators: 1: required PHP version, 2: current PHP version. */
					$aiscs_message = sprintf( __( 'AI SEO Content Studio requires PHP %1$s or newer. This site runs PHP %2$s.', 'ai-seo-content-studio' ), AISCS_MIN_PHP, PHP_VERSION );
				} elseif ( 'wp' === $aiscs_error ) {
					/* translators: 1: required WordPress version, 2: current WordPress version. */
					$aiscs_message = sprintf( __( 'AI SEO Content Studio requires WordPress %1$s or newer. This site runs WordPress %2$s.', 'ai-seo-content-studio' ), AISCS_MIN_WP, get_bloginfo( 'version' ) );
				} else {
					$aiscs_message = __( 'AI SEO Content Studio requires the PHP sodium extension to store API keys securely.', 'ai-seo-content-studio' );
				}
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $aiscs_message ) );
			}
		}
	);
	return;
}

unset( $aiscs_requirement_errors );

require_once AISCS_DIR . 'includes/Autoloader.php';
AiSeoContentStudio\Autoloader::register( 'AiSeoContentStudio\\', AISCS_DIR . 'includes/' );

register_activation_hook( __FILE__, array( AiSeoContentStudio\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( AiSeoContentStudio\Core\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( AiSeoContentStudio\Core\Plugin::class, 'boot' ) );
