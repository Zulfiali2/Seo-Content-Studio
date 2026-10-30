<?php
/**
 * Prompts and schemas for content generation.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Content;

use AiSeoContentStudio\Elementor\BlogTemplates;
use AiSeoContentStudio\Keywords\KeywordPlan;
use AiSeoContentStudio\Providers\Contracts\Message;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Research\Contracts\Market;

defined( 'ABSPATH' ) || exit;

/**
 * Builds one AI request per content part. Each request carries the brief, the
 * approved keywords, the user's facts and what has already been written, so the
 * parts read as one article without repeating each other.
 */
final class ContentPrompts {

	/**
	 * Rules shared by every writing request. They are the same for every part of one
	 * article (part-specific rules travel in the task), so providers can cache them.
	 *
	 * @param bool                $with_instructions The request carries the user's extra instructions.
	 * @param bool                $callouts          The chosen layout styles tip/note/example boxes.
	 * @param WritingProfile|null $profile           Business profile, writing style and fact policy.
	 */
	public static function rules( bool $with_instructions = false, bool $callouts = false, ?WritingProfile $profile = null ): string {
		$profile = $profile ?? WritingProfile::fromArray( array() );
		$trusted = $profile->trustedFacts();
		$lines   = array(
			'You are an expert SEO copywriter and subject writer. You write web page content in the language given as "language" in the article context; the task says which parts to write.',
			'Hard rules:',
			$trusted
				? '- Do not invent statistics, prices, study results, quotes, testimonials, reviews, awards, credentials, client names or first-hand experience. Use facts from "facts_from_user", or official facts you list in "sources" (see Facts below). If a claim would need a source you do not know, write it without the figure and add it to claims_to_verify.'
				: '- Do not invent statistics, prices, dates, study results, quotes, testimonials, reviews, awards, credentials, client names or first-hand experience. Use facts only from "facts_from_user". If a claim would need a source or a figure, write it without the figure and add the claim to claims_to_verify.',
			'- Do not mention competitors by name and do not copy wording from any source.',
			'- Write original, specific, useful content for the stated audience and intent. No filler, no generic padding, no "In today\'s fast-paced world", no repeating the question back.',
			'- Use approved keywords only where they read naturally. Never stuff keywords or put the same keyword in two sentences in a row.',
			'- "required_terms" are must-use keywords: across the whole article each appears at least once in exactly that wording, inside a natural sentence. The task lists the ones still missing; include those that fit this part.',
			'- Return an HTML fragment using only: p, h3, h4, ul, ol, li, strong, em, table, thead, tbody, tr, th, td, blockquote, a. Do not use h1 or h2 (the page structure supplies them). Do not include the section heading itself.',
			'- Links: only internal_links (exact URL), the business\'s own service or contact URLs, and official sources as described below. Add none if none fit. Never invent URLs.',
			'- Paragraphs: mostly 2-4 sentences; vary their length so the page does not read like a template.',
			'- Return "sources" (official facts you used, see Facts) and "claims_to_verify"; both may be empty.',
			'- If the section notes ask for a worked example: one original example, clearly labelled as illustrative, that runs the whole process from start to finish (every step, the running totals and the final check), with a table whose figures add up. Check the arithmetic twice. Other sections refer back to it instead of starting a new example.',
			'Quality standard:',
			...QualityGuidelines::writing( $trusted ),
			...$profile->rules(),
		);
		$rules   = implode( "\n", $lines );
		if ( $callouts ) {
			$rules .= "\n" . '- Where it genuinely helps the reader, you may add at most one short callout box: <blockquote><p><strong>Pro tip:</strong> …</p></blockquote>, or the same with "Note:" or "Example:". The same no-invention rules apply inside it. Do not use blockquote for anything else.';
		}
		if ( $with_instructions ) {
			$rules .= "\n" . '- The site owner added instructions for this rewrite in "user_instructions". Follow them for this part (tone, focus, length, format, points to add or drop), as long as they do not break the hard rules above; where they conflict, the hard rules win.';
		}
		return $rules;
	}

	/**
	 * Payload entry for the user's extra instructions.
	 *
	 * @param string $instructions Instructions.
	 * @return array<string, string>
	 */
	private static function userInstructions( string $instructions ): array {
		return '' === trim( $instructions ) ? array() : array( 'user_instructions' => trim( $instructions ) );
	}

