<?php
/**
 * Read-only, validated view of an uploaded website project ZIP.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Opens a project ZIP and builds an index of the files it may use. Nothing is extracted to
 * disk: files are read from the archive on demand, with a size cap, so an uploaded file can
 * never be executed by the web server and a "ZIP slip" path can never be written anywhere.
 *
 * Every entry is checked before it is listed:
 * - path: no absolute paths, drive letters, "..", NUL bytes, control characters or over-deep
 *   nesting; backslashes are normalised; macOS metadata (__MACOSX, ._*, .DS_Store) is ignored;
 * - type: symbolic links and encrypted entries are refused; executable or server-side files
 *   (PHP, scripts, binaries, .htaccess …) are refused; unknown types are ignored;
 * - size: per-file, total uncompressed size, entry count and compression ratio (zip bombs).
 * The original archive is never changed.
 */
final class ProjectArchive {

	/** Kinds of file the importer uses. */
	public const KINDS = array(
		'html' => array( 'html', 'htm' ),
		'css'  => array( 'css' ),
		'js'   => array( 'js', 'mjs' ),
		'image' => array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico' ),
		'svg'  => array( 'svg' ),
		'font' => array( 'woff', 'woff2', 'ttf', 'otf' ),
	);

	/** Never accepted, whatever their content. */
	private const BLOCKED = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps', 'inc', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'exe', 'dll', 'so', 'dylib', 'bat', 'cmd', 'com', 'msi', 'jar', 'war', 'asp', 'aspx', 'ashx', 'jsp', 'jspx', 'htaccess', 'htpasswd', 'ini', 'user.ini', 'shtml', 'svgz', 'swf', 'vbs', 'ps1', 'scr', 'hta', 'lnk', 'apk', 'app', 'deb', 'rpm' );

	/** Recognised but not used (listed as ignored). */
	private const IGNORED = array( 'json', 'txt', 'md', 'map', 'xml', 'webmanifest', 'pdf', 'mp4', 'webm', 'mov', 'mp3', 'ogg', 'wav', 'eot', 'scss', 'sass', 'less', 'ts', 'tsx', 'jsx', 'yml', 'yaml', 'lock', 'gitignore', 'csv', 'zip', 'psd', 'ai', 'sketch', 'fig', 'bmp', 'tif', 'tiff', 'heic' );

	private \ZipArchive $zip;

	/**
	 * Usable files: normalised path => {index, size, csize, kind}.
	 *
	 * @var array<string, array{index: int, size: int, csize: int, kind: string}>
	 */
	private array $files = array();

	/** @var array<int, array{path: string, reason: string}> */
	private array $rejected = array();

	/** @var string[] */
	private array $ignored = array();

	private int $total = 0;

