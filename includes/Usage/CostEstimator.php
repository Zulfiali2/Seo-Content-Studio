<?php
/**
 * Estimated cost calculation from user-configured prices.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Usage;

use AiSeoContentStudio\Providers\Contracts\Usage;
use AiSeoContentStudio\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Converts reported usage to an estimated cost using the pricing table in settings.
 *
 * Estimates only: providers bill on their own metering, discounts, caching and
 * tiers. Returns null when no price is configured or usage was not reported.
 */
class CostEstimator {

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings.
	 */
	public function __construct( private readonly SettingsRepository $settings ) {}

	/**
	 * Find the pricing row for a provider/model. An exact model match wins over a
	 * provider-wide "*" row.
	 *
	 * @param string $provider Provider id.
	 * @param string $model    Model id.
	 * @return array<string, mixed>|null
	 */
	public function priceFor( string $provider, string $model ): ?array {
		$fallback = null;
		foreach ( (array) $this->settings->get( 'pricing', array() ) as $row ) {
			if ( ! is_array( $row ) || ( $row['provider'] ?? '' ) !== $provider ) {
				continue;
			}
			if ( ( $row['model'] ?? '' ) === $model ) {
				return $row;
			}
			if ( '*' === ( $row['model'] ?? '' ) ) {
				$fallback = $row;
			}
		}
		return $fallback;
	}

	/**
	 * Estimate the cost of a text request.
	 *
	 * @param string $provider Provider id.
	 * @param string $model    Model id.
	 * @param Usage  $usage    Reported usage.
	 * @return array{amount: float, currency: string}|null
	 */
	public function estimateText( string $provider, string $model, Usage $usage ): ?array {
		$price = $this->priceFor( $provider, $model );
		if ( null === $price || ! $usage->isReported() ) {
			return null;
		}
		$in  = $price['input_per_million'] ?? null;
		$out = $price['output_per_million'] ?? null;
		if ( null === $in && null === $out && null === ( $price['per_request'] ?? null ) ) {
			return null;
		}
		$in = (float) $in;
		// Cached input: the price table's "cached input" price when set. Otherwise Anthropic's
		// documented multipliers (reads 0.1x, 5-minute writes 1.25x); other providers are
		// estimated at the full input price, so the estimate never under-reports.
		$cached = $price['cached_input_per_million'] ?? null;
		if ( $usage->cacheIncluded ) {
			$uncached = max( 0, (int) $usage->inputTokens - $usage->cacheReadTokens );
			$input    = $uncached * $in + $usage->cacheReadTokens * ( null === $cached ? $in : (float) $cached );
		} else {
			$input = (int) $usage->inputTokens * $in
				+ $usage->cacheReadTokens * ( null === $cached ? $in * 0.1 : (float) $cached )
				+ $usage->cacheWriteTokens * $in * 1.25;
		}
		$amount = (float) ( $price['per_request'] ?? 0 )
			+ $input / 1_000_000
			+ ( (int) $usage->outputTokens ) * (float) $out / 1_000_000;

		return array(
			'amount'   => round( $amount, 6 ),
			'currency' => (string) ( $price['currency'] ?? 'USD' ),
		);
	}

	/**
	 * Estimate a per-unit operation (images, research calls).
	 *
	 * @param string $provider Provider id.
	 * @param string $model    Model or endpoint id.
	 * @param int    $units    Number of images/requests.
	 * @param string $field    "per_image" or "per_request".
	 * @return array{amount: float, currency: string}|null
	 */
	public function estimateUnits( string $provider, string $model, int $units, string $field ): ?array {
		$price = $this->priceFor( $provider, $model );
		if ( null === $price || null === ( $price[ $field ] ?? null ) ) {
			return null;
		}
		return array(
			'amount'   => round( $units * (float) $price[ $field ], 6 ),
			'currency' => (string) ( $price['currency'] ?? 'USD' ),
		);
	}
}
