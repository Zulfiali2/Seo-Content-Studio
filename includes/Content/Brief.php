<?php
/**
 * Content brief data model.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Content;

use AiSeoContentStudio\Keywords\KeywordPlan;
use AiSeoContentStudio\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Editable content brief. All fields are plain text; HTML is stripped.
 */
final class Brief {

	public const CONTENT_TYPES = array( 'blog_post', 'informational_article', 'service_page', 'landing_page', 'product_description', 'local_seo_page' );

	/**
	 * Sanitise a brief from untrusted input (REST or AI output).
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$length = is_array( $input['length'] ?? null ) ? $input['length'] : array();
		$min    = max( 0, min( 20000, (int) ( $length['min'] ?? 0 ) ) );
		$max    = max( $min, min( 20000, (int) ( $length['max'] ?? 0 ) ) );

		$outline = array();
		foreach ( array_slice( (array) ( $input['outline'] ?? array() ), 0, 60 ) as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$heading = Sanitize::text( $section['heading'] ?? '', 200 );
			if ( '' === $heading ) {
				continue;
			}
			$outline[] = array(
				'level'   => 3 === (int) ( $section['level'] ?? 2 ) ? 3 : 2,
				'heading' => $heading,
				'notes'   => Sanitize::textarea( $section['notes'] ?? '', 1000 ),
			);
		}

		$faqs = array();
		foreach ( array_slice( (array) ( $input['faqs'] ?? array() ), 0, 20 ) as $faq ) {
			$question = is_array( $faq ) ? Sanitize::text( $faq['question'] ?? '', 300 ) : Sanitize::text( $faq, 300 );
			if ( '' !== $question ) {
				$faqs[] = array(
					'question' => $question,
					'notes'    => is_array( $faq ) ? Sanitize::textarea( $faq['notes'] ?? '', 600 ) : '',
				);
			}
		}

		$links = array();
		foreach ( array_slice( (array) ( $input['internal_links'] ?? array() ), 0, 20 ) as $link ) {
			if ( is_array( $link ) && (int) ( $link['post_id'] ?? 0 ) > 0 ) {
				$links[] = array(
					'post_id' => (int) $link['post_id'],
					'title'   => Sanitize::text( $link['title'] ?? '', 200 ),
					'url'     => esc_url_raw( (string) ( $link['url'] ?? '' ) ),
					'anchor'  => Sanitize::text( $link['anchor'] ?? '', 120 ),
				);
			}
		}

		$sources = array();
		foreach ( array_slice( (array) ( $input['external_sources'] ?? array() ), 0, 15 ) as $source ) {
			$description = is_array( $source ) ? Sanitize::text( $source['description'] ?? '', 300 ) : Sanitize::text( $source, 300 );
			if ( '' !== $description ) {
				$sources[] = array(
					'description' => $description,
					'note'        => is_array( $source ) ? Sanitize::text( $source['note'] ?? '', 300 ) : '',
				);
			}
		}

		return array(
			'target_keyword'          => Sanitize::text( $input['target_keyword'] ?? '', 200 ),
			'search_intent'           => Sanitize::enum( $input['search_intent'] ?? '', KeywordPlan::INTENTS ),
			'audience'                => Sanitize::textarea( $input['audience'] ?? '', 500 ),
			'content_type'            => Sanitize::enum( $input['content_type'] ?? '', self::CONTENT_TYPES, 'blog_post' ),
			'title'                   => Sanitize::text( $input['title'] ?? '', 200 ),
			'slug'                    => sanitize_title( (string) ( $input['slug'] ?? '' ) ),
			'meta_title'              => Sanitize::text( $input['meta_title'] ?? '', 120 ),
			'meta_description'        => Sanitize::text( $input['meta_description'] ?? '', 320 ),
			'h1'                      => Sanitize::text( $input['h1'] ?? '', 200 ),
			'outline'                 => $outline,
			'key_topics'              => Sanitize::strings( $input['key_topics'] ?? array(), 40, 200 ),
			'faqs'                    => $faqs,
			'length'                  => array(
				'min'       => $min,
				'max'       => $max,
				'rationale' => Sanitize::text( $length['rationale'] ?? '', 400 ),
			),
			'competitor_observations' => Sanitize::strings( $input['competitor_observations'] ?? array(), 20, 400 ),
			'content_gaps'            => Sanitize::strings( $input['content_gaps'] ?? array(), 20, 400 ),
			'differentiation'         => Sanitize::strings( $input['differentiation'] ?? array(), 20, 400 ),
			'ctas'                    => Sanitize::strings( $input['ctas'] ?? array(), 10, 200 ),
			'internal_links'          => $links,
			'external_sources'        => $sources,
			'verification_notes'      => Sanitize::strings( $input['verification_notes'] ?? array(), 20, 400 ),
		);
	}
}
