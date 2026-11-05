<?php
/**
 * Stores imported project assets in WordPress.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Images go through media_handle_sideload(), so WordPress runs its own file-type checks, keeps
 * unique file names (an existing file is never overwritten) and generates the image sizes. Each
 * attachment records a SHA-256 of its bytes, so the same image is reused instead of duplicated.
 *
 * Fonts cannot be uploaded to the Media Library by default; they are written to
 * uploads/aiscs-imports/{project key}/ under a name made from their hash (only the validated font
 * extensions, never anything the server would execute).
 */
class MediaAssetSink implements AssetSink {

	public const HASH_META    = '_aiscs_asset_sha256';
	public const PROJECT_META = '_aiscs_import_key';
	public const FONT_DIR     = 'aiscs-imports';

	/**
	 * {@inheritDoc}
	 *
	 * @param string $bytes Bytes.
	 * @param string $ext   Extension.
	 * @param string $name  Name.
	 * @param string $alt   Alt text.
	 * @param string $key   Project key.
	 * @param bool   $reuse Reuse identical images.
	 * @return array{id: int, url: string, reused: bool}
	 * @throws ImportException When WordPress refuses the file.
	 */
	public function image( string $bytes, string $ext, string $name, string $alt, string $key, bool $reuse ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new ImportException( 'forbidden', __( 'You are not allowed to upload files to the Media Library.', 'ai-seo-content-studio' ) );
		}
		$hash = hash( 'sha256', $bytes );
		if ( $reuse ) {
			$found = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => self::HASH_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one lookup per asset.
					'meta_value'     => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( array() !== $found && wp_get_attachment_url( (int) $found[0] ) ) {
				return array(
					'id'     => (int) $found[0],
					'url'    => (string) wp_get_attachment_url( (int) $found[0] ),
					'reused' => true,
				);
			}
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$base = sanitize_file_name( pathinfo( $name, PATHINFO_FILENAME ) );
		$base = '' !== $base ? mb_substr( $base, 0, 80 ) : 'imported-image';
		$tmp  = wp_tempnam( $base );
		if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temporary file for sideload.
			throw new ImportException( 'asset_write', __( 'A temporary file for an image could not be written.', 'ai-seo-content-studio' ) );
		}
		$id = media_handle_sideload(
			array(
				'name'     => $base . '.' . ( 'jpeg' === $ext ? 'jpg' : $ext ),
				'tmp_name' => $tmp,
			),
			0,
			null
		);
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		if ( is_wp_error( $id ) ) {
			/* translators: 1: file, 2: WordPress message. */
			throw new ImportException( 'asset_rejected', sprintf( __( 'WordPress did not accept %1$s: %2$s', 'ai-seo-content-studio' ), $name, $id->get_error_message() ) );
		}
		update_post_meta( (int) $id, self::HASH_META, $hash );
		update_post_meta( (int) $id, self::PROJECT_META, $key );
		if ( '' !== $alt ) {
			update_post_meta( (int) $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		return array(
			'id'     => (int) $id,
			'url'    => (string) wp_get_attachment_url( (int) $id ),
			'reused' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $bytes Bytes.
	 * @param string $ext   Extension.
	 * @param string $key   Project key.
	 * @return string URL.
	 * @throws ImportException When the file cannot be written.
	 */
	public function font( string $bytes, string $ext, string $key ): string {
		if ( ! isset( AssetResolver::FONT_TYPES[ $ext ] ) || ! preg_match( '/^[a-f0-9]{8,32}$/', $key ) ) {
			throw new ImportException( 'font_invalid', __( 'Unsupported font file.', 'ai-seo-content-studio' ) );
		}
		$uploads = wp_get_upload_dir();
		$dir     = rtrim( (string) $uploads['basedir'], '/' ) . '/' . self::FONT_DIR . '/' . $key;
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new ImportException( 'asset_write', __( 'The fonts folder could not be created in the uploads directory.', 'ai-seo-content-studio' ) );
		}
		$file = hash( 'sha256', $bytes ) . '.' . $ext;
		if ( ! is_file( $dir . '/' . $file ) && false === file_put_contents( $dir . '/' . $file, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- validated font file.
			throw new ImportException( 'asset_write', __( 'A font file could not be written.', 'ai-seo-content-studio' ) );
		}
		return rtrim( (string) $uploads['baseurl'], '/' ) . '/' . self::FONT_DIR . '/' . $key . '/' . $file;
	}
}
