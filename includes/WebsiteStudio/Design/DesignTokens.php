<?php
/**
 * Brand design tokens: schema, normalisation and validation.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Design;

defined( 'ABSPATH' ) || exit;

/**
 * The versioned token format shared by brand profiles, the analyzers and (later) the
 * design generator. Every value that leaves this class has passed a strict pattern, so
 * tokens can be written into Elementor settings or CSS without further escaping concerns.
 *
 * Schema version 1:
 *
 *   colors      {primary, secondary, accent, text, heading, background, surface, border}: "#RRGGBB" | ""
 *   palette     list of {hex: "#RRGGBB", count: int, roles: string[]} (max 16, most used first)
 *   typography  {heading, body, button}: {family: string, weight: string}
 *               scale.{h1..h6, body}: {desktop, tablet, mobile}: CSS length | ""
 *               line_height {heading, body}: number | CSS length | ""
 *   components  button {radius, padding, background, color}, card {radius, shadow, background, padding},
 *               image {radius}
 *   spacing     section_padding {desktop, tablet, mobile}: CSS box (1-4 lengths) | "", element_gap: CSS length | ""
 *   layout      container_width: CSS length | "", breakpoints {tablet, mobile}: int px | 0
 *   sources     group => where the values came from (elementor_kit, elementor_widgets, theme_json, css, user)
 */
final class DesignTokens {

	public const VERSION = 1;

	public const COLOR_ROLES = array( 'primary', 'secondary', 'accent', 'text', 'heading', 'background', 'surface', 'border' );

	public const FONT_ROLES = array( 'heading', 'body', 'button' );

