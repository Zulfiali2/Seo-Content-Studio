<?php
/**
 * Weighted style observations collected by the analyzers.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Analysis;

defined( 'ABSPATH' ) || exit;

/**
 * A tally of observed style values by slot ("colors.text", "sizes.h1.mobile", ...).
 *
 * Analyzers record what they see with a weight (an explicit Elementor global counts
 * for more than one widget's local colour); the BrandExtractor then picks the
 * strongest value per slot. Values are stored as given; the extractor validates them
 * through DesignTokens before they reach a profile.
 */
final class StyleSignals {

	/** Hard cap on distinct values per slot, so hostile CSS cannot grow the analysis without bound. */
	private const MAX_VALUES = 64;

	/**
	 * Slot => value => weight.
	 *
	 * @var array<string, array<string, float>>
	 */
	private array $slots = array();

	/**
	 * Record a value.
	 *
	 * @param string $slot   Slot name.
	 * @param mixed  $value  Value (scalars only; empty values are ignored).
	 * @param float  $weight Weight.
	 */
	public function add( string $slot, mixed $value, float $weight = 1.0 ): void {
		if ( ! is_scalar( $value ) ) {
			return;
		}
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 200 || $weight <= 0 ) {
			return;
		}
		if ( ! isset( $this->slots[ $slot ][ $value ] ) && count( $this->slots[ $slot ] ?? array() ) >= self::MAX_VALUES ) {
			return;
		}
		$this->slots[ $slot ][ $value ] = ( $this->slots[ $slot ][ $value ] ?? 0.0 ) + $weight;
	}

	/**
	 * Merge another collector (optionally scaled).
	 *
	 * @param StyleSignals $other  Other signals.
	 * @param float        $factor Weight multiplier.
	 */
	public function merge( StyleSignals $other, float $factor = 1.0 ): void {
		foreach ( $other->toArray() as $slot => $values ) {
			foreach ( $values as $value => $weight ) {
				$this->add( $slot, (string) $value, (float) $weight * $factor );
			}
		}
	}

	/**
	 * Values for a slot, strongest first.
	 *
	 * @param string $slot Slot.
	 * @return array<string, float>
	 */
	public function ranked( string $slot ): array {
		$values = $this->slots[ $slot ] ?? array();
		// Ties keep first-seen order: uksort is stable on PHP 8.
		uksort( $values, static fn ( $a, $b ): int => $values[ $b ] <=> $values[ $a ] );
		return $values;
	}

	/**
	 * Strongest value for a slot, after an optional filter.
	 *
	 * @param string        $slot   Slot.
	 * @param callable|null $accept Returns a cleaned value or null to skip.
	 */
	public function top( string $slot, ?callable $accept = null ): string {
		foreach ( array_keys( $this->ranked( $slot ) ) as $value ) {
			$value = (string) $value;
			$clean = null === $accept ? $value : $accept( $value );
			if ( null !== $clean && '' !== $clean ) {
				return (string) $clean;
			}
		}
		return '';
	}

	/**
	 * Whether anything was recorded for a slot.
	 *
	 * @param string $slot Slot.
	 */
	public function has( string $slot ): bool {
		return ! empty( $this->slots[ $slot ] );
	}

	/**
	 * Export (stored with an analysis, rounded for compactness).
	 *
	 * @return array<string, array<string, float>>
	 */
	public function toArray(): array {
		$out = array();
		foreach ( array_keys( $this->slots ) as $slot ) {
			foreach ( $this->ranked( $slot ) as $value => $weight ) {
				$out[ $slot ][ (string) $value ] = round( $weight, 2 );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Rebuild from an export.
	 *
	 * @param mixed $data Exported data.
	 */
	public static function fromArray( mixed $data ): self {
		$signals = new self();
		foreach ( (array) $data as $slot => $values ) {
			if ( ! is_string( $slot ) || ! is_array( $values ) ) {
				continue;
			}
			foreach ( $values as $value => $weight ) {
				$signals->add( $slot, (string) $value, (float) $weight );
			}
		}
		return $signals;
	}
}
