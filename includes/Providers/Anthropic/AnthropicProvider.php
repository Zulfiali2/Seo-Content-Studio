<?php
/**
 * Anthropic adapter (Messages API).
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Anthropic;

use AiSeoContentStudio\Providers\AbstractProvider;
use AiSeoContentStudio\Providers\Contracts\ImageInput;
use AiSeoContentStudio\Providers\Contracts\Capability;
use AiSeoContentStudio\Providers\Contracts\ModelInfo;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Providers\Contracts\TextResponse;
use AiSeoContentStudio\Providers\Contracts\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Uses POST /v1/messages and GET /v1/models. Anthropic does not offer image generation.
 */
class AnthropicProvider extends AbstractProvider {

	public const BASE_URL    = 'https://api.anthropic.com/v1';
	public const API_VERSION = '2023-06-01';

	/** Maximum pages fetched when listing models. */
	private const MAX_MODEL_PAGES = 5;

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'anthropic';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Anthropic Claude';
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities(): array {
		return array( Capability::TEXT, Capability::STRUCTURED_OUTPUT, Capability::VISION, Capability::MODEL_LISTING, Capability::USAGE_REPORTING );
	}

	/**
	 * {@inheritDoc}
	 */
	public function listModels(): array {
		$models = array();
		$after  = null;
		for ( $page = 0; $page < self::MAX_MODEL_PAGES; $page++ ) {
			$query = array( 'limit' => 100 );
			if ( null !== $after ) {
				$query['after_id'] = $after;
			}
			$json = $this->http->requestJson( 'GET', add_query_arg( $query, self::BASE_URL . '/models' ), $this->headers(), null, $this->httpOptions() );
			foreach ( (array) ( $json['data'] ?? array() ) as $row ) {
				if ( is_array( $row ) && is_string( $row['id'] ?? null ) ) {
					$models[] = new ModelInfo( $row['id'], is_string( $row['display_name'] ?? null ) ? $row['display_name'] : '' );
				}
			}
			if ( empty( $json['has_more'] ) || ! is_string( $json['last_id'] ?? null ) ) {
				break;
			}
			$after = $json['last_id'];
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
		$model    = $this->resolveModel( $request );
		$first    = $request->firstUserIndex();
		$messages = array();
		foreach ( $request->messages as $i => $m ) {
			// Images come before the text that refers to them (Anthropic's recommended order).
			$images = array_map(
				static fn ( ImageInput $image ): array => array(
					'type'   => 'image',
					'source' => array(
						'type'       => 'base64',
						'media_type' => $image->mime,
						'data'       => $image->base64,
					),
				),
				$m->images
			);
			if ( array() !== $images && ! ( $i === $first && '' !== $request->sharedContext ) ) {
				$messages[] = array(
					'role'    => $m->role,
					'content' => array_merge(
						$images,
						array(
							array(
								'type' => 'text',
								'text' => $m->content,
							),
						)
					),
				);
				continue;
			}
			if ( $i === $first && '' !== $request->sharedContext ) {
				// Shared context first, marked for prompt caching; the varying task follows.
				$messages[] = array(
					'role'    => $m->role,
					'content' => array_merge(
						array(
							array(
								'type'          => 'text',
								'text'          => $request->sharedContext,
								'cache_control' => array( 'type' => 'ephemeral' ),
							),
						),
						$images,
						array(
							array(
								'type' => 'text',
								'text' => $m->content,
							),
						)
					),
				);
				continue;
			}
			$messages[] = array(
				'role'    => $m->role,
				'content' => $m->content,
			);
		}
		$body = array(
			'model'      => $model,
			'max_tokens' => $request->maxOutputTokens + $this->reasoningHeadroom( strtolower( $model ) ),
			'messages'   => $messages,
		);
		if ( '' !== $request->system ) {
			// The writing rules are identical for every request of a kind, so they are cached too.
			$body['system'] = array(
				array(
					'type'          => 'text',
					'text'          => $request->system,
					'cache_control' => array( 'type' => 'ephemeral' ),
				),
			);
		}
		$output = array();
		if ( null !== $request->jsonSchema ) {
			$output['format'] = array(
				'type'   => 'json_schema',
				'schema' => $request->jsonSchema,
			);
		}
		if ( '' !== $request->effort && self::supportsEffort( $model ) ) {
			$output['effort'] = $request->effort;
		}
		if ( array() !== $output ) {
			$body['output_config'] = $output;
		}
		return $body;
	}

	/**
	 * Claude 5-generation models think by default, and thinking counts towards max_tokens,
	 * so they get the same extra room as other reasoning models.
	 *
	 * @param string $model Lower-case model id.
	 */
	public function reasoningHeadroom( string $model ): int {
		return preg_match( '/^claude-(opus|sonnet|haiku|fable|mythos)-([5-9])/', $model ) ? 16000 : 0;
	}

	/**
	 * Models that accept output_config.effort (Opus 4.5 and the 4.6+ / 5-generation models;
	 * Sonnet 4.5, Haiku 4.5 and older reject it).
	 *
	 * @param string $model Model id.
	 */
	public static function supportsEffort( string $model ): bool {
		$model = strtolower( $model );
		return (bool) preg_match( '/^claude-(opus|sonnet|haiku|fable|mythos)-([5-9]|4-[6-9])/', $model ) || str_starts_with( $model, 'claude-opus-4-5' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param TextRequest $request Request.
	 * @throws ProviderException On failure.
	 */
	public function generate( TextRequest $request ): TextResponse {
		$json = $this->http->requestJson( 'POST', self::BASE_URL . '/messages', $this->headers(), $this->buildBody( $request ), $this->httpOptions() );
		return $this->parseResponse( $json, $request );
	}

	/**
	 * Normalise a Messages API payload (public for tests).
	 *
	 * @param array<string, mixed> $json    Response.
	 * @param TextRequest          $request Original request.
	 * @throws ProviderException On refusal, truncation, empty or invalid output.
	 */
	public function parseResponse( array $json, TextRequest $request ): TextResponse {
		$stop = (string) ( $json['stop_reason'] ?? '' );
		if ( 'refusal' === $stop ) {
			throw $this->refusal( (string) ( $json['stop_details']['explanation'] ?? '' ) );
		}

		$text = '';
		foreach ( (array) ( $json['content'] ?? array() ) as $block ) {
			// Thinking and other non-text blocks are intentionally ignored.
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) && is_string( $block['text'] ?? null ) ) {
				$text .= $block['text'];
			}
		}

		$finish = match ( $stop ) {
			'end_turn', 'stop_sequence' => TextResponse::FINISH_STOP,
			'max_tokens' => TextResponse::FINISH_LENGTH,
			default => TextResponse::FINISH_OTHER,
		};

		if ( null !== $request->jsonSchema && TextResponse::FINISH_LENGTH === $finish ) {
			throw $this->truncated();
		}
		if ( '' === trim( $text ) ) {
			$error = TextResponse::FINISH_LENGTH === $finish ? $this->truncated() : $this->emptyOutput();
			throw $error;
		}

		return new TextResponse(
			$this->id(),
			(string) ( $json['model'] ?? $request->model ?? '' ),
			$text,
			null !== $request->jsonSchema ? $this->decodeStructured( $text, $request ) : null,
			$finish,
			new Usage(
				isset( $json['usage']['input_tokens'] ) ? (int) $json['usage']['input_tokens'] : null,
				isset( $json['usage']['output_tokens'] ) ? (int) $json['usage']['output_tokens'] : null,
				(int) ( $json['usage']['cache_read_input_tokens'] ?? 0 ),
				(int) ( $json['usage']['cache_creation_input_tokens'] ?? 0 ),
				false
			)
		);
	}

	/**
	 * Auth and version headers.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return array(
			'x-api-key'         => $this->apiKey(),
			'anthropic-version' => self::API_VERSION,
		);
	}
}
