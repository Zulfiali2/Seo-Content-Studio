<?php
/**
 * AI judgement of content quality.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\SEO;

use AiSeoContentStudio\Content\ContentModel;
use AiSeoContentStudio\Content\QualityGuidelines;
use AiSeoContentStudio\Keywords\KeywordPlan;
use AiSeoContentStudio\Providers\Contracts\Message;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Research\Contracts\Market;
use AiSeoContentStudio\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Subjective review by an AI model, kept separate from deterministic checks.
 * Scores are the model's opinion on a 0–100 scale, not a search-engine score.
 */
final class AiEvaluation {

	public const CATEGORIES = array( 'search_intent', 'topical_completeness', 'usefulness', 'readability', 'structure', 'faq_relevance', 'trust_signals' );

	/**
	 * Strict-mode compatible schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$str = array( 'type' => 'string' );
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'summary', 'categories', 'findings', 'claims_to_verify' ),
			'properties'           => array(
				'summary'          => $str,
				'categories'       => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'key', 'score', 'rationale' ),
						'properties'           => array(
							'key'       => array(
								'type' => 'string',
								'enum' => self::CATEGORIES,
							),
							'score'     => array( 'type' => 'integer' ),
							'rationale' => $str,
						),
					),
				),
				'findings'         => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'category', 'severity', 'issue', 'recommendation', 'suggested_fix', 'location' ),
						'properties'           => array(
							'category'       => array(
								'type' => 'string',
								'enum' => self::CATEGORIES,
							),
							'severity'       => array(
								'type' => 'string',
								'enum' => array( 'high', 'medium', 'low' ),
							),
							'issue'          => $str,
							'recommendation' => $str,
							'suggested_fix'  => $str,
							'location'       => $str,
						),
					),
				),
				'claims_to_verify' => array(
					'type'  => 'array',
					'items' => $str,
				),
			),
		);
	}

	/**
	 * Build the evaluation request.
	 *
	 * @param array<string, mixed> $doc   Document (title, meta, html).
	 * @param array<string, mixed> $plan  Keyword plan.
	 * @param array<string, mixed> $inputs Inputs.
	 * @param int                  $max_tokens Output cap.
	 */
	public static function request( array $doc, array $plan, array $inputs, int $max_tokens ): TextRequest {
		$system  = implode(
			"\n",
			array(
				'You are a strict senior editor and SEO reviewer. Evaluate the page below for the stated keyword, intent and audience.',
				'- Score each category from 0 to 100 with a short rationale. These are editorial opinions, not search-engine scores; never claim or predict rankings or traffic.',
				'- Findings: specific, actionable issues with the exact location (heading or quote) and a concrete suggested fix. Prioritise by severity. Do not demand a keyword density or a fixed word count.',
				'- trust_signals: whether claims are supported, whether anything reads as invented experience or unverifiable statistics.',
				...QualityGuidelines::review(),
				'- claims_to_verify: factual statements a human should check before publishing.',
				'- Write the review in ' . Market::languageOf( $inputs ) . '. Be concise.',
			)
		);
		$payload = array(
			'primary_keyword'  => (string) ( $plan['primary']['keyword'] ?? $inputs['keyword'] ?? '' ),
			'search_intent'    => (string) ( $plan['intent']['value'] ?? '' ),
			'audience'         => (string) ( $inputs['audience'] ?? '' ),
			'content_type'     => (string) ( $inputs['content_type'] ?? '' ),
			'approved_terms'   => array_merge( KeywordPlan::approved( $plan, 'secondary' ), KeywordPlan::approved( $plan, 'semantic' ) ),
			'title'            => (string) ( $doc['title'] ?? '' ),
			'meta_title'       => (string) ( $doc['meta_title'] ?? '' ),
			'meta_description' => (string) ( $doc['meta_description'] ?? '' ),
			'content_html'     => mb_substr( (string) ( $doc['html'] ?? '' ), 0, 60000 ),
		);
		return new TextRequest( array( Message::user( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ), $system, $max_tokens, null, self::schema(), 'seo_evaluation', 'seo_evaluation' );
	}

	/**
	 * Sanitise model output.
	 *
	 * @param array<string, mixed> $data Output.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $data ): array {
		$categories = array();
		foreach ( (array) ( $data['categories'] ?? array() ) as $c ) {
			$key = Sanitize::enum( $c['key'] ?? '', self::CATEGORIES );
			if ( '' !== $key && ! isset( $categories[ $key ] ) ) {
				$categories[ $key ] = array(
					'key'       => $key,
					'score'     => max( 0, min( 100, (int) ( $c['score'] ?? 0 ) ) ),
					'rationale' => Sanitize::text( $c['rationale'] ?? '', 500 ),
				);
			}
		}
		$findings = array();
		foreach ( array_slice( (array) ( $data['findings'] ?? array() ), 0, 30 ) as $f ) {
			$findings[] = array(
				'category'       => Sanitize::enum( $f['category'] ?? '', self::CATEGORIES, 'usefulness' ),
				'severity'       => Sanitize::enum( $f['severity'] ?? '', array( 'high', 'medium', 'low' ), 'low' ),
				'issue'          => Sanitize::text( $f['issue'] ?? '', 500 ),
				'recommendation' => Sanitize::text( $f['recommendation'] ?? '', 500 ),
				'suggested_fix'  => Sanitize::textarea( $f['suggested_fix'] ?? '', 1200 ),
				'location'       => Sanitize::text( $f['location'] ?? '', 200 ),
			);
		}
		$order = array(
			'high'   => 0,
			'medium' => 1,
			'low'    => 2,
		);
		usort( $findings, static fn ( $a, $b ) => $order[ $a['severity'] ] <=> $order[ $b['severity'] ] );
		$scores = array_column( $categories, 'score' );
		return array(
			'summary'          => Sanitize::textarea( $data['summary'] ?? '', 1500 ),
			'categories'       => array_values( $categories ),
			'overall'          => array() === $scores ? null : (int) round( array_sum( $scores ) / count( $scores ) ),
			'findings'         => $findings,
			'claims_to_verify' => Sanitize::strings( $data['claims_to_verify'] ?? array(), 30, 400 ),
		);
	}
}
