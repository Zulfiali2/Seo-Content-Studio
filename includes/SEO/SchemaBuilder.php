<?php
/**
 * JSON-LD structured data for plugin drafts once published.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Builds only types the plugin can fill truthfully from WordPress data:
 * BlogPosting, Article, WebPage and Service. Product is recommended in the UI
 * but never output, because valid Product markup needs offers or reviews the
 * plugin does not have. FAQPage is built separately (see faqPage()) from the
 * FAQs that are visible on the page.
 */
final class SchemaBuilder {

	/**
	 * Build JSON-LD data for a post, or null.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $type Schema type.
	 * @return array<string, mixed>|null
	 */
	public static function build( \WP_Post $post, string $type ): ?array {
		$url         = (string) get_permalink( $post );
		$description = (string) get_post_meta( $post->ID, '_aiscs_meta_description', true );
		$title       = wp_strip_all_tags( get_the_title( $post ) );

		switch ( $type ) {
			case 'BlogPosting':
			case 'Article':
				$data  = array(
					'@context'         => 'https://schema.org',
					'@type'            => $type,
					'headline'         => mb_substr( $title, 0, 110 ),
					'datePublished'    => get_post_time( 'c', true, $post ),
					'dateModified'     => get_post_modified_time( 'c', true, $post ),
					'mainEntityOfPage' => $url,
					'author'           => array(
						'@type' => 'Person',
						'name'  => get_the_author_meta( 'display_name', (int) $post->post_author ),
					),
					'publisher'        => array(
						'@type' => 'Organization',
						'name'  => get_bloginfo( 'name' ),
					),
				);
				$image = get_the_post_thumbnail_url( $post, 'full' );
				if ( $image ) {
					$data['image'] = $image;
				}
				break;
			case 'WebPage':
				$data = array(
					'@context' => 'https://schema.org',
					'@type'    => 'WebPage',
					'name'     => $title,
					'url'      => $url,
				);
				break;
			case 'Service':
				$data = array(
					'@context' => 'https://schema.org',
					'@type'    => 'Service',
					'name'     => $title,
					'url'      => $url,
					'provider' => array(
						'@type' => 'Organization',
						'name'  => get_bloginfo( 'name' ),
						'url'   => home_url( '/' ),
					),
				);
				$area = (string) get_post_meta( $post->ID, '_aiscs_area_served', true );
				if ( '' !== $area ) {
					$data['areaServed'] = $area;
				}
				break;
			default:
				return null;
		}
		if ( '' !== $description ) {
			$data['description'] = $description;
		}
		return $data;
	}

	/**
	 * FAQPage JSON-LD from the FAQs saved with the draft, or null when it has none.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>|null
	 */
	public static function faqPage( \WP_Post $post ): ?array {
		$entities = array();
		foreach ( (array) get_post_meta( $post->ID, '_aiscs_faqs', true ) as $faq ) {
			$question = is_array( $faq ) ? trim( (string) ( $faq['question'] ?? '' ) ) : '';
			$answer   = is_array( $faq ) ? trim( (string) ( $faq['answer'] ?? '' ) ) : '';
			if ( '' !== $question && '' !== $answer ) {
				$entities[] = array(
					'@type'          => 'Question',
					'name'           => $question,
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $answer,
					),
				);
			}
		}
		if ( array() === $entities ) {
			return null;
		}
		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'url'        => (string) get_permalink( $post ),
			'mainEntity' => $entities,
		);
	}
}
