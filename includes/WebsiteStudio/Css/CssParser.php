<?php
/**
 * Small, non-executing CSS parser.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Css;

defined( 'ABSPATH' ) || exit;

/**
 * Parses CSS text into rules with their media context.
 *
 * Only what the analyzers and the HTML converter need: style rules, @media blocks
 * (nested once or more), custom properties and their var() references. @font-face,
 * @keyframes and other at-rules are skipped whole. The parser never fetches @import
 * targets or url() resources; input is bounded in size and rule count.
 */
final class CssParser {

	public const MAX_BYTES = 1048576;

	public const MAX_RULES = 5000;

	/** Breakpoints used when a media query does not say otherwise (Elementor defaults). */
	public const TABLET_MAX = 1024;
	public const MOBILE_MAX = 767;

	/**
	 * Parse CSS.
	 *
	 * @param string $css CSS text.
	 * @return array{rules: list<array{selectors: string[], declarations: array<string, string>, media: string, device: string}>, variables: array<string, string>, breakpoints: int[], truncated: bool}
	 */
	public function parse( string $css ): array {
		$truncated = strlen( $css ) > self::MAX_BYTES;
		$css       = substr( $css, 0, self::MAX_BYTES );
		$css       = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$css       = (string) preg_replace( '/@(import|charset|namespace)\b[^;{]*;/i', '', $css );

		$rules       = array();
		$breakpoints = array();
		$this->block( $css, '', $rules, $breakpoints, $truncated );

		// Custom properties from :root / html / body (theme.json and Elementor kits emit these).
		$variables = array();
		foreach ( $rules as $rule ) {
			if ( '' !== $rule['media'] ) {
				continue;
			}
			foreach ( $rule['selectors'] as $selector ) {
				if ( in_array( $selector, array( ':root', 'html', 'body', '.elementor-kit' ), true ) || preg_match( '/^\.elementor-kit-\d+$/', $selector ) || str_starts_with( $selector, 'body' ) ) {
					foreach ( $rule['declarations'] as $prop => $value ) {
						if ( str_starts_with( $prop, '--' ) ) {
							$variables[ $prop ] = $value;
						}
					}
				}
			}
		}
		foreach ( $rules as &$rule ) {
			foreach ( $rule['declarations'] as $prop => $value ) {
				$rule['declarations'][ $prop ] = self::resolveVars( $value, $variables );
			}
		}
		unset( $rule );

		sort( $breakpoints );
		return array(
			'rules'       => $rules,
			'variables'   => $variables,
			'breakpoints' => array_values( array_unique( $breakpoints ) ),
			'truncated'   => $truncated,
		);
	}

	/**
	 * Parse one block level (top level or the inside of an @media block).
	 *
	 * @param string   $css         CSS.
	 * @param string   $media       Media query of the enclosing block ("" = none).
	 * @param array    $rules       Rules (out).
	 * @param int[]    $breakpoints Breakpoints seen (out).
	 * @param bool     $truncated   Set when limits were hit.
	 */
	private function block( string $css, string $media, array &$rules, array &$breakpoints, bool &$truncated ): void {
		$len = strlen( $css );
		$pos = 0;
		while ( $pos < $len ) {
			$open = self::find( $css, '{', $pos );
			if ( false === $open ) {
				return;
			}
			$prelude = trim( substr( $css, $pos, $open - $pos ) );
			$close   = self::matching( $css, $open );
			if ( false === $close ) {
				$truncated = true;
				return;
			}
			$body = substr( $css, $open + 1, $close - $open - 1 );
			$pos  = $close + 1;

			if ( count( $rules ) >= self::MAX_RULES ) {
				$truncated = true;
				return;
			}
			if ( str_starts_with( $prelude, '@' ) ) {
				if ( preg_match( '/^@media\s+(.+)$/is', $prelude, $m ) ) {
					$query = trim( (string) preg_replace( '/\s+/', ' ', $m[1] ) );
					if ( preg_match_all( '/(?:max|min)-width\s*:\s*(\d{3,4})(?:\.\d+)?px/i', $query, $bp ) ) {
						foreach ( $bp[1] as $px ) {
							$breakpoints[] = (int) $px;
						}
					}
					$this->block( $body, '' === $media ? $query : $media . ' and ' . $query, $rules, $breakpoints, $truncated );
				} elseif ( preg_match( '/^@(supports|layer|container)\b/i', $prelude ) ) {
					$this->block( $body, $media, $rules, $breakpoints, $truncated );
				}
				// @font-face, @keyframes, @page, ...: skipped.
				continue;
			}
			if ( '' === $prelude ) {
				continue;
			}
			$selectors = array_values(
				array_filter(
					array_map( static fn ( string $s ): string => trim( (string) preg_replace( '/\s+/', ' ', $s ) ), explode( ',', $prelude ) ),
					static fn ( string $s ): bool => '' !== $s && strlen( $s ) <= 300
				)
			);
			if ( array() === $selectors ) {
				continue;
			}
			$rules[] = array(
				'selectors'    => $selectors,
				'declarations' => self::declarations( $body ),
				'media'        => $media,
				'device'       => self::device( $media ),
			);
		}
	}

