<?php
/**
 * Locally generated placeholder images.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Media;

use AiSeoContentStudio\Providers\Contracts\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * A soft diagonal gradient with a few translucent circles, drawn with PHP's GD extension on
 * this server: no external service, no cost, no copyright. Meant to be replaced before
 * publishing, so the Media Library title says "Placeholder".
 */
final class PlaceholderImage {

	/** Pixel sizes per aspect ratio. */
	private const SIZES = array(
		'3:2' => array( 1536, 1024 ),
		'2:3' => array( 1024, 1536 ),
		'1:1' => array( 1200, 1200 ),
	);

	/**
	 * Whether placeholders can be created on this server.
	 */
	public static function available(): bool {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagepng' );
	}

	/**
	 * PNG bytes.
	 *
	 * @param string $aspect 3:2 | 2:3 | 1:1.
	 * @param string $color  Base colour #rrggbb.
	 * @param string $seed   Varies the circle layout (e.g. the slot id).
	 * @throws ProviderException When GD is missing or drawing fails.
	 */
	public static function png( string $aspect, string $color = '#4f46e5', string $seed = '' ): string {
		if ( ! self::available() ) {
			throw new ProviderException( __( 'Placeholders need the PHP GD extension, which is not installed on this server. Ask your host to enable it.', 'ai-seo-content-studio' ), ProviderException::CONFIG );
		}
		[ $w, $h ] = self::SIZES[ $aspect ] ?? self::SIZES['3:2'];
		$base      = self::rgb( $color );
		$dark      = array( 15, 23, 42 );
		$image     = imagecreatetruecolor( $w, $h );
		if ( false === $image ) {
			throw new ProviderException( __( 'The placeholder image could not be created.', 'ai-seo-content-studio' ), ProviderException::SERVER );
		}

		// Diagonal gradient from dark navy to the accent colour, one line at a time.
		$steps = $w + $h;
		for ( $i = 0; $i < $steps; $i += 2 ) {
			$t   = $i / $steps;
			$mix = array_map( static fn ( int $a, int $b ): int => (int) round( $a + ( $b - $a ) * $t ), $dark, $base );
			$c   = imagecolorallocate( $image, $mix[0], $mix[1], $mix[2] );
			imageline( $image, $i, 0, 0, $i, $c );
			imageline( $image, $i + 1, 0, 0, $i + 1, $c );
		}

		// Translucent circles, positioned from the seed so each slot looks a little different.
		imagealphablending( $image, true );
		$hash = md5( $seed . $aspect );
		for ( $n = 0; $n < 5; $n++ ) {
			$x = (int) ( hexdec( substr( $hash, $n * 4, 2 ) ) / 255 * $w );
			$y = (int) ( hexdec( substr( $hash, $n * 4 + 2, 2 ) ) / 255 * $h );
			$r = (int) ( min( $w, $h ) * ( 0.18 + 0.12 * ( $n % 3 ) ) );
			$c = imagecolorallocatealpha( $image, 255, 255, 255, 112 );
			imagefilledellipse( $image, $x, $y, $r * 2, $r * 2, $c );
		}

		ob_start();
		$ok    = imagepng( $image, null, 6 );
		$bytes = (string) ob_get_clean();
		imagedestroy( $image );
		if ( ! $ok || '' === $bytes ) {
			throw new ProviderException( __( 'The placeholder image could not be created.', 'ai-seo-content-studio' ), ProviderException::SERVER );
		}
		return $bytes;
	}

	/**
	 * #rrggbb to [r, g, b] (indigo when invalid).
	 *
	 * @param string $hex Colour.
	 * @return array{0: int, 1: int, 2: int}
	 */
	private static function rgb( string $hex ): array {
		if ( ! preg_match( '/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m ) ) {
			return array( 79, 70, 229 );
		}
		return array( (int) hexdec( $m[1] ), (int) hexdec( $m[2] ), (int) hexdec( $m[3] ) );
	}
}
