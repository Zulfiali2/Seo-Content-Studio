<?php
/**
 * Deep copy of an Elementor element tree with fresh IDs.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Cloner;

use AiSeoContentStudio\WebsiteStudio\Engine\IdGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Copies a page's element tree for a new page. Every element ID and every repeater item
 * `_id` (accordion tabs, icon-list items, ...) is replaced, so the copy shares no IDs with
 * the source. Settings are kept as they are: design, responsive values, animations, global
 * references, custom CSS and media references stay identical.
 *
 * Elementor 4 atomic elements keep their style IDs (style IDs are only referenced from the
 * same element's settings, so they stay consistent after the element ID changes).
 */
final class ElementTreeCopier {

	public function __construct( private readonly IdGenerator $ids = new IdGenerator() ) {}

	/**
	 * Copy.
	 *
	 * @param array<int, mixed> $elements Source tree (not modified).
	 * @return array{elements: list<array<string, mixed>>, id_map: array<string, string>}
	 */
	public function copy( array $elements ): array {
		$this->ids->reserveTree( $elements ); // New IDs never equal any source ID.
		$map = array();
		$out = array();
		foreach ( $elements as $element ) {
			if ( is_array( $element ) ) {
				$out[] = $this->element( $element, $map );
			}
		}
		return array(
			'elements' => $out,
			'id_map'   => $map,
		);
	}

	/**
	 * Copy one element.
	 *
	 * @param array<string, mixed>  $element Element.
	 * @param array<string, string> $map     Old => new IDs.
	 * @return array<string, mixed>
	 */
	private function element( array $element, array &$map ): array {
		$old             = is_string( $element['id'] ?? null ) ? $element['id'] : '';
		$new             = $this->ids->next();
		$map[ $old ]     = $new;
		$element['id']   = $new;
		if ( is_array( $element['settings'] ?? null ) ) {
			$element['settings'] = $this->repeaters( $element['settings'] );
		}
		$children = array();
		foreach ( (array) ( $element['elements'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				$children[] = $this->element( $child, $map );
			}
		}
		$element['elements'] = $children;
		return $element;
	}

	/**
	 * Replace `_id` of repeater items anywhere in a settings array.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private function repeaters( array $settings ): array {
		foreach ( $settings as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			if ( array_is_list( $value ) ) {
				foreach ( $value as $i => $item ) {
					if ( is_array( $item ) && is_string( $item['_id'] ?? null ) ) {
						$item['_id'] = $this->ids->next();
					}
					$value[ $i ] = is_array( $item ) ? $this->repeaters( $item ) : $item;
				}
			} else {
				$value = $this->repeaters( $value );
			}
			$settings[ $key ] = $value;
		}
		return $settings;
	}
}
