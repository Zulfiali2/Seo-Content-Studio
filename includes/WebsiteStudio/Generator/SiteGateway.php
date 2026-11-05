<?php
/**
 * The WordPress reads and writes the page generator needs.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Generator;

use AiSeoContentStudio\Content\DraftWriter;
use AiSeoContentStudio\Media\MediaImporter;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over core functions, so the generator jobs can be tested without WordPress.
 */
class SiteGateway {

	/**
	 * Published, public, password-free pages and posts the new page may link to.
	 *
	 * @param int $limit Max results.
	 * @return list<array{id: int, title: string, url: string, type: string}>
	 */
	public function linkCandidates( int $limit = 60 ): array {
		$ids = get_posts(
			array(
				'post_type'        => array( 'page', 'post' ),
				'post_status'      => 'publish',
				'has_password'     => false,
				'numberposts'      => max( 1, min( 100, $limit ) ),
				'orderby'          => 'modified',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		$out = array();
		foreach ( (array) $ids as $id ) {
			$url = $this->permalink( (int) $id );
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'id'    => (int) $id,
				'title' => mb_substr( html_entity_decode( (string) get_the_title( (int) $id ), ENT_QUOTES, 'UTF-8' ), 0, 150 ),
				'url'   => $url,
				'type'  => (string) get_post_type( (int) $id ),
			);
		}
		return $out;
	}

	/**
	 * Permalink of a published public page or post, or "".
	 *
	 * @param int $id Post.
	 */
	public function permalink( int $id ): string {
		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status || '' !== (string) $post->post_password || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return '';
		}
		return (string) get_permalink( $post );
	}

	/**
	 * Attach a plugin-made image to the draft that uses it, so clean-up never deletes it
	 * (MediaImporter::deleteUnused() keeps attached images).
	 *
	 * @param int $attachment_id Attachment.
	 * @param int $page_id       Page.
	 */
	public function attach( int $attachment_id, int $page_id ): void {
		if ( MediaImporter::isOwn( $attachment_id ) ) {
			wp_update_post(
				array(
					'ID'          => $attachment_id,
					'post_parent' => $page_id,
				)
			);
		}
	}

	/**
	 * Delete an image the plugin made, when the user may delete it.
	 *
	 * @param int $attachment_id Attachment.
	 */
	public function deleteImage( int $attachment_id ): bool {
		if ( ! MediaImporter::isOwn( $attachment_id ) || ! current_user_can( 'delete_post', $attachment_id ) ) {
			return false;
		}
		return false !== wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * URL of an image's large size.
	 *
	 * @param int $attachment_id Attachment.
	 */
	public function imageUrl( int $attachment_id ): string {
		return (string) wp_get_attachment_image_url( $attachment_id, 'large' );
	}

	/**
	 * Store the SEO fields on the draft (plugin meta and Yoast / Rank Math when active).
	 *
	 * @param int                   $page_id Page.
	 * @param array<string, string> $seo     meta_title, meta_description, keyword.
	 */
	public function saveSeo( int $page_id, array $seo ): void {
		if ( '' !== (string) ( $seo['meta_title'] ?? '' ) ) {
			update_post_meta( $page_id, DraftWriter::META['seo_title'], wp_slash( (string) $seo['meta_title'] ) );
		}
		if ( '' !== (string) ( $seo['meta_description'] ?? '' ) ) {
			update_post_meta( $page_id, DraftWriter::META['meta_description'], wp_slash( (string) $seo['meta_description'] ) );
		}
		DraftWriter::syncSeoPlugins( $page_id, $seo );
	}
}
