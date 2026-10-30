<?php
/**
 * Prompts and schemas for analysing and revising existing content.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Optimizer;

use AiSeoContentStudio\Content\ContentModel;
use AiSeoContentStudio\Content\QualityGuidelines;
use AiSeoContentStudio\Content\WritingProfile;
use AiSeoContentStudio\Keywords\KeywordPlan;
use AiSeoContentStudio\Providers\Contracts\Message;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Research\Contracts\Market;
use AiSeoContentStudio\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Analysis returns individually accept/rejectable recommendations; the rewrite
 * applies only the accepted ones and preserves everything else.
 */
final class OptimizerPrompts {

	public const TYPES = array( 'meta_title', 'meta_description', 'title', 'intro', 'add_section', 'rewrite_section', 'remove_section', 'add_faq', 'structure', 'keywords', 'internal_links', 'readability', 'accuracy', 'other' );

	/**
	 * Analysis schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function analysisSchema(): array {
		$str = array( 'type' => 'string' );
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'summary', 'recommendations' ),
			'properties'           => array(
				'summary'         => $str,
				'recommendations' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'type', 'severity', 'title', 'explanation', 'target', 'proposed_change' ),
						'properties'           => array(
							'type'            => array(
								'type' => 'string',
								'enum' => self::TYPES,
							),
							'severity'        => array(
								'type' => 'string',
								'enum' => array( 'high', 'medium', 'low' ),
							),
							'title'           => $str,
							'explanation'     => $str,
							'target'          => $str,
							'proposed_change' => $str,
						),
					),
				),
			),
		);
	}

	/**
	 * Rewrite schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function rewriteSchema(): array {
		$str = array( 'type' => 'string' );
		$obj = static fn ( array $props ): array => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array_keys( $props ),
			'properties'           => $props,
		);
		return $obj(
			array(
				'title'            => $str,
				'meta_title'       => $str,
				'meta_description' => $str,
				'intro_html'       => $str,
				'sections'         => array(
					'type'  => 'array',
					'items' => $obj(
						array(
							'heading' => $str,
							'html'    => $str,
						)
					),
				),
				'faqs'             => array(
					'type'  => 'array',
					'items' => $obj(
						array(
							'question'    => $str,
							'answer_html' => $str,
						)
					),
				),
				'conclusion_html'  => $str,
				'change_summary'   => array(
					'type'  => 'array',
					'items' => $str,
				),
				'claims_to_verify' => array(
					'type'  => 'array',
					'items' => $str,
				),
			)
		);
	}

	/**
	 * Analysis request.
	 *
	 * @param array<string, mixed>      $content Source content.
	 * @param array<string, mixed>      $plan    Keyword plan.
	 * @param array<string, mixed>      $inputs  Inputs.
	 * @param array<string, mixed>      $report  Deterministic report of the source.
	 * @param array<string, mixed>|null $brief Optional brief.
	 * @param int                       $max_tokens Output cap.
	 */
	public static function analysisRequest( array $content, array $plan, array $inputs, array $report, ?array $brief, int $max_tokens ): TextRequest {
		$system  = implode(
			"\n",
			array(
				'You are a senior SEO editor reviewing an existing page against a target keyword and intent.',
				'- Give 5-15 specific recommendations, each independently acceptable. Prioritise by impact on usefulness and intent match.',
				'- Each recommendation: what to change, why (explanation), where (target: heading or element), and proposed_change: the concrete new text or a precise instruction.',
				'- Preserve the author\'s facts and voice. Never propose adding statistics, testimonials, credentials or experience that are not in the page or the user facts; if evidence is missing, recommend that the author adds a verified source.',
				'- Do not demand a keyword density or a fixed word count. Do not claim ranking outcomes.',
				'- Use the deterministic check results provided rather than repeating them verbatim.',
				...QualityGuidelines::review(),
				'- Write in ' . Market::languageOf( $inputs ) . '.',
			)
		);
		$payload = array(
			'primary_keyword'      => (string) ( $plan['primary']['keyword'] ?? $inputs['keyword'] ?? '' ),
			'search_intent'        => (string) ( $plan['intent']['value'] ?? '' ),
			'audience'             => (string) ( $inputs['audience'] ?? '' ),
			'facts_from_user'      => (string) ( $inputs['notes'] ?? '' ),
			'approved_terms'       => array_merge( KeywordPlan::approved( $plan, 'secondary' ), KeywordPlan::approved( $plan, 'semantic' ), KeywordPlan::approved( $plan, 'questions' ) ),
			'brief_outline'        => $brief ? array_column( (array) $brief['outline'], 'heading' ) : null,
			'deterministic_issues' => array_values(
				array_map(
					static fn ( array $c ): string => $c['label'] . ': ' . $c['message'] . ( '' !== $c['recommendation'] ? ' — ' . $c['recommendation'] : '' ),
					array_filter( (array) ( $report['checks'] ?? array() ), static fn ( array $c ) => in_array( $c['status'], array( 'warn', 'fail' ), true ) )
				)
			),
			'page'                 => array(
				'title'            => $content['title'],
				'meta_title'       => $content['meta']['meta_title'],
				'meta_description' => $content['meta']['meta_description'],
				'html'             => mb_substr( ContentModel::bodyHtml( $content ), 0, 60000 ),
			),
		);
		return new TextRequest( array( Message::user( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ), $system, $max_tokens, null, self::analysisSchema(), 'content_analysis', 'optimize_analysis' );
	}

	/**
	 * Rewrite request with accepted recommendations only.
	 *
	 * @param array<string, mixed>             $content  Source content.
	 * @param array<int, array<string, mixed>> $accepted Accepted recommendations.
	 * @param array<string, mixed>             $plan     Plan.
	 * @param array<string, mixed>             $inputs   Inputs.
	 * @param string[]                         $links    Allowed links.
	 * @param int                              $max_tokens Output cap.
	 * @param WritingProfile|null              $profile  Writing style and business (facts stay as in the original).
	 */
	public static function rewriteRequest( array $content, array $accepted, array $plan, array $inputs, array $links, int $max_tokens, ?WritingProfile $profile = null ): TextRequest {
		$system  = implode(
			"\n",
			array(
				'You revise an existing web page by applying ONLY the accepted recommendations.',
				'- Keep everything else as close to the original as possible: facts, figures, names, links, voice and section order, unless a recommendation says otherwise.',
				'- Do not add statistics, quotes, testimonials, credentials, experience or claims that are not in the original or the user facts. Put anything that needs checking in claims_to_verify.',
				'- Return HTML fragments using only p, h3, h4, ul, ol, li, strong, em, table, thead, tbody, tr, th, td, blockquote, a. Section headings go in "heading" (they become H2). Only use links from allowed_links.',
				'- change_summary: one line per change you made.',
				...QualityGuidelines::writing(),
				...( null !== $profile ? $profile->styleRules() : array() ),
				'- Write in ' . Market::languageOf( $inputs ) . '.',
			)
		);
		$payload = array(
			'primary_keyword'          => (string) ( $plan['primary']['keyword'] ?? $inputs['keyword'] ?? '' ),
			'facts_from_user'          => (string) ( $inputs['notes'] ?? '' ),
			'accepted_recommendations' => array_map(
				static fn ( array $r ): array => array_intersect_key( $r, array_flip( array( 'type', 'title', 'target', 'proposed_change' ) ) ),
				$accepted
			),
			'allowed_links'            => $links,
			'original'                 => array(
				'title'            => $content['title'],
				'meta_title'       => $content['meta']['meta_title'],
				'meta_description' => $content['meta']['meta_description'],
				'intro_html'       => $content['intro']['html'],
				'sections'         => array_map(
					static fn ( array $s ): array => array(
						'heading' => $s['heading'],
						'html'    => $s['html'],
					),
					(array) $content['sections']
				),
			),
		);
		return new TextRequest( array( Message::user( wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ), $system, $max_tokens, null, self::rewriteSchema(), 'content_rewrite', 'optimize_rewrite' );
	}

	/**
	 * Sanitise recommendations and give them ids.
	 *
	 * @param array<string, mixed> $data AI output.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recommendations( array $data ): array {
		$out = array();
		foreach ( array_slice( (array) ( $data['recommendations'] ?? array() ), 0, 25 ) as $i => $r ) {
			$title = Sanitize::text( $r['title'] ?? '', 200 );
			if ( '' === $title ) {
				continue;
			}
			$out[] = array(
				'id'              => 'rec-' . ( $i + 1 ),
				'type'            => Sanitize::enum( $r['type'] ?? '', self::TYPES, 'other' ),
				'severity'        => Sanitize::enum( $r['severity'] ?? '', array( 'high', 'medium', 'low' ), 'low' ),
				'title'           => $title,
				'explanation'     => Sanitize::textarea( $r['explanation'] ?? '', 800 ),
				'target'          => Sanitize::text( $r['target'] ?? '', 200 ),
				'proposed_change' => Sanitize::textarea( $r['proposed_change'] ?? '', 3000 ),
				'decision'        => 'pending',
			);
		}
		return $out;
	}
}
