<?php
/**
 * Keyword counting and bolding for generated articles.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Content;

use AiSeoContentStudio\SEO\DeterministicChecks;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic helpers, so must-use keyword coverage and bolding do not depend on the AI
 * getting them right.
 */
final class KeywordTools {

	/** Tags whose text is never bolded (already bold, headings, links, code). */
	private const SKIP = 'h1|h2|h3|h4|h5|h6|a|strong|b|th|code';

	/**
	 * Whole-phrase occurrences of a term in text (case and punctuation ignored).
	 *
	 * @param string $text Plain text.
	 * @param string $term Term.
	 */
	public static function count( string $text, string $term ): int {
		$term = DeterministicChecks::norm( $term );
		if ( '' === $term ) {
			return 0;
		}
		return (int) preg_match_all( '/(?<![\p{L}\p{N}])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/u', DeterministicChecks::norm( $text ) );
	}

	/**
	 * Count each term in the given HTML.
	 *
	 * @param string   $html  HTML.
	 * @param string[] $terms Terms.
	 * @return array<string, int> Term => count.
	 */
	public static function counts( string $html, array $terms ): array {
		$text = ContentModel::text( $html );
		$out  = array();
		foreach ( $terms as $term ) {
			$out[ $term ] = self::count( $text, $term );
		}
		return $out;
	}

	/**
	 * Bold the first use of each term across the article parts, in document order. Terms that
	 * are already bold somewhere are left alone, so running it again changes nothing.
	 *
	 * @param array<string, string> $parts   Part id => HTML, in document order.
	 * @param string[]              $terms   Terms.
	 * @param string                $already Other HTML whose bold terms count as already bolded.
	 * @return array<string, string> Part id => HTML.
	 */
	public static function boldFirst( array $parts, array $terms, string $already = '' ): array {
		$terms = array_values( array_filter( array_map( 'trim', $terms ), static fn ( $t ) => '' !== $t ) );
		usort( $terms, static fn ( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );

		$done = array();
		foreach ( array_merge( array( $already ), array_values( $parts ) ) as $html ) {
			preg_match_all( '#<(strong|b)\b[^>]*>(.*?)</\1>#is', $html, $m );
			foreach ( $m[2] as $bold ) {
				foreach ( $terms as $term ) {
					if ( self::count( ContentModel::text( $bold ), $term ) > 0 ) {
						$done[ mb_strtolower( $term ) ] = true;
					}
				}
			}
		}

		foreach ( $parts as $id => $html ) {
			$tokens = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array();
			$skip   = 0;
			foreach ( $tokens as $i => $token ) {
				if ( str_starts_with( $token, '<' ) ) {
					if ( preg_match( '#^<(/?)(' . self::SKIP . ')\b#i', $token, $tag ) ) {
						$skip = max( 0, $skip + ( '/' === $tag[1] ? -1 : 1 ) );
					}
					continue;
				}
				if ( $skip > 0 || '' === trim( $token ) ) {
					continue;
				}
				$tokens[ $i ] = self::boldText( $token, $terms, $done );
			}
			$parts[ $id ] = implode( '', $tokens );
		}
		return $parts;
	}

	/**
	 * Bold the earliest remaining term in a text node, then continue on both sides of it.
	 *
	 * @param string              $text  Text node (may contain entities).
	 * @param string[]            $terms Terms, longest first.
	 * @param array<string, bool> $done  Terms already bolded (by reference).
	 */
	private static function boldText( string $text, array $terms, array &$done ): string {
		$best = null;
		foreach ( $terms as $term ) {
			if ( isset( $done[ mb_strtolower( $term ) ] ) ) {
				continue;
			}
			if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/iu', $text, $m, PREG_OFFSET_CAPTURE ) && ( null === $best || $m[0][1] < $best[1] ) ) {
				$best = array( $m[0][0], $m[0][1], $term );
			}
		}
		if ( null === $best ) {
			return $text;
		}
		// Shorter terms inside the bolded phrase ("ledger" in "general ledger") are now bold too.
		foreach ( $terms as $term ) {
			if ( self::count( $best[0], $term ) > 0 ) {
				$done[ mb_strtolower( $term ) ] = true;
			}
		}
		$before = substr( $text, 0, $best[1] );
		$after  = substr( $text, $best[1] + strlen( $best[0] ) );
		return self::boldText( $before, $terms, $done ) . '<strong>' . $best[0] . '</strong>' . self::boldText( $after, $terms, $done );
	}
}
