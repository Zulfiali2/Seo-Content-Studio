<?php
/**
 * Cost reported by a research provider.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-reported charge for one API call (not an estimate).
 */
final readonly class ProviderCost {

	/**
	 * Constructor.
	 *
	 * @param float|null $amount   Amount, or null when not reported.
	 * @param string     $currency Currency code.
	 */
	public function __construct( public ?float $amount, public string $currency = 'USD' ) {}
}
