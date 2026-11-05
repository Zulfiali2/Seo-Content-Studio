<?php
/**
 * Temporary storage of uploaded project ZIPs.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Each upload gets a random token and a private folder under the uploads directory (the folder
 * is protected with "deny all" and an empty index file, and only holds the original ZIP, which is
 * never changed, and a JSON state file). The state records the owner, so only the user who
 * uploaded the ZIP (or a settings manager) can use the token. Folders older than a day are removed.
 */
class ImportStore {

	public const DIR = 'aiscs-studio-tmp/imports';

	public const TTL = 86400;

	/**
	 * Constructor.
	 *
	 * @param string|null $base Base directory (tests); default: uploads/aiscs-studio-tmp/imports.
	 */
	public function __construct( private readonly ?string $base = null ) {}

	/**
	 * Store an uploaded file.
	 *
	 * @param string               $source File to copy (the upload's temporary file).
	 * @param array<string, mixed> $state  Initial state (owner, name …).
	 * @return string Token.
	 * @throws ImportException When the file cannot be stored.
	 */
	public function put( string $source, array $state ): string {
		$this->prune();
		$token = bin2hex( random_bytes( 16 ) );
		$dir   = $this->dir() . '/' . $token;
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new ImportException( 'store_failed', __( 'The uploaded ZIP could not be stored on the server (the uploads folder is not writable).', 'ai-seo-content-studio' ) );
		}
		$this->protect( $this->dir() );
		if ( ! copy( $source, $dir . '/archive.zip' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- temporary private copy.
			$this->delete( $token );
			throw new ImportException( 'store_failed', __( 'The uploaded ZIP could not be stored on the server.', 'ai-seo-content-studio' ) );
		}
		$this->save( $token, $state + array( 'created' => time() ) );
		return $token;
	}

	/**
	 * Path of a token's archive ("" when the token is unknown).
	 *
	 * @param string $token Token.
	 */
	public function archive( string $token ): string {
		$path = $this->folder( $token ) . '/archive.zip';
		return self::valid( $token ) && is_file( $path ) ? $path : '';
	}

	/**
	 * State of a token (null when unknown or expired).
	 *
	 * @param string $token Token.
	 * @return array<string, mixed>|null
	 */
	public function state( string $token ): ?array {
		if ( ! self::valid( $token ) ) {
			return null;
		}
		$file = $this->folder( $token ) . '/state.json';
		if ( ! is_file( $file ) ) {
			return null;
		}
		$state = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local state file.
		if ( ! is_array( $state ) || (int) ( $state['created'] ?? 0 ) < time() - self::TTL ) {
			return null;
		}
		return $state;
	}

	/**
	 * Save a token's state.
	 *
	 * @param string               $token Token.
	 * @param array<string, mixed> $state State.
	 */
	public function save( string $token, array $state ): void {
		if ( self::valid( $token ) && is_dir( $this->folder( $token ) ) ) {
			file_put_contents( $this->folder( $token ) . '/state.json', (string) wp_json_encode( $state ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local state file.
		}
	}

	/**
	 * Delete a token's folder.
	 *
	 * @param string $token Token.
	 */
	public function delete( string $token ): void {
		if ( ! self::valid( $token ) ) {
			return;
		}
		$dir = $this->folder( $token );
		foreach ( array( 'archive.zip', 'state.json' ) as $file ) {
			if ( is_file( $dir . '/' . $file ) ) {
				unlink( $dir . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- own temporary file.
			}
		}
		if ( is_dir( $dir ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- own temporary folder.
		}
	}

	/**
	 * Remove folders older than the time to live.
	 *
	 * @return int Folders removed.
	 */
	public function prune(): int {
		$removed = 0;
		foreach ( (array) glob( $this->dir() . '/*', GLOB_ONLYDIR ) as $dir ) {
			$token = basename( (string) $dir );
			if ( self::valid( $token ) && (int) @filemtime( (string) $dir ) < time() - self::TTL ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$this->delete( $token );
				++$removed;
			}
		}
		return $removed;
	}

	/**
	 * Whether a token is well-formed.
	 *
	 * @param string $token Token.
	 */
	public static function valid( string $token ): bool {
		return (bool) preg_match( '/^[a-f0-9]{32}$/', $token );
	}

	/**
	 * Folder of a token.
	 *
	 * @param string $token Token.
	 */
	private function folder( string $token ): string {
		return $this->dir() . '/' . $token;
	}

	/**
	 * Base folder.
	 */
	private function dir(): string {
		if ( null !== $this->base ) {
			return rtrim( $this->base, '/' );
		}
		$uploads = wp_get_upload_dir();
		return rtrim( (string) $uploads['basedir'], '/' ) . '/' . self::DIR;
	}

	/**
	 * Deny web access to the temporary folder.
	 *
	 * @param string $dir Folder.
	 */
	private function protect( string $dir ): void {
		foreach ( array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "Require all denied\nDeny from all\n",
		) as $name => $contents ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				file_put_contents( $dir . '/' . $name, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- protection files.
			}
		}
	}
}
