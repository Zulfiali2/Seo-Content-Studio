<?php
/**
 * Import validated image bytes into the Media Library.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Media;

use AiSeoContentStudio\Providers\Contracts\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * Writes the bytes to a temporary file and hands it to `media_handle_sideload()`, so
 * WordPress runs its own file-type check, generates sizes and creates the attachment.
 * Attachments are tagged so the plugin only ever deletes images it created itself.
 */
class MediaImporter {

	public const META = '_aiscs_generated_image';

	/**
	 * Import.
	 *
	 * @param string               $bytes   Validated image data.
	 * @param array<string, mixed> $file    Result of ImageValidator::validate().
	 * @param array<string, mixed> $details title, alt, caption, prompt, provider, model, project_id.
	 * @return int Attachment id.
	 * @throws ProviderException When the user cannot upload or WordPress rejects the file.
	 */
	public function import( string $bytes, array $file, array $details ): int {
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new ProviderException( __( 'You do not have permission to upload files.', 'ai-seo-content-studio' ), ProviderException::PERMISSION );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$title = sanitize_text_field( (string) ( $details['title'] ?? '' ) );
		$base  = sanitize_file_name( '' !== $title ? sanitize_title( $title ) : 'ai-image' );
		$tmp   = wp_tempnam( $base );
		if ( ! $tmp ) {
			throw new ProviderException( __( 'Could not create a temporary file for the image.', 'ai-seo-content-studio' ), ProviderException::SERVER );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temporary file for media_handle_sideload().
		if ( false === file_put_contents( $tmp, $bytes ) ) {
			wp_delete_file( $tmp );
			throw new ProviderException( __( 'Could not write the temporary image file.', 'ai-seo-content-studio' ), ProviderException::SERVER );
		}

		$upload = array(
			'name'     => mb_substr( $base, 0, 80 ) . '.' . $file['ext'],
			'tmp_name' => $tmp,
		);
		$id     = media_handle_sideload(
			$upload,
			0,
			'' !== $title ? $title : null,
			array( 'post_excerpt' => sanitize_text_field( (string) ( $details['caption'] ?? '' ) ) )
		);
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		if ( is_wp_error( $id ) ) {
			throw new ProviderException(
				/* translators: %s: WordPress error message. */
				sprintf( __( 'WordPress did not accept the image: %s', 'ai-seo-content-studio' ), $id->get_error_message() ),
				ProviderException::INVALID_RESPONSE
			);
		}

		update_post_meta( (int) $id, '_wp_attachment_image_alt', sanitize_text_field( (string) ( $details['alt'] ?? '' ) ) );
		update_post_meta(
			(int) $id,
			self::META,
			wp_slash(
				array(
					'project_id' => (int) ( $details['project_id'] ?? 0 ),
					'provider'   => sanitize_key( (string) ( $details['provider'] ?? '' ) ),
					'model'      => sanitize_text_field( (string) ( $details['model'] ?? '' ) ),
					'prompt'     => sanitize_textarea_field( (string) ( $details['prompt'] ?? '' ) ),
					'created'    => time(),
				)
			)
		);
		return (int) $id;
	}

	/**
	 * Update alt text and caption of an attachment the plugin created.
	 *
	 * @param int    $id      Attachment.
	 * @param string $alt     Alt text.
	 * @param string $caption Caption.
	 */
	public function updateText( int $id, string $alt, string $caption ): void {
		if ( ! self::isOwn( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return;
		}
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_excerpt' => sanitize_text_field( $caption ),
			)
		);
	}

	/**
	 * Delete an attachment, but only one the plugin generated and that is not used by a post.
	 *
	 * @param int $id Attachment.
	 * @return bool Deleted.
	 */
	public function deleteUnused( int $id ): bool {
		if ( ! self::isOwn( $id ) || ! current_user_can( 'delete_post', $id ) ) {
			return false;
		}
		if ( (int) get_post_field( 'post_parent', $id ) > 0 ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off usage check.
		$featured = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s", (string) $id ) );
		if ( $featured > 0 ) {
			return false;
		}
		return false !== wp_delete_attachment( $id, true );
	}

	/**
	 * Attachment created by this plugin.
	 *
	 * @param int $id Attachment.
	 */
	public static function isOwn( int $id ): bool {
		return $id > 0 && 'attachment' === get_post_type( $id ) && is_array( get_post_meta( $id, self::META, true ) );
	}
}
