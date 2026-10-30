<?php
/**
 * Keyword plan data model.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Keywords;

use AiSeoContentStudio\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * The editable, user-approved keyword plan.
 *
 * Every term records its source so provider metrics are never confused with AI
 * suggestions: dataforseo (provider data), serp (seen on the results page),
 * competitor (seen on competitor pages), ai (AI suggestion), user (added by you).
 */
final class KeywordPlan {

	public const SOURCES = array( 'dataforseo', 'serp', 'competitor', 'ai', 'user' );
	public const INTENTS = array( 'informational', 'commercial', 'transactional', 'navigational', 'local', 'mixed' );
	public const GROUPS  = array( 'secondary', 'semantic', 'entities', 'questions', 'geo_modifiers', 'user_terms', 'competitor_topics' );

	/** Max terms per group. */
	private const MAX_TERMS = 100;

	/** Kinds of must-use keyword the site owner can list. */
	public const REQUIRED_KINDS = array( 'secondary', 'semantic', 'nlp' );

	/**
	 * Empty plan.
	 *
	 * @param string $primary Primary keyword.
	 * @return array<string, mixed>
	 */
	public static function empty( string $primary = '' ): array {
		return array(
			'primary'            => array(
				'keyword' => $primary,
				'metrics' => null,
			),
			'intent'             => array(
				'value'  => '',
				'source' => 'user',
				'note'   => '',
			),
			'groups'             => array_fill_keys( self::GROUPS, array() ),
			'excluded'           => array(),
			'placement_guidance' => array(),
			// Must-use keywords: every one appears in the article, in this exact wording.
			'required'           => array_fill_keys( self::REQUIRED_KINDS, array() ),
			'bold_required'      => false,
			// How often the exact primary keyword should appear (0 = no target).
			'primary_uses'       => array(
				'min' => 0,
				'max' => 0,
			),
		);
	}

