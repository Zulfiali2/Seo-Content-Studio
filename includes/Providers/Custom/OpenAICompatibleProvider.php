<?php
/**
 * Custom OpenAI-compatible endpoint adapter (Chat Completions shape).
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Custom;

use AiSeoContentStudio\Providers\AbstractProvider;
use AiSeoContentStudio\Providers\Contracts\Capability;
use AiSeoContentStudio\Providers\Contracts\ConnectionResult;
use AiSeoContentStudio\Providers\Contracts\Message;
use AiSeoContentStudio\Providers\Contracts\ModelInfo;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Providers\Contracts\TextResponse;
use AiSeoContentStudio\Providers\Contracts\Usage;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to any server implementing POST {base}/chat/completions.
 *
 * Compatible servers vary, so: the API key is optional (local servers), native
 * JSON-schema output is opt-in, and model listing failures are a warning rather
 * than an error. The base URL is user-supplied and always SSRF-validated.
 */
class OpenAICompatibleProvider extends AbstractProvider {

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'custom';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		$label = (string) $this->setting( 'label', '' );
		return '' !== $label ? $label : __( 'Custom (OpenAI-compatible)', 'ai-seo-content-studio' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function capabilities(): array {
		$caps = array( Capability::TEXT, Capability::MODEL_LISTING, Capability::USAGE_REPORTING );
		if ( $this->setting( 'supports_json_schema', false ) ) {
			$caps[] = Capability::STRUCTURED_OUTPUT;
		}
		return $caps;
	}

	/**
	 * {@inheritDoc}
	 */
	public function isConfigured(): bool {
		return '' !== $this->baseUrl();
	}

	/**
	 * {@inheritDoc}
	 */
	public function requiresApiKey(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function testConnection(): ConnectionResult {
		if ( ! $this->isConfigured() ) {
			return new ConnectionResult( ConnectionResult::ERROR, __( 'Enter the endpoint base URL first.', 'ai-seo-content-studio' ), array(), ProviderException::CONFIG );
		}
		try {
			$models = $this->listModels();
		} catch ( ProviderException $e ) {
			if ( ProviderException::NOT_FOUND === $e->category ) {
				return new ConnectionResult( ConnectionResult::WARNING, __( 'The server is reachable but does not list models. Enter the model name manually; it will be verified on first use.', 'ai-seo-content-studio' ), array(), $e->category );
			}
			return ConnectionResult::fromException( $e );
		}
		/* translators: %d: number of models. */
		return new ConnectionResult( ConnectionResult::OK, sprintf( _n( 'Connected. %d model listed.', 'Connected. %d models listed.', count( $models ), 'ai-seo-content-studio' ), count( $models ) ), $models );
	}

	/**
	 * {@inheritDoc}
	 */
	public function listModels(): array {
		$json   = $this->http->requestJson( 'GET', $this->baseUrl() . '/models', $this->headers(), null, $this->httpOptions() );
		$models = array();
		foreach ( (array) ( $json['data'] ?? array() ) as $row ) {
			if ( is_array( $row ) && is_string( $row['id'] ?? null ) ) {
				$models[] = new ModelInfo( $row['id'] );
			}
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
		$system = $request->system;
		$native = null !== $request->jsonSchema && $this->supports( Capability::STRUCTURED_OUTPUT );

		if ( null !== $request->jsonSchema && ! $native ) {
			$system .= "\n\nRespond with a single JSON object only, with no surrounding text, that validates against this JSON Schema:\n" . wp_json_encode( $request->jsonSchema );
		}

		$messages = array();
		if ( '' !== trim( $system ) ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => trim( $system ),
			);
		}
		foreach ( $request->messages as $i => $m ) {
			$messages[] = array(
				'role'    => $m->role,
				'content' => $request->renderedContent( (int) $i ),
			);
		}

		$body = array(
			'model'      => $this->resolveModel( $request ),
			'messages'   => $messages,
			'max_tokens' => $request->maxOutputTokens,
		);
		if ( $native ) {
			$body['response_format'] = array(
				'type'        => 'json_schema',
				'json_schema' => array(
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
		if ( ! $this->isConfigured() ) {
			throw new ProviderException( __( 'Enter the custom endpoint base URL under AI Providers.', 'ai-seo-content-studio' ), ProviderException::CONFIG );
		}
		$json = $this->http->requestJson( 'POST', $this->baseUrl() . '/chat/completions', $this->headers(), $this->buildBody( $request ), $this->httpOptions() );
		return $this->parseResponse( $json, $request );
	}

	/**
	 * Normalise a Chat Completions payload (public for tests).
	 *
	 * @param array<string, mixed> $json    Response.
	 * @param TextRequest          $request Original request.
	 * @throws ProviderException On refusal, truncation, empty or invalid output.
	 */
	public function parseResponse( array $json, TextRequest $request ): TextResponse {
		$choice = $json['choices'][0] ?? null;
		if ( ! is_array( $choice ) ) {
			throw $this->emptyOutput();
		}
		$message = is_array( $choice['message'] ?? null ) ? $choice['message'] : array();
		$text    = is_string( $message['content'] ?? null ) ? $message['content'] : '';
		$reason  = (string) ( $choice['finish_reason'] ?? '' );

		if ( ( is_string( $message['refusal'] ?? null ) && '' !== $message['refusal'] && '' === $text ) || 'content_filter' === $reason ) {
			throw $this->refusal( is_string( $message['refusal'] ?? null ) ? $message['refusal'] : '' );
		}

		$finish = match ( $reason ) {
			'stop' => TextResponse::FINISH_STOP,
			'length' => TextResponse::FINISH_LENGTH,
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
				isset( $json['usage']['prompt_tokens'] ) ? (int) $json['usage']['prompt_tokens'] : null,
				isset( $json['usage']['completion_tokens'] ) ? (int) $json['usage']['completion_tokens'] : null
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function httpOptions(): array {
		return array(
			'provider'      => $this->label(),
			'user_url'      => true,
			'allow_private' => (bool) $this->settings->get( 'ai.allow_private_endpoints', false ),
		);
	}

	/**
	 * Configured base URL without trailing slash.
	 */
	private function baseUrl(): string {
		return rtrim( (string) $this->setting( 'base_url', '' ), '/' );
	}

	/**
	 * Optional bearer auth.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		$key = $this->credentials->get( $this->id(), 'api_key' );
		return null === $key ? array() : array( 'Authorization' => 'Bearer ' . $key );
	}
}
