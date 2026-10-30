<?php
/**
 * Style signals from stylesheet rules.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Analysis;

use AiSeoContentStudio\WebsiteStudio\Css\CssParser;

defined( 'ABSPATH' ) || exit;

/**
 * Turns parsed CSS into StyleSignals by classifying each selector into a role
 * (heading level, body text, button, link, card, image, section, container).
 */
final class CssStyleAnalyzer {

	public function __construct( private readonly CssParser $parser = new CssParser() ) {}

	/**
	 * Analyze CSS text.
	 *
	 * @param string       $css     CSS.
	 * @param StyleSignals $signals Signals (out).
	 * @param float        $weight  Weight per observation.
	 * @return array{rules: int, breakpoints: int[], truncated: bool}
	 */
	public function analyze( string $css, StyleSignals $signals, float $weight = 1.0 ): array {
		$parsed = $this->parser->parse( $css );
		foreach ( $parsed['rules'] as $rule ) {
			if ( 'print' === $rule['device'] ) {
				continue;
			}
			foreach ( $rule['selectors'] as $selector ) {
				$role = self::role( $selector );
				if ( '' !== $role ) {
					$this->rule( $role, $rule['declarations'], $rule['device'], $signals, $weight );
				}
			}
		}
		foreach ( $parsed['breakpoints'] as $bp ) {
			$signals->add( $bp <= CssParser::MOBILE_MAX + 20 ? 'breakpoints.mobile' : ( $bp <= CssParser::TABLET_MAX + 20 ? 'breakpoints.tablet' : 'breakpoints.wide' ), (string) $bp, $weight );
		}
		return array(
			'rules'       => count( $parsed['rules'] ),
			'breakpoints' => $parsed['breakpoints'],
			'truncated'   => $parsed['truncated'],
		);
	}

	/**
	 * Classify a selector by its last compound selector.
	 *
	 * @param string $selector Selector.
	 */
	public static function role( string $selector ): string {
		$selector = strtolower( $selector );
		if ( preg_match( '/:(hover|focus|active|visited|focus-visible|focus-within)\b|::?(before|after|placeholder|selection|marker)/', $selector ) ) {
			return ''; // State and pseudo-element styles are not the resting brand style.
		}
		$parts = preg_split( '/\s*[>+~]\s*|\s+/', trim( $selector ) );
		$last  = (string) end( $parts );

		if ( preg_match( '/^(h[1-6])\b/', $last, $m ) || preg_match( '/\.(?:elementor-size-|has-)?(h[1-6])(?:\b|-)/', $last, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '/(^|\.)(button|btn)\b|elementor-button\b|wp-block-button__link|wp-element-button|input\[type=["\']?submit/', $last ) || str_starts_with( $last, 'button' ) ) {
			return 'button';
		}
		if ( preg_match( '/^(body|p)$|^body\.|^\.elementor-kit-\d+$|^:root$/', $last ) || 'html' === $last ) {
			return 'body';
		}
		if ( preg_match( '/^a$|^a[.\[]/', $last ) ) {
			return 'link';
		}
		if ( preg_match( '/^img\b|wp-block-image|elementor-image|attachment-/', $last ) ) {
			return 'image';
		}
		if ( preg_match( '/card|service|feature|box\b|tile|pricing|testimonial/', $last ) ) {
			return 'card';
		}
		if ( preg_match( '/container|wrapper|\.wrap\b|site-content|content-area|alignwide|is-layout-constrained|e-con-inner/', $last ) ) {
			return 'container';
		}
		if ( preg_match( '/^(section|header|footer|main)\b|\.section\b|elementor-section\b|\.e-con\b|wp-block-group\b|wp-block-cover\b/', $last ) ) {
			return 'section';
		}
		return '';
	}

