<?php
/**
 * Finds a design-document section in a page's Elementor data.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Sections;

use AiSeoContentStudio\WebsiteStudio\Editor\ElementTree;

defined( 'ABSPATH' ) || exit;

/**
 * Studio pages built from a PageDocument keep a section map (section id → id of the first
 * top-level Elementor element it produced). One section is one top-level element, except a
 * legacy-layout (no Flexbox Containers) grid section with a header, which is two.
 */
final class SectionLocator {

	/**
	 * Sections of a page with their place in the current Elementor data.
	 *
	 * @param array<string, mixed> $record     Generated page record.
	 * @param array<int, mixed>    $elements   Current elements.
	 * @param bool                 $containers Flexbox Containers active.
	 * @return list<array{section_id: string, index: int, type: string, element_id: string, count: int, present: bool, label: string}>
	 */
	public static function sections( array $record, array $elements, bool $containers ): array {
		$out = array();
		foreach ( (array) ( $record['document']['sections'] ?? array() ) as $index => $section ) {
			$id         = (string) ( $section['id'] ?? '' );
			$element_id = (string) ( $record['section_map'][ $id ] ?? '' );
			$top        = '' === $element_id ? -1 : ElementTree::topIndex( $elements, $element_id );
			$out[]      = array(
				'section_id' => $id,
				'index'      => (int) $index,
				'type'       => (string) ( $section['type'] ?? 'content' ),
				'element_id' => $element_id,
				'count'      => self::span( (array) $section, $containers ),
				'present'    => $top >= 0,
				'label'      => $top >= 0 ? ElementTree::label( (array) array_values( $elements )[ $top ] ) : '',
			);
		}
		return $out;
	}

	/**
	 * One section, or null when it is unknown or no longer on the page.
	 *
	 * @param array<string, mixed> $record     Record.
	 * @param string               $section_id Section id.
	 * @param array<int, mixed>    $elements   Current elements.
	 * @param bool                 $containers Containers active.
	 * @return array{section_id: string, index: int, section: array<string, mixed>, element_id: string, count: int}|null
	 */
	public static function locate( array $record, string $section_id, array $elements, bool $containers ): ?array {
		foreach ( self::sections( $record, $elements, $containers ) as $row ) {
			if ( $row['section_id'] === $section_id && $row['present'] ) {
				return array(
					'section_id' => $section_id,
					'index'      => $row['index'],
					'section'    => (array) $record['document']['sections'][ $row['index'] ],
					'element_id' => $row['element_id'],
					'count'      => $row['count'],
				);
			}
		}
		return null;
	}

	/**
	 * Top-level elements a mapped section occupies.
	 *
	 * @param array<string, mixed> $section    Normalised section.
	 * @param bool                 $containers Containers active.
	 */
	public static function span( array $section, bool $containers ): int {
		if ( $containers || 'stack' === ( $section['layout']['kind'] ?? 'stack' ) ) {
			return 1;
		}
		$groups = 0;
		$other  = 0;
		foreach ( (array) ( $section['elements'] ?? array() ) as $e ) {
			'group' === ( $e['type'] ?? '' ) ? ++$groups : ++$other;
		}
		return $groups > 0 && $other > 0 ? 2 : 1;
	}
}