	/**
	 * Schema for a content part.
	 *
	 * @return array<string, mixed>
	 */
	public static function partSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'html', 'claims_to_verify', 'sources' ),
			'properties'           => array(
				'html'             => array( 'type' => 'string' ),
				'claims_to_verify' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'sources'          => self::sourcesSchema(),
			),
		);
	}

	/**
	 * Schema for cited official sources.
	 *
	 * @return array<string, mixed>
	 */
	public static function sourcesSchema(): array {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'claim', 'url' ),
				'properties'           => array(
					'claim' => array( 'type' => 'string' ),
					'url'   => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * Schema for the final edit of the whole article.
	 *
	 * @return array<string, mixed>
	 */
	public static function editSchema(): array {
		$str     = array( 'type' => 'string' );
		$strings = array(
			'type'  => 'array',
			'items' => $str,
		);
		$object  = static fn ( array $props ): array => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array_keys( $props ),
			'properties'           => $props,
		);
		return $object(
			array(
				'parts'            => array(
					'type'  => 'array',
					'items' => $object(
						array(
							'id'   => $str,
							'html' => $str,
						)
					),
				),
				'faqs'             => array(
					'type'  => 'array',
					'items' => $object(
						array(
							'question'    => $str,
							'answer_html' => $str,
						)
					),
				),
				'changes'          => $strings,
				'claims_to_verify' => $strings,
				'sources'          => self::sourcesSchema(),
			)
		);
	}

	/**
	 * Schema for FAQ answers.
	 *
	 * @return array<string, mixed>
	 */
	public static function faqSchema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'faqs', 'claims_to_verify', 'sources' ),
			'properties'           => array(
				'sources'          => self::sourcesSchema(),
				'faqs'             => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'question', 'answer_html' ),
						'properties'           => array(
							'question'    => array( 'type' => 'string' ),
							'answer_html' => array( 'type' => 'string' ),
						),
					),
				),
				'claims_to_verify' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		);
	}

	/**
	 * Schema for metadata.
	 *
	 * @return array<string, mixed>
	 */
	public static function metaSchema(): array {
		$str = array( 'type' => 'string' );
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'meta_title', 'meta_description', 'title_options', 'description_options', 'slug', 'schema_type', 'schema_reason', 'og_title', 'og_description', 'featured_image_prompt', 'featured_image_alt', 'image_placements' ),
			'properties'           => array(
				'meta_title'            => $str,
				'title_options'         => array(
					'type'  => 'array',
					'items' => $str,
				),
				'description_options'   => array(
					'type'  => 'array',
					'items' => $str,
				),
				'meta_description'      => $str,
				'slug'                  => $str,
				'schema_type'           => array(
					'type' => 'string',
					'enum' => ContentModel::SCHEMA_TYPES,
				),
				'schema_reason'         => $str,
				'og_title'              => $str,
				'og_description'        => $str,
				'featured_image_prompt' => $str,
				'featured_image_alt'    => $str,
				'image_placements'      => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'after_section', 'prompt', 'alt' ),
						'properties'           => array(
							'after_section' => $str,
							'prompt'        => $str,
							'alt'           => $str,
						),
					),
				),
			),
		);
	}

	/**
	 * Shared context for a project.
	 *
	 * @param array<string, mixed> $inputs  Inputs.
	 * @param array<string, mixed> $plan    Keyword plan.
	 * @param array<string, mixed> $brief   Brief.
	 * @param WritingProfile|null  $profile Business profile and fact policy.
	 * @return array<string, mixed>
	 */
	public static function context( array $inputs, array $plan, array $brief, ?WritingProfile $profile = null ): array {
		$approved = array();
		foreach ( KeywordPlan::GROUPS as $group ) {
			$terms = KeywordPlan::approved( $plan, $group );
			if ( array() !== $terms ) {
				$approved[ $group ] = $terms;
			}
		}
		$context  = array(
			'page_title'      => (string) ( $brief['title'] ?? '' ),
			'h1'              => (string) ( $brief['h1'] ?? '' ),
			'primary_keyword' => (string) ( $plan['primary']['keyword'] ?? $inputs['keyword'] ?? '' ),
			'search_intent'   => (string) ( $brief['search_intent'] ?? $plan['intent']['value'] ?? '' ),
			'content_type'    => (string) ( $brief['content_type'] ?? $inputs['content_type'] ?? '' ),
			'audience'        => (string) ( $brief['audience'] ?? $inputs['audience'] ?? '' ),
			'market'          => (string) ( $inputs['market']['location_name'] ?? '' ),
			'language'        => Market::languageOf( $inputs ),
			'facts_from_user' => (string) ( $inputs['notes'] ?? '' ),
			'approved_terms'  => $approved,
			'excluded_terms'  => (array) ( $plan['excluded'] ?? array() ),
			'differentiation' => (array) ( $brief['differentiation'] ?? array() ),
			// What the top results miss: the writer should cover it, not only the brief.
			'content_gaps'    => (array) ( $brief['content_gaps'] ?? array() ),
			'calls_to_action' => (array) ( $brief['ctas'] ?? array() ),
			'internal_links'  => array_map(
				static fn ( array $l ): array => array(
					'url'    => $l['url'],
					'title'  => $l['title'],
					'anchor' => $l['anchor'] ?? '',
				),
				(array) ( $brief['internal_links'] ?? array() )
			),
		);
		$required = array_column( KeywordPlan::required( $plan ), 'text' );
		if ( array() !== $required ) {
			$context['required_terms'] = $required;
		}
		$uses = (array) ( $plan['primary_uses'] ?? array() );
		if ( (int) ( $uses['max'] ?? 0 ) > 0 ) {
			$context['primary_keyword_uses'] = array(
				'min'  => (int) $uses['min'],
				'max'  => (int) $uses['max'],
				'note' => 'Exact-phrase uses across the whole article, including the H1.',
			);
		}
		$min = (int) ( $brief['length']['min'] ?? 0 );
		$max = (int) ( $brief['length']['max'] ?? 0 );
		if ( $max > 0 ) {
			$context['article_word_budget'] = array(
				'min' => $min,
				'max' => $max,
			);
		}
		if ( null !== $profile ) {
			$context += $profile->context();
		}
		if ( 'elementor' === ( $inputs['output'] ?? '' ) && BlogTemplates::isTemplate( (string) ( $inputs['elementor_style'] ?? '' ) ) ) {
			$context['callout_boxes'] = true;
		}
		return $context;
	}

	/**
	 * What the introduction must do.
	 *
	 * @param WritingProfile $profile Profile.
	 */
	private static function introTask( WritingProfile $profile ): string {
		return 'two_paragraphs' === $profile->style( 'intro_format' )
			? 'Write the introduction (no heading) as two <p> paragraphs. Paragraph 1 (roughly 40-70 words): answer the main question directly and use the exact primary keyword once. Paragraph 2 (roughly 25-50 words): say what the reader will learn' . ( '' !== $profile->brand() ? ' and that the guide comes from the business' : '' ) . '. No lists, no headings. Make the opening specific to this topic, not a formula.'
			: 'Write the introduction (no heading). Open with what the reader needs, use the primary keyword naturally in the first 100 words, and set expectations for what the page covers.';
	}

	/**
	 * What the conclusion must do.
	 *
	 * @param WritingProfile $profile Profile.
	 */
	private static function conclusionTask( WritingProfile $profile ): string {
		return ! empty( $profile->style( 'conclusion_cta' ) )
			? 'Write the conclusion (no heading) in about 3-6 sentences: restate the key answer in plain words (use the exact primary keyword once if it reads naturally), then end with a clear call to action' . ( '' !== $profile->brand() ? ' that names the business, its most relevant service and links to that service page or the contact_url (exact URL)' : ' based on calls_to_action' ) . '. Do not repeat earlier sentences.'
			: 'Write the closing section (no heading): a brief, useful wrap-up and a clear next step using the calls_to_action. Do not just repeat earlier text.';
	}

	/**
	 * Schema for the whole article written in one request.
	 *
	 * @return array<string, mixed>
	 */
	public static function articleSchema(): array {
		$schema = self::editSchema();
		unset( $schema['properties']['changes'] );
		$schema['required'] = array_values( array_diff( $schema['required'], array( 'changes' ) ) );
		return $schema;
	}

	/**
	 * Request for the whole article in one go: introduction, every section, FAQ answers and
	 * conclusion, so the AI plans and writes it as one connected piece. Parts the owner approved
	 * or locked are sent as fixed text and not rewritten. It shares the system rules and article
	 * context with every other writing request, so they are cached.
	 *
	 * @param array<string, mixed> $context    Shared context.
	 * @param array<string, mixed> $content    Content.
	 * @param array<string, int>   $words      Target words per part id.
	 * @param int                  $max_tokens Output cap.
	 * @param WritingProfile|null  $profile    Business profile and writing style.
	 */
	public static function articleRequest( array $context, array $content, array $words, int $max_tokens, ?WritingProfile $profile = null ): TextRequest {
		$profile = $profile ?? WritingProfile::fromArray( array() );
		$open    = static fn ( array $p ): bool => ! $p['locked'] && 'approved' !== $p['status'];
		$item    = static function ( array $p, string $task ) use ( $open, $words ): array {
			$row = array(
				'id'       => $p['id'],
				'editable' => $open( $p ),
			);
			if ( $open( $p ) ) {
				$row['task']         = $task;
				$row['target_words'] = (int) ( $words[ $p['id'] ] ?? 150 );
			} else {
				$row['html'] = $p['html'];
			}
			return $row;
		};

		$faqs  = array_values( array_filter( (array) $content['faqs'], static fn ( $f ) => is_array( $f ) && '' !== trim( (string) ( $f['question'] ?? '' ) ) ) );
		$write = ( $content['faqs_enabled'] ?? true ) && 'approved' !== ( $content['faqs_status'] ?? 'draft' ) && array() !== $faqs;
		$rules = array(
			'You are writing the whole article in this request (ignore any wording that suggests a single part). Write every part marked "editable": true and return each one in "parts" with the same id. Parts marked "editable": false were approved by the site owner: do not return them, but write the rest so it fits around them without repeating them.',
			'Plan the whole article before writing: each idea, definition, example and warning appears in exactly one place. Later sections build on earlier ones and refer back instead of repeating.',
			'Sections: write the body only (the H2 heading is added by the page). Follow each section\'s notes and include each listed subheading as an <h3>, in order.',
			'Lengths: keep each part close to its target_words; the whole article (all parts plus FAQ answers) stays within article_word_budget.',
			'Keywords: every term in required_terms appears at least once across the article, spread over the parts where it fits. If primary_keyword_uses is set, use the exact primary keyword that many times in total (the H1 counts as one), never twice in one paragraph.',
		);
		if ( 'once' === $profile->style( 'advice_line' ) ) {
			$rules[] = 'Professional advice: "advice_line" is "allowed" only in the conclusion (at most one sentence); everywhere else it is "not_allowed".';
		}
		if ( $write ) {
			$rules[] = 'FAQs: answer each question in faq_questions in 40-90 words of HTML (p or ul), answering directly in the first sentence so it can be quoted on its own. Keep the questions exactly as given and in the same order. Do not repeat sentences from the article.';
		}

		$payload = array(
			'task'          => 'Write the complete article in one go, as one connected piece: the introduction, every section in order' . ( $write ? ', the FAQ answers' : '' ) . ' and the conclusion.',
			'rules'         => $rules,
			'introduction'  => $item( $content['intro'], self::introTask( $profile ) ),
			'sections'      => array_map(
				static fn ( array $s ): array => array(
					'heading'     => $s['heading'],
					'notes'       => $s['notes'] ?? '',
					'subheadings' => $s['subheadings'] ?? array(),
				) + $item( $s, 'Write the body of this section.' ),
				(array) $content['sections']
			),
			'conclusion'    => $item( $content['conclusion'], self::conclusionTask( $profile ) ),
			'faq_questions' => $write ? array_values( array_map( static fn ( $f ) => (string) $f['question'], $faqs ) ) : array(),
		);

		return new TextRequest(
			array( Message::user( self::taskMessage( $payload ) ) ),
			self::rules( false, ! empty( $context['callout_boxes'] ), $profile ),
			$max_tokens,
			null,
			self::articleSchema(),
			'content_article',
			'content_article',
			self::sharedContext( $context, $content )
		);
	}

	/**
	 * Request for one part (intro, a section or the conclusion).
	 *
	 * @param array<string, mixed> $context Shared context.
	 * @param array<string, mixed> $content Current content.
	 * @param array<string, mixed> $part    Part to write.
	 * @param int                  $words   Target words for this part.
	 * @param int                  $max_tokens Output cap.
	 * @param string               $instructions The user's extra instructions for this part.
	 * @param WritingProfile|null  $profile Business profile and writing style.
	 */
	public static function partRequest( array $context, array $content, array $part, int $words, int $max_tokens, string $instructions = '', ?WritingProfile $profile = null ): TextRequest {
		$profile = $profile ?? WritingProfile::fromArray( array() );
		$written = array();
		$others  = (string) ( $content['h1'] ?? '' );
		$pending = 0;
		foreach ( ContentModel::parts( $content ) as $p ) {
			if ( $p['id'] === $part['id'] ) {
				continue;
			}
			if ( '' !== $p['html'] ) {
				$written[] = array(
					'part'    => $p['heading'] ?? $p['id'],
					'summary' => mb_substr( ContentModel::text( (string) $p['html'] ), 0, 350 ),
				);
				$others   .= "\n" . $p['html'];
			} else {
				++$pending;
			}
		}

		$task = match ( $part['id'] ) {
			'intro' => self::introTask( $profile ),
			'conclusion' => self::conclusionTask( $profile ),
			default => sprintf( 'Write the body of the section titled "%s". Cover the notes. Include each listed subheading as an <h3> in order, each followed by its content.', $part['heading'] ),
		};

		$payload = array(
			'task'            => $task,
			'target_words'    => $words,
			'section_notes'   => $part['notes'] ?? '',
			'subheadings'     => $part['subheadings'] ?? array(),
			'already_written' => $written,
		);
		if ( 'once' === $profile->style( 'advice_line' ) ) {
			$payload['advice_line'] = 'conclusion' === $part['id'] ? 'allowed' : 'not_allowed';
		}
		$missing = array_values( array_filter( (array) ( $context['required_terms'] ?? array() ), static fn ( $t ) => 0 === KeywordTools::count( ContentModel::text( $others ), (string) $t ) ) );
		if ( array() !== $missing ) {
			$payload['required_terms_missing'] = $missing;
		}
		if ( isset( $context['primary_keyword_uses'] ) ) {
			$target = (int) round( ( $context['primary_keyword_uses']['min'] + $context['primary_keyword_uses']['max'] ) / 2 );
			$used   = KeywordTools::count( ContentModel::text( $others ), (string) $context['primary_keyword'] );
			// Spread the uses: at least one in the introduction, never more than two in one part.
			$payload['primary_keyword_in_this_part'] = min( 2, max( 'intro' === $part['id'] ? 1 : 0, (int) round( ( $target - $used ) / ( $pending + 1 ) ) ) );
		}
		$payload += self::userInstructions( $instructions );

		return new TextRequest(
			array( Message::user( self::taskMessage( $payload ) ) ),
			self::rules( '' !== $instructions, ! empty( $context['callout_boxes'] ), $profile ),
			$max_tokens,
			null,
			self::partSchema(),
			'content_part',
			'content_section',
			self::sharedContext( $context, $content )
		);
	}

	/**
	 * Context shared by every request for one article (brief, keywords, facts, links and the
	 * outline). It is identical for each part, so providers can cache it; the per-part task
	 * is sent after it.
	 *
	 * @param array<string, mixed> $context Shared context.
	 * @param array<string, mixed> $content Content (for the outline).
	 */
	public static function sharedContext( array $context, array $content ): string {
		$context['page_outline'] = array_map(
			static fn ( array $s ): array => array(
				'heading'     => $s['heading'],
				'subheadings' => array_column( (array) $s['subheadings'], 'heading' ),
			),
			(array) ( $content['sections'] ?? array() )
		);
		unset( $context['callout_boxes'] );
		return "Article context (JSON):\n" . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * The varying part of a request.
	 *
	 * @param array<string, mixed> $payload Task fields.
	 */
	private static function taskMessage( array $payload ): string {
		return "Task (JSON):\n" . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Request for FAQ answers.
	 *
	 * @param array<string, mixed> $context   Shared context.
	 * @param string[]             $questions Questions.
	 * @param int                  $max_tokens Output cap.
	 * @param string               $instructions The user's extra instructions for the answers.
	 * @param array<string, mixed> $content   Content (for the shared outline).
	 * @param WritingProfile|null  $profile   Business profile and writing style.
	 */
	public static function faqRequest( array $context, array $questions, int $max_tokens, string $instructions = '', array $content = array(), ?WritingProfile $profile = null ): TextRequest {
		$profile = $profile ?? WritingProfile::fromArray( array() );
		$payload = array(
			'task'      => 'Answer each question in 40-90 words of HTML (p or ul). Answer directly in the first sentence, so the answer can be quoted on its own. Do not repeat sentences from the article. Keep the questions exactly as given and in the same order.',
			'questions' => array_values( $questions ),
		);
		if ( 'once' === $profile->style( 'advice_line' ) ) {
			$payload['advice_line'] = 'not_allowed';
		}
		$payload += self::userInstructions( $instructions );
		return new TextRequest( array( Message::user( self::taskMessage( $payload ) ) ), self::rules( '' !== $instructions, ! empty( $context['callout_boxes'] ), $profile ), $max_tokens, null, self::faqSchema(), 'content_faqs', 'content_faqs', self::sharedContext( $context, $content ) );
	}

	/**
	 * Request for the final edit: the whole article in one request, after every part is written.
	 * It shares the system rules and article context with the part requests, so they are cached.
	 *
	 * @param array<string, mixed> $context    Shared context.
	 * @param array<string, mixed> $content    Content.
	 * @param array<string, mixed> $checks     Measured facts about the draft (keyword counts, banned words…).
	 * @param int                  $max_tokens Output cap.
	 * @param WritingProfile|null  $profile    Business profile and writing style.
	 */
	public static function editRequest( array $context, array $content, array $checks, int $max_tokens, ?WritingProfile $profile = null ): TextRequest {
		$profile  = $profile ?? WritingProfile::fromArray( array() );
		$editable = static fn ( array $p ): bool => ! $p['locked'] && 'approved' !== $p['status'];
		$brand    = $profile->brand();
		$advice   = match ( (string) $profile->style( 'advice_line' ) ) {
			'none' => 'Remove every sentence that tells the reader to consult, speak to or check with a professional.',
			'as_needed' => 'Keep at most two sentences in the whole article that suggest a qualified professional; remove the rest.',
			default => 'Keep at most ONE sentence in the whole article that tells the reader to consult or speak to a professional, and only in the conclusion, blended into the call to action. Remove all others.',
		};
		$format = array();
		if ( 'two_paragraphs' === $profile->style( 'intro_format' ) ) {
			$format[] = 'The introduction is two <p> paragraphs: the first (roughly 40-70 words) answers the main question and contains the exact primary keyword, the second (roughly 25-50 words) says what the reader will learn.';
		}
		if ( ! empty( $profile->style( 'conclusion_cta' ) ) ) {
			$format[] = 'The conclusion is about 3-6 sentences and ends with a clear call to action' . ( '' !== $brand ? ' that names the business and links to a listed service page or the contact_url' : '' ) . '.';
		}

		$steps = array(
			'1. Repetition: an example, definition, warning or point that appears in more than one place stays only where it fits best; elsewhere refer back in a few words or remove it. There is only one worked example, introduced once. Questions and answers that duplicate the FAQ list are removed from the sections.',
			'2. Professional advice: ' . $advice,
			'3. Length: the whole article (all parts plus FAQ answers) ends within word_budget. Cut padding and repetition first; never cut facts, steps, examples, specific detail or the worked example\'s figures. Do not shorten good, specific passages just to save words.',
			'4. Format: ' . ( array() === $format ? 'keep the current format.' : implode( ' ', $format ) ),
			'5. Keywords: work every term in checks.missing_required_terms into the most fitting part, in exactly that wording and inside a natural sentence. If checks.primary_keyword_target is set, bring the exact primary keyword count (checks.primary_keyword_uses, which includes the H1) into that range by adding or removing natural uses.',
			'6. Style: fix every break of the writing style rules, including checks.banned_words_found and dashes. Keep <strong> on keywords that are already bold.',
		);

		$item    = static fn ( array $p ) => array(
			'id'       => $p['id'],
			'editable' => $editable( $p ),
			'html'     => $p['html'],
		);
		$payload = array(
			'task'               => 'You are the final editor. Improve the article without flattening it: keep its specific detail, examples and the writer\'s voice, and change only what the steps below require. Edit the whole article as one piece, then return only the parts you changed (same id, the complete new HTML of that part) and, only if you changed an answer, the complete FAQ list (same questions, same order). List each change in "changes" (one short line each).',
			'fix_in_this_order'  => $steps,
			'do_not'             => 'Do not change section headings or <h3> subheadings, change facts or figures, add facts without a source, add or change links (removing one is fine), or touch anything marked "editable": false (the site owner approved it).',
			'word_budget'        => $context['article_word_budget'] ?? null,
			'current_word_count' => (int) ( $checks['word_count'] ?? 0 ),
			'checks'             => $checks,
			'article'            => array(
				'intro'      => $item( $content['intro'] ),
				'sections'   => array_map( static fn ( array $s ): array => array( 'heading' => $s['heading'] ) + $item( $s ), (array) $content['sections'] ),
				'faqs'       => array(
					'editable' => 'approved' !== ( $content['faqs_status'] ?? 'draft' ),
					'items'    => array_values( ContentModel::publishedFaqs( $content ) ),
				),
				'conclusion' => $item( $content['conclusion'] ),
			),
		);

		return new TextRequest(
			array( Message::user( self::taskMessage( $payload ) ) ),
			self::rules( false, ! empty( $context['callout_boxes'] ), $profile ),
			$max_tokens,
			null,
			self::editSchema(),
			'content_edit',
			'content_edit',
			self::sharedContext( $context, $content )
		);
	}

	/**
	 * Request for metadata, schema and image suggestions.
	 *
	 * @param array<string, mixed> $context Shared context.
	 * @param array<string, mixed> $content Content.
	 * @param int                  $max_tokens Output cap.
	 */
	public static function metaRequest( array $context, array $content, int $max_tokens ): TextRequest {
		$brand   = (string) ( $context['business']['name'] ?? '' );
		$system  = implode(
			"\n",
			array(
				'You are a technical SEO specialist. Produce metadata for the finished page.',
				'- meta_title about 50-60 characters, starting with (or very near the start) the exact primary keyword. meta_description about 140-160 characters containing the exact primary keyword once. Both accurate to the content. og_title/og_description may be slightly more conversational.',
				'- title_options: 3 different SEO titles, each under 60 characters, each starting with the exact primary keyword, written to earn the click honestly (a clear benefit, no clickbait, no false promises, no year unless it is in the content).',
				'- description_options: 3 different meta descriptions, each under 150 characters, each containing the exact primary keyword' . ( '' !== $brand ? ' and the business name "' . $brand . '"' : '' ) . ', accurate to the content and ending with a reason to read.',
				'- slug: short, lowercase, hyphenated.',
				'- schema_type: the single most appropriate type. BlogPosting for blog posts, Article for guides, Service for service and local service pages, Product only for a product description, WebPage otherwise. Explain why in schema_reason. Do not suggest FAQPage or review markup.',
				'- featured_image_prompt: a concrete prompt for an original illustrative image (no text, no logos, no real people). featured_image_alt: descriptive alt text.',
				'- image_placements: up to 3 places after a section heading where an image would help, each with a prompt and alt text.',
				'- Write in the language given as "language" in the article context. No invented facts.',
			)
		);
		$payload = array(
			'headings' => array_column( (array) $content['sections'], 'heading' ),
			'excerpt'  => mb_substr( ContentModel::text( (string) $content['intro']['html'] ), 0, 800 ),
		);
		return new TextRequest( array( Message::user( self::taskMessage( $payload ) ) ), $system, $max_tokens, null, self::metaSchema(), 'content_meta', 'content_meta', self::sharedContext( $context, $content ) );
	}
}
