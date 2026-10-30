<?php
/**
 * An image sent to a vision-capable model.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Contracts;

use AiSeoContentStudio\Media\ImageValidator;

defined( 'ABSPATH' ) || exit;

/**
 * PNG, JPEG or WebP bytes (checked by their signature, not by a file name), base64-encoded once.
 * Capped at 5 MB, the smallest per-image limit of the supported providers (Anthropic).
 */
final readonly class ImageInput {

	public const MAX_BYTES = 5242880;

	/**
	 * Constructor (use fromBytes()).
	 *
	 * @param string $mime   image/png | image/jpeg | image/webp.
	 * @param string $base64 Base64 data.
	 * @param string $sha1   Hash of the bytes (duplicate detection).
	 */
	private function __construct( public string $mime, public string $base64, public string $sha1 ) {}

	/**
	 * From raw bytes.
	 *
	 * @param string $bytes Image bytes.
	 * @throws \InvalidArgumentException When the data is not an accepted image.
	 */
	public static function fromBytes( string $bytes ): self {
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( 'The image is larger than 5 MB.' );
		}
		$mime = ImageValidator::sniff( $bytes );
		if ( null === $mime ) {
			throw new \InvalidArgumentException( 'The image is not a PNG, JPEG or WebP file.' );
		}
		return new self( $mime, base64_encode( $bytes ), sha1( $bytes ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- image data for the provider API.
	}

	/**
	 * Data URL (OpenAI-style APIs).
	 */
	public function dataUrl(): string {
		return 'data:' . $this->mime . ';base64,' . $this->base64;
	}
}
