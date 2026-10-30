<?php
/**
 * Shortcodes and styles used by the Elementor blog post templates.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Frontend;

use AiSeoContentStudio\Elementor\BlogTemplates;

defined( 'ABSPATH' ) || exit;

/**
 * Small, read-only pieces of a blog post page that must stay current after publishing:
 * breadcrumbs, category badge, author/date/reading-time line, author box, latest posts,
 * categories and related posts. Each works on the current post and returns '' when it has
 * nothing to show. All output is escaped; nothing here writes data.
 */
class BlogShortcodes {

	/** Words read per minute for the reading-time estimate. */
	private const WORDS_PER_MINUTE = 200;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		$tags = array(
			'aiscs_breadcrumbs'    => 'breadcrumbs',
			'aiscs_post_badge'     => 'badge',
			'aiscs_post_meta'      => 'meta',
			'aiscs_author_box'     => 'authorBox',
			'aiscs_recent_posts'   => 'recentPosts',
			'aiscs_categories'     => 'categories',
			'aiscs_related_posts'  => 'relatedPosts',
			'aiscs_featured_image' => 'featuredImage',
		);
		foreach ( $tags as $tag => $method ) {
			add_shortcode( $tag, array( $this, $method ) );
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueForTemplatePosts' ) );
		add_filter( 'body_class', array( $this, 'bodyClass' ) );
	}

	/**
	 * Load the styles on posts saved with a blog template (also inside the Elementor preview).
	 */
	public function enqueueForTemplatePosts(): void {
		if ( self::isTemplatePost() ) {
			self::enqueue( (string) get_post_meta( (int) get_queried_object_id(), BlogTemplates::META_ACCENT, true ) );
		}
	}

	/**
	 * Mark template posts on the body so their full-width rows cannot cause sideways scrolling.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function bodyClass( $classes ): array {
		$classes = (array) $classes;
		if ( self::isTemplatePost() ) {
			$classes[] = 'aiscs-template';
		}
		return $classes;
	}

	/**
	 * Whether the current request is a single post saved with a blog template.
	 */
	private static function isTemplatePost(): bool {
		$id = is_singular() ? (int) get_queried_object_id() : 0;
		return $id > 0 && '' !== (string) get_post_meta( $id, BlogTemplates::META_TEMPLATE, true );
	}

