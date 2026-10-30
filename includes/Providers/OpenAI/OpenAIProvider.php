<?php
/**
 * OpenAI adapter (Responses API).
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\OpenAI;

use AiSeoContentStudio\Http\HttpClient;
use AiSeoContentStudio\Providers\AbstractProvider;
use AiSeoContentStudio\Providers\Contracts\Capability;
use AiSeoContentStudio\Providers\Contracts\ImageGenerationInterface;
use AiSeoContentStudio\Providers\Contracts\ImageRequest;
use AiSeoContentStudio\Providers\Contracts\ImageResponse;
use AiSeoContentStudio\Providers\Contracts\ModelInfo;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Providers\Contracts\TextResponse;
use AiSeoContentStudio\Providers\Contracts\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Uses POST /v1/responses and GET /v1/models.
 *
 * Image generation is declared in Phase 5 once implemented and tested.
 */
class OpenAIProvider extends AbstractProvider implements ImageGenerationInterface {

	public const BASE_URL = 'https://api.openai.com/v1';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'openai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'OpenAI';
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities(): array {
		return array( Capability::TEXT, Capability::STRUCTURED_OUTPUT, Capability::IMAGE, Capability::MODEL_LISTING, Capability::USAGE_REPORTING );
	}

	/**
	 * {@inheritDoc}
	 */
	public function listModels(): array {
		$json   = $this->http->requestJson( 'GET', $this->baseUrl() . '/models', $this->headers(), null, $this->httpOptions() );
		$models = array();
		foreach ( (array) ( $json['data'] ?? array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && is_string( $row['id'] ) ) {
				$models[] = new ModelInfo( $row['id'] );
			}
		}
		usort( $models, static fn ( ModelInfo $a, ModelInfo $b ): int => strcmp( $a->id, $b->id ) );
		return $models;
	}

