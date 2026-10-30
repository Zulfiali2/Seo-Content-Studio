<?php
/**
 * Lists the site's pages for the Website Studio page selector.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Analysis;

defined( 'ABSPATH' ) || exit;

/**
 * Paginated page listing, limited to pages the current user can edit (the same
 * permission later needed to clone a page or use it as a design reference).
 */
class PageCatalog {

	public const POST_TYPES = array( 'page', 'post' );

	public function __construct( private readonly SiteDesignSource $source ) {}

	/**
	 * List pages.
	 *
	 * @param array{page?: int, per_page?: int, search?: string, post_type?: string, builder?: string} $args Arguments.
	 * @return array{items: list<array<string, mixed>>, total: int, pages: int}
	 */
	public function list( array $args ): array {
		$per        = max( 1, min( 50, (int) ( $args['per_page'] ?? 20 ) ) );
		$post_type  = in_array( $args['post_type'] ?? 'page', self::POST_TYPES, true ) ? (string) $args['post_type'] : 'page';
		$query_args = array(
			'post_type'              => $post_type,
			'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'perm'                   => 'editable',
			'posts_per_page'         => $per,
			'paged'                  => max( 1, (int) ( $args['page'] ?? 1 ) ),
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
		);
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$query_args['s'] = mb_substr( $search, 0, 100 );
		}
		// Temporary AI Studio preview pages are never offered.
		$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only listing.
			array(
				'key'     => '_aiscs_preview',
				'compare' => 'NOT EXISTS',
			),
		);
		$builder = (string) ( $args['builder'] ?? '' );
		if ( 'elementor' === $builder ) {
			$query_args['meta_query'][] = array(
				'key'   => '_elementor_edit_mode',
				'value' => 'builder',
			);
		}

		$query = new \WP_Query( $query_args );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$items[] = $this->row( $post );
		}
		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * One page row.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	public function row( \WP_Post $post ): array {
		$thumb = get_the_post_thumbnail_url( $post, 'thumbnail' );
		return array(
			'id'             => $post->ID,
			'title'          => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'            => (string) get_permalink( $post ),
			'status'         => $post->post_status,
			'post_type'      => $post->post_type,
			'builder'        => $this->source->builder( $post ),
			'featured_image' => is_string( $thumb ) ? $thumb : '',
			'modified'       => mysql_to_rfc3339( $post->post_modified_gmt ),
			'edit_url'       => (string) get_edit_post_link( $post->ID, 'raw' ),
		);
	}
}
