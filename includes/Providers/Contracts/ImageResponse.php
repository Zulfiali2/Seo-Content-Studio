<?php
/**
 * Image generation response.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Raw image bytes as returned by a provider (not yet validated).
 */
final readonly class ImageResponse {

	/**
	 * Constructor.
	 *
	 * @param string $provider       Provider id.
	 * @param string $model          Model.
	 * @param string $bytes          Binary image data.
	 * @param string $revised_prompt Prompt as rewritten by the provider, if reported.
	 * @param Usage  $usage          Token usage, if reported.
	 */
	public function __construct(
		public string $provider,
		public string $model,
		public string $bytes,
		public string $revised_prompt = '',
		public Usage $usage = new Usage(),
	) {}
}
