<?php
/**
 * Controlled access to paid research calls.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research;

use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Research\Contracts\ResearchProviderInterface;
use AiSeoContentStudio\Research\Contracts\ResearchResult;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\Usage\UsageLimiter;
use AiSeoContentStudio\Usage\UsageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps every research call with: cache lookup, hourly limit, call, usage log
 * with the provider-reported cost, and cache store. Cached answers are free and
 * are not counted against limits.
 */
class ResearchGateway {

	/**
	 * Constructor.
	 *
	 * @param ResearchProviderInterface $provider Provider.
	 * @param ResearchCache             $cache    Cache.
	 * @param UsageLimiter              $limiter  Limits.
	 * @param UsageRepository           $usage    Usage log.
	 * @param SettingsRepository        $settings Settings.
	 */
	public function __construct(
		private readonly ResearchProviderInterface $provider,
		private readonly ResearchCache $cache,
		private readonly UsageLimiter $limiter,
		private readonly UsageRepository $usage,
		private readonly SettingsRepository $settings,
	) {}

	/**
	 * The underlying provider.
	 */
	public function provider(): ResearchProviderInterface {
		return $this->provider;
	}

	/**
	 * Run a provider call through the cache.
	 *
	 * @param string               $purpose Usage purpose label (e.g. "serp").
	 * @param array<string, mixed> $params  Cache key parameters.
	 * @param callable             $call    fn(ResearchProviderInterface): ResearchResult.
	 * @param bool                 $refresh Bypass the cache.
	 * @param int|null             $ttl     Cache lifetime in seconds (default from settings).
	 * @return array{data: array<string, mixed>, cached: bool, cached_at: int, cost: float|null, currency: string}
	 * @throws ProviderException On failure (already logged).
	 */
	public function call( string $purpose, array $params, callable $call, bool $refresh = false, ?int $ttl = null ): array {
		$key_params = $params + array(
			'provider' => $this->provider->id(),
			'sandbox'  => (bool) $this->settings->get( 'research.use_sandbox', false ),
		);

		if ( ! $refresh ) {
			$hit = $this->cache->get( $purpose, $key_params );
			if ( null !== $hit ) {
				return array(
					'data'      => (array) $hit['data'],
					'cached'    => true,
					'cached_at' => (int) $hit['cached_at'],
					'cost'      => 0.0,
					'currency'  => 'USD',
				);
			}
		}

		$this->limiter->check( 'research' );

		try {
			$result = $call( $this->provider );
			if ( ! $result instanceof ResearchResult ) {
				throw new ProviderException( 'Research call returned an invalid result.', ProviderException::INVALID_RESPONSE );
			}
		} catch ( ProviderException $e ) {
			$this->usage->record(
				array(
					'operation'  => 'research',
					'purpose'    => $purpose,
					'provider'   => $this->provider->id(),
					'status'     => 'error',
					'error_code' => $e->category,
				)
			);
			throw $e;
		}

		$this->usage->record(
			array(
				'operation'      => 'research',
				'purpose'        => $purpose,
				'provider'       => $this->provider->id(),
				'model'          => $result->endpoint,
				'units'          => 1,
				'estimated_cost' => $result->cost->amount,
				'currency'       => $result->cost->currency,
				'cost_source'    => null === $result->cost->amount ? '' : 'provider',
				'status'         => 'success',
			)
		);

		$this->cache->set( $purpose, $key_params, $result->data, $ttl ?? (int) $this->settings->get( 'research.cache_hours', 72 ) * HOUR_IN_SECONDS );

		return array(
			'data'      => $result->data,
			'cached'    => false,
			'cached_at' => time(),
			'cost'      => $result->cost->amount,
			'currency'  => $result->cost->currency,
		);
	}
}
