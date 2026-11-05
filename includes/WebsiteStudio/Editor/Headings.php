<?php
/**
 * Heading outline of a page.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Editor;

use AiSeoContentStudio\WebsiteStudio\Engine\HtmlFragment;

defined( 'ABSPATH' ) || exit;

/**
 * Heading widgets in reading order and the hierarchy problems among them (shared by the
 * consistency and accessibility checks).
 */
final class Headings {

	/**
	 * Headings in order.
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @return list<array{id: string, level: int, text: string}>
	 */
	public static function outline( array $elements ): array {
		$out = array();
		foreach ( ElementTree::flatten( $elements ) as $row ) {
			if ( 'heading' !== ( $row['element']['widgetType'] ?? '' ) ) {
				continue;
			}
			$tag   = (string) ( $row['element']['settings']['header_size'] ?? 'h2' );
			$out[] = array(
				'id'    => (string) $row['element']['id'],
				'level' => preg_match( '/^h([1-6])$/', $tag, $m ) ? (int) $m[1] : 0, // 0: div/span/p, not a heading.
				'text'  => trim( HtmlFragment::text( (string) ( $row['element']['settings']['title'] ?? '' ) ) ),
			);
		}
		return $out;
	}

	/**
	 * Hierarchy problems with fixes.
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @return list<array{severity: string, message: string, elements: list<string>, fix_label: string, ops: list<array<string, mixed>>}>
	 */
	public static function problems( array $elements ): array {
		$out   = array();
		$all   = array_values( array_filter( self::outline( $elements ), static fn ( array $h ): bool => $h['level'] > 0 ) );
		$h1s   = array_values( array_filter( $all, static fn ( array $h ): bool => 1 === $h['level'] ) );
		if ( array() === $all ) {
			return $out;
		}
		if ( array() === $h1s ) {
			$out[] = array(
				'severity'  => 'error',
				'message'   => __( 'The page has no H1 heading.', 'ai-seo-content-studio' ),
				'elements'  => array( $all[0]['id'] ),
				'fix_label' => __( 'Make the first heading the H1', 'ai-seo-content-studio' ),
				'ops'       => array( ConsistencyChecker::op( $all[0]['id'], array( 'header_size' => 'h1' ) ) ),
			);
		} elseif ( count( $h1s ) > 1 ) {
			$extra = array_slice( $h1s, 1 );
			$out[] = array(
				'severity'  => 'error',
				/* translators: %d: number of H1 headings. */
				'message'   => sprintf( __( 'The page has %d H1 headings; it should have one.', 'ai-seo-content-studio' ), count( $h1s ) ),
				'elements'  => array_column( $extra, 'id' ),
				'fix_label' => __( 'Change the extra H1 headings to H2', 'ai-seo-content-studio' ),
				'ops'       => array_map( static fn ( array $h ): array => ConsistencyChecker::op( $h['id'], array( 'header_size' => 'h2' ) ), $extra ),
			);
		}
		$previous = 0;
		foreach ( $all as $h ) {
			if ( $previous > 0 && $h['level'] > $previous + 1 ) {
				$out[] = array(
					'severity'  => 'warning',
					/* translators: 1: heading level, 2: previous level, 3: heading text. */
					'message'   => sprintf( __( 'An H%1$d follows an H%2$d, skipping a level ("%3$s").', 'ai-seo-content-studio' ), $h['level'], $previous, mb_substr( $h['text'], 0, 60 ) ),
					'elements'  => array( $h['id'] ),
					/* translators: %d: heading level. */
					'fix_label' => sprintf( __( 'Change it to H%d', 'ai-seo-content-studio' ), $previous + 1 ),
					'ops'       => array( ConsistencyChecker::op( $h['id'], array( 'header_size' => 'h' . ( $previous + 1 ) ) ) ),
				);
				$h['level'] = $previous + 1;
			}
			$previous = $h['level'];
		}
		return $out;
	}
}
