<?php
/**
 * Grok (xAI) adapter.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\XAI;

use AiSeoContentStudio\Providers\Contracts\ConnectionResult;
use AiSeoContentStudio\Providers\Contracts\ImageRequest;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\OpenAI\OpenAIProvider;

defined( 'ABSPATH' ) || exit;

/**
 * The xAI API exposes a Responses API with the same request and response shape as
 * OpenAI's (POST https://api.x.ai/v1/responses: instructions, input,
 * max_output_tokens, text.format json_schema with strict, store), authenticated
 * with a Bearer API key. Verified against the request/response schemas of the
 *
 * @ai-sdk/xai 5.0.20 provider package; re-check against docs.x.ai when possible.
 */
class GrokProvider extends OpenAIProvider {

	public const XAI_BASE_URL = 'https://api.x.ai/v1';

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'xai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'xAI Grok';
	}

	/**
	 * Model listing uses GET /v1/models. If it is unavailable for a key, the
	 * test reports a warning and the model can be typed manually.
	 */
	public function testConnection(): ConnectionResult {
		$result = parent::testConnection();
		if ( ProviderException::NOT_FOUND === $result->errorCode ) {
			return new ConnectionResult( ConnectionResult::WARNING, __( 'The key was accepted but model listing is not available. Enter the Grok model name manually; it will be verified on first use.', 'ai-seo-content-studio' ), array(), $result->errorCode );
		}
		return $result;
	}

	/**
	 * Reasoning effort per @ai-sdk/xai 5.0.21 (sent as `reasoning.effort` on the Responses API).
	 *
	 * @param string $model Model id (lower case).
	 */
	public function supportsReasoningEffort( string $model ): bool {
		// The fixed Grok 4.20 reasoning / non-reasoning variants take no effort setting.
		return parent::supportsReasoningEffort( $model ) && ! preg_match( '/^grok-4\.20(-\d{4})?-(non-)?reasoning$/', $model );
	}

	/**
	 * Grok reasoning models (Grok 4 and later, Grok 3 mini) think before answering.
	 *
	 * @param string $model Model id (lower case).
	 */
	public function reasoningHeadroom( string $model ): int {
		return preg_match( '/grok-(3-mini|[4-9])/', $model ) ? 16000 : 0;
	}

	/**
	 * Image request body for xAI (POST /v1/images/generations, verified against @ai-sdk/xai 5.0.21):
	 * an aspect ratio instead of a pixel size, base64 output requested explicitly.
	 *
	 * @param ImageRequest $request Request.
	 * @return array<string, mixed>
	 */
	public function buildImageBody( ImageRequest $request ): array {
		return array(
			'model'           => $this->resolveImageModel( $request ),
			'prompt'          => $request->prompt,
			'n'               => 1,
			'response_format' => 'b64_json',
			'aspect_ratio'    => in_array( $request->aspect, ImageRequest::ASPECTS, true ) ? $request->aspect : '1:1',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function baseUrl(): string {
		return self::XAI_BASE_URL;
	}
}
