<?php
/**
 * Content-aware layout rules for generated pages.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Generator;

use AiSeoContentStudio\WebsiteStudio\Engine\HtmlFragment;

defined( 'ABSPATH' ) || exit;

/**
 * Fits the layout to the content the AI actually wrote, so the design does not depend on the
 * model getting columns right: the number of cards and their text length decide the grid, long
 * hero headings get smaller type, split sections must have two sides, FAQs stay single-column
 * and the page has exactly one H1. Deterministic and pure; every change is reported.
 */
final class ContentLayoutMapper {

	/** Card text above this many words reads badly in narrow columns. */
	public const LONG_CARD_WORDS = 55;

	/**
	 * Adjust a PageDocument input.
	 *
	 * @param array<string, mixed> $doc Document (from PageGenerator::toDocument()).
	 * @return array{document: array<string, mixed>, changes: list<array{section: int, change: string}>}
	 */
	public static function adapt( array $doc ): array {
		$changes = array();
		$h1      = self::ensureOneH1( $doc );
		if ( '' !== $h1 ) {
			$changes[] = array(
				'section' => 0,
				'change'  => $h1,
			);
		}
		foreach ( (array) ( $doc['sections'] ?? array() ) as $si => $section ) {
			foreach ( self::section( $section ) as $change ) {
				$changes[] = array(
					'section' => $si,
					'change'  => $change,
				);
			}
			$doc['sections'][ $si ] = $section;
		}
		return array(
			'document' => $doc,
			'changes'  => $changes,
		);
	}

	/**
	 * Rules for one section.
	 *
	 * @param array<string, mixed> $section Section (changed in place).
	 * @return list<string> Change codes.
	 */
	private static function section( array &$section ): array {
		$changes = array();
		$groups  = array_values( array_filter( (array) $section['elements'], static fn ( array $e ): bool => 'group' === $e['type'] ) );
		$header  = array_values( array_filter( (array) $section['elements'], static fn ( array $e ): bool => 'group' !== $e['type'] ) );
		$kind    = (string) $section['layout']['kind'];
		$n       = count( $groups );

		// FAQs and plain content read best in one column.
		if ( 0 === $n ) {
			if ( 'stack' !== $kind ) {
				$changes[] = 'stack_without_groups';
			}
			$section['layout']['kind']    = 'stack';
			$section['layout']['columns'] = array(
				'desktop' => 1,
				'tablet'  => 1,
				'mobile'  => 1,
			);
		} elseif ( 'faq' === $section['type'] && self::hasAccordion( $groups ) ) {
			$section['elements']          = array_merge( $header, self::unwrap( $groups ) );
			$section['layout']['kind']    = 'stack';
			$section['layout']['columns'] = array(
				'desktop' => 1,
				'tablet'  => 1,
				'mobile'  => 1,
			);
			$changes[]                    = 'faq_single_column';
		} else {
			if ( 'split' === $kind && ( 2 !== $n || array() !== $header ) ) {
				$kind      = 'grid';
				$changes[] = 'split_to_grid';
			} elseif ( 'stack' === $kind ) {
				$kind = 2 === $n && array() === $header ? 'split' : 'grid';
			}
			if ( 'split' === $kind ) {
				$desktop = 2;
			} else {
				$long    = self::averageWords( $groups ) > self::LONG_CARD_WORDS;
				$desktop = self::gridColumns( $n, $long );
				if ( $long && $desktop < self::gridColumns( $n, false ) ) {
					$changes[] = 'long_cards_fewer_columns';
				}
			}
			if ( (int) ( $section['layout']['columns']['desktop'] ?? 0 ) !== $desktop ) {
				$changes[] = 'columns_' . $desktop;
			}
			$section['layout']['kind']    = $kind;
			$section['layout']['columns'] = array(
				'desktop' => $desktop,
				// Three cards on a tablet would leave one alone on a second row.
				'tablet'  => 1 === $desktop || 3 === $n ? 1 : 2,
				'mobile'  => 1,
			);
		}

		// At most two buttons above the content; more is noise.
		$buttons = 0;
		$kept    = array();
		foreach ( $section['elements'] as $e ) {
			if ( 'button' === $e['type'] && ++$buttons > 2 ) {
				$changes[] = 'extra_button_removed';
				continue;
			}
			$kept[] = $e;
		}
		$section['elements'] = $kept;

		// Long hero headings get smaller type so they do not fill the screen.
		foreach ( $section['elements'] as $i => $e ) {
			if ( 'group' === $e['type'] ) {
				foreach ( $e['elements'] as $j => $child ) {
					if ( self::shrinkH1( $section['elements'][ $i ]['elements'][ $j ] ) ) {
						$changes[] = 'long_heading_smaller';
					}
				}
			} elseif ( self::shrinkH1( $section['elements'][ $i ] ) ) {
				$changes[] = 'long_heading_smaller';
			}
		}
		return array_values( array_unique( $changes ) );
	}

