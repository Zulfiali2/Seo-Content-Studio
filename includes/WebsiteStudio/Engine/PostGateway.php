<?php
/**
 * The WordPress post operations the DraftManager needs.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over core post functions, so the DraftManager's ownership and rollback rules
 * can be tested without WordPress.
 */
class PostGateway {

	/**
	 * Create a draft page.
	 *
	 * @param string $title     Title.
	 * @param string $slug      Slug ("" = from title; core makes it unique).
	 * @param int    $author    Author.
	 * @param string $post_type page|post.
	 * @return int Post ID, 0 on failure.
	 */
	public function insertDraft( string $title, string $slug, int $author, string $post_type = 'page' ): int {
		$id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_name'    => '' === $slug ? '' : sanitize_title( $slug ),
				'post_author'  => $author,
				'post_content' => '',
			),
			true
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Permanently delete a post (rollback of a draft this request created).
	 *
	 * @param int $id Post.
	 */
	public function delete( int $id ): void {
		wp_delete_post( $id, true );
	}

	/**
	 * Post status, or "" when the post does not exist.
	 *
	 * @param int $id Post.
	 */
	public function status( int $id ): string {
		$post = get_post( $id );
		return $post ? (string) $post->post_status : '';
	}

	/**
	 * Post type, or "".
	 *
	 * @param int $id Post.
	 */
	public function type( int $id ): string {
		$post = get_post( $id );
		return $post ? (string) $post->post_type : '';
	}

	/**
	 * Set a meta value.
	 *
	 * @param int    $id    Post.
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function setMeta( int $id, string $key, mixed $value ): void {
		update_post_meta( $id, $key, is_string( $value ) ? wp_slash( $value ) : $value );
	}

	/**
	 * Whether a user can edit a post / create pages.
	 *
	 * @param int    $user_id User.
	 * @param string $cap     Capability.
	 * @param int    $id      Post (0 for a primitive capability).
	 */
	public function can( int $user_id, string $cap, int $id = 0 ): bool {
		return $id > 0 ? user_can( $user_id, $cap, $id ) : user_can( $user_id, $cap );
	}

	/**
	 * Preview link of a draft (only viewable by users who can edit it).
	 *
	 * @param int $id Post.
	 */
	public function previewUrl( int $id ): string {
		$post = get_post( $id );
		return $post ? (string) get_preview_post_link( $post ) : '';
	}

	/**
	 * Edit link in the WordPress editor.
	 *
	 * @param int $id Post.
	 */
	public function editUrl( int $id ): string {
		return (string) get_edit_post_link( $id, 'raw' );
	}
}