	/**
	 * Build the request body (public for tests).
	 *
	 * @param TextRequest $request Request.
	 * @return array<string, mixed>
	 */
	public function buildBody( TextRequest $request ): array {
		$model = $this->resolveModel( $request );
		$input = array();
		foreach ( $request->messages as $i => $m ) {
			// Shared context first: identical prompt starts are cached automatically.
			$input[] = array(
				'role'    => $m->role,
				'content' => $request->renderedContent( (int) $i ),
			);
		}
		$body = array(
			'model'             => $model,
			'input'             => $input,
			'max_output_tokens' => $this->outputLimit( $request ),
			'store'             => false,
		);
		if ( '' !== $request->effort && $this->supportsReasoningEffort( strtolower( $model ) ) ) {
			$body['reasoning'] = array( 'effort' => $request->effort );
		}
		if ( '' !== $request->sharedContext && $this->usesPromptCacheKey() ) {
			// Routes requests that share a prefix to the same cache (OpenAI Responses `prompt_cache_key`).
			$body['prompt_cache_key'] = 'aiscs-' . substr( hash( 'sha256', $request->system . $request->sharedContext ), 0, 40 );
		}
		if ( '' !== $request->system ) {
			$body['instructions'] = $request->system;
		}
		if ( null !== $request->jsonSchema ) {
			$body['text'] = array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => $request->schemaName,
					'schema' => $request->jsonSchema,
					'strict' => true,
				),
			);
		}
		return $body;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param TextRequest $request Request.
	 * @throws ProviderException On failure.
	 */
	public function generate( TextRequest $request ): TextResponse {
		$json = $this->http->requestJson( 'POST', $this->baseUrl() . '/responses', $this->headers(), $this->buildBody( $request ), $this->httpOptions() );
		return $this->parseResponse( $json, $request );
	}

	/**
	 * Normalise a Responses API payload (public for tests).
	 *
	 * @param array<string, mixed> $json    Response.
	 * @param TextRequest          $request Original request.
	 * @throws ProviderException On refusal, truncation, empty or invalid output.
	 */
	public function parseResponse( array $json, TextRequest $request ): TextResponse {
		$text    = '';
		$refusal = '';
		foreach ( (array) ( $json['output'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || 'message' !== ( $item['type'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $item['content'] ?? array() ) as $part ) {
				if ( ! is_array( $part ) ) {
					continue;
				}
				if ( 'output_text' === ( $part['type'] ?? '' ) && is_string( $part['text'] ?? null ) ) {
					$text .= $part['text'];
				} elseif ( 'refusal' === ( $part['type'] ?? '' ) ) {
					$refusal .= (string) ( $part['refusal'] ?? '' );
				}
			}
		}

		$status = (string) ( $json['status'] ?? '' );
		if ( 'failed' === $status ) {
			throw new ProviderException(
				/* translators: 1: provider name, 2: upstream error message. */
				sprintf( __( '%1$s reported a failed response: %2$s', 'ai-seo-content-studio' ), $this->label(), HttpClient::upstreamMessage( array( 'error' => $json['error'] ?? array() ) ) ),
				ProviderException::SERVER,
				true
			);
		}
		if ( '' !== $refusal && '' === $text ) {
			throw $this->refusal( $refusal );
		}

		$finish = TextResponse::FINISH_STOP;
		if ( 'incomplete' === $status ) {
			$finish = 'max_output_tokens' === ( $json['incomplete_details']['reason'] ?? '' ) ? TextResponse::FINISH_LENGTH : TextResponse::FINISH_OTHER;
		}

		if ( null !== $request->jsonSchema && TextResponse::FINISH_LENGTH === $finish ) {
			throw $this->truncated();
		}
		if ( '' === trim( $text ) ) {
			$error = TextResponse::FINISH_LENGTH === $finish ? $this->truncated() : $this->emptyOutput();
			throw $error;
		}

		$usage = new Usage(
			isset( $json['usage']['input_tokens'] ) ? (int) $json['usage']['input_tokens'] : null,
			isset( $json['usage']['output_tokens'] ) ? (int) $json['usage']['output_tokens'] : null,
			(int) ( $json['usage']['input_tokens_details']['cached_tokens'] ?? 0 ),
			0,
			true
		);

		return new TextResponse(
			$this->id(),
			(string) ( $json['model'] ?? $request->model ?? '' ),
			$text,
			null !== $request->jsonSchema ? $this->decodeStructured( $text, $request ) : null,
			$finish,
			$usage
		);
	}

	/**
	 * Reasoning models accept `reasoning.effort` (low | medium | high).
	 *
	 * @param string $model Model id (lower case).
	 */
	public function supportsReasoningEffort( string $model ): bool {
		return $this->reasoningHeadroom( $model ) > 0;
	}

	/**
	 * Whether `prompt_cache_key` is sent (OpenAI only; not verified for compatible APIs).
	 */
	protected function usesPromptCacheKey(): bool {
		return 'openai' === $this->id();
	}

	/**
	 * OpenAI reasoning models (o-series, GPT-5 and later except the "chat" variants) count
	 * reasoning tokens as output. Model families follow the official @ai-sdk/openai capabilities.
	 *
	 * @param string $model Model id (lower case).
	 */
	public function reasoningHeadroom( string $model ): int {
		$reasoning = (bool) preg_match( '/^o\d/', $model )
			|| ( (bool) preg_match( '/^gpt-(\d+)/', $model, $m ) && (int) $m[1] >= 5 && ! str_contains( $model, '-chat' ) );
		return $reasoning ? 16000 : 0;
	}

	/**
	 * Image model from settings.
	 */
	public function defaultImageModel(): string {
		return (string) $this->setting( 'image_model', '' );
	}

	/**
	 * Request body for POST /images/generations (verified against the openai SDK 7.32 types).
	 * GPT image models always return base64 and reject `response_format`; the legacy DALL·E
	 * models are asked for base64 explicitly so no image URL ever has to be fetched.
	 *
	 * @param ImageRequest $request Request.
	 * @return array<string, mixed>
	 */
	public function buildImageBody( ImageRequest $request ): array {
		$model = $this->resolveImageModel( $request );
		$dalle = str_starts_with( $model, 'dall-e' );
		$sizes = 'dall-e-3' === $model
			? array(
				'1:1' => '1024x1024',
				'3:2' => '1792x1024',
				'2:3' => '1024x1792',
			)
			: array(
				'1:1' => '1024x1024',
				'3:2' => '1536x1024',
				'2:3' => '1024x1536',
			);
		$body  = array(
			'model'  => $model,
			'prompt' => $request->prompt,
			'n'      => 1,
			'size'   => $sizes[ $request->aspect ] ?? '1024x1024',
		);
		if ( $dalle ) {
			$body['response_format'] = 'b64_json';
		}
		return $body;
	}

	/**
	 * Generate an image.
	 *
	 * @param ImageRequest $request Request.
	 */
	public function generateImage( ImageRequest $request ): ImageResponse {
		$json = $this->http->requestJson( 'POST', $this->baseUrl() . '/images/generations', $this->headers(), $this->buildImageBody( $request ), $this->imageHttpOptions() );
		return $this->parseImageResponse( $json, $this->resolveImageModel( $request ) );
	}

	/**
	 * Parse an images response (`data[].b64_json`).
	 *
	 * @param array<string, mixed> $json  Response.
	 * @param string               $model Requested model.
	 * @throws ProviderException When no image is returned.
	 */
	public function parseImageResponse( array $json, string $model ): ImageResponse {
		$first = is_array( $json['data'][0] ?? null ) ? $json['data'][0] : array();
		if ( isset( $first['respect_moderation'] ) && false === $first['respect_moderation'] ) {
			throw $this->refusal( __( 'The image was blocked by the provider\'s content policy.', 'ai-seo-content-studio' ) );
		}
		$b64 = is_string( $first['b64_json'] ?? null ) ? $first['b64_json'] : '';
		if ( '' === $b64 ) {
			throw new ProviderException(
				/* translators: %s: provider name. */
				sprintf( __( '%s returned no image data.', 'ai-seo-content-studio' ), $this->label() ),
				ProviderException::INVALID_RESPONSE
			);
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- provider returns base64 image data.
		$bytes = base64_decode( $b64, true );
		if ( false === $bytes || '' === $bytes ) {
			throw new ProviderException( __( 'The image data could not be decoded.', 'ai-seo-content-studio' ), ProviderException::INVALID_RESPONSE );
		}
		return new ImageResponse(
			$this->id(),
			$model,
			$bytes,
			is_string( $first['revised_prompt'] ?? null ) ? $first['revised_prompt'] : '',
			new Usage(
				isset( $json['usage']['input_tokens'] ) ? (int) $json['usage']['input_tokens'] : null,
				isset( $json['usage']['output_tokens'] ) ? (int) $json['usage']['output_tokens'] : null
			)
		);
	}

	/**
	 * Image model or a configuration error.
	 *
	 * @param ImageRequest $request Request.
	 * @throws ProviderException When no image model is chosen.
	 */
	protected function resolveImageModel( ImageRequest $request ): string {
		$model = $request->model ?? $this->defaultImageModel();
		if ( '' === $model ) {
			throw new ProviderException(
				/* translators: %s: provider name. */
				sprintf( __( 'Choose an image model for %s under AI Providers.', 'ai-seo-content-studio' ), $this->label() ),
				ProviderException::CONFIG
			);
		}
		return $model;
	}

	/**
	 * API base URL. Overridden by adapters for Responses-compatible APIs.
	 */
	protected function baseUrl(): string {
		return self::BASE_URL;
	}

	/**
	 * Auth headers.
	 *
	 * @return array<string, string>
	 */
	protected function headers(): array {
		return array( 'Authorization' => 'Bearer ' . $this->apiKey() );
	}
}
