<?php
/**
 * Brand design tokens from style signals.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Design;

use AiSeoContentStudio\WebsiteStudio\Analysis\StyleSignals;

defined( 'ABSPATH' ) || exit;

/**
 * Chooses one value per design token from weighted observations.
 *
 * Signals arrive grouped by where they were observed (elementor_kit, elementor_widgets,
 * theme_json, css); the groups are merged with their weights and the strongest valid value
 * wins. Each token group records the source that supplied its winning value. Tokens with no
 * observation stay empty: nothing is invented.
 */
final class BrandExtractor {

	/** Every slot a colour token can come from. */
	private const COLOR_SLOTS = array( 'colors.primary', 'colors.secondary', 'colors.accent', 'colors.button_bg', 'colors.palette', 'colors.text', 'colors.heading', 'colors.link', 'colors.card_bg', 'colors.section_bg', 'colors.background' );

	/**
	 * Extract tokens.
	 *
	 * @param array<string, StyleSignals> $sets Signals by source (see DesignTokens::SOURCES).
	 * @return array<string, mixed> Normalised tokens (DesignTokens schema).
	 */
	public function extract( array $sets ): array {
		$all = new StyleSignals();
		foreach ( $sets as $signals ) {
			if ( $signals instanceof StyleSignals ) {
				$all->merge( $signals );
			}
		}
		$color  = static fn ( string $v ): ?string => DesignTokens::color( $v );
		$opaque = static fn ( string $v ): ?string => ( null !== ( $c = DesignTokens::color( $v ) ) && 7 === strlen( $c ) ) ? $c : null;

		$t       = DesignTokens::blank();
		$sources = array();

		// Colours.
		$palette = $this->palette( $all );
		$brand   = array_values( array_filter( array_keys( $palette ), static fn ( string $hex ): bool => ! self::isNeutral( $hex ) ) );

		$t['colors']['primary'] = $all->top( 'colors.primary', $opaque ) ?: $all->top( 'colors.button_bg', $opaque ) ?: ( $brand[0] ?? '' );
		$used                   = array( $t['colors']['primary'] );
		$t['colors']['secondary'] = $all->top( 'colors.secondary', $opaque ) ?: self::firstUnused( $brand, $used );
		$used[]                   = $t['colors']['secondary'];
		$t['colors']['accent']    = $all->top( 'colors.accent', $opaque ) ?: self::firstUnused( array_merge( array_keys( $all->ranked( 'colors.link' ) ), $brand ), $used, $opaque );
		$t['colors']['text']       = $all->top( 'colors.text', $opaque );
		$t['colors']['heading']    = $all->top( 'colors.heading', $opaque ) ?: $t['colors']['text'];
		$t['colors']['background'] = $all->top( 'colors.background', $opaque );
		// Surface: the first panel colour that differs from the page (browsers default to white).
		$t['colors']['surface'] = self::firstUnused( array_merge( array_keys( $all->ranked( 'colors.card_bg' ) ), array_keys( $all->ranked( 'colors.section_bg' ) ) ), array( $t['colors']['background'] ?: '#FFFFFF' ), $opaque );
		$t['colors']['border']  = $all->top( 'colors.border', $color );
		$sources['colors']      = $this->source( $sets, self::COLOR_SLOTS, $t['colors']['primary'] ?: $t['colors']['text'] );

		$roles = array();
		foreach ( DesignTokens::COLOR_ROLES as $role ) {
			if ( '' !== $t['colors'][ $role ] ) {
				$roles[ $t['colors'][ $role ] ][] = $role;
			}
		}
		foreach ( array( 'colors.link' => 'link', 'colors.button_bg' => 'button' ) as $slot => $role ) {
			$top = $all->top( $slot, $color );
			if ( '' !== $top ) {
				$roles[ $top ][] = $role;
			}
		}
		foreach ( array_slice( $palette, 0, 16, true ) as $hex => $weight ) {
			$t['palette'][] = array(
				'hex'   => $hex,
				'count' => (int) round( $weight ),
				'roles' => array_values( array_unique( $roles[ $hex ] ?? array() ) ),
			);
		}

		// Typography.
		$family = static fn ( string $v ): ?string => DesignTokens::fontFamily( $v );
		$weight = static fn ( string $v ): ?string => DesignTokens::fontWeight( $v );
		$t['typography']['body']['family']    = $all->top( 'fonts.body', $family );
		$t['typography']['heading']['family'] = $all->top( 'fonts.heading', $family ) ?: $t['typography']['body']['family'];
		$t['typography']['button']['family']  = $all->top( 'fonts.button', $family ) ?: $t['typography']['body']['family'];
		foreach ( DesignTokens::FONT_ROLES as $role ) {
			$t['typography'][ $role ]['weight'] = $all->top( "weights.$role", $weight );
		}
		$length = static fn ( string $v ): ?string => DesignTokens::length( $v );
		foreach ( DesignTokens::SCALE_KEYS as $key ) {
			foreach ( DesignTokens::DEVICES as $device ) {
				$t['typography']['scale'][ $key ][ $device ] = $all->top( "sizes.$key.$device", $length );
			}
		}
		$line = static fn ( string $v ): ?string => DesignTokens::lineHeight( $v );
		$t['typography']['line_height']['heading'] = $all->top( 'line_heights.heading', $line );
		$t['typography']['line_height']['body']    = $all->top( 'line_heights.body', $line );
		$sources['typography']                     = $this->source( $sets, array( 'fonts.body', 'fonts.heading' ), $t['typography']['body']['family'] ?: $t['typography']['heading']['family'] );

		// Components.
		$box    = static fn ( string $v ): ?string => DesignTokens::box( $v );
		$shadow = static fn ( string $v ): ?string => DesignTokens::shadow( $v );
		$t['components']['button'] = array(
			'radius'     => $all->top( 'radii.button', $box ),
			'padding'    => $all->top( 'paddings.button', $box ),
			'background' => $all->top( 'colors.button_bg', $color ) ?: $t['colors']['primary'],
			'color'      => $all->top( 'colors.button_text', $color ),
		);
		$t['components']['card']   = array(
			'radius'     => $all->top( 'radii.card', $box ),
			'shadow'     => $all->top( 'shadows.card', $shadow ),
			'background' => $all->top( 'colors.card_bg', $color ),
			'padding'    => $all->top( 'paddings.card', $box ),
		);
		$t['components']['image']  = array( 'radius' => $all->top( 'radii.image', $box ) );
		$sources['components']     = $this->source( $sets, array( 'radii.button', 'colors.button_bg', 'radii.card' ), $t['components']['button']['radius'] ?: $t['components']['button']['background'] );

		// Spacing and layout.
		foreach ( DesignTokens::DEVICES as $device ) {
			$t['spacing']['section_padding'][ $device ] = $all->top( "paddings.section.$device", $box );
		}
		$t['spacing']['element_gap']     = $all->top( 'spacing.gap', $length );
		$t['layout']['container_width'] = $all->top( 'layout.container_width', $length );
		foreach ( array( 'tablet', 'mobile' ) as $device ) {
			$t['layout']['breakpoints'][ $device ] = (int) $all->top( "breakpoints.$device", static fn ( string $v ): ?string => ctype_digit( $v ) && (int) $v >= 240 && (int) $v <= 2560 ? $v : null );
		}
		$sources['layout'] = $this->source( $sets, array( 'layout.container_width', 'paddings.section.desktop' ), $t['layout']['container_width'] ?: $t['spacing']['section_padding']['desktop'] );

		$t['sources'] = $sources;
		return DesignTokens::normalize( $t );
	}

