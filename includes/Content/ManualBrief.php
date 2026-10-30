<?php
/**
 * Starting brief for an outline the SEO writes by hand.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Content;

use AiSeoContentStudio\SEO\Cannibalization;

defined( 'ABSPATH' ) || exit;

/**
 * Every new project gets this brief, so the Brief step opens the outline editor straight
 * away instead of asking the AI for a brief. The SEO writes the H2/H3 headings, notes, FAQs
 * and length; "Regenerate brief" still asks the AI when wanted. Nothing here is billable:
 * related pages for internal links come from a WordPress search.
 *
 * Turn it off with: add_filter( 'aiscs_manual_brief', '__return_false' );
 */
final class ManualBrief {

	/** Empty H2 rows the editor shows to start the outline. */
	private const START_ROWS = 3;

	/** Related pages offered as internal links (the SEO removes what does not fit). */
	private const MAX_LINKS = 5;

	/**
	 * Whether new projects start with a hand-written outline.
	 */
	public static function enabled(): bool {
		return (bool) apply_filters( 'aiscs_manual_brief', true );
	}

	/**
	 * Starting brief from the project inputs and keyword plan.
	 *
	 * @param array<string, mixed> $inputs     Project inputs.
	 * @param array<string, mixed> $plan       Keyword plan.
	 * @param int                  $project_id Project (left out of the related pages).
	 * @return array<string, mixed>
	 */
	public static function skeleton( array $inputs, array $plan, int $project_id ): array {
		$keyword = (string) ( $inputs['keyword'] ?? '' );
		$title   = mb_strtoupper( mb_substr( $keyword, 0, 1 ) ) . mb_substr( $keyword, 1 );
		$brief   = Brief::sanitize(
			array(
				'target_keyword' => $keyword,
				'search_intent'  => (string) ( $plan['intent']['value'] ?? '' ),
				'audience'       => (string) ( $inputs['audience'] ?? '' ),
				'content_type'   => (string) ( $inputs['content_type'] ?? '' ),
				'title'          => $title,
				'h1'             => $title,
				'slug'           => $keyword,
				'length'         => array(
					'min' => (int) ( $inputs['length_min'] ?? 0 ),
					'max' => (int) ( $inputs['length_max'] ?? 0 ),
				),
				'internal_links' => array_slice( BriefJob::findCandidates( $plan, $project_id ), 0, self::MAX_LINKS ),
			)
		);
		// Empty rows to type into; Brief::sanitize() drops any still empty when the SEO saves.
		$brief['outline'] = array_fill(
			0,
			self::START_ROWS,
			array(
				'level'   => 2,
				'heading' => '',
				'notes'   => '',
			)
		);
		$brief['manual']             = true;
		$brief['verification_notes'] = self::keywordWarnings( $keyword, (int) ( $inputs['source_post_id'] ?? 0 ) );
		return $brief;
	}

	/**
	 * Warnings for the brief when other pages already target the keyword (shown in the Brief
	 * step, before any money is spent on writing).
	 *
	 * @param string $keyword Primary keyword.
	 * @param int    $source  Page an optimisation started from (not a competitor of itself).
	 * @return string[]
	 */
	public static function keywordWarnings( string $keyword, int $source = 0 ): array {
		$pages = Cannibalization::find( $keyword, array( $source ) );
		if ( array() === $pages ) {
			return array();
		}
		$out = array( __( 'Other pages on this site already target this keyword. Consider updating one of them instead, or give this article a clearly different angle:', 'ai-seo-content-studio' ) );
		return array_merge( $out, Cannibalization::lines( $pages ) );
	}

	/**
	 * The starting brief, not yet saved by the SEO or replaced by an AI brief, so it may be
	 * rebuilt when the keyword or keyword plan changes.
	 *
	 * @param array<string, mixed>|null $brief Stored brief.
	 */
	public static function isUntouched( ?array $brief ): bool {
		return is_array( $brief ) && ! empty( $brief['manual'] ) && empty( $brief['edited_at'] ) && empty( $brief['generated'] );
	}

	/**
	 * Whether a brief has at least one H2 section to write.
	 *
	 * @param array<string, mixed>|null $brief Brief.
	 */
	public static function hasSections( ?array $brief ): bool {
		foreach ( (array) ( $brief['outline'] ?? array() ) as $item ) {
			if ( is_array( $item ) && 2 === (int) ( $item['level'] ?? 2 ) && '' !== trim( (string) ( $item['heading'] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}
}
