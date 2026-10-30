<?php
/**
 * Image generation capability.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by providers that declare Capability::IMAGE.
 */
interface ImageGenerationInterface {

	/**
	 * Generate one image.
	 *
	 * @param ImageRequest $request Request.
	 * @throws ProviderException On any failure, including refusals.
	 */
	public function generateImage( ImageRequest $request ): ImageResponse;

	/**
	 * Image model from settings ('' when none chosen).
	 */
	public function defaultImageModel(): string;
}