	/**
	 * All observed colours with their combined weight, strongest first.
	 *
	 * @param StyleSignals $all Signals.
	 * @return array<string, float> Hex => weight.
	 */
	private function palette( StyleSignals $all ): array {
		$weights = array();
		foreach ( $all->toArray() as $slot => $values ) {
			if ( ! str_starts_with( $slot, 'colors.' ) ) {
				continue;
			}
			foreach ( $values as $value => $weight ) {
				$hex = DesignTokens::color( (string) $value );
				if ( null === $hex || 7 !== strlen( $hex ) ) {
					continue; // Translucent overlays are not palette colours.
				}
				$weights[ $hex ] = ( $weights[ $hex ] ?? 0.0 ) + (float) $weight;
			}
		}
		arsort( $weights );
		return $weights;
	}

	/**
	 * Which source supplied a value (the first, in priority order, that observed it in the given slots).
	 *
	 * @param array<string, StyleSignals> $sets  Signals by source.
	 * @param string[]                    $slots Slots to check.
	 * @param string                      $value Chosen value.
	 */
	private function source( array $sets, array $slots, string $value ): string {
		if ( '' === $value ) {
			return 'none';
		}
		foreach ( $sets as $source => $signals ) {
			foreach ( $slots as $slot ) {
				foreach ( array_keys( $signals->ranked( $slot ) ) as $observed ) {
					$observed = (string) $observed;
					if ( $observed === $value || DesignTokens::color( $observed ) === $value || DesignTokens::fontFamily( $observed ) === $value || DesignTokens::box( $observed ) === $value ) {
						return in_array( $source, DesignTokens::SOURCES, true ) ? $source : 'none';
					}
				}
			}
		}
		return 'none';
	}

	/**
	 * First candidate not already used.
	 *
	 * @param string[]      $candidates Candidates.
	 * @param string[]      $used       Values to skip.
	 * @param callable|null $clean      Normaliser.
	 */
	private static function firstUnused( array $candidates, array $used, ?callable $clean = null ): string {
		foreach ( $candidates as $candidate ) {
			$value = null === $clean ? (string) $candidate : $clean( (string) $candidate );
			if ( is_string( $value ) && '' !== $value && ! in_array( $value, $used, true ) ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Whether a colour is a neutral (grey, near-black or near-white) rather than a brand hue.
	 *
	 * @param string $hex #RRGGBB.
	 */
	public static function isNeutral( string $hex ): bool {
		[ $h, $s, $l ] = self::hsl( $hex );
		unset( $h );
		return $s < 0.15 || $l < 0.1 || $l > 0.95;
	}

	/**
	 * HSL of a #RRGGBB colour (h in degrees, s and l in 0..1).
	 *
	 * @param string $hex Colour.
	 * @return float[]
	 */
	public static function hsl( string $hex ): array {
		$r   = hexdec( substr( $hex, 1, 2 ) ) / 255;
		$g   = hexdec( substr( $hex, 3, 2 ) ) / 255;
		$b   = hexdec( substr( $hex, 5, 2 ) ) / 255;
		$max = max( $r, $g, $b );
		$min = min( $r, $g, $b );
		$l   = ( $max + $min ) / 2;
		if ( $max === $min ) {
			return array( 0.0, 0.0, $l );
		}
		$d = $max - $min;
		$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );
		$h = match ( $max ) {
			$r      => ( $g - $b ) / $d + ( $g < $b ? 6 : 0 ),
			$g      => ( $b - $r ) / $d + 2,
			default => ( $r - $g ) / $d + 4,
		};
		return array( $h * 60, $s, $l );
	}
}
