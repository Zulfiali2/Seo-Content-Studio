<?php
/**
 * Result of one research provider call.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Normalised data plus the provider-reported cost and the endpoint used.
 */
final readonly class ResearchResult {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data     Normalised data (shape depends on the call).
	 * @param ProviderCost         $cost     Reported cost.
	 * @param string               $endpoint Provider endpoint identifier (for usage logs).
	 */
	public function __construct( public array $data, public ProviderCost $cost, public string $endpoint ) {}
}
