<?php
/**
 * Single entry point for billable text generation.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers;

use AiSeoContentStudio\Logging\Logger;
use AiSeoContentStudio\Providers\Contracts\Capability;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\Contracts\TextGenerationInterface;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Providers\Contracts\TextResponse;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\Support\Lock;
use AiSeoContentStudio\Usage\CostEstimator;
use AiSeoContentStudio\Usage\UsageLimiter;
use AiSeoContentStudio\Usage\UsageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps provider calls with: provider/model resolution, request-size limits,
 * output caps, hourly limits, duplicate in-flight protection and usage logging.
 * Feature modules (research, writer, evaluator) must call this rather than
 * adapters directly so every billable call is controlled and recorded.
 */
class TextGenerationService {

	/** Purposes that return a whole article and may exceed the "max output tokens" setting. */
	private const WHOLE_DOCUMENT_PURPOSES = array( 'content_article', 'content_chat', 'content_edit', 'optimize_rewrite' );

	/** Output ceiling for those purposes (only a limit: unused tokens are not billed). */
	private const WHOLE_DOCUMENT_MAX_OUTPUT = 32000;

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry   $providers Providers.
	 * @param SettingsRepository $settings  Settings.
	 * @param UsageLimiter       $limiter   Limits.
	 * @param UsageRepository    $usage     Usage log.
	 * @param CostEstimator      $costs     Cost estimates.
	 * @param Logger             $logger    Logger.
	 */
	public function __construct(
		private readonly ProviderRegistry $providers,
		private readonly SettingsRepository $settings,
		private readonly UsageLimiter $limiter,
		private readonly UsageRepository $usage,
		private readonly CostEstimator $costs,
		private readonly Logger $logger,
	) {}

	/**
	 * Generate text with the given or default provider.
	 *
	 * @param TextRequest $request     Request.
	 * @param string|null $provider_id Provider id; null = default text provider.
	 * @throws ProviderException On any failure (already logged).
	 */
	public function generate( TextRequest $request, ?string $provider_id = null ): TextResponse {
		$provider_id = $provider_id ?? (string) $this->settings->get( 'ai.default_text_provider', '' );
		$provider    = '' === $provider_id ? null : $this->providers->get( $provider_id );

		if ( ! $provider instanceof TextGenerationInterface || ! $provider->supports( Capability::TEXT ) ) {
			throw new ProviderException( __( 'Choose a default text provider under AI Providers.', 'ai-seo-content-studio' ), ProviderException::CONFIG );
		}
		if ( ! $provider->isConfigured() ) {
			/* translators: %s: provider name. */
			throw new ProviderException( sprintf( __( '%s is not configured yet.', 'ai-seo-content-studio' ), $provider->label() ), ProviderException::CONFIG );
		}

		$max_input = (int) $this->settings->get( 'ai.max_input_chars' );
		if ( $request->inputLength() > $max_input ) {
			throw new ProviderException(
				/* translators: %d: character limit. */
				sprintf( __( 'The request is larger than the configured limit of %d characters.', 'ai-seo-content-studio' ), $max_input ),
				ProviderException::LIMIT
			);
		}

		$max_output = (int) $this->settings->get( 'ai.max_output_tokens' );
		if ( in_array( $request->purpose, self::WHOLE_DOCUMENT_PURPOSES, true ) ) {
			// A whole article in one answer needs more room than the per-part setting allows.
			$max_output = max( $max_output, self::WHOLE_DOCUMENT_MAX_OUTPUT );
		}
		if ( $request->maxOutputTokens > $max_output ) {
			$request = $request->withMaxOutputTokens( $max_output );
		}

		// Duplicate detection uses the request as asked for, before the cost policy adjusts it.
		$hash = $request->hash();

		// Cost policy: thinking effort and the optional quick-tasks model, for every provider.
		$original = $request;
		$mode     = CostPolicy::mode( $this->settings->get( 'ai.cost_mode', CostPolicy::BALANCED ) );
		if ( null === $request->model && $provider instanceof AbstractProvider && CostPolicy::useQuickModel( $request->purpose, $mode ) && '' !== $provider->quickTextModel() ) {
			$request = $request->withModel( $provider->quickTextModel() );
		}
		$effort = CostPolicy::effort( $request->purpose, $mode );
		if ( '' === $request->effort && '' !== $effort ) {
			$request = $request->withEffort( $effort );
		}

		$this->limiter->check( 'text' );

		$ttl = (int) $this->settings->get( 'ai.request_timeout' ) * ( 1 + (int) $this->settings->get( 'ai.max_retries' ) ) + 60;
		if ( ! Lock::acquire( 'text:' . $hash, $ttl ) ) {
			throw new ProviderException( __( 'An identical request is already running. Wait for it to finish.', 'ai-seo-content-studio' ), ProviderException::LIMIT );
		}

		$model = $request->model ?? ( $provider instanceof AbstractProvider ? $provider->defaultTextModel() : '' );

		try {
			try {
				$response = $provider->generate( $request );
			} catch ( ProviderException $first ) {
				// A rejected cost setting (retired quick model, effort not accepted by this model)
				// must not fail the step: retry once exactly as originally asked.
				if ( $request === $original || ! in_array( $first->category, array( ProviderException::INVALID_REQUEST, ProviderException::NOT_FOUND ), true ) ) {
					throw $first;
				}
				$this->logger->warning(
					'Retrying without cost settings',
					array(
						'provider' => $provider->id(),
						'category' => $first->category,
					)
				);
				$request  = $original;
				$model    = $request->model ?? ( $provider instanceof AbstractProvider ? $provider->defaultTextModel() : '' );
				$response = $provider->generate( $request );
			}
		} catch ( ProviderException $e ) {
			$this->usage->record(
				array(
					'operation'    => 'text',
					'purpose'      => $request->purpose,
					'provider'     => $provider->id(),
					'model'        => $model,
					'status'       => 'error',
					'error_code'   => $e->category,
					'request_hash' => $hash,
				)
			);
			$this->logger->error(
				'Text generation failed',
				array(
					'provider' => $provider->id(),
					'category' => $e->category,
					'status'   => $e->httpStatus,
				)
			);
			throw $e;
		} finally {
			Lock::release( 'text:' . $hash );
		}

		$billed_model = '' !== $response->model ? $response->model : $model;
		$cost         = $this->costs->estimateText( $provider->id(), $model, $response->usage )
			?? $this->costs->estimateText( $provider->id(), $billed_model, $response->usage );

		$this->usage->record(
			array(
				'operation'      => 'text',
				'purpose'        => $request->purpose,
				'provider'       => $provider->id(),
				'model'          => $billed_model,
				'input_tokens'   => $response->usage->totalInput() ?? 0,
				'output_tokens'  => $response->usage->outputTokens ?? 0,
				'estimated_cost' => $cost['amount'] ?? null,
				'currency'       => $cost['currency'] ?? 'USD',
				'cost_source'    => null === $cost ? '' : 'estimate',
				'status'         => 'success',
				'request_hash'   => $hash,
			)
		);

		return $response;
	}
}