	/**
	 * Smaller type for a long H1.
	 *
	 * @param array<string, mixed> $e Element (changed in place).
	 * @return bool Whether it changed.
	 */
	private static function shrinkH1( array &$e ): bool {
		if ( 'heading' !== $e['type'] || 'h1' !== $e['level'] ) {
			return false;
		}
		$words = self::words( (string) $e['text'] );
		$sizes = $words > 14 ? array( '36px', '30px', '26px' ) : ( $words > 7 ? array( '44px', '36px', '30px' ) : null );
		if ( null === $sizes ) {
			return false;
		}
		$e['style'] = array(
			'font_size' => array(
				'desktop' => $sizes[0],
				'tablet'  => $sizes[1],
				'mobile'  => $sizes[2],
			),
		);
		return true;
	}

	/**
	 * Columns for a grid of $n cards.
	 *
	 * @param int  $n    Cards.
	 * @param bool $long Long card text.
	 */
	public static function gridColumns( int $n, bool $long ): int {
		$columns = match ( true ) {
			$n <= 1      => 1,
			2 === $n     => 2,
			3 === $n     => 3,
			4 === $n     => 4,
			0 === $n % 4 && 0 !== $n % 3 => 4,
			default      => 3,
		};
		return $long ? min( 2, $columns ) : $columns;
	}

	/**
	 * Make sure the page has exactly one H1: the first heading of the first section.
	 *
	 * @param array<string, mixed> $doc Document (changed in place).
	 * @return string Change code, or "".
	 */
	private static function ensureOneH1( array &$doc ): string {
		$found = false;
		foreach ( (array) ( $doc['sections'] ?? array() ) as $section ) {
			foreach ( (array) $section['elements'] as $e ) {
				$found = $found || ( 'heading' === $e['type'] && 'h1' === $e['level'] );
			}
		}
		if ( $found || empty( $doc['sections'] ) ) {
			return '';
		}
		foreach ( $doc['sections'][0]['elements'] as $i => $e ) {
			if ( 'heading' === $e['type'] ) {
				$doc['sections'][0]['elements'][ $i ]['level'] = 'h1';
				return 'h1_added';
			}
		}
		return '';
	}

	/**
	 * Whether any group holds an accordion.
	 *
	 * @param list<array<string, mixed>> $groups Groups.
	 */
	private static function hasAccordion( array $groups ): bool {
		foreach ( $groups as $g ) {
			foreach ( $g['elements'] as $e ) {
				if ( 'accordion' === $e['type'] ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Elements of groups, in order.
	 *
	 * @param list<array<string, mixed>> $groups Groups.
	 * @return list<array<string, mixed>>
	 */
	private static function unwrap( array $groups ): array {
		$out = array();
		foreach ( $groups as $g ) {
			$out = array_merge( $out, $g['elements'] );
		}
		return $out;
	}

	/**
	 * Average visible words per group.
	 *
	 * @param list<array<string, mixed>> $groups Groups.
	 */
	private static function averageWords( array $groups ): float {
		if ( array() === $groups ) {
			return 0.0;
		}
		$total = 0;
		foreach ( $groups as $g ) {
			foreach ( PageGenerator::texts( $g['elements'] ) as $text ) {
				$total += self::words( $text );
			}
		}
		return $total / count( $groups );
	}

	/**
	 * Word count of plain text.
	 *
	 * @param string $text Text.
	 */
	private static function words( string $text ): int {
		$text = trim( HtmlFragment::text( $text ) );
		return '' === $text ? 0 : count( (array) preg_split( '/\s+/u', $text ) );
	}
}
