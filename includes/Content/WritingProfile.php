<?php
/**
 * The site owner's business profile, writing style and fact policy.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Content;

use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the "Brand and Writing Style" settings into prompt rules and context.
 *
 * Everything here comes from the site owner, so the AI can name the business, its
 * services and its author without inventing them. Rules that must be the same for
 * every part of an article go into the (cached) system prompt; rules that differ per
 * part (where the advice line or the call to action may go) are added to each task.
 */
final class WritingProfile {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $business Business profile.
	 * @param array<string, mixed> $style    Writing style.
	 * @param array<string, mixed> $facts    Fact policy.
	 */
	public function __construct(
		private readonly array $business,
		private readonly array $style,
		private readonly array $facts,
	) {}

	/**
	 * From the stored settings.
	 *
	 * @param SettingsRepository $settings Settings.
	 */
	public static function fromSettings( SettingsRepository $settings ): self {
		return self::fromArray( $settings->all() );
	}

	/**
	 * From a settings array (missing keys use the defaults).
	 *
	 * @param array<string, mixed> $all Settings.
	 */
	public static function fromArray( array $all ): self {
		$all = SettingsSchema::merge( SettingsSchema::defaults(), $all );
		return new self( (array) $all['business'], (array) $all['style'], (array) $all['facts'] );
	}

	/**
	 * Business details for the prompt context; empty fields are left out.
	 *
	 * @return array<string, mixed>
	 */
	public function business(): array {
		$out    = array_filter(
			array(
				'name'        => trim( (string) ( $this->business['name'] ?? '' ) ),
				'about'       => trim( (string) ( $this->business['about'] ?? '' ) ),
				'country'     => trim( (string) ( $this->business['country'] ?? '' ) ),
				'contact_url' => trim( (string) ( $this->business['contact_url'] ?? '' ) ),
				'services'    => array_values( array_filter( (array) ( $this->business['services'] ?? array() ), static fn ( $s ) => is_array( $s ) && '' !== trim( (string) ( $s['name'] ?? '' ) ) ) ),
			)
		);
		$author = array_filter(
			array(
				'name'        => trim( (string) ( $this->business['author_name'] ?? '' ) ),
				'credentials' => trim( (string) ( $this->business['author_credentials'] ?? '' ) ),
				'bio'         => trim( (string) ( $this->business['author_bio'] ?? '' ) ),
			)
		);
		if ( array() !== $author ) {
			$out['author'] = $author;
		}
		return $out;
	}

	/**
	 * Name of the business, or ''.
	 */
	public function brand(): string {
		return trim( (string) ( $this->business['name'] ?? '' ) );
	}

	/**
	 * The business's own page addresses (services and contact), which the writer may link to.
	 *
	 * @return string[]
	 */
	public function links(): array {
		$urls = array( (string) ( $this->business['contact_url'] ?? '' ) );
		foreach ( (array) ( $this->business['services'] ?? array() ) as $service ) {
			$urls[] = is_array( $service ) ? (string) ( $service['url'] ?? '' ) : '';
		}
		return array_values( array_unique( array_filter( array_map( 'trim', $urls ) ) ) );
	}

	/**
	 * Whether the writer may state facts from trusted official sources.
	 */
	public function trustedFacts(): bool {
		return 'trusted_sources' === ( $this->facts['mode'] ?? 'trusted_sources' );
	}

	/**
	 * Whether verified official sources are linked in the text.
	 */
	public function outboundLinks(): bool {
		return $this->trustedFacts() && ! empty( $this->facts['outbound_links'] );
	}

