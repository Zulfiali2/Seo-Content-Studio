<?php
/**
 * Finds pages on this site that already target the same keyword.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Two pages aimed at the same search compete with each other (keyword cannibalization), and
 * Google usually ranks neither well. A page counts as targeting the keyword when its title or
 * slug contains it, or when Yoast SEO or Rank Math has it as the focus keyword. Published and
 * scheduled posts and pages are checked; the project's own draft and the page an optimisation
 * started from are left out.
 */
final class Cannibalization {

	/** Pages listed at most. */
	private const LIMIT = 5;

	/**
	 * Pages that already target the keyword.
	 *
	 * @param string $keyword Primary keyword.
	 * @param int[]  $exclude Post ids to leave out.
	 * @return array<int, array{id: int, title: string, url: string, reason: string}>
	 */
	public static function find( string $keyword, array $exclude = array() ): array {
		$needle = self::normalise( $keyword );
		if ( mb_strlen( $needle ) < 3 || ! class_exists( '\WP_Query' ) ) {
			return array();
		}
		$exclude = array_values( array_filter( array_map( 'intval', $exclude ) ) );
		$base    = array(
			'post_type'              => array( 'post', 'page' ),
			'post_status'            => array( 'publish', 'future' ),
			'posts_per_page'         => 20,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'post__not_in'           => $exclude,
		);
		$slug    = sanitize_title( $keyword );
		$found   = array();

		// Title or slug contains the keyword (WordPress search narrows the candidates first).
		foreach ( ( new \WP_Query( $base + array( 's' => $keyword ) ) )->posts as $post ) {
			$title = self::normalise( wp_strip_all_tags( get_the_title( $post ) ) );
			if ( str_contains( $title, $needle ) ) {
				$found[ $post->ID ] = self::row( $post, __( 'Title contains the keyword', 'ai-seo-content-studio' ) );
			} elseif ( '' !== $slug && ( $post->post_name === $slug || str_starts_with( $post->post_name, $slug . '-' ) ) ) {
				$found[ $post->ID ] = self::row( $post, __( 'URL contains the keyword', 'ai-seo-content-studio' ) );
			}
		}

		// Focus keyword set in Yoast SEO or Rank Math (Rank Math stores a comma-separated list).
		$meta = new \WP_Query(
			$base + array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one bounded lookup when checking a project.
					'relation' => 'OR',
					array(
						'key'     => '_yoast_wpseo_focuskw',
						'value'   => $keyword,
						'compare' => '=',
					),
					array(
						'key'     => 'rank_math_focus_keyword',
						'value'   => $keyword,
						'compare' => 'LIKE',
					),
				),
			)
		);
		foreach ( $meta->posts as $post ) {
			if ( isset( $found[ $post->ID ] ) ) {
				continue;
			}
			$focus = array_merge(
				array( (string) get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true ) ),
				explode( ',', (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true ) )
			);
			foreach ( $focus as $term ) {
				if ( self::normalise( $term ) === $needle ) {
					$found[ $post->ID ] = self::row( $post, __( 'Focus keyword in the SEO plugin', 'ai-seo-content-studio' ) );
					break;
				}
			}
		}

		return array_slice( array_values( $found ), 0, self::LIMIT );
	}

	/**
	 * One line per page for messages and reports: "Title (URL), reason".
	 *
	 * @param array<int, array{id: int, title: string, url: string, reason: string}> $pages Pages.
	 * @return string[]
	 */
	public static function lines( array $pages ): array {
		return array_map(
			static fn ( array $p ): string => sprintf( '%s (%s): %s', $p['title'], $p['url'], $p['reason'] ),
			$pages
		);
	}

	/**
	 * A found page.
	 *
	 * @param \WP_Post $post   Post.
	 * @param string   $reason Why it matches.
	 * @return array{id: int, title: string, url: string, reason: string}
	 */
	private static function row( \WP_Post $post, string $reason ): array {
		return array(
			'id'     => (int) $post->ID,
			'title'  => wp_strip_all_tags( get_the_title( $post ) ),
			'url'    => (string) get_permalink( $post ),
			'reason' => $reason,
		);
	}

	/**
	 * Lower case, letters and digits only, single spaces.
	 *
	 * @param string $text Text.
	 */
	private static function normalise( string $text ): string {
		return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( $text ) ) );
	}
}
