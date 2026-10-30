<?php
/**
 * Validate image bytes returned by a provider.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Media;

use AiSeoContentStudio\Providers\Contracts\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * Decides the real file type from the bytes (never from what the provider claims) and
 * enforces size and dimension limits before anything touches the file system.
 */
final class ImageValidator {

	public const MAX_BYTES = 20971520;
	public const MAX_SIDE  = 8192;
	public const MIN_SIDE  = 64;

	/**
	 * Largest total pixel count accepted (width × height). Decoding needs about 4 bytes per
	 * pixel, so 25 million pixels is roughly 100 MB of memory while WordPress makes the sizes.
	 * Sites with more or less memory can change it with the `aiscs_max_image_pixels` filter.
	 */
	public const MAX_PIXELS = 25000000;

	/** Allowed types: MIME => extension. */
	public const TYPES = array(
		'image/png'  => 'png',
		'image/jpeg' => 'jpg',
		'image/webp' => 'webp',
	);

	/**
	 * Validate.
	 *
	 * @param string $bytes Binary data.
	 * @return array{mime: string, ext: string, width: int, height: int, bytes: int}
	 * @throws ProviderException When the data is not an acceptable image.
	 */
	public static function validate( string $bytes ): array {
		$size = strlen( $bytes );
		if ( $size < 32 ) {
			throw new ProviderException( __( 'The provider returned an empty or truncated image.', 'ai-seo-content-studio' ), ProviderException::INVALID_RESPONSE );
		}
		if ( $size > self::MAX_BYTES ) {
			throw new ProviderException( __( 'The generated image is larger than 20 MB and was not saved.', 'ai-seo-content-studio' ), ProviderException::INVALID_RESPONSE );
		}

		$mime = self::sniff( $bytes );
		if ( null === $mime ) {
			throw new ProviderException( __( 'The provider returned data that is not a PNG, JPEG or WebP image.', 'ai-seo-content-studio' ), ProviderException::INVALID_RESPONSE );
		}

		$info = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid data is reported below.
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			throw new ProviderException( __( 'The generated image could not be read.', 'ai-seo-content-studio' ), ProviderException::INVALID_RESPONSE );
		}
		if ( ( $info['mime'] ?? $mime ) !== $mime ) {
			throw new ProviderException( __( 'The image type does not match its contents.', 'ai-seo-content-studio' ), ProviderException::INVALID_RESPONSE );
		}
		[ $width, $height ] = array( (int) $info[0], (int) $info[1] );
		if ( $width > self::MAX_SIDE || $height > self::MAX_SIDE || $width < self::MIN_SIDE || $height < self::MIN_SIDE ) {
			throw new ProviderException( __( 'The generated image has unsupported dimensions.', 'ai-seo-content-studio' ), ProviderException::INVALID_RESPONSE );
		}
		// Checked from the header only, before WordPress decodes the image to make its sizes.
		$max_pixels = function_exists( 'apply_filters' ) ? (int) apply_filters( 'aiscs_max_image_pixels', self::MAX_PIXELS ) : self::MAX_PIXELS;
		if ( $width * $height > max( 1000000, $max_pixels ) ) {
			throw new ProviderException( __( 'The image has too many pixels to process safely on this server. Choose a smaller image.', 'ai-seo-content-studio' ), ProviderException::INVALID_RESPONSE );
		}

		return array(
			'mime'   => $mime,
			'ext'    => self::TYPES[ $mime ],
			'width'  => $width,
			'height' => $height,
			'bytes'  => $size,
		);
	}

	/**
	 * Identify PNG, JPEG or WebP by signature.
	 *
	 * @param string $bytes Data.
	 */
	public static function sniff( string $bytes ): ?string {
		if ( str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) ) {
			return 'image/png';
		}
		if ( str_starts_with( $bytes, "\xFF\xD8\xFF" ) ) {
			return 'image/jpeg';
		}
		if ( 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'image/webp';
		}
		return null;
	}
}
