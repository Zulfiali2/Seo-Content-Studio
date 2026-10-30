<?php
/**
 * Shared adapter behaviour.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers;

use AiSeoContentStudio\Http\HttpClient;
use AiSeoContentStudio\Providers\Contracts\Capability;
use AiSeoContentStudio\Providers\Contracts\ConnectionResult;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\Contracts\ProviderInterface;
use AiSeoContentStudio\Providers\Contracts\TextGenerationInterface;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Security\CredentialStore;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\Support\JsonSchemaValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for the bundled adapters. Provider-specific wire formats stay in subclasses.
 */
abstract class AbstractProvider implements ProviderInterface, TextGenerationInterface {

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository  $settings    Settings.
	 * @param CredentialStore     $credentials Credentials.
	 * @param HttpClient          $http        HTTP client.
	 * @param JsonSchemaValidator $validator   Structured output validator.
	 */
	public function __construct(
		protected readonly SettingsRepository $settings,
		protected readonly CredentialStore $credentials,
		protected readonly HttpClient $http,
		protected readonly JsonSchemaValidator $validator = new JsonSchemaValidator(),
	) {}

	/**
	 * {@inheritDoc}
	 *
	 * @param Capability $capability Capability.
	 */
	public function supports( Capability $capability ): bool {
		return in_array( $capability, $this->capabilities(), true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function isConfigured(): bool {
		return $this->credentials->has( $this->id(), 'api_key' );
	}

	/**
	 * Whether this adapter requires an API key.
	 */
	public function requiresApiKey(): bool {
		return true;
	}

	/**
	 * Default text model from settings ('' when not chosen).
	 */
	public function defaultTextModel(): string {
		return (string) $this->setting( 'text_model', '' );
	}

	/**
	 * Optional cheaper model for quick tasks (metadata, reviews, analysis); '' = use the main model.
	 */
	public function quickTextModel(): string {
		return (string) $this->setting( 'quick_model', '' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function testConnection(): ConnectionResult {
		if ( ! $this->isConfigured() ) {
			return new ConnectionResult( ConnectionResult::ERROR, __( 'Add an API key first.', 'ai-seo-content-studio' ), array(), ProviderException::CONFIG );
		}
		try {
			$models = $this->listModels();
		} catch ( ProviderException $e ) {
			return ConnectionResult::fromException( $e );
		}
		return new ConnectionResult(
			ConnectionResult::OK,
			/* translators: %d: number of models. */
			sprintf( _n( 'Connected. %d model is available to this key.', 'Connected. %d models are available to this key.', count( $models ), 'ai-seo-content-studio' ), count( $models ) ),
			$models
		);
	}

	/**
	 * Extra output tokens for models that reason before answering. Their reasoning counts
	 * against the same output limit, so without headroom structured answers get cut off.
	 * The limit is only a ceiling: unused tokens are not billed.
	 *
	 * @param string $model Model id.
	 */
	public function reasoningHeadroom( string $model ): int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return 0;
	}

	/**
	 * Output limit to send: the requested answer budget plus reasoning headroom.
	 *
	 * @param TextRequest $request Request.
	 */
	protected function outputLimit( TextRequest $request ): int {
		return $request->maxOutputTokens + $this->reasoningHeadroom( strtolower( (string) ( $request->model ?? $this->defaultTextModel() ) ) );
	}

	/**
	 * Per-provider setting.
	 *
	 * @param string $key     Key under ai.providers.{id}.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	protected function setting( string $key, mixed $fallback = null ): mixed {
		return $this->settings->get( 'ai.providers.' . $this->id() . '.' . $key, $fallback );
	}

	/**
	 * Decrypted API key.
	 *
	 * @throws ProviderException When missing or unreadable.
	 */
	protected function apiKey(): string {
		$key = $this->credentials->get( $this->id(), 'api_key' );
		if ( null === $key ) {
			throw new ProviderException(
				/* translators: %s: provider name. */
				sprintf( __( 'No usable API key is stored for %s. Add it under AI Providers.', 'ai-seo-content-studio' ), $this->label() ),
				ProviderException::CONFIG
			);
		}
		return $key;
	}

	/**
	 * Model for a request.
	 *
	 * @param TextRequest $request Request.
	 * @throws ProviderException When no model is configured.
	 */
	protected function resolveModel( TextRequest $request ): string {
		$model = $request->model ?? $this->defaultTextModel();
		if ( '' === $model ) {
			throw new ProviderException(
				/* translators: %s: provider name. */
				sprintf( __( 'Choose a text model for %s under AI Providers.', 'ai-seo-content-studio' ), $this->label() ),
				ProviderException::CONFIG
			);
		}
		return $model;
	}

	/**
	 * Decode and validate structured output.
	 *
	 * @param string      $text    Model output.
	 * @param TextRequest $request Request with jsonSchema.
	 * @return array<string, mixed>
	 * @throws ProviderException When the output is not valid JSON for the schema.
	 */
	protected function decodeStructured( string $text, TextRequest $request ): array {
		$trimmed = trim( $text );
		// Tolerate a Markdown code fence around the JSON (some compatible endpoints add one).
		if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/s', $trimmed, $m ) ) {
			$trimmed = $m[1];
		}
		$data = json_decode( $trimmed, true );
		if ( ! is_array( $data ) ) {
			throw new ProviderException(
				/* translators: %s: provider name. */
				sprintf( __( '%s returned output that is not valid JSON.', 'ai-seo-content-studio' ), $this->label() ),
				ProviderException::INVALID_RESPONSE
			);
		}
		$errors = $this->validator->validate( $data, (array) $request->jsonSchema );
		if ( array() !== $errors ) {
			throw new ProviderException(
				/* translators: 1: provider name, 2: first validation error. */
				sprintf( __( '%1$s returned JSON that does not match the expected structure (%2$s).', 'ai-seo-content-studio' ), $this->label(), $errors[0] ),
				ProviderException::INVALID_RESPONSE
			);
		}
		return $data;
	}

	/**
	 * Exception for a response with no usable output.
	 */
	protected function emptyOutput(): ProviderException {
		/* translators: %s: provider name. */
		return new ProviderException( sprintf( __( '%s returned an empty response.', 'ai-seo-content-studio' ), $this->label() ), ProviderException::INVALID_RESPONSE );
	}

	/**
	 * Exception for a refusal.
	 *
	 * @param string $detail Provider-supplied detail.
	 */
	protected function refusal( string $detail = '' ): ProviderException {
		/* translators: %s: provider name. */
		$message = sprintf( __( '%s declined to produce this content.', 'ai-seo-content-studio' ), $this->label() );
		if ( '' !== $detail ) {
			$message .= ' ' . mb_substr( wp_strip_all_tags( $detail ), 0, 300 );
		}
		return new ProviderException( $message, ProviderException::REFUSAL );
	}

	/**
	 * Exception for output truncated by the token limit.
	 */
	protected function truncated(): ProviderException {
		return new ProviderException( __( 'The AI ran out of room before finishing its answer. Try again; if it keeps happening, choose a faster model (for example a "Flash" or "mini" model) or raise "Max output tokens" under AI Providers → Advanced.', 'ai-seo-content-studio' ), ProviderException::TRUNCATED );
	}

	/**
	 * HTTP options for image requests: larger responses, a longer timeout and no automatic
	 * retries (a retried generation could be billed twice).
	 *
	 * @return array<string, mixed>
	 */
	protected function imageHttpOptions(): array {
		return $this->httpOptions() + array(
			'max_bytes' => 33554432,
			'timeout'   => max( 120, (int) $this->settings->get( 'ai.request_timeout', 90 ) ),
			'retries'   => 0,
		);
	}

	/**
	 * Common options for HttpClient.
	 *
	 * @return array<string, mixed>
	 */
	protected function httpOptions(): array {
		return array( 'provider' => $this->label() );
	}
}
