<?php
/**
 * Prompt and schema for AI analysis of research data.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research;

use AiSeoContentStudio\Keywords\KeywordPlan;
use AiSeoContentStudio\Providers\Contracts\Message;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Research\Contracts\Market;
use AiSeoContentStudio\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the analysis request from collected research and sanitises the result.
 *
 * The schema avoids keywords that some providers' structured-output modes reject
 * (min/max constraints); limits are applied after validation instead.
 */
final class ResearchAnalysis {

	/** Maximum characters of research context sent to the model. */
	private const MAX_CONTEXT = 30000;

	/**
	 * JSON schema (strict-mode compatible: every property required, no extras).
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$strings = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'search_intent', 'secondary_keywords', 'semantic_variations', 'entities', 'questions', 'common_topics', 'content_gaps', 'differentiation_ideas', 'geo_modifiers', 'recommended_length', 'placement_guidance', 'notes', 'competitor_page_keywords' ),
			'properties'           => array(
				'competitor_page_keywords' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'url', 'keywords' ),
						'properties'           => array(
							'url'      => array( 'type' => 'string' ),
							'keywords' => $strings,
						),
					),
				),
				'search_intent'            => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'value', 'rationale' ),
					'properties'           => array(
						'value'     => array(
							'type' => 'string',
							'enum' => KeywordPlan::INTENTS,
						),
						'rationale' => array( 'type' => 'string' ),
					),
				),
				'secondary_keywords'       => $strings,
				'semantic_variations'      => $strings,
				'entities'                 => $strings,
				'questions'                => $strings,
				'common_topics'            => $strings,
				'content_gaps'             => $strings,
				'differentiation_ideas'    => $strings,
				'geo_modifiers'            => $strings,
				'recommended_length'       => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'min', 'max', 'rationale' ),
					'properties'           => array(
						'min'       => array( 'type' => 'integer' ),
						'max'       => array( 'type' => 'integer' ),
						'rationale' => array( 'type' => 'string' ),
					),
				),
				'placement_guidance'       => $strings,
				'notes'                    => $strings,
			),
		);
	}

	/**
	 * Build the request.
	 *
	 * @param array<string, mixed> $inputs   Project inputs.
	 * @param array<string, mixed> $research Research section.
	 * @param int                  $max_tokens Output cap.
	 */
	public static function request( array $inputs, array $research, int $max_tokens = 4000 ): TextRequest {
		$system = implode(
			"\n",
			array(
				'You are an SEO content strategist preparing keyword research for a new page.',
				'Rules:',
				'- Use only the research data provided. Never invent search volumes, difficulty scores, rankings, traffic or other metrics; do not output any numbers about search demand.',
				'- Your keyword suggestions are AI-generated semantic suggestions. Do not describe them as an official Google or NLP keyword list.',
				'- Suggest keywords a real searcher would use; avoid near-duplicates of the primary keyword and avoid keyword stuffing.',
				'- Entities are people, organisations, products, places, standards or concepts that a complete page on the topic would naturally mention.',
				'- Questions must be genuine searcher questions relevant to the intent.',
				'- Content gaps and differentiation ideas must be specific and grounded in what the competitor outlines cover or miss. Do not copy competitor wording. Favour ideas that add information the top results lack (information gain) over restating them.',
				'- competitor_ranking_keywords (when present) are real keywords the top-ranking pages rank for; use the relevant ones to inform secondary keywords, questions and gaps.',
				'- recommended_length: a realistic word-count range for this content type and intent, justified by the competitor word counts when available. It is guidance, not a ranking requirement.',
				'- placement_guidance: short, natural keyword placement advice (title, H1, intro, headings, meta description). Never recommend a keyword density.',
				'- competitor_page_keywords: only when competitor_ranking_keywords is NOT provided: for each competitor outline (same url), up to 10 search keywords the page clearly targets, judged from its title and headings. These are your reading of the page, not ranking data, and carry no numbers. Otherwise return an empty list.',
				'- When serp_top_results is "unavailable", base suggestions on your knowledge of how people search for this topic in the market and on the competitor outlines provided.',
				'- Write in ' . Market::languageOf( $inputs ) . '. Keep each list to the most useful items (at most 15).',
			)
		);

		$context = array(
			'primary_keyword' => (string) ( $inputs['keyword'] ?? '' ),
			'market'          => (string) ( $inputs['market']['location_name'] ?? '' ),
			'language'        => (string) ( $inputs['market']['language_code'] ?? 'en' ),
			'content_type'    => (string) ( $inputs['content_type'] ?? '' ),
			'audience'        => (string) ( $inputs['audience'] ?? '' ),
			'notes_from_user' => (string) ( $inputs['notes'] ?? '' ),
		);

		$serp = is_array( $research['serp'] ?? null ) ? $research['serp'] : null;
		if ( $serp ) {
			$context['serp_top_results'] = array_map(
				static fn ( array $r ): array => array(
					'rank'        => $r['rank'] ?? 0,
					'domain'      => $r['domain'] ?? '',
					'title'       => $r['title'] ?? '',
					'description' => $r['description'] ?? '',
				),
				array_slice( (array) ( $serp['organic'] ?? array() ), 0, 10 )
			);
			$context['people_also_ask']  = array_slice( (array) ( $serp['questions'] ?? array() ), 0, 15 );
			$context['related_searches'] = array_slice( (array) ( $serp['related_searches'] ?? array() ), 0, 15 );
		} else {
			$context['serp_top_results'] = 'unavailable';
		}

		$related = (array) ( $research['related'] ?? array() );
		if ( array() !== $related ) {
			$context['provider_related_keywords'] = array_map( static fn ( array $k ): string => (string) ( $k['keyword'] ?? '' ), array_slice( $related, 0, 40 ) );
		}

		// Keywords the top competitor pages rank for (terms only; metrics stay out of the AI's hands).
		$ranked = array();
		foreach ( (array) ( $research['competitor_keywords'] ?? array() ) as $page ) {
			foreach ( (array) ( $page['keywords'] ?? array() ) as $k ) {
				$term = mb_strtolower( trim( (string) ( $k['keyword'] ?? '' ) ) );
				if ( '' !== $term ) {
					$ranked[ $term ] = true;
				}
			}
		}
		if ( array() !== $ranked ) {
			$context['competitor_ranking_keywords'] = array_slice( array_keys( $ranked ), 0, 60 );
		}

		$outlines = array();
		foreach ( (array) ( $research['competitors'] ?? array() ) as $page ) {
			if ( 'fetched' !== ( $page['status'] ?? '' ) || ! is_array( $page['outline'] ?? null ) ) {
				continue;
			}
			$o          = $page['outline'];
			$outlines[] = array(
				'url'          => $page['url'],
				'title'        => $o['title'] ?? '',
				'h1'           => $o['h1'] ?? array(),
				'headings'     => array_map( static fn ( array $h ): string => str_repeat( '#', (int) $h['level'] ) . ' ' . $h['text'], array_slice( (array) ( $o['headings'] ?? array() ), 0, 40 ) ),
				'word_count'   => $o['word_count'] ?? 0,
				'schema_types' => $o['schema_types'] ?? array(),
			);
		}
		$context['competitor_outlines'] = array() === $outlines ? 'unavailable' : $outlines;

		// Drop the lowest-ranked competitor outlines until the context fits.
		$json = (string) wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$size = strlen( $json );
		while ( $size > self::MAX_CONTEXT && is_array( $context['competitor_outlines'] ) && array_key_exists( 1, $context['competitor_outlines'] ) ) {
			array_pop( $context['competitor_outlines'] );
			$json = (string) wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$size = strlen( $json );
		}

		return new TextRequest(
			array( Message::user( "Research data (JSON):\n" . $json . "\n\nReturn the analysis as JSON matching the schema." ) ),
			$system,
			$max_tokens,
			null,
			self::schema(),
			'keyword_research',
			'research_analysis'
		);
	}

	/**
	 * Sanitise and cap the AI output.
	 *
	 * @param array<string, mixed> $data Validated AI output.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $data ): array {
		$length = (array) ( $data['recommended_length'] ?? array() );
		$min    = max( 0, min( 20000, (int) ( $length['min'] ?? 0 ) ) );
		$out    = array(
			'search_intent'      => array(
				'value'     => Sanitize::enum( $data['search_intent']['value'] ?? '', KeywordPlan::INTENTS ),
				'rationale' => Sanitize::text( $data['search_intent']['rationale'] ?? '', 400 ),
			),
			'recommended_length' => array(
				'min'       => $min,
				'max'       => max( $min, min( 20000, (int) ( $length['max'] ?? 0 ) ) ),
				'rationale' => Sanitize::text( $length['rationale'] ?? '', 400 ),
			),
		);
		foreach ( array( 'secondary_keywords', 'semantic_variations', 'entities', 'questions', 'common_topics', 'content_gaps', 'differentiation_ideas', 'geo_modifiers', 'placement_guidance', 'notes' ) as $key ) {
			$out[ $key ] = Sanitize::strings( $data[ $key ] ?? array(), 15, 300 );
		}
		return $out;
	}
}