	/**
	 * Trusted domains, lowercase, without "www." (a domain also covers its subdomains).
	 *
	 * @return string[]
	 */
	public function trustedDomains(): array {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', strtolower( (string) ( $this->facts['trusted_domains'] ?? '' ) ) ) ?: array() as $domain ) {
			$domain = preg_replace( '#^(https?://)?(www\.)?#', '', trim( $domain ) );
			$domain = rtrim( (string) strtok( (string) $domain, '/' ), '.' );
			if ( '' !== $domain && preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain ) ) {
				$out[] = $domain;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether a URL is on a trusted domain.
	 *
	 * @param string $url URL.
	 */
	public function isTrusted( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return false;
		}
		foreach ( $this->trustedDomains() as $domain ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Words and phrases to avoid.
	 *
	 * @return string[]
	 */
	public function bannedWords(): array {
		$words = array_map( 'trim', preg_split( '/[,\n]+/', (string) ( $this->style['banned_words'] ?? '' ) ) ?: array() );
		return array_values( array_unique( array_filter( $words, static fn ( $w ) => '' !== $w && mb_strlen( $w ) <= 60 ) ) );
	}

	/**
	 * A style option.
	 *
	 * @param string $key Key.
	 */
	public function style( string $key ): mixed {
		return $this->style[ $key ] ?? null;
	}

	/**
	 * Whether the whole article gets a final edit after the parts are written.
	 */
	public function editPass(): bool {
		return ! empty( $this->style['edit_pass'] );
	}

	/**
	 * Style and fact rules for the system prompt. Identical for every part of an article.
	 *
	 * @return string[]
	 */
	public function rules(): array {
		return array_merge( $this->styleRules(), $this->factRules() );
	}

	/**
	 * Writing style and business rules (no fact policy).
	 *
	 * @return string[]
	 */
	public function styleRules(): array {
		$english = 'en-US' === ( $this->style['english'] ?? 'en-GB' ) ? 'American English spelling and usage' : 'British English spelling and usage (for example "organise", "colour", "programme")';
		$level   = match ( (string) ( $this->style['reading_level'] ?? 'simple' ) ) {
			'expert' => 'Write for specialists: technical terms are fine, but keep the writing clear and direct.',
			'uk_plain' => 'Write in UK plain English, the way GOV.UK writes for the public: aim for a reading age of about 9, short everyday words, active voice, speak to the reader as "you", one idea per sentence, and explain any technical term in plain words the first time it appears.',
			'standard' => 'Write for a general adult reader (about UK reading age 13-14, US grade 8-9): plain words, and explain technical terms the first time.',
			default => 'Write for a reading age of about 11-12 (UK Year 7, US grade 6): everyday words, few words of four or more syllables, and explain every technical term in plain words the first time it appears.',
		};
		$max   = (int) ( $this->style['max_sentence_words'] ?? 20 );
		$rules = array(
			'Writing style (from the site owner):',
			'- When writing in English, use ' . $english . '.',
			'- ' . $level,
			sprintf( '- Keep most sentences between 10 and %d words. Vary sentence length and structure naturally so it reads like a person wrote it.', max( 10, $max ) ),
			'- Make direct, confident statements. Only qualify a statement when the facts really depend on circumstances, and then say exactly what they depend on.',
			'- Connect ideas naturally; no stock transitions, no press-release tone, no filler.',
			'- Structure: use <h3> subheadings for distinct sub-topics and bullet or numbered lists for steps, checklists and comparisons. Use a table when it makes figures or options easier to compare.',
			'- Do not repeat an example, definition, warning or point that another part already covers (see already_written); refer back to it in a few words instead.',
		);
		$tone  = trim( (string) ( $this->style['tone'] ?? '' ) );
		if ( '' !== $tone ) {
			$rules[] = '- Tone: ' . $tone;
		}
		if ( ! empty( $this->style['no_em_dashes'] ) ) {
			$rules[] = '- Never use em dashes (—) or spaced en dashes as punctuation. Use commas, full stops, colons or brackets.';
		}
		$banned = $this->bannedWords();
		if ( array() !== $banned ) {
			$rules[] = '- Never use these words or phrases: ' . implode( ', ', $banned ) . '.';
		}
		$rules[] = match ( (string) ( $this->style['advice_line'] ?? 'once' ) ) {
			'none' => '- Do not tell the reader to consult, speak to or check with a professional (accountant, lawyer, adviser, doctor and so on).',
			'as_needed' => '- Where a decision really depends on personal circumstances, you may suggest a qualified professional, but never more than once per part.',
			default => '- Do not tell the reader to consult, speak to or check with a professional (accountant, lawyer, adviser, doctor and so on) unless the task says "advice_line": "allowed". The article says it at most once.',
		};

		$business = $this->business();
		if ( array() !== $business ) {
			$rules[] = '- "business" is the site owner\'s own business. Name it where it is natural (the introduction and the call to action, and at most 3 times in the whole article). Mention only the services listed, and link a service only with its exact url. Do not turn informational sections into adverts.';
			if ( isset( $business['author'] ) ) {
				$rules[] = '- "business.author" wrote the article. Write with the authority of those credentials, but never invent personal stories, client results or experience that is not in facts_from_user.';
			}
		}

		return $rules;
	}

	/**
	 * Fact policy rules (the response must have a "sources" list).
	 *
	 * @return string[]
	 */
	public function factRules(): array {
		$rules = array();
		if ( $this->trustedFacts() ) {
			$rules[] = '- Facts: besides facts_from_user, you may state well-established facts (rules, thresholds, deadlines, rates, dates) published by official sources on trusted_source_domains, but only when you are confident they are current. Add each one to "sources" with the claim and the exact official page URL you know. Never guess a URL: if you are not sure of the exact page, use url "" and keep the fact general. The site owner checks every listed source before publishing.';
			$rules[] = $this->outboundLinks()
				? '- You may link a fact to its official source with <a href="…"> using the same URL you put in "sources" (at most one link per source). Links that cannot be verified are removed automatically.'
				: '- Do not add links to external sites.';
		}
		return $rules;
	}

	/**
	 * Values for the shared (cached) prompt context.
	 *
	 * @return array<string, mixed>
	 */
	public function context(): array {
		$out      = array();
		$business = $this->business();
		if ( array() !== $business ) {
			$out['business'] = $business;
		}
		if ( $this->trustedFacts() ) {
			$out['trusted_source_domains'] = $this->trustedDomains();
		}
		return $out;
	}

	/**
	 * Deterministic clean-up of AI text: replaces em dashes when they are not wanted.
	 *
	 * @param string $html HTML.
	 */
	public function polish( string $html ): string {
		if ( empty( $this->style['no_em_dashes'] ) ) {
			return $html;
		}
		// Em dashes, and spaced en dashes used as dashes (not ranges like 5–6).
		$dash = '(?:\s*(?:—|&mdash;|&#8212;)\s*|\s+(?:–|&ndash;|&#8211;)\s+)';
		// Text between tags only, one sentence at a time: a pair of dashes around an aside
		// becomes a pair of commas; a single dash usually introduces an explanation, so it
		// becomes a colon (or a comma when the sentence already has a colon).
		$fixed = preg_replace_callback(
			'/>([^<]*)</u',
			static function ( array $m ) use ( $dash ): string {
				$sentences = preg_split( '/(?<=[.!?])(\s+)/u', $m[1], -1, PREG_SPLIT_DELIM_CAPTURE ) ?: array( $m[1] );
				foreach ( $sentences as $i => $sentence ) {
					$count = (int) preg_match_all( '/' . $dash . '/u', $sentence );
					if ( 0 === $count ) {
						continue;
					}
					$single = 1 === $count && ! str_contains( $sentence, ':' );
					// A dash just before the end of a sentence is dropped rather than replaced.
					$sentence        = (string) preg_replace( '/' . $dash . '(?=[.!?]*$)/u', '', $sentence );
					$sentences[ $i ] = (string) preg_replace_callback(
						'/' . $dash . '(.*)$/us',
						static function ( array $d ) use ( $single ): string {
							// A colon needs an explanation after it: not a joining word or a one-word ending.
							$colon = $single && ! preg_match( '/^(and|but|or|nor|so|yet|then|which|who)\b/iu', $d[1] ) && count( preg_split( '/\s+/u', trim( $d[1] ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() ) >= 2;
							return ( $colon ? ': ' : ', ' ) . $d[1];
						},
						$sentence
					);
					// Any further dashes in the sentence (pairs around an aside) become commas.
					$sentences[ $i ] = (string) preg_replace( '/' . $dash . '/u', ', ', $sentences[ $i ] );
				}
				return '>' . implode( '', $sentences ) . '<';
			},
			'>' . $html . '<'
		);
		return null === $fixed ? $html : substr( $fixed, 1, -1 );
	}
}
