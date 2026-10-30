<?php
/**
 * Short-lived storage for uploaded screenshots.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Vision;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps an uploaded screenshot only until the analysis step has read it.
 *
 * Files live in uploads/aiscs-studio-tmp/ under a random 32-hex-character name with a ".bin"
 * extension (never served as an image), next to an index.php and a deny-all .htaccess. Tokens
 * are validated before any path is built, so a token cannot point outside the directory.
 * Files are deleted right after analysis; anything older than a day is pruned daily.
 */
class ScreenshotStore {

	public const DIR = 'aiscs-studio-tmp';

	/**
	 * Base directory override (tests).
	 *
	 * @param string|null $base Directory.
	 */
	public function __construct( private readonly ?string $base = null ) {}

	/**
	 * Store bytes; returns the token.
	 *
	 * @param string $bytes Bytes.
	 * @throws \RuntimeException When the file cannot be written.
	 */
	public function put( string $bytes ): string {
		$dir = $this->dir();
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- private temporary directory.
			throw new \RuntimeException( 'temp_dir' );
		}
		foreach ( array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "Require all denied\nDeny from all\n",
		) as $name => $contents ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- protection files.
			}
		}
		$token = bin2hex( random_bytes( 16 ) );
		if ( false === file_put_contents( $this->path( $token ), $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temporary screenshot.
			throw new \RuntimeException( 'temp_write' );
		}
		return $token;
	}

	/**
	 * Read bytes ("" when missing or the token is invalid).
	 *
	 * @param string $token Token.
	 */
	public function get( string $token ): string {
		if ( ! self::valid( $token ) || ! is_file( $this->path( $token ) ) ) {
			return '';
		}
		$bytes = file_get_contents( $this->path( $token ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temporary file.
		return is_string( $bytes ) ? $bytes : '';
	}

	/**
	 * Delete a screenshot.
	 *
	 * @param string $token Token.
	 */
	public function delete( string $token ): void {
		if ( self::valid( $token ) && is_file( $this->path( $token ) ) ) {
			unlink( $this->path( $token ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- own temporary file.
		}
	}

	/**
	 * Remove screenshots older than a number of seconds.
	 *
	 * @param int $age Seconds.
	 * @return int Files removed.
	 */
	public function prune( int $age = 86400 ): int {
		$removed = 0;
		foreach ( (array) glob( $this->dir() . '/*.bin' ) as $file ) {
			if ( is_string( $file ) && self::valid( basename( $file, '.bin' ) ) && filemtime( $file ) < time() - $age ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- own temporary file.
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Token format.
	 *
	 * @param string $token Token.
	 */
	public static function valid( string $token ): bool {
		return (bool) preg_match( '/^[a-f0-9]{32}$/', $token );
	}

	/**
	 * Directory.
	 */
	private function dir(): string {
		if ( null !== $this->base ) {
			return rtrim( $this->base, '/' );
		}
		$uploads = wp_get_upload_dir();
		return rtrim( (string) $uploads['basedir'], '/' ) . '/' . self::DIR;
	}

	/**
	 * Path of a (validated) token.
	 *
	 * @param string $token Token.
	 */
	private function path( string $token ): string {
		return $this->dir() . '/' . $token . '.bin';
	}
}
