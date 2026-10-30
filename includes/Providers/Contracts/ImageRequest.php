<?php
/**
 * Image generation request.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-neutral image request. Adapters translate the aspect ratio into their own
 * size or aspect parameters.
 */
final readonly class ImageRequest {

	/** Supported aspect ratios (every adapter maps these). */
	public const ASPECTS = array( '1:1', '3:2', '2:3' );

	/**
	 * Constructor.
	 *
	 * @param string      $prompt  Prompt.
	 * @param string      $aspect  One of ASPECTS.
	 * @param string|null $model   Model override.
	 * @param string      $purpose Usage-log purpose.
	 * @throws \InvalidArgumentException On an empty prompt.
	 */
	public function __construct(
		public string $prompt,
		public string $aspect = '1:1',
		public ?string $model = null,
		public string $purpose = 'image',
	) {
		if ( '' === trim( $prompt ) ) {
			throw new \InvalidArgumentException( 'An image prompt is required.' );
		}
	}

	/**
	 * Copy with a model.
	 *
	 * @param string $model Model.
	 */
	public function withModel( string $model ): self {
		return new self( $this->prompt, $this->aspect, $model, $this->purpose );
	}

	/**
	 * Duplicate-request hash.
	 */
	public function hash(): string {
		return hash( 'sha256', (string) wp_json_encode( array( 'image', $this->prompt, $this->aspect, $this->model ) ) );
	}
}
