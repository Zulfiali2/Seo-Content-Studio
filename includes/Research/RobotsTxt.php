<?php
/**
 * Minimal robots.txt evaluator.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates Allow/Disallow rules (RFC 9309): the group matching our product token
 * wins over "*"; within a group the longest matching rule wins and Allow wins ties.
 * Supports "*" wildcards and "$" end anchors.
 */
final class RobotsTxt {

	/**
	 * Rules for the selected group: list of [allow(bool), pattern].
	 *
	 * @var array<int, array{0: bool, 1: string}>
	 */
	private array $rules;

	/**
	 * Constructor.
	 *
	 * @param string $content robots.txt body.
	 * @param string $agent   Product token, e.g. "AI-SEO-Content-Studio".
	 */
	public function __construct( string $content, string $agent ) {
		$groups   = array();
		$current  = array();
		$in_rules = false;
		foreach ( preg_split( '/\r\n|\r|\n/', $content ) ?: array() as $line ) {
			$line = trim( (string) preg_replace( '/#.*/', '', $line ) );
			if ( '' === $line || ! str_contains( $line, ':' ) ) {
				continue;
			}
			[ $field, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
			$field             = strtolower( $field );
			if ( 'user-agent' === $field ) {
				if ( $in_rules ) {
					$current  = array();
					$in_rules = false;
				}
				$current[] = strtolower( $value );
				foreach ( $current as $ua ) {
					$groups[ $ua ] = $groups[ $ua ] ?? array();
				}
				continue;
			}
			if ( 'allow' === $field || 'disallow' === $field ) {
				$in_rules = true;
				if ( 'disallow' === $field && '' === $value ) {
					continue; // Empty Disallow allows everything.
				}
				foreach ( $current as $ua ) {
					$groups[ $ua ][] = array( 'allow' === $field, $value );
				}
			}
		}
		$agent       = strtolower( $agent );
		$this->rules = $groups[ $agent ] ?? $groups['*'] ?? array();
	}

	/**
	 * Whether a path (with query) may be fetched.
	 *
	 * @param string $path URL path plus optional query string.
	 */
	public function isAllowed( string $path ): bool {
		if ( '' === $path ) {
			$path = '/';
		}
		$best_length = -1;
		$allowed     = true;
		foreach ( $this->rules as [ $allow, $pattern ] ) {
			if ( ! self::matches( $pattern, $path ) ) {
				continue;
			}
			$length = strlen( $pattern );
			if ( $length > $best_length || ( $length === $best_length && $allow ) ) {
				$best_length = $length;
				$allowed     = $allow;
			}
		}
		return $allowed;
	}

	/**
	 * Pattern match with "*" and "$".
	 *
	 * @param string $pattern Rule path.
	 * @param string $path    Path.
	 */
	private static function matches( string $pattern, string $path ): bool {
		$anchored = str_ends_with( $pattern, '$' );
		$pattern  = $anchored ? substr( $pattern, 0, -1 ) : $pattern;
		$regex    = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . ( $anchored ? '$' : '' ) . '#';
		return 1 === preg_match( $regex, $path );
	}
}
