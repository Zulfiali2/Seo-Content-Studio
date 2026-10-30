<?php
/**
 * Unique Elementor element IDs.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Issues 7-character lowercase hex IDs (Elementor's own format) that are unique within
 * one document, including repeater item `_id`s. IDs already present in a document can be
 * reserved so that inserted elements never collide with them.
 */
final class IdGenerator {

	/**
	 * Issued or reserved IDs.
	 *
	 * @var array<string, true>
	 */
	private array $used = array();

	/**
	 * Source of random IDs.
	 *
	 * @var callable(): string
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param callable|null $source Returns a candidate ID (tests inject a deterministic one).
	 */
	public function __construct( ?callable $source = null ) {
		$this->source = $source ?? static fn (): string => substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
	}

	/**
	 * A new unique ID.
	 *
	 * @throws \RuntimeException When the source keeps returning used or invalid IDs.
	 */
	public function next(): string {
		for ( $i = 0; $i < 1000; $i++ ) {
			$id = (string) ( $this->source )();
			if ( self::isValid( $id ) && ! isset( $this->used[ $id ] ) ) {
				$this->used[ $id ] = true;
				return $id;
			}
		}
		throw new \RuntimeException( 'Could not generate a unique element id.' );
	}

	/**
	 * Mark IDs as taken.
	 *
	 * @param string ...$ids IDs.
	 */
	public function reserve( string ...$ids ): void {
		foreach ( $ids as $id ) {
			$this->used[ $id ] = true;
		}
	}

	/**
	 * Reserve every element ID and repeater `_id` in a tree.
	 *
	 * @param array<int, mixed> $elements Elements.
	 */
	public function reserveTree( array $elements ): void {
		array_walk_recursive(
			$elements,
			function ( $value, $key ): void {
				if ( ( 'id' === $key || '_id' === $key ) && is_string( $value ) ) {
					$this->used[ $value ] = true;
				}
			}
		);
	}

	/**
	 * Elementor's ID format.
	 *
	 * @param string $id ID.
	 */
	public static function isValid( string $id ): bool {
		return (bool) preg_match( '/^[a-z0-9]{7,8}$/', $id );
	}
}
