<?php
/**
 * Static analysis of a project's JavaScript (it is never run).
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Reads JavaScript as text: what it does for the page (menus, tabs, scroll effects …), what in
 * it needs care (network requests, redirects, dynamic code, WordPress access …), and which
 * classes and ids it refers to (those parts of the page are kept as original HTML so the script
 * still finds them). The result is shown to the user before any script can be approved.
 */
final class ScriptAnalyzer {

	/** Patterns => feature label key. */
	private const FEATURES = array(
		'mobile_menu'     => '/(menu|nav|burger|hamburger|toggle)[\w-]*["\']?\)?\s*\.?\s*(classList|addEventListener|onclick)|aria-expanded/i',
		'tabs'            => '/\btab(s|list|panel)?\b|role\s*=\s*["\']tab/i',
		'accordion'       => '/accordion|\bfaq\b|<details|\.open\s*=|toggleAttribute\(\s*["\']open/i',
		'slider'          => '/slider|carousel|swiper|slick|splide|glide/i',
		'marquee'         => '/marquee|ticker/i',
		'scroll_reveal'   => '/IntersectionObserver/',
		'scroll_effects'  => '/addEventListener\(\s*["\']scroll|onscroll|scrollY|pageYOffset|scrollTop/',
		'sticky_header'   => '/sticky|is-scrolled|scrolled/i',
		'counter'         => '/count(er|up)|animateValue|requestAnimationFrame[\s\S]{0,200}(textContent|innerText)/i',
		'forms'           => '/addEventListener\(\s*["\']submit|\.submit\(|FormData|checkValidity|reportValidity/',
		'smooth_scroll'   => '/scrollIntoView|behavior\s*:\s*["\']smooth|scrollTo\(/',
		'progress'        => '/progress/i',
		'modal'           => '/modal|dialog|lightbox|popup/i',
	);

	/** Patterns => [risk key, severity]. */
	private const RISKS = array(
		'dynamic_code'     => array( '/\beval\s*\(|\bnew\s+Function\s*\(|set(Timeout|Interval)\s*\(\s*["\'`]/', 'high' ),
		'loads_scripts'    => array( '/createElement\(\s*["\']script|\bimport\s*\(|importScripts\s*\(|document\.write(ln)?\s*\(/', 'high' ),
		'wordpress_access' => array( '/wpApiSettings|\bwp\.(api|ajax|data|apiFetch)\b|ajaxurl|\/wp-admin|\/wp-json|admin-ajax\.php|_wpnonce|X-WP-Nonce/i', 'high' ),
		'network'          => array( '/\bfetch\s*\(|XMLHttpRequest|sendBeacon|\bWebSocket\b|EventSource|\$\.(ajax|get|post|getJSON)\s*\(/', 'medium' ),
		'cookies_storage'  => array( '/document\.cookie|localStorage|sessionStorage|indexedDB/', 'medium' ),
		'redirect'         => array( '/(window\.|document\.)?location(\.href)?\s*=(?!=)|location\.(replace|assign)\s*\(|window\.open\s*\(/', 'medium' ),
		'html_injection'   => array( '/\.(innerHTML|outerHTML)\s*\+?=|insertAdjacentHTML|\.html\s*\(/', 'low' ),
		'messaging'        => array( '/postMessage|addEventListener\(\s*["\']message/', 'low' ),
		'obfuscated'       => array( '/\\\\x[0-9a-f]{2}(\\\\x[0-9a-f]{2}){20,}|atob\s*\(|String\.fromCharCode\s*\([\d\s,]{40,}/i', 'high' ),
		'global_events'    => array( '/addEventListener\(\s*["\'](keydown|keypress|keyup|copy|paste|beforeunload)/', 'low' ),
	);

	/**
	 * Analyse one script.
	 *
	 * @param string $code Source.
	 * @return array{bytes: int, features: string[], risks: array<int, array{key: string, severity: string}>, level: string, hooks: string[], uses_jquery: bool, waits_for_dom: bool}
	 */
	public static function analyze( string $code ): array {
		$features = array();
		foreach ( self::FEATURES as $key => $pattern ) {
			if ( preg_match( $pattern, $code ) ) {
				$features[] = $key;
			}
		}
		$risks = array();
		$level = 'none';
		$rank  = array( 'none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3 );
		foreach ( self::RISKS as $key => [ $pattern, $severity ] ) {
			if ( preg_match( $pattern, $code ) ) {
				$risks[] = array(
					'key'      => $key,
					'severity' => $severity,
				);
				if ( $rank[ $severity ] > $rank[ $level ] ) {
					$level = $severity;
				}
			}
		}
		return array(
			'bytes'         => strlen( $code ),
			'features'      => $features,
			'risks'         => $risks,
			'level'         => $level,
			'hooks'         => self::hooks( $code ),
			'uses_jquery'   => (bool) preg_match( '/\bjQuery\b|\$\(\s*(document|function|["\'])/', $code ),
			'waits_for_dom' => (bool) preg_match( '/DOMContentLoaded|\$\(\s*document\s*\)\.ready|\$\(\s*function|window\.onload|addEventListener\(\s*["\']load/', $code ),
		);
	}

	/**
	 * Class and id names a script may look up (words inside its string literals).
	 *
	 * @param string $code Source.
	 * @return string[]
	 */
	public static function hooks( string $code ): array {
		$names = array();
		if ( preg_match_all( '/"((?:[^"\\\\\n]|\\\\.){1,200})"|\'((?:[^\'\\\\\n]|\\\\.){1,200})\'|`([^`]{1,200})`/', $code, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $match ) {
				$text = $match[1] ?? '';
				$text = '' !== $text ? $text : ( $match[2] ?? '' );
				$text = '' !== $text ? $text : ( $match[3] ?? '' );
				if ( preg_match_all( '/[.#]?([A-Za-z_][A-Za-z0-9_-]{1,60})/', $text, $words ) ) {
					foreach ( $words[1] as $word ) {
						$names[ $word ] = true;
					}
				}
			}
		}
		return array_keys( $names );
	}
}
