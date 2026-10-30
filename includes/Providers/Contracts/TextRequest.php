<?php
/**
 * Provider-neutral text generation request.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable generation request.
 */
final readonly class TextRequest {

	/**
	 * Constructor.
	 *
	 * @param Message[]                 $messages        Conversation (at least one user message).
	 * @param string                    $system          System instructions.
	 * @param int                       $maxOutputTokens Output cap.
	 * @param string|null               $model           Model id; null = provider default from settings.
	 * @param array<string, mixed>|null $jsonSchema      JSON Schema for structured output.
	 * @param string                    $schemaName      Name for the schema (a-z, 0-9, _ and -).
	 * @param string                    $purpose         Short label for usage logs, e.g. "connection_test".
	 * @param string                    $sharedContext   Stable context shared by a series of requests (e.g. every part of
	 *                                                   one article). Providers place it first in the user turn so it
	 *                                                   can be cached; the varying task comes after it.
	 * @param string                    $effort          Thinking effort: low | medium | high | '' (provider default).
	 * @throws \InvalidArgumentException When no messages are given.
	 */
	public function __construct(
		public array $messages,
		public string $system = '',
		public int $maxOutputTokens = 4000,
		public ?string $model = null,
		public ?array $jsonSchema = null,
		public string $schemaName = 'response',
		public string $purpose = 'generation',
		public string $sharedContext = '',
		public string $effort = '',
	) {
		if ( array() === $messages ) {
			throw new \InvalidArgumentException( 'At least one message is required.' );
		}
	}

	/**
	 * Copy with a resolved model id.
	 *
	 * @param string $model Model.
	 */
	public function withModel( string $model ): self {
		return new self( $this->messages, $this->system, $this->maxOutputTokens, $model, $this->jsonSchema, $this->schemaName, $this->purpose, $this->sharedContext, $this->effort );
	}

	/**
	 * Copy with a capped output limit.
	 *
	 * @param int $max Maximum tokens.
	 */
	public function withMaxOutputTokens( int $max ): self {
		return new self( $this->messages, $this->system, $max, $this->model, $this->jsonSchema, $this->schemaName, $this->purpose, $this->sharedContext, $this->effort );
	}

	/**
	 * Copy with a thinking effort.
	 *
	 * @param string $effort low | medium | high | ''.
	 */
	public function withEffort( string $effort ): self {
		return new self( $this->messages, $this->system, $this->maxOutputTokens, $this->model, $this->jsonSchema, $this->schemaName, $this->purpose, $this->sharedContext, in_array( $effort, array( 'low', 'medium', 'high' ), true ) ? $effort : '' );
	}

	/**
	 * Content of a user message as sent to providers without native caching blocks:
	 * the shared context first (a stable, cacheable prefix), then the message.
	 *
	 * @param int $index Message index.
	 */
	public function renderedContent( int $index ): string {
		$content = $this->messages[ $index ]->content;
		if ( '' !== $this->sharedContext && $index === $this->firstUserIndex() ) {
			return $this->sharedContext . "\n\n" . $content;
		}
		return $content;
	}

	/**
	 * Index of the first user message.
	 */
	public function firstUserIndex(): int {
		foreach ( $this->messages as $i => $message ) {
			if ( 'user' === $message->role ) {
				return (int) $i;
			}
		}
		return 0;
	}

	/** Total characters of input (for request-size limits). */
	public function inputLength(): int {
		$length = strlen( $this->system ) + strlen( $this->sharedContext );
		foreach ( $this->messages as $message ) {
			$length += strlen( $message->content );
		}
		return $length + ( null === $this->jsonSchema ? 0 : strlen( (string) wp_json_encode( $this->jsonSchema ) ) );
	}

	/** Stable hash of the request for duplicate detection. */
	public function hash(): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					$this->system,
					$this->sharedContext,
					$this->effort,
					array_map( static fn ( Message $m ): array => array( $m->role, $m->content ), $this->messages ),
					$this->maxOutputTokens,
					$this->model,
					$this->jsonSchema,
				)
			)
		);
	}
}