	/**
	 * Enqueue the stylesheet once, with the accent colour.
	 *
	 * @param string $accent Accent colour.
	 */
	public static function enqueue( string $accent = '' ): void {
		if ( wp_style_is( 'aiscs-blog-templates', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'aiscs-blog-templates', AISCS_URL . 'assets/frontend/blog-templates.css', array(), AISCS_VERSION );
		wp_add_inline_style( 'aiscs-blog-templates', ':root{--aiscs-accent:' . BlogTemplates::accent( $accent ) . ';}' );
	}

	/**
	 * Home › category › title (or parent pages for pages).
	 */
	public function breadcrumbs(): string {
		$post = $this->post();
		if ( null === $post ) {
			return '';
		}
		$items = array( '<li><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'ai-seo-content-studio' ) . '</a></li>' );
		if ( 'page' === $post->post_type ) {
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor ) {
				$items[] = '<li><a href="' . esc_url( (string) get_permalink( $ancestor ) ) . '">' . esc_html( get_the_title( $ancestor ) ) . '</a></li>';
			}
		} else {
			$category = $this->category( $post );
			if ( null !== $category ) {
				$items[] = '<li><a href="' . esc_url( (string) get_category_link( $category ) ) . '">' . esc_html( $category->name ) . '</a></li>';
			}
		}
		$items[] = '<li aria-current="page">' . esc_html( get_the_title( $post ) ) . '</li>';
		return $this->wrap( '<nav class="aiscs-crumbs" aria-label="' . esc_attr__( 'Breadcrumb', 'ai-seo-content-studio' ) . '"><ol>' . implode( '', $items ) . '</ol></nav>' );
	}

	/**
	 * The post's featured image, large size.
	 */
	public function featuredImage(): string {
		$post = $this->post();
		if ( null === $post || ! has_post_thumbnail( $post ) ) {
			return '';
		}
		return $this->wrap( '<figure class="aiscs-featured">' . get_the_post_thumbnail( $post, 'large', array( 'fetchpriority' => 'high' ) ) . '</figure>' );
	}

	/**
	 * Category badge.
	 */
	public function badge(): string {
		$post     = $this->post();
		$category = null === $post ? null : $this->category( $post );
		if ( null === $category ) {
			return '';
		}
		return $this->wrap( '<a class="aiscs-badge" href="' . esc_url( (string) get_category_link( $category ) ) . '">' . esc_html( $category->name ) . '</a>' );
	}

	/**
	 * Author, date, reading time and last update.
	 */
	public function meta(): string {
		$post = $this->post();
		if ( null === $post ) {
			return '';
		}
		$author = (int) $post->post_author;
		$parts  = array(
			'<span class="aiscs-meta__author">' . get_avatar( $author, 32, '', '', array( 'class' => 'aiscs-meta__avatar' ) )
				/* translators: %s: author name. */
				. sprintf( esc_html__( 'By %s', 'ai-seo-content-studio' ), '<a href="' . esc_url( get_author_posts_url( $author ) ) . '">' . esc_html( get_the_author_meta( 'display_name', $author ) ) . '</a>' ) . '</span>',
			'<span class="aiscs-meta__date"><time datetime="' . esc_attr( (string) get_the_date( 'c', $post ) ) . '">' . esc_html( (string) get_the_date( '', $post ) ) . '</time></span>',
			/* translators: %d: minutes. */
			'<span class="aiscs-meta__time">' . esc_html( sprintf( _n( '%d min read', '%d min read', self::minutes( $post ), 'ai-seo-content-studio' ), self::minutes( $post ) ) ) . '</span>',
		);
		if ( 'publish' === $post->post_status && get_the_modified_date( 'Y-m-d', $post ) !== get_the_date( 'Y-m-d', $post ) ) {
			$parts[] = '<span class="aiscs-meta__updated">' . esc_html__( 'Updated:', 'ai-seo-content-studio' ) . ' <time datetime="' . esc_attr( (string) get_the_modified_date( 'c', $post ) ) . '">' . esc_html( (string) get_the_modified_date( '', $post ) ) . '</time></span>';
		}
		return $this->wrap( '<div class="aiscs-meta">' . implode( '', $parts ) . '</div>' );
	}

	/**
	 * About the author.
	 */
	public function authorBox(): string {
		$post = $this->post();
		if ( null === $post ) {
			return '';
		}
		$author = (int) $post->post_author;
		$name   = (string) get_the_author_meta( 'display_name', $author );
		if ( '' === $name ) {
			return '';
		}
		$bio  = (string) get_the_author_meta( 'description', $author );
		$html = '<div class="aiscs-card aiscs-author"><p class="aiscs-card__title">' . esc_html__( 'About the author', 'ai-seo-content-studio' ) . '</p>'
			. '<div class="aiscs-author__body">' . get_avatar( $author, 96, '', '', array( 'class' => 'aiscs-author__avatar' ) )
			. '<div><p class="aiscs-author__name">' . esc_html( $name ) . '</p>'
			. ( '' !== $bio ? '<p class="aiscs-author__bio">' . esc_html( $bio ) . '</p>' : '' )
			. '<a class="aiscs-author__link" href="' . esc_url( get_author_posts_url( $author ) ) . '">' . esc_html__( 'View all posts', 'ai-seo-content-studio' ) . ' →</a>'
			. '</div></div></div>';
		return $this->wrap( $html );
	}

	/**
	 * Latest published posts with thumbnails.
	 *
	 * @param array<string, string>|string $atts Attributes: count, title.
	 */
	public function recentPosts( $atts = array() ): string {
		$atts  = shortcode_atts(
			array(
				'count' => 4,
				'title' => __( 'Latest posts', 'ai-seo-content-studio' ),
			),
			$atts,
			'aiscs_recent_posts'
		);
		$post  = $this->post();
		$posts = $this->query( array(), null === $post ? 0 : $post->ID, max( 1, min( 8, (int) $atts['count'] ) ) );
		if ( array() === $posts ) {
			return '';
		}
		$items = '';
		foreach ( $posts as $p ) {
			$items .= '<li><a class="aiscs-mini" href="' . esc_url( (string) get_permalink( $p ) ) . '">' . $this->thumb( $p, 'thumbnail', 'aiscs-mini__thumb' )
				. '<span><span class="aiscs-mini__title">' . esc_html( get_the_title( $p ) ) . '</span><span class="aiscs-mini__date">' . esc_html( (string) get_the_date( '', $p ) ) . '</span></span></a></li>';
		}
		return $this->wrap( '<div class="aiscs-card"><p class="aiscs-card__title">' . esc_html( (string) $atts['title'] ) . '</p><ul class="aiscs-minis">' . $items . '</ul></div>' );
	}

	/**
	 * Categories with post counts.
	 *
	 * @param array<string, string>|string $atts Attributes: count, title.
	 */
	public function categories( $atts = array() ): string {
		$atts  = shortcode_atts(
			array(
				'count' => 8,
				'title' => __( 'Categories', 'ai-seo-content-studio' ),
			),
			$atts,
			'aiscs_categories'
		);
		$terms = get_categories(
			array(
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => max( 1, min( 20, (int) $atts['count'] ) ),
				'hide_empty' => true,
			)
		);
		$items = '';
		foreach ( (array) $terms as $term ) {
			if ( $term instanceof \WP_Term && ! $this->isPlaceholder( $term ) ) {
				$items .= '<li><a href="' . esc_url( (string) get_category_link( $term ) ) . '"><span>' . esc_html( $term->name ) . '</span><span class="aiscs-cats__count">' . (int) $term->count . '</span></a></li>';
			}
		}
		if ( '' === $items ) {
			return '';
		}
		return $this->wrap( '<div class="aiscs-card"><p class="aiscs-card__title">' . esc_html( (string) $atts['title'] ) . '</p><ul class="aiscs-cats">' . $items . '</ul></div>' );
	}

	/**
	 * Related posts (same categories first, then the latest posts) as cards.
	 *
	 * @param array<string, string>|string $atts Attributes: count, title.
	 */
	public function relatedPosts( $atts = array() ): string {
		$atts  = shortcode_atts(
			array(
				'count' => 4,
				'title' => __( 'You might also like', 'ai-seo-content-studio' ),
			),
			$atts,
			'aiscs_related_posts'
		);
		$post  = $this->post();
		$count = max( 1, min( 8, (int) $atts['count'] ) );
		$self  = null === $post ? 0 : $post->ID;
		$cats  = null === $post ? array() : wp_get_post_categories( $post->ID );
		$posts = array() === $cats ? array() : $this->query( array( 'category__in' => $cats ), $self, $count );
		if ( count( $posts ) < $count ) {
			$seen  = array_merge( array( $self ), wp_list_pluck( $posts, 'ID' ) );
			$posts = array_merge( $posts, $this->query( array( 'post__not_in' => $seen ), 0, $count - count( $posts ) ) );
		}
		if ( array() === $posts ) {
			return '';
		}
		$cards = '';
		foreach ( $posts as $p ) {
			$cards .= '<a class="aiscs-related__card" href="' . esc_url( (string) get_permalink( $p ) ) . '">' . $this->thumb( $p, 'medium_large', 'aiscs-related__thumb' )
				. '<span class="aiscs-related__body"><span class="aiscs-related__title">' . esc_html( get_the_title( $p ) ) . '</span>'
				/* translators: %d: minutes. */
				. '<span class="aiscs-related__meta">' . esc_html( (string) get_the_date( '', $p ) ) . ' · ' . esc_html( sprintf( _n( '%d min read', '%d min read', self::minutes( $p ), 'ai-seo-content-studio' ), self::minutes( $p ) ) ) . '</span></span></a>';
		}
		return $this->wrap( '<section class="aiscs-related"><h2 class="aiscs-related__heading">' . esc_html( (string) $atts['title'] ) . '</h2><div class="aiscs-related__grid">' . $cards . '</div></section>' );
	}

	/**
	 * Estimated reading time in minutes (at least 1).
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function minutes( \WP_Post $post ): int {
		$words = count( preg_split( '/\s+/u', trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() );
		return max( 1, (int) ceil( $words / self::WORDS_PER_MINUTE ) );
	}

	/**
	 * Current post.
	 */
	private function post(): ?\WP_Post {
		$post = get_post();
		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Main category: the SEO plugin's primary category when set, else the first one.
	 * The default "Uncategorized" category is not shown.
	 *
	 * @param \WP_Post $post Post.
	 */
	private function category( \WP_Post $post ): ?\WP_Term {
		$terms = get_the_category( $post->ID );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return null;
		}
		foreach ( array( '_yoast_wpseo_primary_category', 'rank_math_primary_category' ) as $key ) {
			$primary = (int) get_post_meta( $post->ID, $key, true );
			foreach ( $terms as $term ) {
				if ( $primary > 0 && $term->term_id === $primary ) {
					return $term;
				}
			}
		}
		foreach ( $terms as $term ) {
			if ( ! $this->isPlaceholder( $term ) ) {
				return $term;
			}
		}
		return null;
	}

	/**
	 * The untouched default category ("Uncategorized").
	 *
	 * @param \WP_Term $term Term.
	 */
	private function isPlaceholder( \WP_Term $term ): bool {
		return (int) get_option( 'default_category' ) === $term->term_id && 'uncategorized' === $term->slug;
	}

	/**
	 * Published posts.
	 *
	 * @param array<string, mixed> $args    Extra query args.
	 * @param int                  $exclude Post to leave out.
	 * @param int                  $count   Number of posts.
	 * @return \WP_Post[]
	 */
	private function query( array $args, int $exclude, int $count ): array {
		$args += array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => $count,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'suppress_filters'    => false,
		);
		if ( $exclude > 0 ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), array( $exclude ) );
		}
		return array_values( array_filter( get_posts( $args ), static fn ( $p ): bool => $p instanceof \WP_Post ) );
	}

	/**
	 * Featured image, or a coloured placeholder with the first letter of the title.
	 *
	 * @param \WP_Post $post  Post.
	 * @param string   $size  Image size.
	 * @param string   $css_class CSS class.
	 */
	private function thumb( \WP_Post $post, string $size, string $css_class ): string {
		if ( has_post_thumbnail( $post ) ) {
			return (string) get_the_post_thumbnail(
				$post,
				$size,
				array(
					'class'   => $css_class,
					'loading' => 'lazy',
					'alt'     => '',
				)
			);
		}
		return '<span class="' . esc_attr( $css_class ) . ' aiscs-thumb--empty" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( get_the_title( $post ), 0, 1 ) ) ) . '</span>';
	}

	/**
	 * Load the styles wherever a shortcode is used.
	 *
	 * @param string $html Markup.
	 */
	private function wrap( string $html ): string {
		$id = get_the_ID();
		self::enqueue( $id ? (string) get_post_meta( (int) $id, BlogTemplates::META_ACCENT, true ) : '' );
		return $html;
	}
}