	public const SCALE_KEYS = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'body' );

	public const DEVICES = array( 'desktop', 'tablet', 'mobile' );

	public const SOURCES = array( 'elementor_kit', 'elementor_widgets', 'theme_json', 'css', 'user', 'none' );

	private const LENGTH = '/^-?\d{1,4}(\.\d{1,3})?(px|em|rem|%|vw|vh)$|^0$/';

	/**
	 * An empty token set.
	 *
	 * @return array<string, mixed>
	 */
	public static function blank(): array {
		$devices = array_fill_keys( self::DEVICES, '' );
		return array(
			'schema_version' => self::VERSION,
			'colors'         => array_fill_keys( self::COLOR_ROLES, '' ),
			'palette'        => array(),
			'typography'     => array(
				'heading'     => array(
					'family' => '',
					'weight' => '',
				),
				'body'        => array(
					'family' => '',
					'weight' => '',
				),
				'button'      => array(
					'family' => '',
					'weight' => '',
				),
				'scale'       => array_fill_keys( self::SCALE_KEYS, $devices ),
				'line_height' => array(
					'heading' => '',
					'body'    => '',
				),
			),
			'components'     => array(
				'button' => array(
					'radius'     => '',
					'padding'    => '',
					'background' => '',
					'color'      => '',
				),
				'card'   => array(
					'radius'     => '',
					'shadow'     => '',
					'background' => '',
					'padding'    => '',
				),
				'image'  => array( 'radius' => '' ),
			),
			'spacing'        => array(
				'section_padding' => $devices,
				'element_gap'     => '',
			),
			'layout'         => array(
				'container_width' => '',
				'breakpoints'     => array(
					'tablet' => 0,
					'mobile' => 0,
				),
			),
			'sources'        => array(),
		);
	}

	/**
	 * Normalise untrusted token input (AI output, REST input, stored data) into schema v1.
	 * Invalid values are dropped and reported in $errors as "path: reason".
	 *
	 * @param mixed    $input  Input.
	 * @param string[] $errors Collected validation errors.
	 * @return array<string, mixed>
	 */
	public static function normalize( mixed $input, array &$errors = array() ): array {
		$out = self::blank();
		if ( ! is_array( $input ) ) {
			$errors[] = 'tokens: must be an object';
			return $out;
		}
		if ( isset( $input['schema_version'] ) && self::VERSION !== (int) $input['schema_version'] ) {
			$errors[] = 'schema_version: unsupported version';
		}

		foreach ( self::COLOR_ROLES as $role ) {
			$out['colors'][ $role ] = self::field( $input['colors'][ $role ] ?? '', array( self::class, 'color' ), "colors.$role", $errors );
		}

		$seen = array();
		foreach ( array_slice( (array) ( $input['palette'] ?? array() ), 0, 16 ) as $i => $entry ) {
			$hex = is_array( $entry ) ? self::color( $entry['hex'] ?? '' ) : null;
			if ( null === $hex || '' === $hex ) {
				$errors[] = "palette[$i]: invalid colour";
				continue;
			}
			if ( isset( $seen[ $hex ] ) ) {
				continue;
			}
			$seen[ $hex ]     = true;
			$roles            = array_values( array_intersect( array_map( 'strval', (array) ( $entry['roles'] ?? array() ) ), array_merge( self::COLOR_ROLES, array( 'link', 'button' ) ) ) );
			$out['palette'][] = array(
				'hex'   => $hex,
				'count' => max( 0, (int) ( $entry['count'] ?? 0 ) ),
				'roles' => $roles,
			);
		}

		foreach ( self::FONT_ROLES as $role ) {
			$font                              = (array) ( $input['typography'][ $role ] ?? array() );
			$out['typography'][ $role ]['family'] = self::field( $font['family'] ?? '', array( self::class, 'fontFamily' ), "typography.$role.family", $errors );
			$out['typography'][ $role ]['weight'] = self::field( $font['weight'] ?? '', array( self::class, 'fontWeight' ), "typography.$role.weight", $errors );
		}
		foreach ( self::SCALE_KEYS as $key ) {
			foreach ( self::DEVICES as $device ) {
				$out['typography']['scale'][ $key ][ $device ] = self::field( $input['typography']['scale'][ $key ][ $device ] ?? '', array( self::class, 'length' ), "typography.scale.$key.$device", $errors );
			}
		}
		foreach ( array( 'heading', 'body' ) as $key ) {
			$out['typography']['line_height'][ $key ] = self::field( $input['typography']['line_height'][ $key ] ?? '', array( self::class, 'lineHeight' ), "typography.line_height.$key", $errors );
		}

		$component_rules = array(
			'button' => array(
				'radius'     => 'box',
				'padding'    => 'box',
				'background' => 'color',
				'color'      => 'color',
			),
			'card'   => array(
				'radius'     => 'box',
				'shadow'     => 'shadow',
				'background' => 'color',
				'padding'    => 'box',
			),
			'image'  => array( 'radius' => 'box' ),
		);
		foreach ( $component_rules as $component => $fields ) {
			foreach ( $fields as $field => $rule ) {
				$out['components'][ $component ][ $field ] = self::field( $input['components'][ $component ][ $field ] ?? '', array( self::class, $rule ), "components.$component.$field", $errors );
			}
		}

		foreach ( self::DEVICES as $device ) {
			$out['spacing']['section_padding'][ $device ] = self::field( $input['spacing']['section_padding'][ $device ] ?? '', array( self::class, 'box' ), "spacing.section_padding.$device", $errors );
		}
		$out['spacing']['element_gap']     = self::field( $input['spacing']['element_gap'] ?? '', array( self::class, 'length' ), 'spacing.element_gap', $errors );
		$out['layout']['container_width'] = self::field( $input['layout']['container_width'] ?? '', array( self::class, 'length' ), 'layout.container_width', $errors );
		foreach ( array( 'tablet', 'mobile' ) as $device ) {
			$bp = (int) ( $input['layout']['breakpoints'][ $device ] ?? 0 );
			if ( 0 !== $bp && ( $bp < 240 || $bp > 2560 ) ) {
				$errors[] = "layout.breakpoints.$device: out of range";
				$bp       = 0;
			}
			$out['layout']['breakpoints'][ $device ] = $bp;
		}

		foreach ( (array) ( $input['sources'] ?? array() ) as $group => $source ) {
			if ( is_string( $group ) && preg_match( '/^[a-z_]{1,32}$/', $group ) && in_array( $source, self::SOURCES, true ) ) {
				$out['sources'][ $group ] = $source;
			}
		}
		return $out;
	}

	/**
	 * Apply a validator to one value; "" stays "" without an error.
	 *
	 * @param mixed    $value     Value.
	 * @param callable $validator Returns the clean value, or null when invalid.
	 * @param string   $path      Path for errors.
	 * @param string[] $errors    Errors.
	 */
	private static function field( mixed $value, callable $validator, string $path, array &$errors ): string {
		if ( null === $value || '' === $value ) {
			return '';
		}
		if ( ! is_scalar( $value ) ) {
			$errors[] = "$path: must be a string";
			return '';
		}
		$clean = $validator( (string) $value );
		if ( null === $clean ) {
			$errors[] = "$path: invalid value";
			return '';
		}
		return $clean;
	}

	/**
	 * Hex colour (#RGB, #RRGGBB, #RRGGBBAA) to upper-case #RRGGBB; rgb()/rgba() to hex (alpha dropped
	 * when opaque, kept as #RRGGBBAA otherwise).
	 *
	 * @param mixed $value Value.
	 */
	public static function color( mixed $value ): ?string {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^#([0-9a-f]{3})$/', $value, $m ) ) {
			return strtoupper( '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] );
		}
		if ( preg_match( '/^#([0-9a-f]{6})$/', $value, $m ) ) {
			return strtoupper( '#' . $m[1] );
		}
		if ( preg_match( '/^#([0-9a-f]{6})([0-9a-f]{2})$/', $value, $m ) ) {
			return strtoupper( 'ff' === $m[2] ? '#' . $m[1] : '#' . $m[1] . $m[2] );
		}
		if ( preg_match( '/^rgba?\(\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*[, ]\s*(\d{1,3})\s*(?:[,\/]\s*([0-9.]+%?)\s*)?\)$/', $value, $m ) ) {
			$rgb = array( (int) $m[1], (int) $m[2], (int) $m[3] );
			if ( max( $rgb ) > 255 ) {
				return null;
			}
			$hex   = sprintf( '#%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2] );
			$alpha = isset( $m[4] ) ? ( str_ends_with( $m[4], '%' ) ? (float) $m[4] / 100 : (float) $m[4] ) : 1.0;
			if ( $alpha <= 0 ) {
				return null; // Fully transparent: not a brand colour.
			}
			return $alpha >= 1 ? $hex : $hex . sprintf( '%02X', (int) round( min( 1, $alpha ) * 255 ) );
		}
		return null;
	}

	/**
	 * A single CSS length.
	 *
	 * @param mixed $value Value.
	 */
	public static function length( mixed $value ): ?string {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( self::LENGTH, $value ) ? $value : null;
	}

	/**
	 * One to four CSS lengths (padding, margin, border-radius).
	 *
	 * @param mixed $value Value.
	 */
	public static function box( mixed $value ): ?string {
		$parts = preg_split( '/\s+/', strtolower( trim( (string) $value ) ) );
		if ( ! is_array( $parts ) || count( $parts ) < 1 || count( $parts ) > 4 ) {
			return null;
		}
		foreach ( $parts as $part ) {
			if ( null === self::length( $part ) ) {
				return null;
			}
		}
		return implode( ' ', $parts );
	}

	/**
	 * A single box-shadow: offset-x offset-y [blur [spread]] colour, optional inset.
	 *
	 * @param mixed $value Value.
	 */
	public static function shadow( mixed $value ): ?string {
		$value = strtolower( trim( (string) $value ) );
		if ( 'none' === $value ) {
			return 'none';
		}
		if ( ! preg_match( '/^(inset\s+)?((?:-?\d{1,3}(?:\.\d+)?(?:px)?\s+){2,4})(#[0-9a-f]{3,8}|rgba?\([0-9.,\s\/%]+\))$/', $value, $m ) ) {
			return null;
		}
		$color = self::color( $m[3] );
		if ( null === $color || '' === $color ) {
			return null;
		}
		$lengths = array_map( static fn ( string $l ): string => ( '0' === $l || str_ends_with( $l, 'px' ) ) ? $l : $l . 'px', preg_split( '/\s+/', trim( $m[2] ) ) );
		return trim( $m[1] ) . ( '' !== trim( $m[1] ) ? ' ' : '' ) . implode( ' ', $lengths ) . ' ' . $color;
	}

	/**
	 * A font family list without quotes or CSS syntax beyond commas.
	 *
	 * @param mixed $value Value.
	 */
	public static function fontFamily( mixed $value ): ?string {
		$families = array();
		foreach ( explode( ',', (string) $value ) as $family ) {
			$family = trim( $family, " \t\n\r\"'" );
			if ( '' === $family ) {
				continue;
			}
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9 _\-]{0,60}$/', $family ) ) {
				return null;
			}
			$families[] = $family;
		}
		return array() === $families ? null : implode( ', ', array_slice( $families, 0, 4 ) );
	}

	/**
	 * Font weight.
	 *
	 * @param mixed $value Value.
	 */
	public static function fontWeight( mixed $value ): ?string {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^([1-9]00|normal|bold)$/', $value ) ? $value : null;
	}

	/**
	 * Line height: unitless number or a length.
	 *
	 * @param mixed $value Value.
	 */
	public static function lineHeight( mixed $value ): ?string {
		$value = strtolower( trim( (string) $value ) );
		if ( preg_match( '/^\d(\.\d{1,3})?$/', $value ) ) {
			return $value;
		}
		return self::length( $value );
	}
}