	/**
	 * Sanitise a plan from untrusted input (REST or AI-derived).
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$primary = is_array( $input['primary'] ?? null ) ? $input['primary'] : array();
		$intent  = is_array( $input['intent'] ?? null ) ? $input['intent'] : array();
		$plan    = self::empty( Sanitize::text( $primary['keyword'] ?? '', 200 ) );

		$plan['primary']['metrics'] = is_array( $primary['metrics'] ?? null ) ? self::metrics( $primary['metrics'] ) : null;
		$plan['intent']             = array(
			'value'  => Sanitize::enum( $intent['value'] ?? '', self::INTENTS ),
			'source' => Sanitize::enum( $intent['source'] ?? '', self::SOURCES, 'user' ),
			'note'   => Sanitize::text( $intent['note'] ?? '', 300 ),
		);

		$excluded = array_map( 'mb_strtolower', Sanitize::strings( $input['excluded'] ?? array(), 200, 200 ) );
		foreach ( self::GROUPS as $group ) {
			$plan['groups'][ $group ] = self::terms( $input['groups'][ $group ] ?? array(), $excluded );
		}
		$plan['excluded']           = $excluded;
		$plan['placement_guidance'] = Sanitize::strings( $input['placement_guidance'] ?? array(), 20, 300 );

		$seen = array();
		foreach ( self::REQUIRED_KINDS as $kind ) {
			foreach ( Sanitize::strings( $input['required'][ $kind ] ?? array(), 30, 120 ) as $term ) {
				$key = mb_strtolower( $term );
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ]                = true;
					$plan['required'][ $kind ][] = $term;
				}
			}
		}
		$plan['bold_required'] = ! empty( $input['bold_required'] );
		$uses                  = is_array( $input['primary_uses'] ?? null ) ? $input['primary_uses'] : array();
		$min                   = max( 0, min( 50, (int) ( $uses['min'] ?? 0 ) ) );
		$plan['primary_uses']  = array(
			'min' => $min,
			'max' => max( $min, min( 50, (int) ( $uses['max'] ?? 0 ) ) ),
		);

		return $plan;
	}

	/**
	 * Every must-use keyword with its kind, in the order listed.
	 *
	 * @param array<string, mixed> $plan Plan.
	 * @return array<int, array{text: string, kind: string}>
	 */
	public static function required( array $plan ): array {
		$out = array();
		foreach ( self::REQUIRED_KINDS as $kind ) {
			foreach ( (array) ( $plan['required'][ $kind ] ?? array() ) as $term ) {
				if ( is_string( $term ) && '' !== trim( $term ) ) {
					$out[] = array(
						'text' => trim( $term ),
						'kind' => $kind,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Approved terms of a group (for prompts and briefs).
	 *
	 * @param array<string, mixed> $plan  Sanitised plan.
	 * @param string               $group Group name.
	 * @return string[]
	 */
	public static function approved( array $plan, string $group ): array {
		$out = array();
		foreach ( (array) ( $plan['groups'][ $group ] ?? array() ) as $term ) {
			if ( ! empty( $term['approved'] ) ) {
				$out[] = (string) $term['text'];
			}
		}
		return $out;
	}

	/**
	 * Build a term.
	 *
	 * @param string                    $text     Text.
	 * @param string                    $source   Source.
	 * @param bool                      $approved Approved.
	 * @param array<string, mixed>|null $metrics  Provider metrics.
	 * @param string                    $note     Note.
	 * @return array<string, mixed>
	 */
	public static function term( string $text, string $source, bool $approved = false, ?array $metrics = null, string $note = '' ): array {
		return array(
			'text'     => $text,
			'source'   => $source,
			'approved' => $approved,
			'metrics'  => $metrics,
			'note'     => $note,
		);
	}

	/**
	 * Sanitise a list of terms, dropping duplicates and excluded terms.
	 *
	 * @param mixed    $terms    Input.
	 * @param string[] $excluded Lowercase excluded terms.
	 * @return array<int, array<string, mixed>>
	 */
	private static function terms( mixed $terms, array $excluded ): array {
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$out  = array();
		$seen = array();
		foreach ( $terms as $term ) {
			if ( is_string( $term ) ) {
				$term = array( 'text' => $term );
			}
			if ( ! is_array( $term ) ) {
				continue;
			}
			$text = Sanitize::text( $term['text'] ?? '', 200 );
			$key  = mb_strtolower( $text );
			if ( '' === $text || isset( $seen[ $key ] ) || in_array( $key, $excluded, true ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = self::term(
				$text,
				Sanitize::enum( $term['source'] ?? '', self::SOURCES, 'user' ),
				! empty( $term['approved'] ),
				is_array( $term['metrics'] ?? null ) ? self::metrics( $term['metrics'] ) : null,
				Sanitize::text( $term['note'] ?? '', 300 )
			);
			if ( count( $out ) >= self::MAX_TERMS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Sanitise provider metrics (only provider fields are kept).
	 *
	 * @param array<string, mixed> $m Metrics.
	 * @return array<string, mixed>
	 */
	public static function metrics( array $m ): array {
		return array(
			'search_volume'      => Sanitize::nullableInt( $m['search_volume'] ?? null ),
			'cpc'                => Sanitize::nullableFloat( $m['cpc'] ?? null ),
			'competition'        => Sanitize::nullableFloat( $m['competition'] ?? null ),
			'competition_level'  => isset( $m['competition_level'] ) ? Sanitize::text( $m['competition_level'], 20 ) : null,
			'keyword_difficulty' => Sanitize::nullableInt( $m['keyword_difficulty'] ?? null ),
			'intent'             => isset( $m['intent'] ) ? Sanitize::text( $m['intent'], 30 ) : null,
			'last_updated'       => isset( $m['last_updated'] ) ? Sanitize::text( $m['last_updated'], 40 ) : null,
			'source'             => 'dataforseo',
		);
	}
}
