<?php
/**
 * Controlled entry point for image generation.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers;

use AiSeoContentStudio\Logging\Logger;
use AiSeoContentStudio\Media\ImageValidator;
use AiSeoContentStudio\Providers\Contracts\Capability;
use AiSeoContentStudio\Providers\Contracts\ImageGenerationInterface;
use AiSeoContentStudio\Providers\Contracts\ImageRequest;
use AiSeoContentStudio\Providers\Contracts\ImageResponse;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\Contracts\ProviderInterface;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\Support\Lock;
use AiSeoContentStudio\Usage\CostEstimator;
use AiSeoContentStudio\Usage\UsageLimiter;
use AiSeoContentStudio\Usage\UsageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Every image request goes through here: provider resolution, hourly limit, duplicate
 * protection, byte validation and the usage log (one unit per image, priced from the
 * "per image" column of the price table).
 */
class ImageGenerationService {

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry   $providers Providers.
	 * @param SettingsRepository $settings  Settings.
	 * @param UsageLimiter       $limiter   Limits.
	 * @param UsageRepository    $usage     Usage log.
	 * @param CostEstimator      $costs     Prices.
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
	 * Configured image provider, or null.
	 */
	public function provider(): (ProviderInterface&ImageGenerationInterface)|null {
		$id       = (string) $this->settings->get( 'ai.default_image_provider', '' );
		$provider = '' === $id ? null : $this->providers->get( $id );
		return $provider instanceof ImageGenerationInterface && $provider instanceof ProviderInterface && $provider->supports( Capability::IMAGE ) ? $provider : null;
	}

	/**
	 * Generate and validate one image.
	 *
	 * @param ImageRequest $request Request.
	 * @return array{response: ImageResponse, file: array{mime: string, ext: string, width: int, height: int, bytes: int}}
	 * @throws ProviderException On configuration, limit, provider or validation errors.
	 */
	public function generate( ImageRequest $request ): array {
		$provider = $this->provider();
		if ( null === $provider ) {
			throw new ProviderException( __( 'Choose a default image provider under AI Providers.', 'ai-seo-content-studio' ), ProviderException::CONFIG );
		}
		if ( ! $provider->isConfigured() ) {
			/* translators: %s: provider name. */
			throw new ProviderException( sprintf( __( '%s is not configured yet.', 'ai-seo-content-studio' ), $provider->label() ), ProviderException::CONFIG );
		}
		if ( mb_strlen( $request->prompt ) > 4000 ) {
			throw new ProviderException( __( 'The image prompt is too long (4,000 characters at most).', 'ai-seo-content-studio' ), ProviderException::LIMIT );
		}

		$this->limiter->check( 'image' );

		$hash = $request->hash();
		// Shorter than the job lock, so a step re-run after a crash is not blocked by a stale lock.
		if ( ! Lock::acquire( 'image:' . $hash, max( 120, (int) $this->settings->get( 'ai.request_timeout', 90 ) ) + 60 ) ) {
			throw new ProviderException( __( 'An identical image request is already running. Wait for it to finish.', 'ai-seo-content-studio' ), ProviderException::LIMIT );
		}

		$model = $request->model ?? $provider->defaultImageModel();
		try {
			$response = $provider->generateImage( $request );
			$file     = ImageValidator::validate( $response->bytes );
		} catch ( ProviderException $e ) {
			$this->usage->record(
				array(
					'operation'    => 'image',
					'purpose'      => $request->purpose,
					'provider'     => $provider->id(),
					'model'        => $model,
					'status'       => 'error',
					'error_code'   => $e->category,
					'request_hash' => $hash,
				)
			);
			$this->logger->error(
				'Image generation failed',
				array(
					'provider' => $provider->id(),
					'category' => $e->category,
				)
			);
			throw $e;
		} finally {
			Lock::release( 'image:' . $hash );
		}

		$billed = '' !== $response->model ? $response->model : $model;
		$cost   = $this->costs->estimateUnits( $provider->id(), $model, 1, 'per_image' )
			?? $this->costs->estimateUnits( $provider->id(), $billed, 1, 'per_image' );
		$this->usage->record(
			array(
				'operation'      => 'image',
				'purpose'        => $request->purpose,
				'provider'       => $provider->id(),
				'model'          => $billed,
				'input_tokens'   => $response->usage->inputTokens ?? 0,
				'output_tokens'  => $response->usage->outputTokens ?? 0,
				'units'          => 1,
				'estimated_cost' => $cost['amount'] ?? null,
				'currency'       => $cost['currency'] ?? 'USD',
				'cost_source'    => null === $cost ? '' : 'estimate',
				'status'         => 'success',
				'request_hash'   => $hash,
			)
		);

		return array(
			'response' => $response,
			'file'     => $file,
		);
	}
}
