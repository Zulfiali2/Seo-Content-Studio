<?php
/**
 * Builds a keyword plan from research results.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Keywords;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic merge of provider data, SERP observations, competitor outlines
 * and AI suggestions into the plan structure. Nothing is approved automatically:
 * the user approves terms. When research is re-run, existing approvals, user
 * terms and exclusions are kept.
 */
final class KeywordPlanBuilder {

	/**
	 * Build.
	 *
	 * @param string                    $primary  Primary keyword.
	 * @param array<string, mixed>      $research Research section.
	 * @param array<string, mixed>|null $existing Previous plan.
	 * @return array<string, mixed> Sanitised plan.
	 */
	public static function build( string $primary, array $research, ?array $existing = null ): array {
		$plan     = KeywordPlan::empty( $primary );
		$analysis = is_array( $research['analysis'] ?? null ) ? $research['analysis'] : array();
		$metrics  = array_change_key_case( (array) ( $research['suggestion_metrics'] ?? array() ) );

		$plan['primary']['metrics'] = is_array( $research['primary_metrics'] ?? null ) ? $research['primary_metrics'] : null;

		$provider_intent = (string) ( $research['primary_metrics']['intent'] ?? '' );
		$ai_intent       = (string) ( $analysis['search_intent']['value'] ?? '' );
		if ( in_array( $provider_intent, KeywordPlan::INTENTS, true ) ) {
			// The AI rationale explains the AI's guess, so it is only shown when they differ.
			$plan['intent'] = array(
				'value'  => $provider_intent,
				'source' => 'dataforseo',
				'note'   => '' !== $ai_intent && $ai_intent !== $provider_intent
					/* translators: %s: intent suggested by the AI. */
					? sprintf( __( 'AI analysis suggested "%s".', 'ai-seo-content-studio' ), $ai_intent )
					: '',
			);
		} elseif ( '' !== (string) ( $analysis['search_intent']['value'] ?? '' ) ) {
			$plan['intent'] = array(
				'value'  => (string) $analysis['search_intent']['value'],
				'source' => 'ai',
				'note'   => (string) ( $analysis['search_intent']['rationale'] ?? '' ),
			);
		}

		$lookup = static fn ( string $text ): ?array => $metrics[ mb_strtolower( $text ) ] ?? null;

		$secondary = array();
		foreach ( (array) ( $research['related'] ?? array() ) as $kw ) {
			$secondary[] = KeywordPlan::term( (string) $kw['keyword'], 'dataforseo', false, $kw );
		}
		foreach ( (array) ( $research['serp']['related_searches'] ?? array() ) as $text ) {
			$secondary[] = KeywordPlan::term( (string) $text, 'serp', false, $lookup( (string) $text ), 'Google related search' );
		}
		foreach ( (array) ( $analysis['secondary_keywords'] ?? array() ) as $text ) {
			$secondary[] = KeywordPlan::term( (string) $text, 'ai', false, $lookup( (string) $text ) );
		}
		$plan['groups']['secondary'] = $secondary;

		foreach ( (array) ( $analysis['semantic_variations'] ?? array() ) as $text ) {
			$plan['groups']['semantic'][] = KeywordPlan::term( (string) $text, 'ai', false, $lookup( (string) $text ) );
		}
		foreach ( (array) ( $analysis['entities'] ?? array() ) as $text ) {
			$plan['groups']['entities'][] = KeywordPlan::term( (string) $text, 'ai' );
		}

		$questions = array();
		foreach ( (array) ( $research['serp']['questions'] ?? array() ) as $text ) {
			$questions[] = KeywordPlan::term( (string) $text, 'serp', false, null, 'People also ask' );
		}
		foreach ( (array) ( $research['competitors'] ?? array() ) as $page ) {
			foreach ( (array) ( $page['outline']['questions'] ?? array() ) as $text ) {
				$questions[] = KeywordPlan::term( (string) $text, 'competitor', false, null, (string) wp_parse_url( (string) $page['url'], PHP_URL_HOST ) );
			}
		}
		foreach ( (array) ( $analysis['questions'] ?? array() ) as $text ) {
			$questions[] = KeywordPlan::term( (string) $text, 'ai' );
		}
		$plan['groups']['questions'] = $questions;

		foreach ( (array) ( $analysis['geo_modifiers'] ?? array() ) as $text ) {
			$plan['groups']['geo_modifiers'][] = KeywordPlan::term( (string) $text, 'ai' );
		}
		foreach ( (array) ( $analysis['common_topics'] ?? array() ) as $text ) {
			$plan['groups']['competitor_topics'][] = KeywordPlan::term( (string) $text, 'ai', false, null, 'Summarised from competitor headings' );
		}
		$plan['placement_guidance'] = (array) ( $analysis['placement_guidance'] ?? array() );

		if ( $existing ) {
			$plan = self::mergeExisting( $plan, $existing );
		}
		return KeywordPlan::sanitize( $plan );
	}

	/**
	 * Keep the user's previous decisions.
	 *
	 * @param array<string, mixed> $plan     New plan.
	 * @param array<string, mixed> $existing Previous plan.
	 * @return array<string, mixed>
	 */
	private static function mergeExisting( array $plan, array $existing ): array {
		$approved = array();
		foreach ( (array) ( $existing['groups'] ?? array() ) as $group => $terms ) {
			foreach ( (array) $terms as $term ) {
				if ( ! empty( $term['approved'] ) ) {
					$approved[ $group ][ mb_strtolower( (string) $term['text'] ) ] = true;
				}
				// Terms the user added by hand always survive a re-run.
				if ( 'user' === ( $term['source'] ?? '' ) && isset( $plan['groups'][ $group ] ) ) {
					$plan['groups'][ $group ][] = $term;
				}
			}
		}
		foreach ( $plan['groups'] as $group => $terms ) {
			foreach ( $terms as $i => $term ) {
				if ( isset( $approved[ $group ][ mb_strtolower( (string) $term['text'] ) ] ) ) {
					$plan['groups'][ $group ][ $i ]['approved'] = true;
				}
			}
		}
		$plan['excluded'] = (array) ( $existing['excluded'] ?? array() );
		// Must-use keywords and their options are the user's own.
		foreach ( array( 'required', 'bold_required', 'primary_uses' ) as $key ) {
			if ( array_key_exists( $key, $existing ) ) {
				$plan[ $key ] = $existing[ $key ];
			}
		}
		if ( 'user' === ( $existing['intent']['source'] ?? '' ) && '' !== (string) ( $existing['intent']['value'] ?? '' ) ) {
			$plan['intent'] = $existing['intent'];
		}
		return $plan;
	}
}
