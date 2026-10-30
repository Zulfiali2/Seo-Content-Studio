<?php
/**
 * Read and change helpers for Elementor element trees.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Editor;

use AiSeoContentStudio\WebsiteStudio\Engine\HtmlFragment;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions over the `elements` array Elementor stores for a document. Elements are
 * addressed by their Elementor id; nothing here talks to WordPress.
 */
final class ElementTree {

	/**
	 * Every element, depth first, with its ancestors' ids.
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @param list<string>      $parents  Ancestor ids (internal).
	 * @return list<array{element: array<string, mixed>, parents: list<string>, top: string}>
	 */
	public static function flatten( array $elements, array $parents = array() ): array {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$id    = (string) ( $el['id'] ?? '' );
			$out[] = array(
				'element' => $el,
				'parents' => $parents,
				'top'     => array() === $parents ? $id : $parents[0],
			);
			if ( is_array( $el['elements'] ?? null ) ) {
				$out = array_merge( $out, self::flatten( $el['elements'], array_merge( $parents, array( $id ) ) ) );
			}
		}
		return $out;
	}

	/**
	 * Find an element by id.
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @param string            $id       Element id.
	 * @return array<string, mixed>|null
	 */
	public static function find( array $elements, string $id ): ?array {
		foreach ( self::flatten( $elements ) as $row ) {
			if ( (string) ( $row['element']['id'] ?? '' ) === $id ) {
				return $row['element'];
			}
		}
		return null;
	}

	/**
	 * Index of a top-level element, or -1.
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @param string            $id       Element id.
	 */
	public static function topIndex( array $elements, string $id ): int {
		foreach ( array_values( $elements ) as $i => $el ) {
			if ( is_array( $el ) && (string) ( $el['id'] ?? '' ) === $id ) {
				return $i;
			}
		}
		return -1;
	}

	/**
	 * Replace $count consecutive top-level elements starting at the one with $id.
	 *
	 * @param array<int, mixed>          $elements    Elements.
	 * @param string                     $id          First element replaced.
	 * @param int                        $count       Elements replaced (≥1).
	 * @param list<array<string, mixed>> $replacement New elements.
	 * @return list<array<string, mixed>>|null Null when $id is not a top-level element.
	 */
	public static function replaceTop( array $elements, string $id, int $count, array $replacement ): ?array {
		$elements = array_values( $elements );
		$index    = self::topIndex( $elements, $id );
		if ( $index < 0 ) {
			return null;
		}
		array_splice( $elements, $index, max( 1, $count ), $replacement );
		return array_values( $elements );
	}

	/**
	 * Insert top-level elements at a position (clamped; -1 = end).
	 *
	 * @param array<int, mixed>          $elements Elements.
	 * @param int                        $position Position.
	 * @param list<array<string, mixed>> $insert   New elements.
	 * @return list<array<string, mixed>>
	 */
	public static function insertTop( array $elements, int $position, array $insert ): array {
		$elements = array_values( $elements );
		$position = $position < 0 ? count( $elements ) : min( $position, count( $elements ) );
		array_splice( $elements, $position, 0, $insert );
		return array_values( $elements );
	}

	/**
	 * Change settings of one element anywhere in the tree.
	 *
	 * @param array<int, mixed>    $elements Elements.
	 * @param string               $id       Element id.
	 * @param array<string, mixed> $set      Key => value (null removes the key). "__globals__.key" removes
	 *                                       or sets a global reference.
	 * @param bool                 $found    Whether the element was found.
	 * @return array<int, mixed>
	 */
	public static function setSettings( array $elements, string $id, array $set, bool &$found = false ): array {
		foreach ( $elements as $i => $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( (string) ( $el['id'] ?? '' ) === $id ) {
				$found    = true;
				$settings = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
				foreach ( $set as $key => $value ) {
					if ( str_starts_with( (string) $key, '__globals__.' ) ) {
						$global = substr( (string) $key, 12 );
						if ( null === $value ) {
							unset( $settings['__globals__'][ $global ] );
						} else {
							$settings['__globals__'][ $global ] = $value;
						}
						continue;
					}
					if ( null === $value ) {
						unset( $settings[ $key ] );
					} else {
						$settings[ $key ] = $value;
					}
				}
				if ( isset( $settings['__globals__'] ) && array() === $settings['__globals__'] ) {
					unset( $settings['__globals__'] );
				}
				$elements[ $i ]['settings'] = $settings;
				return $elements;
			}
			if ( is_array( $el['elements'] ?? null ) ) {
				$elements[ $i ]['elements'] = self::setSettings( $el['elements'], $id, $set, $found );
				if ( $found ) {
					return $elements;
				}
			}
		}
		return $elements;
	}

	/**
	 * A short human label for an element: its first heading, else its first text, else its type.
	 *
	 * @param array<string, mixed> $el Element.
	 */
	public static function label( array $el ): string {
		$texts = self::texts( array( $el ) );
		foreach ( self::flatten( array( $el ) ) as $row ) {
			if ( 'heading' === ( $row['element']['widgetType'] ?? '' ) ) {
				$title = trim( HtmlFragment::text( (string) ( $row['element']['settings']['title'] ?? '' ) ) );
				if ( '' !== $title ) {
					return mb_substr( $title, 0, 80 );
				}
			}
		}
		if ( array() !== $texts ) {
			return mb_substr( $texts[0], 0, 80 );
		}
		return (string) ( $el['widgetType'] ?? $el['elType'] ?? 'element' );
	}

	/**
	 * Visible texts of elements (headings, text, buttons, lists, accordions, icon boxes).
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @return list<string>
	 */
	public static function texts( array $elements ): array {
		$out = array();
		foreach ( self::flatten( $elements ) as $row ) {
			$s = (array) ( $row['element']['settings'] ?? array() );
			foreach ( array( 'title', 'editor', 'text', 'title_text', 'description_text', 'html' ) as $key ) {
				if ( is_string( $s[ $key ] ?? null ) && 'html' !== $key ) {
					$text = trim( HtmlFragment::text( $s[ $key ] ) );
					if ( '' !== $text ) {
						$out[] = $text;
					}
				}
			}
			foreach ( array( 'icon_list' => 'text', 'tabs' => 'tab_title' ) as $list => $key ) {
				foreach ( (array) ( $s[ $list ] ?? array() ) as $item ) {
					if ( is_array( $item ) && is_string( $item[ $key ] ?? null ) ) {
						$text = trim( HtmlFragment::text( $item[ $key ] ) );
						if ( '' !== $text ) {
							$out[] = $text;
						}
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Widget types used, with counts.
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @return array<string, int>
	 */
	public static function widgets( array $elements ): array {
		$out = array();
		foreach ( self::flatten( $elements ) as $row ) {
			$type = (string) ( $row['element']['widgetType'] ?? '' );
			if ( '' !== $type ) {
				$out[ $type ] = ( $out[ $type ] ?? 0 ) + 1;
			}
		}
		return $out;
	}

	/**
	 * Overview of top-level elements.
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @return list<array{id: string, type: string, label: string, widgets: int}>
	 */
	public static function outline( array $elements ): array {
		$out = array();
		foreach ( array_values( $elements ) as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$out[] = array(
				'id'      => (string) ( $el['id'] ?? '' ),
				'type'    => (string) ( $el['elType'] ?? '' ),
				'label'   => self::label( $el ),
				'widgets' => array_sum( self::widgets( array( $el ) ) ),
			);
		}
		return $out;
	}
}
