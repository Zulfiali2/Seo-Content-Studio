<?php
/**
 * Google Gemini adapter (Generative Language API, v1beta).
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Gemini;

use AiSeoContentStudio\Providers\AbstractProvider;
use AiSeoContentStudio\Providers\Contracts\ImageInput;
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
 * Uses models/{model}:generateContent and GET models. The key is sent in the
 * x-goog-api-key header, never in the URL.
 */
class GeminiProvider extends AbstractProvider implements ImageGenerationInterface {

	public const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta';

	/** Maximum pages fetched when listing models. */
	private const MAX_MODEL_PAGES = 5;

	/** Values of finishReason that mean the output was blocked. */
	private const BLOCKED = array( 'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY' );

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'gemini';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Google Gemini';
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities(): array {
		return array( Capability::TEXT, Capability::STRUCTURED_OUTPUT, Capability::IMAGE, Capability::VISION, Capability::MODEL_LISTING, Capability::USAGE_REPORTING );
	}

	/**
	 * {@inheritDoc}
	 */
	public function listModels(): array {
		$models = array();
		$token  = null;
		for ( $page = 0; $page < self::MAX_MODEL_PAGES; $page++ ) {
			$query = array( 'pageSize' => 1000 );
			if ( null !== $token ) {
				$query['pageToken'] = $token;
			}
			$json = $this->http->requestJson( 'GET', add_query_arg( $query, self::BASE_URL . '/models' ), $this->headers(), null, $this->httpOptions() );
			foreach ( (array) ( $json['models'] ?? array() ) as $row ) {
				if ( ! is_array( $row ) || ! is_string( $row['name'] ?? null ) ) {
					continue;
				}
				$models[] = new ModelInfo(
					self::stripPrefix( $row['name'] ),
					is_string( $row['displayName'] ?? null ) ? $row['displayName'] : '',
					array_values( array_filter( (array) ( $row['supportedGenerationMethods'] ?? array() ), 'is_string' ) )
				);
			}
			if ( ! is_string( $json['nextPageToken'] ?? null ) || '' === $json['nextPageToken'] ) {
				break;
			}
			$token = $json['nextPageToken'];
		}
		return $models;
	}

