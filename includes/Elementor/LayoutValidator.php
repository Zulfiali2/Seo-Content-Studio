<?php
/**
 * Validate Elementor element data before saving.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Structural checks on an element tree: allowed element and widget types, correct
 * nesting, unique well-formed ids and array settings. Returns a list of problems
 * (empty = valid). Nothing is saved when a problem is found.
 */
final class LayoutValidator {

	private const MAX_ELEMENTS = 2000;

	/**
	 * Validate.
	 *
	 * @param array<int, mixed> $elements          Top-level elements.
	 * @param string[]          $available_widgets Widget types registered on this site ([] = skip check).
	 * @return string[] Problems.
	 */
	public static function validate( array $elements, array $available_widgets = array() ): array {
		$errors = array();
		$ids    = array();
		$count  = 0;
		if ( array() === $elements ) {
			$errors[] = 'The layout is empty.';
		}
		foreach ( $elements as $i => $element ) {
			self::node( $element, 'root', "[$i]", $available_widgets, $ids, $count, $errors );
		}
		if ( $count > self::MAX_ELEMENTS ) {
			$errors[] = 'The layout has too many elements.';
		}
		return $errors;
	}

	/**
	 * Check one node recursively.
	 *
	 * @param mixed               $node      Node.
	 * @param string              $parent_type Parent type (root, container, section, column).
	 * @param string              $path      Path for messages.
	 * @param string[]            $available Registered widgets.
	 * @param array<string, true> $ids       Seen ids.
	 * @param int                 $count     Element count.
	 * @param string[]            $errors    Problems.
	 */
	private static function node( mixed $node, string $parent_type, string $path, array $available, array &$ids, int &$count, array &$errors ): void {
		++$count;
		if ( ! is_array( $node ) ) {
			$errors[] = "$path is not an element.";
			return;
		}
		$id   = $node['id'] ?? null;
		$type = $node['elType'] ?? null;
		if ( ! is_string( $id ) || ! preg_match( '/^[a-z0-9]{7,8}$/', $id ) ) {
			$errors[] = "$path has an invalid id.";
		} elseif ( isset( $ids[ $id ] ) ) {
			$errors[] = "$path reuses id $id.";
		} else {
			$ids[ $id ] = true;
		}
		if ( ! is_array( $node['settings'] ?? null ) ) {
			$errors[] = "$path has no settings array.";
		}
		if ( ! is_array( $node['elements'] ?? null ) ) {
			$errors[] = "$path has no elements array.";
			return;
		}

		$allowed_parents = array(
			'container' => array( 'root', 'container' ),
			'section'   => array( 'root' ),
			'column'    => array( 'section' ),
			'widget'    => array( 'container', 'column' ),
		);
		if ( ! is_string( $type ) || ! isset( $allowed_parents[ $type ] ) ) {
			$errors[] = "$path has an unsupported element type.";
			return;
		}
		if ( ! in_array( $parent_type, $allowed_parents[ $type ], true ) ) {
			$errors[] = "$path: a $type cannot be placed inside $parent_type.";
		}

		if ( 'widget' === $type ) {
			$widget = $node['widgetType'] ?? null;
			if ( ! is_string( $widget ) || ! in_array( $widget, LayoutBuilder::WIDGETS, true ) ) {
				$errors[] = "$path uses a widget this plugin does not create.";
			} elseif ( array() !== $available && ! in_array( $widget, $available, true ) ) {
				$errors[] = "$path uses the \"$widget\" widget, which is not available on this site.";
			}
			if ( array() !== $node['elements'] ) {
				$errors[] = "$path: widgets cannot contain elements.";
			}
			return;
		}

		foreach ( $node['elements'] as $i => $child ) {
			self::node( $child, $type, "$path.elements[$i]", $available, $ids, $count, $errors );
		}
	}

	/**
	 * Count widgets in a tree.
	 *
	 * @param array<int, mixed> $elements Elements.
	 */
	public static function widgetCount( array $elements ): int {
		$n = 0;
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( 'widget' === ( $element['elType'] ?? '' ) ) {
				++$n;
			}
			$n += self::widgetCount( (array) ( $element['elements'] ?? array() ) );
		}
		return $n;
	}
}
