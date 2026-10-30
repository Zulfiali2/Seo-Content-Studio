<?php
/**
 * Section-level comparison of two revisions.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\History;

use AiSeoContentStudio\WebsiteStudio\Editor\ElementTree;

defined( 'ABSPATH' ) || exit;

/**
 * Compares top-level elements (sections) by their Elementor id: added, removed, moved and
 * changed sections, and for changed ones the texts that were added or removed.
 */
final class RevisionDiff {

	/**
	 * Compare.
	 *
	 * @param array<int, mixed> $from Older elements.
	 * @param array<int, mixed> $to   Newer elements.
	 * @return array{added: list<array<string, mixed>>, removed: list<array<string, mixed>>, changed: list<array<string, mixed>>, moved: list<array<string, mixed>>, unchanged: int}
	 */
	public static function compare( array $from, array $to ): array {
		$a   = self::index( $from );
		$b   = self::index( $to );
		$out = array(
			'added'     => array(),
			'removed'   => array(),
			'changed'   => array(),
			'moved'     => array(),
			'unchanged' => 0,
		);
		foreach ( $b as $id => $row ) {
			if ( ! isset( $a[ $id ] ) ) {
				$out['added'][] = array(
					'id'       => $id,
					'label'    => ElementTree::label( $row['element'] ),
					'position' => $row['position'],
				);
				continue;
			}
			$same_tree = wp_json_encode( $a[ $id ]['element'] ) === wp_json_encode( $row['element'] );
			if ( ! $same_tree ) {
				$before           = ElementTree::texts( array( $a[ $id ]['element'] ) );
				$after            = ElementTree::texts( array( $row['element'] ) );
				$out['changed'][] = array(
					'id'           => $id,
					'label'        => ElementTree::label( $row['element'] ),
					'position'     => $row['position'],
					'text_removed' => array_slice( array_values( array_diff( $before, $after ) ), 0, 10 ),
					'text_added'   => array_slice( array_values( array_diff( $after, $before ) ), 0, 10 ),
					'style_only'   => $before === $after,
				);
			}
			if ( $a[ $id ]['position'] !== $row['position'] ) {
				$out['moved'][] = array(
					'id'   => $id,
					'from' => $a[ $id ]['position'],
					'to'   => $row['position'],
				);
			}
			if ( $same_tree && $a[ $id ]['position'] === $row['position'] ) {
				++$out['unchanged'];
			}
		}
		foreach ( $a as $id => $row ) {
			if ( ! isset( $b[ $id ] ) ) {
				$out['removed'][] = array(
					'id'       => $id,
					'label'    => ElementTree::label( $row['element'] ),
					'position' => $row['position'],
				);
			}
		}
		return $out;
	}

	/**
	 * Top-level elements by id.
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @return array<string, array{element: array<string, mixed>, position: int}>
	 */
	private static function index( array $elements ): array {
		$out = array();
		foreach ( array_values( $elements ) as $i => $el ) {
			if ( is_array( $el ) && '' !== (string) ( $el['id'] ?? '' ) ) {
				$out[ (string) $el['id'] ] = array(
					'element'  => $el,
					'position' => $i,
				);
			}
		}
		return $out;
	}
}
