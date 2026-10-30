<?php
/**
 * Reads an existing post for optimisation (read-only).
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Optimizer;

use AiSeoContentStudio\Content\ContentModel;

defined( 'ABSPATH' ) || exit;

/**
 * Detects the content format and converts the post into the plugin's content
 * structure (intro + H2 sections). The source post is never written to.
 *
 * Elementor pages are read from the HTML copy Elementor keeps in post_content.
 * The original is never edited in place: the revision is saved as a new draft
 * (an Elementor draft when Elementor is active, otherwise a standard draft).
 */
final class SourceContent {

	/**
	 * Format of a post: blocks, classic or elementor.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function format( \WP_Post $post ): string {
		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			return 'elementor';
		}
		return has_blocks( $post->post_content ) ? 'blocks' : 'classic';
	}

	/**
	 * Body HTML: blocks rendered, shortcodes stripped, then restricted to safe tags.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{html: string, links: string[]}
	 */
	public static function html( \WP_Post $post ): array {
		$raw   = has_blocks( $post->post_content ) ? do_blocks( $post->post_content ) : wpautop( $post->post_content );
		$raw   = strip_shortcodes( $raw );
		$links = self::links( $raw );
		return array(
			'html'  => ContentModel::cleanHtml( (string) preg_replace( '#<h1\b[^>]*>.*?</h1>#is', '', $raw ), $links ),
			'links' => $links,
		);
	}

	/**
	 * Links in the source that point to this site (kept during rewriting).
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	public static function links( string $html ): array {
		preg_match_all( '#<a\s[^>]*href="([^"]+)"#i', $html, $m );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return array_values( array_unique( array_filter( $m[1], static fn ( $u ) => (string) wp_parse_url( html_entity_decode( $u ), PHP_URL_HOST ) === $host ) ) );
	}

	/**
	 * Split HTML at H2 headings into the content structure.
	 *
	 * @param string               $title Post title.
	 * @param string               $html  Clean HTML.
	 * @param array<string, mixed> $meta  Meta values (meta_title, meta_description, slug).
	 * @return array<string, mixed>
	 */
	public static function toContent( string $title, string $html, array $meta = array() ): array {
		$parts    = preg_split( '#(<h2\b[^>]*>.*?</h2>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array( $html );
		$intro    = trim( (string) array_shift( $parts ) );
		$sections = array();
		$total    = count( $parts );
		for ( $i = 0; $i < $total; $i += 2 ) {
			$heading    = trim( wp_strip_all_tags( (string) $parts[ $i ] ) );
			$sections[] = ContentModel::part(
				'section-' . ( count( $sections ) + 1 ),
				array(
					'heading'     => $heading,
					'notes'       => '',
					'subheadings' => array(),
					'html'        => trim( (string) ( $parts[ $i + 1 ] ?? '' ) ),
					'status'      => 'draft',
				)
			);
		}
		$content             = ContentModel::fromBrief(
			array(
				'title' => $title,
				'h1'    => $title,
			)
		);
		$content['intro']    = ContentModel::part(
			'intro',
			array(
				'html'   => $intro,
				'status' => '' === $intro ? 'empty' : 'draft',
			)
		);
		$content['sections'] = $sections;
		$content['meta']     = array_merge( $content['meta'], $meta );
		return $content;
	}
}