	/**
	 * Parse a declaration block ("color: red; padding: 0 1px").
	 *
	 * @param string $body Declarations.
	 * @return array<string, string>
	 */
	public static function declarations( string $body ): array {
		$out = array();
		foreach ( self::splitTopLevel( $body, ';' ) as $declaration ) {
			$colon = strpos( $declaration, ':' );
			if ( false === $colon ) {
				continue;
			}
			$prop  = strtolower( trim( substr( $declaration, 0, $colon ) ) );
			$value = trim( (string) preg_replace( '/\s*!important\s*$/i', '', trim( substr( $declaration, $colon + 1 ) ) ) );
			if ( '' === $prop || '' === $value || ! preg_match( '/^(--)?[a-z][a-z0-9-]{0,60}$/', $prop ) || strlen( $value ) > 1000 ) {
				continue;
			}
			$out[ $prop ] = $value;
		}
		return $out;
	}

	/**
	 * Which device a media query targets.
	 *
	 * @param string $media Media query.
	 */
	public static function device( string $media ): string {
		if ( '' === $media ) {
			return 'desktop';
		}
		if ( preg_match_all( '/max-width\s*:\s*(\d{3,4})(?:\.\d+)?px/i', $media, $m ) ) {
			$max = min( array_map( 'intval', $m[1] ) );
			if ( $max <= self::MOBILE_MAX + 20 ) {
				return 'mobile';
			}
			if ( $max <= self::TABLET_MAX + 20 ) {
				return 'tablet';
			}
		}
		if ( preg_match( '/\bprint\b/i', $media ) ) {
			return 'print';
		}
		return 'desktop';
	}

	/**
	 * Replace var(--x[, fallback]) with known values (two levels deep).
	 *
	 * @param string                $value     Value.
	 * @param array<string, string> $variables Variables.
	 */
	public static function resolveVars( string $value, array $variables ): string {
		for ( $depth = 0; $depth < 3 && str_contains( $value, 'var(' ); $depth++ ) {
			$value = (string) preg_replace_callback(
				'/var\(\s*(--[a-zA-Z0-9_-]+)\s*(?:,\s*([^()]*(?:\([^()]*\))?[^()]*))?\)/',
				static function ( array $m ) use ( $variables ): string {
					if ( isset( $variables[ $m[1] ] ) && ! str_contains( $variables[ $m[1] ], 'var(' . $m[1] ) ) {
						return $variables[ $m[1] ];
					}
					return isset( $m[2] ) ? trim( $m[2] ) : $m[0];
				},
				$value
			);
		}
		return $value;
	}

	/**
	 * Split on a delimiter outside quotes and parentheses.
	 *
	 * @param string $text      Text.
	 * @param string $delimiter One character.
	 * @return string[]
	 */
	public static function splitTopLevel( string $text, string $delimiter ): array {
		$parts = array();
		$depth = 0;
		$quote = '';
		$start = 0;
		$len   = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $text[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $c ) {
					++$i;
				} elseif ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '(' === $c ) {
				++$depth;
			} elseif ( ')' === $c && $depth > 0 ) {
				--$depth;
			} elseif ( $c === $delimiter && 0 === $depth ) {
				$parts[] = substr( $text, $start, $i - $start );
				$start   = $i + 1;
			}
		}
		$parts[] = substr( $text, $start );
		return array_values( array_filter( array_map( 'trim', $parts ), static fn ( string $p ): bool => '' !== $p ) );
	}

	/**
	 * Position of $char at or after $from, outside quotes.
	 *
	 * @param string $css  CSS.
	 * @param string $char Character.
	 * @param int    $from Offset.
	 */
	private static function find( string $css, string $char, int $from ): int|false {
		$quote = '';
		$len   = strlen( $css );
		for ( $i = $from; $i < $len; $i++ ) {
			$c = $css[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $c ) {
					++$i;
				} elseif ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( $c === $char ) {
				return $i;
			}
		}
		return false;
	}

	/**
	 * Position of the brace closing the one at $open.
	 *
	 * @param string $css  CSS.
	 * @param int    $open Offset of "{".
	 */
	private static function matching( string $css, int $open ): int|false {
		$depth = 0;
		$quote = '';
		$len   = strlen( $css );
		for ( $i = $open; $i < $len; $i++ ) {
			$c = $css[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $c ) {
					++$i;
				} elseif ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '{' === $c ) {
				++$depth;
			} elseif ( '}' === $c ) {
				--$depth;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}
		return false;
	}
}
