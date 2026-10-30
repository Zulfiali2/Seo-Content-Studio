<?php
/**
 * Normalised generation result.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-neutral response.
 */
final readonly class TextResponse {

	public const FINISH_STOP    = 'stop';
	public const FINISH_LENGTH  = 'length';
	public const FINISH_REFUSAL = 'refusal';
	public const FINISH_OTHER   = 'other';

	/**
	 * Constructor.
	 *
	 * @param string                    $provider     Provider id.
	 * @param string                    $model        Model that produced the output (as reported).
	 * @param string                    $text         Text output (raw JSON text for structured requests).
	 * @param array<string, mixed>|null $data         Decoded structured output.
	 * @param string                    $finishReason One of the FINISH_* constants.
	 * @param Usage                     $usage        Token usage.
	 */
	public function __construct(
		public string $provider,
		public string $model,
		public string $text,
		public ?array $data,
		public string $finishReason,
		public Usage $usage,
	) {}
}
