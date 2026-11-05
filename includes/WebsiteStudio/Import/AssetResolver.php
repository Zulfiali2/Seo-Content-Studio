<?php
/**
 * Validates project assets and turns them into URLs.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Every asset is checked by content, not by name:
 * - raster images: the bytes must decode as the type their extension claims (PNG, JPEG, GIF,
 *   WebP, AVIF or ICO), within the pixel limit;
 * - SVG: parsed as XML and cleaned by SvgSanitizer; it is inlined as a data URI (never stored as
 *   a file in the site's uploads, so an SVG can never run in the site's origin);
 * - fonts: WOFF, WOFF2, TrueType or OpenType signatures only.
 * For previews, assets become data URIs (within a budget). For drafts, ImportService imports
 * images into the Media Library and stores fonts in the uploads folder.
 */
final class AssetResolver {

	/** Image MIME types accepted, by extension. */
	public const IMAGE_TYPES = array(
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
		'ico'  => 'image/vnd.microsoft.icon',
	);

	/** Font MIME types by extension. */
	public const FONT_TYPES = array(
		'woff'  => 'font/woff',
		'woff2' => 'font/woff2',
		'ttf'   => 'font/ttf',
		'otf'   => 'font/otf',
	);

	private int $budget;

	/** @var array<string, string> path => data URI ("" when refused) */
	private array $cache = array();

	/** @var array<string, string> path => reason */
	private array $problems = array();

	/**
	 * Constructor.
	 *
	 * @param ProjectArchive     $archive Archive.
	 * @param array<string, int> $limits  Limits.
	 */
	public function __construct( private readonly ProjectArchive $archive, private readonly array $limits ) {
		$this->budget = $limits['preview_bytes'];
	}

