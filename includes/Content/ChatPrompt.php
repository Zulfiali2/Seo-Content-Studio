<?php
/**
 * Chat-style article prompt: one short, plain-language brief and a free HTML answer.
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
 * Asks for the article the way a person asks in a chat: what to write, for whom, with which
 * keywords, brand and links, and a few writing guidelines. The AI may use its own knowledge
 * (typical figures, examples, steps); every specific figure comes back in a check list for the
 * site owner. The answer is plain HTML (no JSON), split into the content model by parse().
 */
final class ChatPrompt {

	public const PURPOSE = 'content_chat';

	/** Marker lines in the answer. */
	private const FAQ_MARK        = '<!-- FAQ -->';
	private const CONCLUSION_MARK = '<!-- CONCLUSION -->';
	private const CHECK_MARK      = '===CHECK===';

	/** Competitor pages and headings per page sent as reference. */
	private const MAX_COMPETITORS = 5;
	private const MAX_HEADINGS    = 15;

	/**
	 * Build the request.
	 *
	 * @param array<string, mixed> $inputs     Project inputs.
	 * @param array<string, mixed> $plan       Keyword plan.
	 * @param array<string, mixed> $brief      Brief (outline, FAQs, length, internal links).
	 * @param array<string, mixed> $research   Research (competitor outlines), may be empty.
	 * @param WritingProfile       $profile    Brand and writing style.
	 * @param int                  $max_tokens Output cap.
	 */
	public static function request( array $inputs, array $plan, array $brief, array $research, WritingProfile $profile, int $max_tokens ): TextRequest {
		$keyword  = (string) ( $plan['primary']['keyword'] ?? $inputs['keyword'] ?? '' );
		$title    = '' !== trim( (string) ( $brief['title'] ?? '' ) ) ? (string) $brief['title'] : $keyword;
		$language = Market::languageOf( $inputs );
		$min      = (int) ( $brief['length']['min'] ?? 0 );
		$max      = (int) ( $brief['length']['max'] ?? 0 );
		if ( $max <= 0 ) {
			$min = 1200;
			$max = 1500;
		}

		$lines   = array();
		$lines[] = sprintf( 'Write an in-depth, original article titled "%s" in %s.', $title, $language );
		$lines[] = sprintf( 'Length: %d to %d words. Do not go over %d words.', $min, $max, $max );
		$market  = (string) ( $inputs['market']['location_name'] ?? '' );
		if ( '' !== $market ) {
			$lines[] = 'Readers are in ' . $market . '. Use local context (currency, units, rules, brands, places) where it matters.';
		}
		$audience = trim( (string) ( $brief['audience'] ?? $inputs['audience'] ?? '' ) );
		if ( '' !== $audience ) {
			$lines[] = 'Audience: ' . $audience;
		}

		// Keywords.
		$lines[] = '';
		$lines[] = 'KEYWORDS';
		$uses    = (array) ( $plan['primary_uses'] ?? array() );
		if ( (int) ( $uses['max'] ?? 0 ) > 0 ) {
			$lines[] = sprintf( '- Main keyword: "%s". Use it in exactly this wording %d to %d times across the article: in the first paragraph, in at least one H2 and in the conclusion. Never twice in one paragraph. Do not open the introduction or the conclusion by repeating the title or the keyword as a question.', $keyword, (int) $uses['min'], (int) $uses['max'] );
		} else {
			$lines[] = sprintf( '- Main keyword: "%s". Use it in exactly this wording in the first paragraph, in at least one H2 and in the conclusion, and elsewhere only where it reads naturally (about once per 150 words at most). Do not open the introduction or the conclusion by repeating the title or the keyword as a question.', $keyword );
		}
		$required = array();
		foreach ( KeywordPlan::required( $plan ) as $term ) {
			$required[ (string) ( $term['kind'] ?? 'secondary' ) ][] = (string) $term['text'];
		}
		$labels = array(
			'secondary' => 'Secondary keywords',
			'semantic'  => 'Semantic keywords',
			'nlp'       => 'NLP terms and entities',
		);
		foreach ( $labels as $kind => $label ) {
			if ( ! empty( $required[ $kind ] ) ) {
				$lines[] = '- ' . $label . ' (use every one at least once, in exactly this wording, spread across the article, not bunched in one paragraph): ' . implode( ', ', $required[ $kind ] );
			}
		}
		$approved = array_merge(
			KeywordPlan::approved( $plan, 'secondary' ),
			KeywordPlan::approved( $plan, 'semantic' ),
			KeywordPlan::approved( $plan, 'entities' ),
			KeywordPlan::approved( $plan, 'user_terms' )
		);
		$approved = array_values( array_diff( array_unique( $approved ), array_merge( array(), ...array_values( $required ) ) ) );
		if ( array() !== $approved ) {
			$lines[] = '- Related terms to work in where they fit: ' . implode( ', ', array_slice( $approved, 0, 40 ) );
		}
		$excluded = (array) ( $plan['excluded'] ?? array() );
		if ( array() !== $excluded ) {
			$lines[] = '- Do not use: ' . implode( ', ', array_map( 'strval', $excluded ) );
		}

		// Structure.
		$lines[] = '';
		$lines[] = 'STRUCTURE';
		$outline = array();
		foreach ( (array) ( $brief['outline'] ?? array() ) as $item ) {
			$heading = trim( (string) ( $item['heading'] ?? '' ) );
			if ( '' !== $heading ) {
				$notes     = trim( (string) ( $item['notes'] ?? '' ) );
				$outline[] = ( 3 === (int) ( $item['level'] ?? 2 ) ? '   H3: ' : '- H2: ' ) . $heading . ( '' !== $notes ? ' (' . $notes . ')' : '' );
			}
		}
		if ( array() !== $outline ) {
			$lines[] = 'Use this outline in this order. Add more H2 or H3 sections only where the topic needs them to be complete:';
			array_push( $lines, ...$outline );
		} else {
			$lines[] = 'Create your own outline: H2 sections that fully cover the topic and the related questions people search for, with H3 sub-sections where useful. Phrase headings the way people search.';
		}
		$lines[] = '- Do not repeat the title as an H2. Each H2 answers a different question, so no two sections cover the same ground.';
		$competitors = self::competitorOutlines( $research );
		if ( array() !== $competitors ) {
			$lines[] = 'Headings of top-ranking competitor pages, for reference only. Cover what they cover that readers need, add what they miss, and never copy their wording:';
			array_push( $lines, ...$competitors );
		}
		$faqs = array();
		foreach ( (array) ( $brief['faqs'] ?? array() ) as $faq ) {
			$q = trim( (string) ( is_array( $faq ) ? ( $faq['question'] ?? '' ) : $faq ) );
			if ( '' !== $q ) {
				$faqs[] = $q;
			}
		}
		$faqs = array_merge( $faqs, KeywordPlan::approved( $plan, 'questions' ) );
		$lines[] = array() !== $faqs
			? '- FAQ section: answer these questions (add others people often ask, 4 to 6 in total): ' . implode( ' | ', array_slice( array_unique( $faqs ), 0, 8 ) ) . '. Skip any question an H2 already answers.'
			: '- FAQ section: 4 to 6 questions people often ask about this topic that the H2 sections do not already answer.';

		// Brand and links.
		$business = $profile->business();
		$links    = array();
		foreach ( (array) ( $brief['internal_links'] ?? array() ) as $link ) {
			if ( '' !== (string) ( $link['url'] ?? '' ) ) {
				$links[] = '- ' . trim( (string) ( $link['title'] ?? '' ) ) . ': ' . $link['url'];
			}
		}
		if ( array() !== $business || array() !== $links ) {
			$lines[] = '';
			$lines[] = 'BRAND AND LINKS';
		}
		if ( '' !== $profile->brand() ) {
			$lines[] = sprintf( '- Our brand is "%s". Mention it naturally 2 to 3 times (the introduction or body, and the call to action). Write from the business\'s point of view: where the brand offers a fitting option, say so, and do not steer readers to competitors or away from what the business sells. Do not turn informational sections into adverts.', $profile->brand() );
			if ( '' !== (string) ( $business['about'] ?? '' ) ) {
				$lines[] = '- About the brand: ' . $business['about'];
			}
			foreach ( (array) ( $business['services'] ?? array() ) as $service ) {
				$lines[] = '- Our service: ' . trim( (string) ( $service['name'] ?? '' ) ) . ( '' !== (string) ( $service['url'] ?? '' ) ? ' (link it with this URL: ' . $service['url'] . ')' : '' );
			}
			if ( '' !== (string) ( $business['contact_url'] ?? '' ) ) {
				$lines[] = '- Contact page for the call to action: ' . $business['contact_url'];
			}
			if ( isset( $business['author'] ) ) {
				$lines[] = '- Written by ' . trim( implode( ', ', array_filter( array( $business['author']['name'] ?? '', $business['author']['credentials'] ?? '' ) ) ) ) . '. Write with that expertise, but never invent personal stories or client results.';
			}
		}
		if ( array() !== $links ) {
			$lines[] = '- Pages on our site to link where they genuinely fit (2 to 4 links, natural anchor text, exact URLs):';
			array_push( $lines, ...$links );
		}
		$lines[] = $profile->outboundLinks()
			? '- Outbound links: link 1 to 3 authoritative sources (official bodies, regulators, manufacturers, standards) with full https URLs you are sure exist. Leave a link out rather than guess. Links whose page does not load are removed.'
			: '- Do not link to other websites.';

		$notes = trim( (string) ( $inputs['notes'] ?? '' ) );
		if ( '' !== $notes ) {
			$lines[] = '';
			$lines[] = 'INSTRUCTIONS AND FACTS FROM THE SITE OWNER (follow these; they override the guidelines below where they conflict)';
			$lines[] = $notes;
		}

		// Writing guidelines (from the site owner's style settings).
		$lines[] = '';
		$lines[] = 'HOW TO WRITE';
		$lines[] = '- Write with the knowledge of an experienced professional in this field. Show it through substance: practical steps, real-world examples, typical figures, comparisons, mistakes to avoid and the reasons behind advice.';
		$lines[] = '- Never claim personal experience ("in my years", "I have seen", "we tested") unless it is in the site owner\'s instructions and facts. Use "we" only for the business, and only for what the site owner told you about it.';
		$lines[] = '- Use your own knowledge freely. Do not invent studies, survey results, quotes, reviews, customer stories or credentials.';
		$lines[] = 'two_paragraphs' === $profile->style( 'intro_format' )
			? '- Introduction: two paragraphs, the first about 4 lines and the second about 3 lines. Answer the main question in the first paragraph and use the main keyword there.'
			: '- Introduction: answer the main question straight away and use the main keyword in the first paragraph.';
		$lines[] = '- After each H2, answer the heading directly in the first one or two sentences, then go deeper. This helps Google AI Overviews and AI assistants quote the page.';
		$lines[] = '- Use H3 sub-sections and bullet or numbered lists. Add a table where it makes options or figures easy to compare.';
		$lines[] = '- ' . self::readingLevel( (string) $profile->style( 'reading_level' ) );
		$lines[] = sprintf( '- Keep most sentences between 10 and %d words, and vary their length and structure.', max( 10, (int) $profile->style( 'max_sentence_words' ) ) );
		$tone    = trim( (string) $profile->style( 'tone' ) );
		$lines[] = '- Tone: ' . ( '' !== $tone ? $tone : 'Professional but conversational, clear and direct.' ) . ' Make direct statements without hedging. No press-release tone, no filler, no emojis.';
		if ( 'English' === $language ) {
			$lines[] = '- Use ' . ( 'en-US' === $profile->style( 'english' ) ? 'American' : 'British' ) . ' English spelling.';
		}
		if ( ! empty( $profile->style( 'no_em_dashes' ) ) ) {
			$lines[] = '- Do not use em dashes. Use standard punctuation.';
		}
		$banned = $profile->bannedWords();
		if ( array() !== $banned ) {
			$lines[] = '- Never use these words: ' . implode( ', ', $banned ) . '.';
		}
		$lines[] = ! empty( $profile->style( 'conclusion_cta' ) )
			? '- Conclusion: 4 to 5 sentences that sum up the answer and end with a clear call to action' . ( '' !== $profile->brand() ? ' for ' . $profile->brand() : '' ) . '.'
			: '- Conclusion: a short wrap-up with a clear next step for the reader.';

		$lines[] = '';
		$lines[] = 'OUTPUT FORMAT (important)';
		$lines[] = '- Return only the article as HTML. No markdown, no code fences, no title or H1 (the page adds the title).';
		$lines[] = '- Allowed tags: p, h2, h3, h4, ul, ol, li, strong, em, table, thead, tbody, tr, th, td, blockquote, a.';
		$lines[] = '- Order: the introduction paragraphs (no heading), then the H2 sections, then a line ' . self::FAQ_MARK . ' followed by each FAQ as <h3>question</h3> and its answer (40 to 60 words, answering in the first sentence), then a line ' . self::CONCLUSION_MARK . ' followed by the conclusion paragraphs (no heading).';
		$lines[] = '- After the article write a line ' . self::CHECK_MARK . ' and then, one per line, every specific figure, price, date, rate or rule you stated, so the site owner can verify it before publishing. Write nothing after that list.';

		$system = 'You are an expert SEO content writer. You write original, genuinely helpful articles that show first-hand expertise (E-E-A-T), rank on Google and are easy for AI Overviews and AI assistants to quote. Follow the brief exactly.';

		return new TextRequest(
			array( Message::user( implode( "\n", $lines ) ) ),
			$system,
			$max_tokens,
			null,
			null,
			'content_chat',
			self::PURPOSE
		);
	}

