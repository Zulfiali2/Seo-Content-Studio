<?php
/**
 * Small sanitising helpers for structured user/AI data.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text sanitisers with length and count caps.
 */
final class Sanitize {

	/**
	 * Single-line text.
	 *
	 * @param mixed $value Input.
	 * @param int   $max   Max characters.
	 */
	public static function text( mixed $value, int $max = 200 ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $max ) : '';
	}

	/**
	 * Multi-line text (newlines kept, tags removed).
	 *
	 * @param mixed $value Input.
	 * @param int   $max   Max characters.
	 */
	public static function textarea( mixed $value, int $max = 2000 ): string {
		return is_scalar( $value ) ? mb_substr( sanitize_textarea_field( (string) $value ), 0, $max ) : '';
	}

	/**
	 * List of unique, non-empty strings.
	 *
	 * @param mixed $value Input.
	 * @param int   $max_items Max items.
	 * @param int   $max_len   Max characters per item.
	 * @return string[]
	 */
	public static function strings( mixed $value, int $max_items = 50, int $max_len = 200 ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			$text = self::text( $item, $max_len );
			if ( '' !== $text && ! in_array( mb_strtolower( $text ), array_map( 'mb_strtolower', $out ), true ) ) {
				$out[] = $text;
			}
			if ( count( $out ) >= $max_items ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Value from an allow-list.
	 *
	 * @param mixed    $value   Input.
	 * @param string[] $allowed Allowed values.
	 * @param string   $fallback Fallback.
	 */
	public static function enum( mixed $value, array $allowed, string $fallback = '' ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Nullable integer.
	 *
	 * @param mixed $value Input.
	 */
	public static function nullableInt( mixed $value ): ?int {
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * Nullable float.
	 *
	 * @param mixed $value Input.
	 */
	public static function nullableFloat( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}
}