	/**
	 * Open and validate.
	 *
	 * @param string               $path   Archive file.
	 * @param array<string, int>   $limits ImportLimits::all() values.
	 * @throws ImportException When the file is not a usable ZIP or breaks a limit.
	 */
	public function __construct( string $path, private readonly array $limits ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new ImportException( 'zip_unsupported', __( 'This server cannot read ZIP files (the PHP zip extension is missing). Ask your host to enable it.', 'ai-seo-content-studio' ) );
		}
		if ( ! is_file( $path ) ) {
			throw new ImportException( 'zip_missing', __( 'The uploaded ZIP file could not be found. Upload it again.', 'ai-seo-content-studio' ) );
		}
		$size = (int) filesize( $path );
		if ( $size > $limits['archive_bytes'] ) {
			/* translators: %s: size limit. */
			throw new ImportException( 'zip_too_large', sprintf( __( 'The ZIP file is larger than %s.', 'ai-seo-content-studio' ), size_format( $limits['archive_bytes'] ) ) );
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading the signature.
		$magic  = false === $handle ? '' : (string) fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( false !== $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( "PK\x03\x04" !== $magic && "PK\x05\x06" !== $magic ) {
			throw new ImportException( 'zip_invalid', __( 'The file is not a ZIP archive.', 'ai-seo-content-studio' ) );
		}
		$this->zip = new \ZipArchive();
		$opened    = $this->zip->open( $path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS );
		if ( true !== $opened ) {
			throw new ImportException( 'zip_invalid', __( 'The ZIP archive is damaged or uses a format this server cannot read.', 'ai-seo-content-studio' ) );
		}
		if ( $this->zip->numFiles > $limits['entries'] ) {
			/* translators: %d: entry limit. */
			throw new ImportException( 'zip_too_many_files', sprintf( __( 'The ZIP contains more than %d files.', 'ai-seo-content-studio' ), $limits['entries'] ) );
		}
		$this->index();
		if ( $this->total > $limits['extracted_bytes'] ) {
			/* translators: %s: size limit. */
			throw new ImportException( 'zip_too_large_extracted', sprintf( __( 'The files in the ZIP add up to more than %s.', 'ai-seo-content-studio' ), size_format( $limits['extracted_bytes'] ) ) );
		}
	}

	/**
	 * Close the archive.
	 */
	public function __destruct() {
		if ( isset( $this->zip ) ) {
			@$this->zip->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may already be closed.
		}
	}

	/**
	 * Build the index.
	 *
	 * @throws ImportException On an entry that makes the whole archive unsafe.
	 */
	private function index(): void {
		for ( $i = 0; $i < $this->zip->numFiles; $i++ ) {
			$stat = $this->zip->statIndex( $i, \ZipArchive::FL_UNCHANGED );
			if ( false === $stat ) {
				continue;
			}
			$raw = (string) $stat['name'];
			if ( str_ends_with( $raw, '/' ) || str_ends_with( $raw, '\\' ) ) {
				continue; // Directory entry.
			}
			$path = self::normalise( $raw );
			if ( null === $path ) {
				$this->rejected[] = array(
					'path'   => self::displayName( $raw ),
					'reason' => __( 'Unsafe path (absolute, outside the project or with control characters).', 'ai-seo-content-studio' ),
				);
				continue;
			}
			if ( self::isMetadata( $path ) ) {
				continue;
			}
			if ( substr_count( $path, '/' ) > $this->limits['depth'] ) {
				$this->rejected[] = array(
					'path'   => $path,
					'reason' => __( 'Nested too deeply.', 'ai-seo-content-studio' ),
				);
				continue;
			}
			if ( ! empty( $stat['encryption_method'] ) ) {
				$this->rejected[] = array(
					'path'   => $path,
					'reason' => __( 'Password-protected entries cannot be read.', 'ai-seo-content-studio' ),
				);
				continue;
			}
			$opsys = 0;
			$attr  = 0;
			if ( $this->zip->getExternalAttributesIndex( $i, $opsys, $attr ) && \ZipArchive::OPSYS_UNIX === $opsys && 0120000 === ( ( $attr >> 16 ) & 0170000 ) ) {
				$this->rejected[] = array(
					'path'   => $path,
					'reason' => __( 'Symbolic links are not allowed.', 'ai-seo-content-studio' ),
				);
				continue;
			}
			$ext = self::extension( $path );
			if ( in_array( $ext, self::BLOCKED, true ) || str_contains( strtolower( basename( $path ) ), '.php' ) ) {
				$this->rejected[] = array(
					'path'   => $path,
					'reason' => __( 'Executable or server-side files are never imported.', 'ai-seo-content-studio' ),
				);
				continue;
			}
			$kind = self::kindOf( $ext );
			if ( null === $kind ) {
				$this->ignored[] = $path;
				continue;
			}
			$size  = (int) $stat['size'];
			$csize = (int) $stat['comp_size'];
			if ( $size > $this->limits['file_bytes'] ) {
				$this->rejected[] = array(
					'path'   => $path,
					/* translators: %s: size limit. */
					'reason' => sprintf( __( 'Larger than %s.', 'ai-seo-content-studio' ), size_format( $this->limits['file_bytes'] ) ),
				);
				continue;
			}
			if ( $size > 1048576 && $csize > 0 && $size / $csize > $this->limits['ratio'] ) {
				throw new ImportException( 'zip_bomb', __( 'The ZIP contains a file that expands to an unusually large size (a "zip bomb"). It was not read.', 'ai-seo-content-studio' ) );
			}
			if ( isset( $this->files[ $path ] ) ) {
				continue; // Duplicate entry name: the first one wins, like most unzip tools.
			}
			$this->total          += $size;
			$this->files[ $path ] = array(
				'index' => $i,
				'size'  => $size,
				'csize' => $csize,
				'kind'  => $kind,
			);
		}
		ksort( $this->files, SORT_NATURAL | SORT_FLAG_CASE );
	}

	/**
	 * Normalise an entry name, or null when it is unsafe.
	 *
	 * @param string $name Entry name.
	 */
	public static function normalise( string $name ): ?string {
		if ( '' === $name || preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			return null;
		}
		$name = str_replace( '\\', '/', $name );
		if ( str_starts_with( $name, '/' ) || preg_match( '/^[A-Za-z]:/', $name ) || str_starts_with( $name, '//' ) ) {
			return null;
		}
		$out = array();
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return null;
			}
			$out[] = $segment;
		}
		return array() === $out || strlen( implode( '/', $out ) ) > 500 ? null : implode( '/', $out );
	}

	/**
	 * Resolve a reference made from a file, against the archive.
	 *
	 * @param string $from Path of the referring file ("" for the project root).
	 * @param string $ref  Reference as written (relative or root-relative), without query or fragment.
	 * @return string|null Normalised path inside the project, or null when it leaves it.
	 */
	public static function resolve( string $from, string $ref ): ?string {
		$ref = rawurldecode( $ref );
		if ( '' === $ref ) {
			return null;
		}
		if ( str_starts_with( $ref, '/' ) ) {
			return self::normalise( ltrim( $ref, '/' ) );
		}
		$dir   = str_contains( $from, '/' ) ? substr( $from, 0, (int) strrpos( $from, '/' ) ) : '';
		$parts = '' === $dir ? array() : explode( '/', $dir );
		foreach ( explode( '/', str_replace( '\\', '/', $ref ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( array() === $parts ) {
					return null;
				}
				array_pop( $parts );
				continue;
			}
			$parts[] = $segment;
		}
		return array() === $parts ? null : self::normalise( implode( '/', $parts ) );
	}

	/**
	 * Find a file by its path, or (for root-relative references in nested projects) by the same
	 * path below the project's single top folder.
	 *
	 * @param string|null $path Normalised path.
	 */
	public function find( ?string $path ): ?string {
		if ( null === $path ) {
			return null;
		}
		if ( isset( $this->files[ $path ] ) ) {
			return $path;
		}
		$lower = strtolower( $path );
		foreach ( array_keys( $this->files ) as $candidate ) {
			if ( strtolower( $candidate ) === $lower ) {
				return $candidate; // Case-insensitive match (projects made on Windows or macOS).
			}
		}
		$root = $this->topFolder();
		return '' !== $root && isset( $this->files[ $root . '/' . $path ] ) ? $root . '/' . $path : null;
	}

	/**
	 * Read a file's bytes, at most $max bytes (default: the per-file limit).
	 *
	 * @param string $path Normalised path from files().
	 * @param int    $max  Byte cap.
	 * @throws ImportException When the file is missing, larger than the cap or unreadable.
	 */
	public function read( string $path, int $max = 0 ): string {
		$entry = $this->files[ $path ] ?? null;
		if ( null === $entry ) {
			throw new ImportException( 'file_missing', sprintf( 'Not in the archive: %s', $path ) );
		}
		$max = $max > 0 ? $max : $this->limits['file_bytes'];
		if ( $entry['size'] > $max ) {
			/* translators: 1: file, 2: size limit. */
			throw new ImportException( 'file_too_large', sprintf( __( '%1$s is larger than %2$s.', 'ai-seo-content-studio' ), $path, size_format( $max ) ) );
		}
		$stream = $this->zip->getStream( $this->zip->getNameIndex( $entry['index'], \ZipArchive::FL_UNCHANGED ) );
		if ( false === $stream ) {
			throw new ImportException( 'file_unreadable', sprintf( 'Could not read %s.', $path ) );
		}
		$data = '';
		while ( ! feof( $stream ) && strlen( $data ) <= $max ) {
			$chunk = fread( $stream, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- reading from the archive stream.
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$data .= $chunk;
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( strlen( $data ) > $max ) {
			// The header understated the size: treat it as a bomb.
			throw new ImportException( 'file_too_large', sprintf( 'Larger than declared: %s', $path ) );
		}
		return $data;
	}

	/**
	 * Usable files.
	 *
	 * @return array<string, array{index: int, size: int, csize: int, kind: string}>
	 */
	public function files(): array {
		return $this->files;
	}

	/**
	 * Paths of one kind.
	 *
	 * @param string $kind Kind.
	 * @return string[]
	 */
	public function ofKind( string $kind ): array {
		return array_keys( array_filter( $this->files, static fn ( array $f ): bool => $kind === $f['kind'] ) );
	}

	/**
	 * Refused entries.
	 *
	 * @return array<int, array{path: string, reason: string}>
	 */
	public function rejected(): array {
		return $this->rejected;
	}

	/**
	 * Ignored entries.
	 *
	 * @return string[]
	 */
	public function ignored(): array {
		return $this->ignored;
	}

	/**
	 * Total uncompressed size of the usable files.
	 */
	public function totalBytes(): int {
		return $this->total;
	}

	/**
	 * The single folder that holds every file, or "".
	 */
	public function topFolder(): string {
		$first = null;
		foreach ( array_keys( $this->files ) as $path ) {
			$top = str_contains( $path, '/' ) ? strstr( $path, '/', true ) : '';
			if ( '' === $top ) {
				return '';
			}
			if ( null === $first ) {
				$first = $top;
			} elseif ( $first !== $top ) {
				return '';
			}
		}
		return (string) $first;
	}

	/**
	 * Lower-case extension.
	 *
	 * @param string $path Path.
	 */
	public static function extension( string $path ): string {
		$base = strtolower( basename( $path ) );
		if ( in_array( $base, array( '.htaccess', '.htpasswd', '.user.ini' ), true ) ) {
			return ltrim( $base, '.' );
		}
		return str_contains( $base, '.' ) ? substr( $base, (int) strrpos( $base, '.' ) + 1 ) : '';
	}

	/**
	 * Kind of an extension, or null when unused.
	 *
	 * @param string $ext Extension.
	 */
	public static function kindOf( string $ext ): ?string {
		foreach ( self::KINDS as $kind => $extensions ) {
			if ( in_array( $ext, $extensions, true ) ) {
				return $kind;
			}
		}
		return null;
	}

	/**
	 * Whether a path is operating-system metadata.
	 *
	 * @param string $path Path.
	 */
	private static function isMetadata( string $path ): bool {
		$base = basename( $path );
		return str_starts_with( $path, '__MACOSX/' ) || str_contains( $path, '/__MACOSX/' ) || str_starts_with( $base, '._' ) || in_array( $base, array( '.DS_Store', 'Thumbs.db', 'desktop.ini' ), true ) || str_contains( '/' . $path, '/.git/' );
	}

	/**
	 * A raw entry name made safe for display.
	 *
	 * @param string $name Name.
	 */
	private static function displayName( string $name ): string {
		return mb_substr( (string) preg_replace( '/[\x00-\x1F\x7F]/', '?', $name ), 0, 200 );
	}
}
