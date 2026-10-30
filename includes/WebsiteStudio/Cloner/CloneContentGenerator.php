<?php
/**
 * AI request and response handling for rewriting a cloned page's content.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Cloner;

use AiSeoContentStudio\Content\QualityGuidelines;
use AiSeoContentStudio\Providers\Contracts\Message;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\WebsiteStudio\Engine\HtmlFragment;

defined( 'ABSPATH' ) || exit;

/**
 * Builds one structured AI request that writes new text for every selected slot of a page
 * at a similar length, so the copy fits the existing design, and validates the answer.
 */
final class CloneContentGenerator {

	public const PURPOSE = 'studio_clone_content';

	/** Roles written as HTML fragments. */
	private const HTML_ROLES = array( 'paragraph', 'faq_answer' );

	/**
	 * Build the request.
	 *
	 * @param array<string, mixed>             $brief   topic, primary_keyword, secondary_keywords[], audience, intent, location, tone, notes.
	 * @param array<int, array<string, mixed>> $slots   Slots to write (replace=true).
	 * @param array<string, mixed>             $context Business context (WritingProfile::context()) and brand tokens summary.
	 * @param int                              $max_out Max output tokens.
	 */
	public static function request( array $brief, array $slots, array $context, int $max_out = 8000 ): TextRequest {
		$items = array();
		foreach ( $slots as $slot ) {
			$items[] = array(
				'key'          => $slot['key'],
				'role'         => $slot['role'],
				'level'        => $slot['level'],
				'section'      => $slot['section_type'] . ' #' . ( (int) $slot['section_index'] + 1 ),
				'current_text' => $slot['text'],
				'target_words' => self::targetWords( (int) $slot['words'], (string) $slot['role'] ),
				'format'       => in_array( $slot['role'], self::HTML_ROLES, true ) ? 'html' : 'plain',
			);
		}

		$rules = array_merge(
			array(
				'You are rewriting the text of an existing web page so the same design can be used for a new page. The layout is fixed: write new text for every slot listed, in the same order and role, so each piece fits where the current text sits.',
				'- Return every key exactly once. Keep each text close to target_words (headings, buttons and card titles may be shorter, never much longer); long text would overflow the design.',
				'- current_text shows the slot\'s purpose and length only. Do not reuse its topic-specific facts, names or claims; write about the new topic.',
				'- Headings stay headings: write the h1 slot as the page\'s main heading and include the primary keyword naturally there and in at least one other heading. Do not repeat the same heading twice.',
				'- Buttons: 1-4 words, an action ("Book a consultation"). Card titles: 2-6 words.',
				'- "html" slots: only <p>, <strong>, <em>, <ul>, <ol>, <li>. No links, no headings, no images.',
				'- "plain" slots: plain text, no HTML, no markdown.',
				'- Use the primary keyword and secondary keywords where they read naturally; never stuff keywords.',
				'- Never invent business facts: no prices, statistics, years in business, numbers of clients, awards, certifications, qualifications, testimonials, client names, guarantees, addresses, phone numbers or e-mail addresses. Use only the facts in "business" and "notes". If a slot needs a fact you do not have, write it so it is true without the fact.',
				'- Write for the stated audience, intent and location, in the language of the brief, with the requested tone.',
				'- meta_title: at most 60 characters with the primary keyword. meta_description: 140-160 characters with the primary keyword, describing the page honestly.',
			),
			QualityGuidelines::writing( false )
		);

		$payload = array(
			'brief'    => array(
				'topic'              => (string) ( $brief['topic'] ?? '' ),
				'primary_keyword'    => (string) ( $brief['primary_keyword'] ?? '' ),
				'secondary_keywords' => array_values( (array) ( $brief['secondary_keywords'] ?? array() ) ),
				'audience'           => (string) ( $brief['audience'] ?? '' ),
				'intent'             => (string) ( $brief['intent'] ?? '' ),
				'location'           => (string) ( $brief['location'] ?? '' ),
				'tone'               => (string) ( $brief['tone'] ?? '' ),
				'notes'              => (string) ( $brief['notes'] ?? '' ),
			),
			'business' => $context['business'] ?? new \stdClass(),
			'slots'    => $items,
		);

		return new TextRequest(
			array( new Message( 'user', (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ),
			implode( "\n", $rules ),
			$max_out,
			null,
			self::schema(),
			'page_content',
			self::PURPOSE
		);
	}

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
			'required'             => array( 'page_title', 'meta_title', 'meta_description', 'slots' ),
			'properties'           => array(
				'page_title'       => $str,
				'meta_title'       => $str,
				'meta_description' => $str,
				'slots'            => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'key', 'text' ),
						'properties'           => array(
							'key'  => $str,
							'text' => $str,
						),
					),
				),
			),
		);
	}

	/**
	 * Validate the answer.
	 *
	 * @param mixed                            $data  Parsed JSON.
	 * @param array<int, array<string, mixed>> $slots Requested slots.
	 * @return array{texts: array<string, string>, page_title: string, meta_title: string, meta_description: string, warnings: list<array{key: string, issue: string}>}
	 */
	public static function parse( mixed $data, array $slots ): array {
		$data     = is_array( $data ) ? $data : array();
		$by_key   = array();
		$warnings = array();
		foreach ( $slots as $slot ) {
			$by_key[ $slot['key'] ] = $slot;
		}
		$texts = array();
		foreach ( (array) ( $data['slots'] ?? array() ) as $item ) {
			$key = is_array( $item ) && is_string( $item['key'] ?? null ) ? $item['key'] : '';
			if ( ! isset( $by_key[ $key ] ) || isset( $texts[ $key ] ) || ! is_string( $item['text'] ?? null ) ) {
				continue;
			}
			$slot  = $by_key[ $key ];
			$clean = $slot['html'] ? HtmlFragment::clean( $item['text'] ) : mb_substr( HtmlFragment::text( $item['text'] ), 0, 500 );
			if ( '' === HtmlFragment::text( $clean ) ) {
				continue;
			}
			$texts[ $key ] = $clean;
			$words         = count( preg_split( '/\s+/u', HtmlFragment::text( $clean ) ) );
			$limit         = max( 6, (int) ceil( self::targetWords( (int) $slot['words'], (string) $slot['role'] ) * 1.6 ) );
			if ( $words > $limit ) {
				$warnings[] = array(
					'key'   => $key,
					'issue' => 'longer_than_design',
				);
			}
		}
		foreach ( array_keys( $by_key ) as $key ) {
			if ( ! isset( $texts[ $key ] ) ) {
				$warnings[] = array(
					'key'   => $key,
					'issue' => 'not_written',
				);
			}
		}
		return array(
			'texts'            => $texts,
			'page_title'       => mb_substr( HtmlFragment::text( (string) ( $data['page_title'] ?? '' ) ), 0, 200 ),
			'meta_title'       => mb_substr( HtmlFragment::text( (string) ( $data['meta_title'] ?? '' ) ), 0, 70 ),
			'meta_description' => mb_substr( HtmlFragment::text( (string) ( $data['meta_description'] ?? '' ) ), 0, 170 ),
			'warnings'         => $warnings,
		);
	}

	/**
	 * Words to aim for in a slot.
	 *
	 * @param int    $current Current word count.
	 * @param string $role    Role.
	 */
	public static function targetWords( int $current, string $role ): int {
		$min = match ( $role ) {
			'button'     => 1,
			'card_title', 'heading', 'faq_question', 'tab_title', 'list_item' => 2,
			default      => 12,
		};
		return max( $min, $current );
	}
}