	/**
	 * Split the answer into introduction, sections, FAQs, conclusion and claims to check.
	 *
	 * @param string $raw AI answer.
	 * @return array{intro: string, sections: array<int, array{heading: string, html: string}>, faqs: array<int, array{question: string, answer_html: string}>, conclusion: string, claims: string[]}
	 */
	public static function parse( string $raw ): array {
		$raw = trim( (string) preg_replace( '/^```(?:html)?\s*|\s*```\s*$/i', '', trim( $raw ) ) );

		$claims = array();
		$parts  = preg_split( '/^\s*' . preg_quote( self::CHECK_MARK, '/' ) . '\s*$/mi', $raw, 2 );
		if ( is_array( $parts ) && 2 === count( $parts ) ) {
			$raw = $parts[0];
			foreach ( preg_split( '/\R/', wp_strip_all_tags( $parts[1] ) ) ?: array() as $line ) {
				// Drop a list marker ("- ", "* ", "1. ", "2) ") but not a leading figure such as "5kW".
				$line = trim( (string) preg_replace( '/^\s*(?:[-*•]|\d+[.)])\s+/u', '', $line ) );
				if ( '' !== $line ) {
					$claims[] = $line;
				}
			}
		}
		$raw = (string) preg_replace( '#<h1\b[^>]*>.*?</h1>#is', '', $raw );

		// Conclusion and FAQ: by marker, or else by a recognisable last H2.
		$conclusion = '';
		$faq_html   = '';
		$split      = preg_split( '/' . preg_quote( self::CONCLUSION_MARK, '/' ) . '/i', $raw, 2 );
		if ( is_array( $split ) && 2 === count( $split ) ) {
			$raw        = $split[0];
			$conclusion = $split[1];
		}
		$split = preg_split( '/' . preg_quote( self::FAQ_MARK, '/' ) . '/i', $raw, 2 );
		if ( is_array( $split ) && 2 === count( $split ) ) {
			$raw      = $split[0];
			$faq_html = $split[1];
		}

		$chunks   = preg_split( '#<h2\b[^>]*>(.*?)</h2>#is', $raw, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array( $raw );
		$intro    = trim( (string) array_shift( $chunks ) );
		$sections = array();
		for ( $i = 0; $i + 1 < count( $chunks ); $i += 2 ) {
			$sections[] = array(
				'heading' => trim( html_entity_decode( wp_strip_all_tags( (string) $chunks[ $i ] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
				'html'    => trim( (string) $chunks[ $i + 1 ] ),
			);
		}
		if ( '' === trim( $conclusion ) && array() !== $sections && preg_match( '/conclusion|final thoughts|wrapping up|bottom line|summary/i', (string) end( $sections )['heading'] ) ) {
			$conclusion = (string) array_pop( $sections )['html'];
		}
		if ( '' === trim( $faq_html ) ) {
			foreach ( $sections as $i => $section ) {
				if ( preg_match( '/\bFAQs?\b|frequently asked|common questions/i', $section['heading'] ) ) {
					$faq_html = $section['html'];
					unset( $sections[ $i ] );
					break;
				}
			}
		}
		// A heading the AI put over the FAQ block or the conclusion is not part of the content.
		$faq_html   = (string) preg_replace( '#^\s*<h2\b[^>]*>.*?</h2>#is', '', $faq_html );
		$conclusion = (string) preg_replace( '#^\s*<h2\b[^>]*>.*?</h2>#is', '', $conclusion );

		$faqs  = array();
		$items = preg_split( '#<h3\b[^>]*>(.*?)</h3>#is', $faq_html, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array();
		for ( $i = 1; $i + 1 < count( $items ); $i += 2 ) {
			$question = trim( html_entity_decode( wp_strip_all_tags( (string) $items[ $i ] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' !== $question ) {
				$faqs[] = array(
					'question'    => $question,
					'answer_html' => trim( (string) $items[ $i + 1 ] ),
				);
			}
		}

		return array(
			'intro'      => $intro,
			'sections'   => array_values( $sections ),
			'faqs'       => $faqs,
			'conclusion' => trim( $conclusion ),
			'claims'     => array_slice( $claims, 0, 60 ),
		);
	}

	/**
	 * External https links in the answer (candidates to verify), with their anchor text.
	 *
	 * @param string $raw  AI answer.
	 * @param string $home This site's host.
	 * @return array<string, string> URL => anchor text.
	 */
	public static function outboundLinks( string $raw, string $home ): array {
		preg_match_all( '#<a\b[^>]*href="(https://[^"]+)"[^>]*>(.*?)</a>#is', $raw, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $match ) {
			$url  = html_entity_decode( $match[1] );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' !== $host && $host !== $home && ! str_ends_with( $host, '.' . $home ) && ! isset( $out[ $url ] ) ) {
				$out[ $url ] = trim( wp_strip_all_tags( $match[2] ) );
			}
		}
		return $out;
	}

	/**
	 * Competitor headings as reference lines.
	 *
	 * @param array<string, mixed> $research Research.
	 * @return string[]
	 */
	private static function competitorOutlines( array $research ): array {
		$lines = array();
		$n     = 0;
		foreach ( (array) ( $research['competitors'] ?? array() ) as $page ) {
			$headings = array();
			foreach ( (array) ( $page['outline']['headings'] ?? array() ) as $h ) {
				$text = trim( (string) ( $h['text'] ?? '' ) );
				if ( '' !== $text && (int) ( $h['level'] ?? 2 ) <= 3 ) {
					$headings[] = $text;
				}
			}
			if ( 'fetched' !== ( $page['status'] ?? '' ) || array() === $headings ) {
				continue;
			}
			$lines[] = '- Page ' . ( ++$n ) . ': ' . implode( ' | ', array_slice( $headings, 0, self::MAX_HEADINGS ) );
			if ( $n >= self::MAX_COMPETITORS ) {
				break;
			}
		}
		return $lines;
	}

	/**
	 * Reading level guideline.
	 *
	 * @param string $level Setting.
	 */
	private static function readingLevel( string $level ): string {
		return match ( $level ) {
			'expert' => 'Write for readers who know the field: technical terms are fine, but keep it clear.',
			'standard' => 'Write for a general adult reader: everyday words, and explain technical terms the first time.',
			'uk_plain' => 'Write in plain English: short everyday words, active voice, speak to the reader as "you", and explain technical terms.',
			default => 'Write in simple, everyday words (about a 6th grade reading level). Use technical terms only when needed, and explain them.',
		};
	}
}