	/**
	 * Build the request body (public for tests).
	 *
	 * @param TextRequest $request Request.
	 * @return array<string, mixed>
	 */
	public function buildBody( TextRequest $request ): array {
		$contents = array();
		foreach ( $request->messages as $i => $m ) {
			// Shared context first: Gemini 2.5+ caches identical prompt starts implicitly.
			$contents[] = array(
				'role'  => 'assistant' === $m->role ? 'model' : 'user',
				'parts' => array_merge(
					array_map(
						static fn ( ImageInput $image ): array => array(
							'inlineData' => array(
								'mimeType' => $image->mime,
								'data'     => $image->base64,
							),
						),
						$m->images
					),
					array( array( 'text' => $request->renderedContent( (int) $i ) ) )
				),
			);
		}
		$body     = array(
			'contents'         => $contents,
			'generationConfig' => array( 'maxOutputTokens' => $this->outputLimit( $request ) ),
		);
		$thinking = self::thinkingConfig( strtolower( (string) ( $request->model ?? $this->defaultTextModel() ) ), $request->effort );
		if ( null !== $thinking ) {
			$body['generationConfig']['thinkingConfig'] = $thinking;
		}
		if ( '' !== $request->system ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $request->system ) ) );
		}
		if ( null !== $request->jsonSchema ) {
			$body['generationConfig']['responseMimeType']   = 'application/json';
			$body['generationConfig']['responseJsonSchema'] = $request->jsonSchema;
		}
		return $body;
	}

	/**
	 * Endpoint for a model (public for tests).
	 *
	 * @param string $model Model id with or without "models/" prefix.
	 */
	public function endpoint( string $model ): string {
		return self::BASE_URL . '/models/' . rawurlencode( self::stripPrefix( $model ) ) . ':generateContent';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param TextRequest $request Request.
	 * @throws ProviderException On failure.
	 */
	public function generate( TextRequest $request ): TextResponse {
		$model = $this->resolveModel( $request );
		$json  = $this->http->requestJson( 'POST', $this->endpoint( $model ), $this->headers(), $this->buildBody( $request ), $this->httpOptions() );
		return $this->parseResponse( $json, $request->withModel( $model ) );
	}

	/**
	 * Thinking settings for an effort level, following @ai-sdk/google 4.0.94: Gemini 3 and
	 * later take `thinkingLevel` (low / medium / high); Gemini 2.5 takes a `thinkingBudget`.
	 *
	 * @param string $model  Model id (lower case).
	 * @param string $effort low | medium | high | ''.
	 * @return array<string, mixed>|null
	 */
	public static function thinkingConfig( string $model, string $effort ): ?array {
		if ( '' === $effort || 'high' === $effort ) {
			return null; // Provider default (dynamic thinking).
		}
		$model = preg_replace( '#^models/#', '', $model );
		if ( preg_match( '/^gemini-([3-9]|\d{2,})/', (string) $model ) && ! str_contains( (string) $model, 'gemini-3-pro-image' ) ) {
			return array( 'thinkingLevel' => $effort );
		}
		if ( preg_match( '/^gemini-2\.5/', (string) $model ) ) {
			return array( 'thinkingBudget' => 'low' === $effort ? 1024 : 4096 );
		}
		return null;
	}

	/**
	 * Gemini 2.5 and later think before answering (thinking tokens are billed as output).
	 *
	 * @param string $model Model id (lower case).
	 */
	public function reasoningHeadroom( string $model ): int {
		return preg_match( '/gemini-(2\.5|[3-9])/', $model ) ? 16000 : 0;
	}

	/**
	 * Image model from settings.
	 */
	public function defaultImageModel(): string {
		return (string) $this->setting( 'image_model', '' );
	}

	/**
	 * Image request body: generateContent with IMAGE output and an aspect ratio
	 * (verified against @ai-sdk/google 4.0.94, which supports only "gemini-" image models).
	 *
	 * @param ImageRequest $request Request.
	 * @return array<string, mixed>
	 */
	public function buildImageBody( ImageRequest $request ): array {
		return array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $request->prompt ) ),
				),
			),
			'generationConfig' => array(
				'responseModalities' => array( 'IMAGE' ),
				'imageConfig'        => array( 'aspectRatio' => in_array( $request->aspect, ImageRequest::ASPECTS, true ) ? $request->aspect : '1:1' ),
			),
		);
	}

	/**
	 * Generate an image.
	 *
	 * @param ImageRequest $request Request.
	 * @throws ProviderException When no Gemini image model is chosen.
	 */
	public function generateImage( ImageRequest $request ): ImageResponse {
		$model = self::stripPrefix( $request->model ?? $this->defaultImageModel() );
		if ( '' === $model ) {
			/* translators: %s: provider name. */
			throw new ProviderException( sprintf( __( 'Choose an image model for %s under AI Providers.', 'ai-seo-content-studio' ), $this->label() ), ProviderException::CONFIG );
		}
		if ( ! str_starts_with( $model, 'gemini-' ) ) {
			throw new ProviderException( __( 'Choose a Gemini image model (its id starts with "gemini-" and contains "image"). Other Google image models are not supported.', 'ai-seo-content-studio' ), ProviderException::CONFIG );
		}
		$json = $this->http->requestJson( 'POST', $this->endpoint( $model ), $this->headers(), $this->buildImageBody( $request ), $this->imageHttpOptions() );
		return $this->parseImageResponse( $json, $model );
	}

	/**
	 * First inline image of a generateContent response.
	 *
	 * @param array<string, mixed> $json  Response.
	 * @param string               $model Model.
	 * @throws ProviderException On refusal or when no image is returned.
	 */
	public function parseImageResponse( array $json, string $model ): ImageResponse {
		$block_reason = (string) ( $json['promptFeedback']['blockReason'] ?? '' );
		if ( '' !== $block_reason ) {
			throw $this->refusal( $block_reason );
		}
		$candidate = is_array( $json['candidates'][0] ?? null ) ? $json['candidates'][0] : array();
		$reason    = (string) ( $candidate['finishReason'] ?? '' );
		if ( in_array( $reason, self::BLOCKED, true ) ) {
			throw $this->refusal( $reason );
		}
		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			$data = is_array( $part ) && is_array( $part['inlineData'] ?? null ) ? $part['inlineData'] : null;
			if ( $data && is_string( $data['data'] ?? null ) && str_starts_with( (string) ( $data['mimeType'] ?? '' ), 'image/' ) ) {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- inline image data.
				$bytes = base64_decode( $data['data'], true );
				if ( false !== $bytes && '' !== $bytes ) {
					$meta = is_array( $json['usageMetadata'] ?? null ) ? $json['usageMetadata'] : array();
					return new ImageResponse(
						$this->id(),
						(string) ( $json['modelVersion'] ?? $model ),
						$bytes,
						'',
						new Usage( isset( $meta['promptTokenCount'] ) ? (int) $meta['promptTokenCount'] : null, isset( $meta['candidatesTokenCount'] ) ? (int) $meta['candidatesTokenCount'] : null )
					);
				}
			}
		}
		throw new ProviderException(
			/* translators: %s: provider name. */
			sprintf( __( '%s returned no image. The model may only produce text; choose an image model.', 'ai-seo-content-studio' ), $this->label() ),
			ProviderException::INVALID_RESPONSE
		);
	}

	/**
	 * Normalise a generateContent payload (public for tests).
	 *
	 * @param array<string, mixed> $json    Response.
	 * @param TextRequest          $request Original request (with resolved model).
	 * @throws ProviderException On refusal, truncation, empty or invalid output.
	 */
	public function parseResponse( array $json, TextRequest $request ): TextResponse {
		$block_reason = (string) ( $json['promptFeedback']['blockReason'] ?? '' );
		if ( '' !== $block_reason ) {
			throw $this->refusal( $block_reason );
		}

		$candidate = $json['candidates'][0] ?? null;
		if ( ! is_array( $candidate ) ) {
			throw $this->emptyOutput();
		}

		$reason = (string) ( $candidate['finishReason'] ?? '' );
		if ( in_array( $reason, self::BLOCKED, true ) ) {
			throw $this->refusal( $reason );
		}

		$text = '';
		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			// Skip thought summaries; keep only answer text.
			if ( is_array( $part ) && is_string( $part['text'] ?? null ) && empty( $part['thought'] ) ) {
				$text .= $part['text'];
			}
		}

		$finish = match ( $reason ) {
			'STOP' => TextResponse::FINISH_STOP,
			'MAX_TOKENS' => TextResponse::FINISH_LENGTH,
			default => TextResponse::FINISH_OTHER,
		};

		if ( null !== $request->jsonSchema && TextResponse::FINISH_LENGTH === $finish ) {
			throw $this->truncated();
		}
		if ( '' === trim( $text ) ) {
			$error = TextResponse::FINISH_LENGTH === $finish ? $this->truncated() : $this->emptyOutput();
			throw $error;
		}

		$meta   = is_array( $json['usageMetadata'] ?? null ) ? $json['usageMetadata'] : array();
		$output = null;
		if ( isset( $meta['candidatesTokenCount'] ) || isset( $meta['thoughtsTokenCount'] ) ) {
			// Thinking tokens are billed as output.
			$output = (int) ( $meta['candidatesTokenCount'] ?? 0 ) + (int) ( $meta['thoughtsTokenCount'] ?? 0 );
		}

		return new TextResponse(
			$this->id(),
			(string) ( $json['modelVersion'] ?? $request->model ?? '' ),
			$text,
			null !== $request->jsonSchema ? $this->decodeStructured( $text, $request ) : null,
			$finish,
			new Usage( isset( $meta['promptTokenCount'] ) ? (int) $meta['promptTokenCount'] : null, $output, (int) ( $meta['cachedContentTokenCount'] ?? 0 ), 0, true )
		);
	}

	/**
	 * Remove the "models/" resource prefix.
	 *
	 * @param string $name Model resource name.
	 */
	public static function stripPrefix( string $name ): string {
		return str_starts_with( $name, 'models/' ) ? substr( $name, 7 ) : $name;
	}

	/**
	 * Auth header.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array( 'x-goog-api-key' => $this->apiKey() );
	}
}
