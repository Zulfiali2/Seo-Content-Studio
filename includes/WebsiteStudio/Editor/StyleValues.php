<?php
/**
 * Reading Elementor setting values.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Editor;

use AiSeoContentStudio\WebsiteStudio\Design\DesignTokens;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor stores sizes as {unit, size} sliders, boxes as {unit, top, right, bottom, left}
 * and colours either as values or as `__globals__` references to the kit. These helpers turn
 * them into comparable numbers and hex colours.
 */
final class StyleValues {

	/**
	 * A slider or plain length in px (rem/em × 16), or null.
	 *
	 * @param mixed $value Value.
	 */
	public static function px( mixed $value ): ?float {
		if ( is_array( $value ) ) {
			if ( isset( $value['top'] ) ) {
				return self::px( ( (string) $value['top'] ) . ( (string) ( $value['unit'] ?? 'px' ) ) );
			}
			if ( ! isset( $value['size'] ) || '' === $value['size'] ) {
				return null;
			}
			return self::px( ( (string) $value['size'] ) . ( (string) ( $value['unit'] ?? 'px' ) ) );
		}
		if ( ! is_scalar( $value ) || ! preg_match( '/^(-?\d+(?:\.\d+)?)\s*(px|rem|em)?$/', trim( (string) $value ), $m ) ) {
			return null;
		}
		return (float) $m[1] * ( in_array( $m[2] ?? 'px', array( 'rem', 'em' ), true ) ? 16 : 1 );
	}

	/**
	 * Vertical padding (top, bottom) of a box in px, or null.
	 *
	 * @param mixed $box Box value.
	 * @return array{0: float, 1: float}|null
	 */
	public static function vertical( mixed $box ): ?array {
		if ( ! is_array( $box ) || ! isset( $box['top'], $box['bottom'] ) || '' === (string) $box['top'] ) {
			return null;
		}
		$unit = (string) ( $box['unit'] ?? 'px' );
		$top  = self::px( $box['top'] . $unit );
		$bot  = self::px( $box['bottom'] . $unit );
		return null === $top || null === $bot ? null : array( $top, $bot );
	}

	/**
	 * First length of a CSS box string ("80px 0") in px, or null.
	 *
	 * @param string $box Box.
	 */
	public static function boxTop( string $box ): ?float {
		$parts = preg_split( '/\s+/', trim( $box ) );
		return '' === trim( $box ) ? null : self::px( (string) $parts[0] );
	}

	/**
	 * Colour of a setting: the global it references (resolved with the kit), or its value.
	 *
	 * @param array<string, mixed>  $settings Settings.
	 * @param string                $key      Key.
	 * @param array<string, string> $kit      Kit colour id => hex.
	 * @return array{hex: string, global: string}|null Null when unset or transparent.
	 */
	public static function color( array $settings, string $key, array $kit ): ?array {
		$ref = (string) ( $settings['__globals__'][ $key ] ?? '' );
		if ( '' !== $ref ) {
			$id  = preg_match( '/id=([A-Za-z0-9_-]+)/', $ref, $m ) ? $m[1] : '';
			$hex = DesignTokens::color( (string) ( $kit[ $id ] ?? '' ) );
			return null === $hex || '' === $hex ? array(
				'hex'    => '',
				'global' => $id,
			) : array(
				'hex'    => substr( $hex, 0, 7 ),
				'global' => $id,
			);
		}
		$hex = is_string( $settings[ $key ] ?? null ) ? DesignTokens::color( $settings[ $key ] ) : null;
		if ( null === $hex || '' === $hex || ( 9 === strlen( $hex ) && '00' === substr( $hex, 7, 2 ) ) ) {
			return null;
		}
		return array(
			'hex'    => substr( $hex, 0, 7 ),
			'global' => '',
		);
	}

	/**
	 * WCAG contrast ratio of two #RRGGBB colours.
	 *
	 * @param string $a Colour.
	 * @param string $b Colour.
	 */
	public static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Relative luminance.
	 *
	 * @param string $hex #RRGGBB.
	 */
	public static function luminance( string $hex ): float {
		$c = array();
		foreach ( array( 1, 3, 5 ) as $i ) {
			$v   = hexdec( substr( $hex, $i, 2 ) ) / 255;
			$c[] = $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
		}
		return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
	}

	/**
	 * RGB distance of two #RRGGBB colours (0–441).
	 *
	 * @param string $a Colour.
	 * @param string $b Colour.
	 */
	public static function distance( string $a, string $b ): float {
		$sum = 0;
		foreach ( array( 1, 3, 5 ) as $i ) {
			$sum += ( hexdec( substr( $a, $i, 2 ) ) - hexdec( substr( $b, $i, 2 ) ) ) ** 2;
		}
		return sqrt( $sum );
	}

	/**
	 * Kit colours id => hex from kit settings.
	 *
	 * @param array<string, mixed> $kit Kit settings.
	 * @return array<string, string>
	 */
	public static function kitColors( array $kit ): array {
		$out = array();
		foreach ( array( 'system_colors', 'custom_colors' ) as $group ) {
			foreach ( (array) ( $kit[ $group ] ?? array() ) as $item ) {
				$hex = is_array( $item ) ? DesignTokens::color( (string) ( $item['color'] ?? '' ) ) : null;
				if ( null !== $hex && '' !== $hex && is_string( $item['_id'] ?? null ) ) {
					$out[ $item['_id'] ] = substr( $hex, 0, 7 );
				}
			}
		}
		return $out;
	}
}
