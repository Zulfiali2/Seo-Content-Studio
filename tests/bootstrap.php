<?php
/**
 * Unit-test bootstrap: minimal WordPress function stubs for the pure classes under test.
 *
 * Only functions the tested classes call are stubbed, with core's behaviour for the inputs
 * the tests use. Classes that need a real WordPress (repositories, REST controllers,
 * Elementor bridge) are covered by the integration checklist in docs/website-studio/TESTING.md.
 *
 * @package AiSeoContentStudio
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		return trim( strip_tags( (string) $text ) );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}
if ( ! function_exists( 'mysql_to_rfc3339' ) ) {
	function mysql_to_rfc3339( $date ) {
		return str_replace( ' ', 'T', (string) $date );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number, $domain = '' ) {
		return 1 === (int) $number ? $single : $plural;
	}
}

require dirname( __DIR__ ) . '/includes/Autoloader.php';
AiSeoContentStudio\Autoloader::register( 'AiSeoContentStudio\\', dirname( __DIR__ ) . '/includes/' );
