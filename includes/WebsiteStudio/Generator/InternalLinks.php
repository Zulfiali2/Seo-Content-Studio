<?php
/**
 * Internal link suggestions for generated pages.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Generator;

use AiSeoContentStudio\WebsiteStudio\Engine\HtmlFragment;

defined( 'ABSPATH' ) || exit;

/**
 * The AI only proposes links: every suggestion must point to a published page from the
 * candidate list and use an anchor phrase that really is in the named section's text. Nothing
 * is linked until the user approves a suggestion; approved links are inserted by wrapping the
 * first unlinked occurrence of the anchor in a text element, and the target is checked again
 * at that moment.
 */
final class InternalLinks {

	public const MAX_SUGGESTIONS = 8;

	/**
	 * Validate the model's suggestions.
	 *
	 * @param list<mixed>                $raw        Suggestions {target_id, anchor, section, reason}.
	 * @param list<array<string, mixed>> $candidates Candidates {id, title, url, type}.
	 * @param array<string, mixed>       $doc        Document.
	 * @return list<array{id: string, target_id: int, title: string, url: string, anchor: string, section: int, reason: string}>
	 */
	public static function suggestions( array $raw, array $candidates, array $doc ): array {
		$by_id = array();
		foreach ( $candidates as $c ) {
			$by_id[ (int) $c['id'] ] = $c;
		}
		$out  = array();
		$used = array();
		foreach ( $raw as $s ) {
			if ( ! is_array( $s ) || count( $out ) >= self::MAX_SUGGESTIONS ) {
				continue;
			}
			$target  = (int) ( $s['target_id'] ?? 0 );
			$anchor  = trim( HtmlFragment::text( (string) ( $s['anchor'] ?? '' ) ) );
			$section = (int) ( $s['section'] ?? -1 );
			$words   = '' === $anchor ? 0 : count( (array) preg_split( '/\s+/u', $anchor ) );
			if ( ! isset( $by_id[ $target ] ) || isset( $used[ $target ] ) || $words < 1 || $words > 8 || mb_strlen( $anchor ) > 80 ) {
				continue;
			}
			if ( ! isset( $doc['sections'][ $section ] ) || null === self::locate( (array) $doc['sections'][ $section ]['elements'], $anchor ) ) {
				continue; // The anchor must be text the page really contains.
			}
			$used[ $target ] = true;
			$out[]           = array(
				'id'        => 'link-' . ( count( $out ) + 1 ),
				'target_id' => $target,
				'title'     => (string) $by_id[ $target ]['title'],
				'url'       => (string) $by_id[ $target ]['url'],
				'anchor'    => $anchor,
				'section'   => $section,
				'reason'    => mb_substr( trim( HtmlFragment::text( (string) ( $s['reason'] ?? '' ) ) ), 0, 200 ),
			);
		}
		return $out;
	}

	/**
	 * Insert approved links.
	 *
	 * @param array<string, mixed>                $doc     Document.
	 * @param list<array<string, mixed>>          $links   Approved suggestions.
	 * @param callable(int): string               $current fn( target_id ): current permalink, or "" when the
	 *                                                     target is no longer a published public page.
	 * @return array{document: array<string, mixed>, applied: list<string>, skipped: list<string>}
	 */
	public static function apply( array $doc, array $links, callable $current ): array {
		$applied = array();
		$skipped = array();
		foreach ( $links as $link ) {
			$url = (string) $current( (int) $link['target_id'] );
			$si  = (int) $link['section'];
			if ( '' === $url || ! isset( $doc['sections'][ $si ] ) ) {
				$skipped[] = (string) $link['id'];
				continue;
			}
			$path = self::locate( (array) $doc['sections'][ $si ]['elements'], (string) $link['anchor'] );
			if ( null === $path ) {
				$skipped[] = (string) $link['id'];
				continue;
			}
			$el = &$doc['sections'][ $si ]['elements'][ $path[0] ];
			if ( null !== $path[1] ) {
				$el = &$el['elements'][ $path[1] ];
			}
			$el['html'] = self::wrap( (string) $el['html'], (string) $link['anchor'], $url );
			unset( $el );
			$applied[] = (string) $link['id'];
		}
		return array(
			'document' => $doc,
			'applied'  => $applied,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Position of the first text element containing the anchor outside a link.
	 *
	 * @param array<int, mixed> $elements Section elements.
	 * @param string            $anchor   Anchor text.
	 * @return array{0: int, 1: int|null}|null [element index, group child index].
	 */
	private static function locate( array $elements, string $anchor ): ?array {
		foreach ( $elements as $i => $e ) {
			if ( 'text' === ( $e['type'] ?? '' ) && self::wrap( (string) $e['html'], $anchor, '#' ) !== (string) $e['html'] ) {
				return array( $i, null );
			}
			if ( 'group' === ( $e['type'] ?? '' ) ) {
				foreach ( (array) $e['elements'] as $j => $child ) {
					if ( 'text' === ( $child['type'] ?? '' ) && self::wrap( (string) $child['html'], $anchor, '#' ) !== (string) $child['html'] ) {
						return array( $i, $j );
					}
				}
			}
		}
		return null;
	}

	/**
	 * Wrap the first occurrence of $anchor (case-insensitive, whole words, outside <a>) in a link.
	 *
	 * @param string $html   Sanitised HTML.
	 * @param string $anchor Plain anchor text.
	 * @param string $url    Link target.
	 */
	public static function wrap( string $html, string $anchor, string $url ): string {
		$needle = htmlspecialchars( $anchor, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$parts  = (array) preg_split( '/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$in_a   = 0;
		foreach ( $parts as $i => $part ) {
			$part = (string) $part;
			if ( str_starts_with( $part, '<' ) ) {
				if ( preg_match( '#^<a[\s>]#i', $part ) ) {
					++$in_a;
				} elseif ( preg_match( '#^</a>#i', $part ) ) {
					$in_a = max( 0, $in_a - 1 );
				}
				continue;
			}
			if ( $in_a > 0 ) {
				continue;
			}
			$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/iu';
			if ( preg_match( $pattern, $part, $m, PREG_OFFSET_CAPTURE ) ) {
				$parts[ $i ] = substr( $part, 0, $m[0][1] )
					. '<a href="' . htmlspecialchars( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '">' . $m[0][0] . '</a>'
					. substr( $part, $m[0][1] + strlen( $m[0][0] ) );
				return implode( '', $parts );
			}
		}
		return $html;
	}
}
