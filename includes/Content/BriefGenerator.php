<?php
/**
 * Prompt, schema and post-processing for content brief generation.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Content;

use AiSeoContentStudio\Keywords\KeywordPlan;
use AiSeoContentStudio\Providers\Contracts\Message;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Research\Contracts\Market;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the brief request from the approved keyword plan and research, and
 * turns the AI output into a sanitised Brief. Internal links are only accepted
 * for candidate posts that actually exist on this site.
 */
final class BriefGenerator {

	/**
	 * Strict-mode compatible schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$strings = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
		$object  = static fn ( array $props ): array => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array_keys( $props ),
			'properties'           => $props,
		);
		$str     = array( 'type' => 'string' );
		$int     = array( 'type' => 'integer' );

		return $object(
			array(
				'title'                   => $str,
				'slug'                    => $str,
				'meta_title'              => $str,
				'meta_description'        => $str,
				'h1'                      => $str,
				'search_intent'           => array(
					'type' => 'string',
					'enum' => KeywordPlan::INTENTS,
				),
				'audience'                => $str,
				'outline'                 => array(
					'type'  => 'array',
					'items' => $object(
						array(
							'level'   => $int,
							'heading' => $str,
							'notes'   => $str,
						)
					),
				),
				'key_topics'              => $strings,
				'faqs'                    => array(
					'type'  => 'array',
					'items' => $object(
						array(
							'question' => $str,
							'notes'    => $str,
						)
					),
				),
				'length'                  => $object(
					array(
						'min'       => $int,
						'max'       => $int,
						'rationale' => $str,
					)
				),
				'competitor_observations' => $strings,
				'content_gaps'            => $strings,
				'differentiation'         => $strings,
				'ctas'                    => $strings,
				'internal_links'          => array(
					'type'  => 'array',
					'items' => $object(
						array(
							'post_id' => $int,
							'anchor'  => $str,
						)
					),
				),
				'external_sources'        => array(
					'type'  => 'array',
					'items' => $object(
						array(
							'description' => $str,
							'note'        => $str,
						)
					),
				),
				'verification_notes'      => $strings,
			)
		);
	}

	/**
	 * Build the request.
	 *
	 * @param array<string, mixed>             $inputs     Project inputs.
	 * @param array<string, mixed>             $plan       Keyword plan.
	 * @param array<string, mixed>             $research   Research.
	 * @param array<int, array<string, mixed>> $candidates Internal link candidates.
	 * @param int                              $max_tokens Output cap.
	 * @param WritingProfile|null              $profile    Business profile and writing style.
	 */
	public static function request( array $inputs, array $plan, array $research, array $candidates, int $max_tokens = 6000, ?WritingProfile $profile = null ): TextRequest {
		$profile = $profile ?? WritingProfile::fromArray( array() );
		$extra   = array(
			'- FAQs: the FAQ list is the only question-and-answer block. Never plan an H2 called "FAQ", "Frequently asked questions", "Common questions" or similar, and do not repeat an FAQ question as a heading.',
			'- Use H3 sub-sections (level 3) for distinct sub-topics, and say in the notes where a bullet list, numbered steps or a table helps the reader.',
			'- Plan each idea, definition and example for exactly one section, so sections do not repeat each other; say in the notes what each section covers that the others do not.',
		);
		if ( ! empty( $profile->style( 'worked_example' ) ) && in_array( (string) ( $inputs['content_type'] ?? '' ), array( 'blog_post', 'informational_article' ), true ) ) {
			$extra[] = '- Worked example: only if the topic is a process, calculation or decision the reader carries out step by step (for example a how-to, a cost calculation or a comparison with figures), plan one H2 section with an original, realistic scenario (clearly illustrative) that runs the whole process from start to finish, every step and the final check. Say in its notes exactly what it must show. No other section starts a second example. For list, review, opinion, news or general explainer topics plan no worked example section.';
		}
		if ( '' !== $profile->brand() ) {
			$extra[] = '- "business" is the site owner\'s business. Use its services and contact page for ctas. If its services fit the topic, you may plan one short section near the end on how the business helps, listing only the given services.';
		}
		if ( (int) ( $inputs['length_max'] ?? 0 ) > 0 ) {
			$extra[] = sprintf( '- length: the site owner wants %d-%d words for the whole article including FAQs. Plan the number of sections and FAQs to fit.', (int) ( $inputs['length_min'] ?? 0 ), (int) $inputs['length_max'] );
		}
		$system = implode(
			"\n",
			array(
				'You are a senior SEO content strategist writing a content brief for a writer.',
				'Rules:',
				'- Base the brief on the approved keywords and research provided. Do not invent statistics, sources, studies, quotes, testimonials, credentials or first-hand experience.',
				'- Structure: one H1, then H2 sections with optional H3 sub-sections (outline level 2 or 3) in a logical order that satisfies the search intent.',
				'- Use the primary keyword naturally in the title, H1, meta title and meta description. Spread approved secondary and semantic terms across headings and notes where they fit; never force them or recommend a keyword density.',
				'- meta_title: about 50-60 characters. meta_description: about 140-160 characters, accurate and specific. slug: short, lowercase, hyphenated.',
				'- length: a realistic word-count range for this content type and intent, informed by the research; explain briefly. It is guidance, not a ranking guarantee.',
				'- FAQs: only questions a searcher genuinely asks, using the approved questions where relevant.',
				'- competitor_observations / content_gaps / differentiation: specific and grounded in the research; do not copy competitor wording.',
				'- internal_links: choose only from the candidate pages provided, by post_id, when genuinely relevant; otherwise return an empty list.',
				'- external_sources: describe the TYPE of authoritative source to cite (e.g. "official regulator guidance on X"); never invent URLs or titles. Mark each as needing verification.',
				'- verification_notes: list any claims or facts the writer must verify before publishing.',
				...QualityGuidelines::planning(),
				...$extra,
				'- Write the brief in ' . Market::languageOf( $inputs ) . '.',
			)
		);

		$approved = array();
		foreach ( KeywordPlan::GROUPS as $group ) {
			$approved[ $group ] = KeywordPlan::approved( $plan, $group );
		}

		$context = array(
			'primary_keyword'          => (string) ( $plan['primary']['keyword'] ?? $inputs['keyword'] ?? '' ),
			'search_intent'            => (string) ( $plan['intent']['value'] ?? '' ),
			'content_type'             => (string) ( $inputs['content_type'] ?? '' ),
			'market'                   => (string) ( $inputs['market']['location_name'] ?? '' ),
			'audience'                 => (string) ( $inputs['audience'] ?? '' ),
			'notes_from_user'          => (string) ( $inputs['notes'] ?? '' ),
			'approved'                 => $approved,
			'excluded_terms'           => (array) ( $plan['excluded'] ?? array() ),
			'research_summary'         => array(
				'content_gaps'           => (array) ( $research['analysis']['content_gaps'] ?? array() ),
				'differentiation_ideas'  => (array) ( $research['analysis']['differentiation_ideas'] ?? array() ),
				'common_topics'          => (array) ( $research['analysis']['common_topics'] ?? array() ),
				'recommended_length'     => $research['analysis']['recommended_length'] ?? null,
				'serp_titles'            => array_map( static fn ( $r ) => (string) ( $r['title'] ?? '' ), array_slice( (array) ( $research['serp']['organic'] ?? array() ), 0, 10 ) ),
				'competitor_word_counts' => array_values(
					array_filter(
						array_map( static fn ( $c ) => (int) ( $c['outline']['word_count'] ?? 0 ), (array) ( $research['competitors'] ?? array() ) )
					)
				),
			),
			'required_terms'           => array_column( KeywordPlan::required( $plan ), 'text' ),
			'internal_link_candidates' => array_map(
				static fn ( array $c ): array => array(
					'post_id' => $c['post_id'],
					'title'   => $c['title'],
				),
				$candidates
			),
		);

		$context += $profile->context();

		return new TextRequest(
			array( Message::user( "Brief inputs (JSON):\n" . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n\nReturn the content brief as JSON matching the schema." ) ),
			$system,
			$max_tokens,
			null,
			self::schema(),
			'content_brief',
			'content_brief'
		);
	}

	/**
	 * Turn AI output into a sanitised brief.
	 *
	 * @param array<string, mixed>             $data       AI output.
	 * @param array<string, mixed>             $inputs     Project inputs.
	 * @param array<string, mixed>             $plan       Keyword plan.
	 * @param array<int, array<string, mixed>> $candidates Internal link candidates.
	 * @return array<string, mixed>
	 */
	public static function toBrief( array $data, array $inputs, array $plan, array $candidates ): array {
		$by_id = array();
		foreach ( $candidates as $candidate ) {
			$by_id[ (int) $candidate['post_id'] ] = $candidate;
		}
		$links = array();
		foreach ( (array) ( $data['internal_links'] ?? array() ) as $link ) {
			$id = (int) ( $link['post_id'] ?? 0 );
			if ( isset( $by_id[ $id ] ) ) {
				$links[] = array(
					'post_id' => $id,
					'title'   => $by_id[ $id ]['title'],
					'url'     => $by_id[ $id ]['url'],
					'anchor'  => (string) ( $link['anchor'] ?? '' ),
				);
			}
		}
		$data['internal_links'] = $links;
		$data['target_keyword'] = (string) ( $plan['primary']['keyword'] ?? $inputs['keyword'] ?? '' );
		$data['outline']        = self::withoutFaqSections( (array) ( $data['outline'] ?? array() ), (array) ( $data['faqs'] ?? array() ), $data['faqs'] );
		if ( (int) ( $inputs['length_max'] ?? 0 ) > 0 ) {
			$data['length'] = array(
				'min'       => (int) ( $inputs['length_min'] ?? 0 ),
				'max'       => (int) $inputs['length_max'],
				'rationale' => __( 'Length set on the keyword step.', 'ai-seo-content-studio' ),
			);
		}
		$data['content_type'] = (string) ( $inputs['content_type'] ?? 'blog_post' );
		if ( '' === trim( (string) ( $data['audience'] ?? '' ) ) ) {
			$data['audience'] = (string) ( $inputs['audience'] ?? '' );
		}
		return Brief::sanitize( $data );
	}

	/**
	 * Drop H2 sections that are an FAQ block when the brief has an FAQ list (the page already
	 * shows the FAQs once). Their question sub-headings move to the FAQ list if new.
	 *
	 * @param array<int, mixed> $outline Outline.
	 * @param array<int, mixed> $faqs    FAQ list.
	 * @param mixed             $out     FAQ list after the move (by reference).
	 * @return array<int, mixed>
	 */
	public static function withoutFaqSections( array $outline, array $faqs, mixed &$out ): array {
		$out = $faqs;
		if ( array() === $faqs ) {
			return $outline;
		}
		$known = array();
		foreach ( $faqs as $faq ) {
			$known[ mb_strtolower( trim( (string) ( is_array( $faq ) ? ( $faq['question'] ?? '' ) : $faq ) ) ) ] = true;
		}
		$result = array();
		$skip   = false;
		foreach ( $outline as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$heading = (string) ( $item['heading'] ?? '' );
			if ( 3 !== (int) ( $item['level'] ?? 2 ) ) {
				$skip = (bool) preg_match( '/\b(faqs?|frequently asked|common questions|questions and answers|q\s*&\s*a)\b/i', $heading );
				if ( $skip ) {
					continue;
				}
			} elseif ( $skip ) {
				if ( str_ends_with( trim( $heading ), '?' ) && ! isset( $known[ mb_strtolower( trim( $heading ) ) ] ) ) {
					$out[]                                      = array(
						'question' => $heading,
						'notes'    => (string) ( $item['notes'] ?? '' ),
					);
					$known[ mb_strtolower( trim( $heading ) ) ] = true;
				}
				continue;
			}
			$result[] = $item;
		}
		return $result;
	}
}
