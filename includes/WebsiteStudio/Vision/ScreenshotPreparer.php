<?php
/**
 * Validates an uploaded screenshot and prepares it for a vision model.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Vision;

use AiSeoContentStudio\Media\ImageValidator;
use AiSeoContentStudio\Providers\Contracts\ImageInput;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts PNG, JPEG and WebP (by signature and decoded header, never by file name), up to
 * 10 MB, 64–8192 px per side and the plugin's pixel limit. For the model, the image is scaled
 * to at most 1280 px wide and cut into top-to-bottom tiles of at most 1400 px (up to 8 tiles),
 * so text on a tall full-page screenshot stays readable. Tiles are re-encoded as JPEG, which
 * also drops any metadata (EXIF, GPS) the upload carried.
 */
final class ScreenshotPreparer {

	public const MAX_UPLOAD_BYTES = 10485760;
	public const MAX_WIDTH        = 1280;
	public const TILE_HEIGHT      = 1400;
	public const MAX_TILES        = 8;

	/**
	 * Validate an upload.
	 *
	 * @param string $bytes File contents.
	 * @return array{mime: string, width: int, height: int, bytes: int}
	 * @throws \InvalidArgumentException With a user-facing reason.
	 */
	public static function validate( string $bytes ): array {
		$size = strlen( $bytes );
		if ( $size < 32 ) {
			throw new \InvalidArgumentException( __( 'The file is empty.', 'ai-seo-content-studio' ) );
		}
		if ( $size > self::MAX_UPLOAD_BYTES ) {
			throw new \InvalidArgumentException( __( 'The screenshot is larger than 10 MB.', 'ai-seo-content-studio' ) );
		}
		$mime = ImageValidator::sniff( $bytes );
		if ( null === $mime ) {
			throw new \InvalidArgumentException( __( 'Upload a PNG, JPEG or WebP image.', 'ai-seo-content-studio' ) );
		}
		$info = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid data is reported below.
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) || ( $info['mime'] ?? '' ) !== $mime ) {
			throw new \InvalidArgumentException( __( 'The image could not be read, or its contents do not match its type.', 'ai-seo-content-studio' ) );
		}
		[ $w, $h ] = array( (int) $info[0], (int) $info[1] );
		if ( $w < ImageValidator::MIN_SIDE || $h < ImageValidator::MIN_SIDE || $w > ImageValidator::MAX_SIDE || $h > ImageValidator::MAX_SIDE ) {
			throw new \InvalidArgumentException( __( 'Use an image between 64 and 8192 pixels on each side.', 'ai-seo-content-studio' ) );
		}
		$max_pixels = function_exists( 'apply_filters' ) ? (int) apply_filters( 'aiscs_max_image_pixels', ImageValidator::MAX_PIXELS ) : ImageValidator::MAX_PIXELS;
		if ( $w * $h > max( 1000000, $max_pixels ) ) {
			throw new \InvalidArgumentException( __( 'The image has too many pixels to process safely on this server.', 'ai-seo-content-studio' ) );
		}
		return array(
			'mime'   => $mime,
			'width'  => $w,
			'height' => $h,
			'bytes'  => $size,
		);
	}

	/**
	 * Tiles for the model.
	 *
	 * @param string $bytes Validated image.
	 * @return array{tiles: list<ImageInput>, width: int, height: int, truncated: bool}
	 * @throws \InvalidArgumentException When the image cannot be processed.
	 */
	public static function tiles( string $bytes ): array {
		$info = self::validate( $bytes );
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagejpeg' ) ) {
			// Without GD the original is sent as it is, when it is small enough for every provider.
			if ( $info['bytes'] > ImageInput::MAX_BYTES ) {
				throw new \InvalidArgumentException( __( 'The PHP GD extension is needed to prepare large screenshots. Ask your host to enable it, or upload a smaller image.', 'ai-seo-content-studio' ) );
			}
			return array(
				'tiles'     => array( ImageInput::fromBytes( $bytes ) ),
				'width'     => $info['width'],
				'height'    => $info['height'],
				'truncated' => false,
			);
		}
		$src = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure handled below.
		if ( false === $src ) {
			throw new \InvalidArgumentException( __( 'The image could not be decoded.', 'ai-seo-content-studio' ) );
		}
		$scale  = min( 1.0, self::MAX_WIDTH / $info['width'] );
		$width  = max( 1, (int) round( $info['width'] * $scale ) );
		$height = max( 1, (int) round( $info['height'] * $scale ) );
		$count  = (int) ceil( $height / self::TILE_HEIGHT );
		$tiles  = array();
		for ( $i = 0; $i < min( $count, self::MAX_TILES ); $i++ ) {
			$top    = $i * self::TILE_HEIGHT;
			$tile_h = min( self::TILE_HEIGHT, $height - $top );
			$tile   = imagecreatetruecolor( $width, $tile_h );
			if ( false === $tile ) {
				imagedestroy( $src );
				throw new \InvalidArgumentException( __( 'The image could not be processed.', 'ai-seo-content-studio' ) );
			}
			// White behind transparent areas (JPEG has no alpha).
			imagefill( $tile, 0, 0, (int) imagecolorallocate( $tile, 255, 255, 255 ) );
			imagecopyresampled( $tile, $src, 0, 0, 0, (int) round( $top / $scale ), $width, $tile_h, $info['width'], (int) round( $tile_h / $scale ) );
			ob_start();
			imagejpeg( $tile, null, 85 );
			$jpeg = (string) ob_get_clean();
			imagedestroy( $tile );
			$tiles[] = ImageInput::fromBytes( $jpeg );
		}
		imagedestroy( $src );
		return array(
			'tiles'     => $tiles,
			'width'     => $width,
			'height'    => $height,
			'truncated' => $count > self::MAX_TILES,
		);
	}
}