	/**
	 * Data URI for a project asset ("" when refused or over the preview budget).
	 *
	 * @param string $path Normalised path.
	 */
	public function dataUri( string $path ): string {
		if ( isset( $this->cache[ $path ] ) ) {
			return $this->cache[ $path ];
		}
		try {
			$asset = $this->load( $path );
		} catch ( ImportException $e ) {
			$this->problems[ $path ] = $e->getMessage();
			return $this->cache[ $path ] = '';
		}
		if ( strlen( $asset['bytes'] ) > $this->budget ) {
			$this->problems[ $path ] = __( 'Not shown in the preview (the preview size budget is used up).', 'ai-seo-content-studio' );
			return $this->cache[ $path ] = '';
		}
		$this->budget -= strlen( $asset['bytes'] );
		return $this->cache[ $path ] = 'data:' . $asset['mime'] . ';base64,' . base64_encode( $asset['bytes'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI.
	}

	/**
	 * Load and validate an asset.
	 *
	 * @param string $path Normalised path.
	 * @return array{bytes: string, mime: string, ext: string, kind: string, width: int, height: int}
	 * @throws ImportException When the file is missing or its content is not what it claims to be.
	 */
	public function load( string $path ): array {
		$file = $this->archive->files()[ $path ] ?? null;
		if ( null === $file ) {
			throw new ImportException( 'asset_missing', sprintf( __( '%s is not in the ZIP.', 'ai-seo-content-studio' ), $path ) );
		}
		$ext   = ProjectArchive::extension( $path );
		$bytes = $this->archive->read( $path );
		if ( 'svg' === $file['kind'] ) {
			if ( strlen( $bytes ) > $this->limits['svg_bytes'] ) {
				throw new ImportException( 'svg_too_large', sprintf( __( '%1$s is larger than %2$s, the limit for inlined SVG images.', 'ai-seo-content-studio' ), $path, size_format( $this->limits['svg_bytes'] ) ) );
			}
			$clean = ( new SvgSanitizer() )->file( ProjectParser::toUtf8( $bytes ) );
			if ( '' === $clean ) {
				throw new ImportException( 'svg_invalid', sprintf( __( '%s is not a valid SVG image.', 'ai-seo-content-studio' ), $path ) );
			}
			return array(
				'bytes'  => $clean,
				'mime'   => 'image/svg+xml',
				'ext'    => 'svg',
				'kind'   => 'svg',
				'width'  => 0,
				'height' => 0,
			);
		}
		if ( 'font' === $file['kind'] ) {
			if ( ! self::isFont( $bytes, $ext ) ) {
				throw new ImportException( 'font_invalid', sprintf( __( '%s is not a valid font file.', 'ai-seo-content-studio' ), $path ) );
			}
			return array(
				'bytes'  => $bytes,
				'mime'   => self::FONT_TYPES[ $ext ],
				'ext'    => $ext,
				'kind'   => 'font',
				'width'  => 0,
				'height' => 0,
			);
		}
		if ( 'image' === $file['kind'] ) {
			$info = self::imageInfo( $bytes, $ext );
			if ( null === $info ) {
				throw new ImportException( 'image_invalid', sprintf( __( '%s is not a valid image of its type.', 'ai-seo-content-studio' ), $path ) );
			}
			if ( $info['width'] * $info['height'] > $this->limits['image_pixels'] ) {
				throw new ImportException( 'image_too_large', sprintf( __( '%s has too many pixels to process safely.', 'ai-seo-content-studio' ), $path ) );
			}
			return array(
				'bytes'  => $bytes,
				'mime'   => $info['mime'],
				'ext'    => $ext,
				'kind'   => 'image',
				'width'  => $info['width'],
				'height' => $info['height'],
			);
		}
		throw new ImportException( 'asset_type', sprintf( __( '%s is not an image or font.', 'ai-seo-content-studio' ), $path ) );
	}

	/**
	 * Problems found while resolving (path => reason).
	 *
	 * @return array<string, string>
	 */
	public function problems(): array {
		return $this->problems;
	}

	/**
	 * Image type and size from its bytes, or null when they do not match the extension.
	 *
	 * @param string $bytes Bytes.
	 * @param string $ext   Extension.
	 * @return array{mime: string, width: int, height: int}|null
	 */
	public static function imageInfo( string $bytes, string $ext ): ?array {
		$expected = self::IMAGE_TYPES[ $ext ] ?? '';
		if ( '' === $expected || strlen( $bytes ) < 12 ) {
			return null;
		}
		$sniffed = match ( true ) {
			str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) => 'image/png',
			str_starts_with( $bytes, "\xFF\xD8\xFF" ) => 'image/jpeg',
			str_starts_with( $bytes, 'GIF87a' ) || str_starts_with( $bytes, 'GIF89a' ) => 'image/gif',
			'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) => 'image/webp',
			'ftyp' === substr( $bytes, 4, 4 ) && in_array( substr( $bytes, 8, 4 ), array( 'avif', 'avis' ), true ) => 'image/avif',
			"\x00\x00\x01\x00" === substr( $bytes, 0, 4 ) => 'image/vnd.microsoft.icon',
			default => '',
		};
		if ( $sniffed !== $expected ) {
			return null;
		}
		if ( 'image/vnd.microsoft.icon' === $sniffed ) {
			$w = ord( $bytes[6] ) ?: 256;
			$h = ord( $bytes[7] ) ?: 256;
			return array( 'mime' => $sniffed, 'width' => $w, 'height' => $h );
		}
		$size = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid data is reported.
		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			// AVIF needs a recent GD; accept the signature with an unknown size.
			return 'image/avif' === $sniffed ? array( 'mime' => $sniffed, 'width' => 0, 'height' => 0 ) : null;
		}
		return array(
			'mime'   => $sniffed,
			'width'  => (int) $size[0],
			'height' => (int) $size[1],
		);
	}

	/**
	 * Whether bytes are a font of the extension's type.
	 *
	 * @param string $bytes Bytes.
	 * @param string $ext   Extension.
	 */
	public static function isFont( string $bytes, string $ext ): bool {
		$head = substr( $bytes, 0, 4 );
		return match ( $ext ) {
			'woff'  => 'wOFF' === $head,
			'woff2' => 'wOF2' === $head,
			'ttf'   => "\x00\x01\x00\x00" === $head || 'true' === $head,
			'otf'   => 'OTTO' === $head,
			default => false,
		};
	}
}