	/**
	 * Record one rule's declarations for a role.
	 *
	 * @param string                $role         Role.
	 * @param array<string, string> $declarations Declarations.
	 * @param string                $device       Device.
	 * @param StyleSignals          $signals      Signals.
	 * @param float                 $weight       Weight.
	 */
	private function rule( string $role, array $declarations, string $device, StyleSignals $signals, float $weight ): void {
		$heading = (bool) preg_match( '/^h[1-6]$/', $role );
		$d       = $declarations;
		$color   = $d['color'] ?? '';
		$bg      = self::background( $d );

		if ( 'desktop' === $device ) {
			if ( '' !== $color ) {
				$slot = match ( true ) {
					$heading           => 'colors.heading',
					'button' === $role => 'colors.button_text',
					'link' === $role   => 'colors.link',
					default            => 'body' === $role ? 'colors.text' : '',
				};
				if ( '' !== $slot ) {
					$signals->add( $slot, $color, $weight );
				}
			}
			if ( '' !== $bg ) {
				$slot = match ( $role ) {
					'button'            => 'colors.button_bg',
					'card'              => 'colors.card_bg',
					'body'              => 'colors.background',
					'section'           => 'colors.section_bg',
					default             => '',
				};
				if ( '' !== $slot ) {
					$signals->add( $slot, $bg, $weight );
				}
			}
			foreach ( array( 'border-color', 'border' ) as $prop ) {
				if ( isset( $d[ $prop ] ) && preg_match( '/(#[0-9a-f]{3,8}|rgba?\([^)]*\))/i', $d[ $prop ], $m ) ) {
					$signals->add( 'colors.border', $m[1], $weight * 0.5 );
				}
			}

			$font_group = $heading ? 'heading' : ( 'button' === $role ? 'button' : ( 'body' === $role ? 'body' : '' ) );
			if ( '' !== $font_group ) {
				if ( isset( $d['font-family'] ) ) {
					$signals->add( "fonts.$font_group", self::firstFamily( $d['font-family'] ), $weight );
				}
				if ( isset( $d['font-weight'] ) ) {
					$signals->add( "weights.$font_group", $d['font-weight'], $weight );
				}
				if ( isset( $d['font'] ) && preg_match( '/(?:^|\s)\d+(?:px|rem|em)(?:\/[^\s]+)?\s+(.+)$/', $d['font'], $m ) ) {
					$signals->add( "fonts.$font_group", self::firstFamily( $m[1] ), $weight * 0.8 );
				}
				if ( isset( $d['line-height'] ) && 'button' !== $font_group ) {
					$signals->add( "line_heights.$font_group", $d['line-height'], $weight );
				}
			}
		}

		if ( ( $heading || 'body' === $role ) && isset( $d['font-size'] ) ) {
			$signals->add( 'sizes.' . ( $heading ? $role : 'body' ) . ".$device", $d['font-size'], $weight );
		}
		if ( in_array( $role, array( 'button', 'card', 'image' ), true ) && isset( $d['border-radius'] ) ) {
			$signals->add( "radii.$role", $d['border-radius'], $weight );
		}
		if ( 'card' === $role && isset( $d['box-shadow'] ) ) {
			$signals->add( 'shadows.card', $d['box-shadow'], $weight );
		}
		if ( in_array( $role, array( 'button', 'card' ), true ) && isset( $d['padding'] ) && 'desktop' === $device ) {
			$signals->add( "paddings.$role", $d['padding'], $weight );
		}
		if ( 'section' === $role && isset( $d['padding'] ) ) {
			$signals->add( "paddings.section.$device", $d['padding'], $weight );
		}
		if ( 'container' === $role && 'desktop' === $device ) {
			foreach ( array( 'max-width', 'width' ) as $prop ) {
				if ( isset( $d[ $prop ] ) && preg_match( '/^\d{3,4}px$/', $d[ $prop ] ) ) {
					$signals->add( 'layout.container_width', $d[ $prop ], 'max-width' === $prop ? $weight : $weight * 0.5 );
				}
			}
		}
		if ( isset( $d['gap'] ) && in_array( $role, array( 'section', 'container', 'card' ), true ) ) {
			$signals->add( 'spacing.gap', $d['gap'], $weight * 0.5 );
		}
	}

	/**
	 * Solid background colour of a rule, if any.
	 *
	 * @param array<string, string> $d Declarations.
	 */
	private static function background( array $d ): string {
		if ( isset( $d['background-color'] ) ) {
			return $d['background-color'];
		}
		if ( isset( $d['background'] ) && ! str_contains( $d['background'], 'gradient' ) && preg_match( '/(#[0-9a-f]{3,8}\b|rgba?\([^)]*\))/i', $d['background'], $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * First family of a font-family list, without quotes.
	 *
	 * @param string $list Font family list.
	 */
	public static function firstFamily( string $list ): string {
		foreach ( explode( ',', $list ) as $family ) {
			$family = trim( $family, " \t\"'" );
			if ( '' !== $family && ! in_array( strtolower( $family ), array( 'inherit', 'initial', 'unset', 'var' ), true ) && ! str_contains( $family, '(' ) ) {
				return $family;
			}
		}
		return '';
	}
}
