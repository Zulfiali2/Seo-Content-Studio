<?php
/**
 * Token usage reported by a provider.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Usage numbers. Null means the provider did not report the value.
 */
final readonly class Usage {

	/**
	 * Constructor.
	 *
	 * @param int|null $inputTokens      Input tokens (see $cacheIncluded).
	 * @param int|null $outputTokens     Output tokens (including thinking).
	 * @param int      $cacheReadTokens  Input tokens served from the provider's prompt cache.
	 * @param int      $cacheWriteTokens Input tokens written to the cache (Anthropic bills a premium).
	 * @param bool     $cacheIncluded    Whether $inputTokens already contains the cached tokens
	 *                                   (OpenAI, Gemini) or not (Anthropic).
	 */
	public function __construct(
		public ?int $inputTokens = null,
		public ?int $outputTokens = null,
		public int $cacheReadTokens = 0,
		public int $cacheWriteTokens = 0,
		public bool $cacheIncluded = true,
	) {}

	/**
	 * All input tokens processed (uncached + cached), for usage logs.
	 */
	public function totalInput(): ?int {
		if ( null === $this->inputTokens ) {
			return null;
		}
		return $this->cacheIncluded ? $this->inputTokens : $this->inputTokens + $this->cacheReadTokens + $this->cacheWriteTokens;
	}

	/** Whether the provider reported any numbers. */
	public function isReported(): bool {
		return null !== $this->inputTokens || null !== $this->outputTokens;
	}
}
