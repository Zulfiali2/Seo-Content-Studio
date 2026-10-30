<?php
/**
 * AI page generator: brief → one structured request → PageDocument.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Generator;

use AiSeoContentStudio\Content\QualityGuidelines;
use AiSeoContentStudio\Providers\Contracts\Message;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\WebsiteStudio\Engine\HtmlFragment;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;
use AiSeoContentStudio\WebsiteStudio\Vision\VisionDesign;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a complete landing page (structure, copy, image prompts, SEO fields and internal-link
 * suggestions) in one strict-JSON request. The answer is untrusted: toDocument() keeps only what
 * the PageDocument schema allows, and the layout is then adjusted by ContentLayoutMapper.
 *
 * Colours are never chosen by the model: sections name a background role, which becomes a
 * token reference, so the brand profile (or the Elementor kit) decides the actual colours.
 */
final class PageGenerator {

	public const PURPOSE = 'studio_page_generate';

	public const LENGTHS = array(
		'short'  => array( 4, 600 ),
		'medium' => array( 6, 1000 ),
		'long'   => array( 8, 1600 ),
	);

	public const MAX_IMAGES = 8;

	public const BACKGROUNDS = array( '', 'surface', 'primary', 'secondary', 'dark' );

	/**
	 * The generation request.
	 *
	 * @param array<string, mixed>             $brief      Brief (topic, primary_keyword, secondary_keywords, intent,
	 *                                                     location, audience, tone, notes, length, sections, images).
	 * @param array<string, mixed>             $context    WritingProfile::context() (business facts).
	 * @param list<array<string, mixed>>       $candidates Internal link candidates {id, title, url, type}.
	 * @param int                              $max_out    Max output tokens.
	 */
	public static function request( array $brief, array $context, array $candidates, int $max_out = 16000 ): TextRequest {
		[ $sections, $words ] = self::LENGTHS[ $brief['length'] ?? '' ] ?? self::LENGTHS['medium'];
		$images               = max( 0, min( self::MAX_IMAGES, (int) ( $brief['images'] ?? 3 ) ) );

		$rules = array_merge(
			array(
				'You are a senior conversion copywriter and web designer. Write one complete landing page for the brief and describe its layout as sections from top to bottom. Skip the site header, navigation and footer: the theme provides them.',
				"- About $sections sections and $words words of visible text in total. Start with a hero: one h1 heading (the only h1 on the page, at most 10 words, with the primary keyword), a short supporting paragraph and one or two buttons. End with a call-to-action section.",
				'- Choose sections that suit the search intent: benefits or services as cards, how it works as steps, about, FAQ (4-6 real questions searchers ask, each with a complete answer of 30-70 words), call to action. If "requested_sections" is given, use those section types in that order.',
				'- Layout: "stack" for one column of content; "split" for exactly two side-by-side groups (typically text and an image); "grid" for repeated cards. Put a section heading and intro in "header" and each card or column in "groups". Grids hold 3, 4 or 6 cards of similar length; card text 15-40 words; card titles 2-6 words.',
				'- Headings: h2 for section headings, h3 for card titles. Use the primary keyword in the h1 and naturally in at least one h2; use secondary keywords where they read naturally; never stuff keywords.',
				"- Images: at most $images image elements in the whole page (0 means none). Each needs \"text\" (alt text describing the image, 5-15 words) and \"prompt\" (a detailed prompt for an AI image generator: subject, setting, composition, lighting, photographic style; no text, letters, logos or watermarks in the image; no identifiable real people or brands). Use aspect 3:2 for wide images, 1:1 for square, 2:3 for tall.",
				...self::contentRules(),
				'- link_suggestions: up to 5 internal links, only to pages in "internal_pages" (use their id as target_id) that are genuinely relevant. The anchor must be an exact phrase of 2-6 words that appears in a text element of the section you name (section = zero-based section index). Do not link from headings or buttons. Use [] when none fit.',
				'- meta_title: at most 60 characters with the primary keyword. meta_description: 140-160 characters with the primary keyword, describing the page honestly. slug: 2-6 lowercase words joined by hyphens.',
				'- Write for the stated audience, intent and location, in the language of the brief, with the requested tone.',
			),
			QualityGuidelines::writing( false )
		);

		$pages = array();
		foreach ( array_slice( $candidates, 0, 40 ) as $c ) {
			$pages[] = array(
				'id'    => (int) $c['id'],
				'title' => (string) $c['title'],
				'type'  => (string) $c['type'],
			);
		}
		$payload = array(
			'brief'          => array(
				'topic'              => (string) ( $brief['topic'] ?? '' ),
				'primary_keyword'    => (string) ( $brief['primary_keyword'] ?? '' ),
				'secondary_keywords' => array_values( (array) ( $brief['secondary_keywords'] ?? array() ) ),
				'audience'           => (string) ( $brief['audience'] ?? '' ),
				'intent'             => (string) ( $brief['intent'] ?? '' ),
				'location'           => (string) ( $brief['location'] ?? '' ),
				'tone'               => (string) ( $brief['tone'] ?? '' ),
				'notes'              => (string) ( $brief['notes'] ?? '' ),
				'requested_sections' => array_values( (array) ( $brief['sections'] ?? array() ) ),
			),
			'business'       => $context['business'] ?? new \stdClass(),
			'internal_pages' => $pages,
		);

		return new TextRequest(
			array( new Message( 'user', (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ),
			implode( "\n", $rules ),
			$max_out,
			null,
			self::schema(),
			'landing_page',
			self::PURPOSE
		);
	}

	/**
	 * Content and formatting rules shared with section regeneration.
	 *
	 * @return list<string>
	 */
	public static function contentRules(): array {
		return array(
			'- Each section names a background role: "" (page background), "surface" (light tint, use it to alternate sections), "primary", "secondary" or "dark" (for a hero or call to action). Never give colour values.',
			'- Text elements are plain text; separate paragraphs with a blank line. No HTML, no markdown. Buttons: 1-4 words, an action. Icons: a Font Awesome solid name from this list only: ' . implode( ', ', PageDocument::ICONS ) . '.',
			'- Never invent business facts: no prices, statistics, percentages, years in business, client counts, awards, certifications, guarantees, testimonials, reviews, client names, addresses, phone numbers or e-mail addresses. Use only facts in "business" and "notes". Do not create testimonials or statistics sections unless the notes contain the real testimonials or figures. If the page needs a fact you do not have, write a visible placeholder in square brackets, e.g. "[Add your phone number]", and list it in "assumptions".',
		);
	}

	/**
	 * Strict-mode schema (no recursion: groups hold leaf elements only).
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$str  = array( 'type' => 'string' );
		$int  = array( 'type' => 'integer' );
		$list = static fn ( array $items ): array => array(
			'type'  => 'array',
			'items' => $items,
		);
		return self::object(
			array(
				'page_title'       => $str,
				'meta_title'       => $str,
				'meta_description' => $str,
				'slug'             => $str,
				'sections'         => $list( self::sectionSchema() ),
				'link_suggestions' => $list(
					self::object(
						array(
							'target_id' => $int,
							'anchor'    => $str,
							'section'   => $int,
							'reason'    => $str,
						)
					)
				),
				'assumptions'      => $list( $str ),
			)
		);
	}

	/**
	 * Schema of one section (also used by section regeneration).
	 *
	 * @return array<string, mixed>
	 */
	public static function sectionSchema(): array {
		$str  = array( 'type' => 'string' );
		$strs = array(
			'type'  => 'array',
			'items' => $str,
		);
		$enum = static fn ( array $values ): array => array(
			'type' => 'string',
			'enum' => $values,
		);
		$list = static fn ( array $items ): array => array(
			'type'  => 'array',
			'items' => $items,
		);
		$leaf = self::object(
			array(
				'type'    => $enum( array( 'heading', 'text', 'button', 'image', 'icon', 'list', 'accordion', 'divider' ) ),
				'level'   => $enum( array( '', 'h1', 'h2', 'h3', 'h4' ) ),
				'text'    => $str,
				'items'   => $strs,
				'answers' => $strs,
				'variant' => $enum( array( '', 'primary', 'secondary' ) ),
				'icon'    => $str,
				'aspect'  => $enum( array( '', '3:2', '1:1', '2:3' ) ),
				'prompt'  => $str,
			)
		);
		return self::object(
			array(
				'type'       => $enum( PageDocument::SECTION_TYPES ),
				'layout'     => $enum( array( 'stack', 'split', 'grid' ) ),
				'columns'    => array( 'type' => 'integer' ),
				'background' => $enum( self::BACKGROUNDS ),
				'align'      => $enum( array( 'left', 'center' ) ),
				'header'     => $list( $leaf ),
				'groups'     => $list(
					self::object(
						array(
							'card'     => array( 'type' => 'boolean' ),
							'elements' => $list( $leaf ),
						)
					)
				),
			)
		);
	}

	/**
	 * Strict object schema.
	 *
	 * @param array<string, mixed> $props Properties.
	 * @return array<string, mixed>
	 */
	private static function object( array $props ): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array_keys( $props ),
			'properties'           => $props,
		);
	}

	/**
	 * Turn the answer into a PageDocument input (image slots as `_image` with their prompt),
	 * SEO fields, raw link suggestions and a report.
	 *
	 * @param mixed                $data  Parsed JSON.
	 * @param array<string, mixed> $brief Brief (cta_url, images, primary_keyword).
	 * @return array{document: array<string, mixed>, seo: array<string, string>, links: list<mixed>, report: array<string, mixed>}
	 */
	public static function toDocument( mixed $data, array $brief = array() ): array {
		$data   = is_array( $data ) ? $data : array();
		$report = array(
			'placeholders'   => 0,
			'icons_replaced' => 0,
			'dropped'        => 0,
			'images_removed' => 0,
			'assumptions'    => array_values( array_filter( array_map( static fn ( $a ): string => PageDocument::plain( $a, 200 ), array_slice( (array) ( $data['assumptions'] ?? array() ), 0, 15 ) ) ) ),
		);
		$cta    = self::ctaUrl( (string) ( $brief['cta_url'] ?? '' ) );

		$sections = array();
		$h1_used  = false;
		foreach ( array_slice( (array) ( $data['sections'] ?? array() ), 0, 20 ) as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$header = VisionDesign::leaves( (array) ( $s['header'] ?? array() ), $report, $h1_used, true );
			$groups = array();
			$bg     = in_array( $s['background'] ?? '', self::BACKGROUNDS, true ) ? (string) $s['background'] : '';
			foreach ( array_slice( (array) ( $s['groups'] ?? array() ), 0, 12 ) as $g ) {
				if ( ! is_array( $g ) ) {
					continue;
				}
				$leaves = VisionDesign::leaves( (array) ( $g['elements'] ?? array() ), $report, $h1_used, true );
				if ( array() === $leaves ) {
					continue;
				}
				$card     = ! empty( $g['card'] );
				$groups[] = array(
					'type'     => 'group',
					'style'    => array(
						// A card stands out from its section: light sections get page-background cards, others surface cards.
						'background' => $card ? ( 'surface' === $bg ? 'token:background' : 'token:surface' ) : '',
						'radius'     => $card ? '12px' : '',
						'shadow'     => $card ? '0 4px 16px 0 #0000001A' : '',
						'padding'    => $card ? '32px' : '',
					),
					'elements' => array_slice( $leaves, 0, 20 ),
				);
			}
			if ( array() === $header && array() === $groups ) {
				continue;
			}
			$layout     = in_array( $s['layout'] ?? '', array( 'stack', 'split', 'grid' ), true ) ? $s['layout'] : 'stack';
			$columns    = max( 1, min( 6, (int) ( $s['columns'] ?? 0 ) ?: count( $groups ) ) );
			$type       = in_array( $s['type'] ?? '', PageDocument::SECTION_TYPES, true ) ? $s['type'] : 'content';
			$sections[] = array(
				'id'       => 'section-' . ( count( $sections ) + 1 ),
				'type'     => $type,
				'layout'   => array(
					'kind'          => $layout,
					'columns'       => array(
						'desktop' => $columns,
						'tablet'  => min( 2, $columns ),
						'mobile'  => 1,
					),
					'align'         => 'center' === ( $s['align'] ?? '' ) ? 'center' : 'left',
					'content_width' => 'boxed',
					'min_height'    => 'hero' === $type && 0 === count( $sections ) ? '520px' : '',
				),
				'style'    => array(
					'background' => match ( $bg ) {
						'surface'   => 'token:surface',
						'primary'   => 'token:primary',
						'secondary' => 'token:secondary',
						'dark'      => 'token:heading',
						default     => '',
					},
					'text_color' => in_array( $bg, array( 'primary', 'secondary', 'dark' ), true ) ? 'token:background' : '',
				),
				'elements' => array_slice( array_merge( $header, $groups ), 0, 60 ),
			);
		}

		$sections = self::finish( $sections, $cta, max( 0, min( self::MAX_IMAGES, (int) ( $brief['images'] ?? self::MAX_IMAGES ) ) ), $report );
		$title    = PageDocument::plain( $data['page_title'] ?? '', 200 );
		$slug     = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( PageDocument::plain( $data['slug'] ?? '', 80 ) ) ), '-' );
		return array(
			'document' => array(
				'schema_version' => PageDocument::VERSION,
				'page'           => array(
					'title' => '' === $title ? PageDocument::plain( $brief['topic'] ?? '', 200 ) : $title,
					'slug'  => $slug,
				),
				'sections'       => $sections,
			),
			'seo'      => array(
				'meta_title'       => PageDocument::plain( $data['meta_title'] ?? '', 70 ),
				'meta_description' => PageDocument::plain( $data['meta_description'] ?? '', 170 ),
				'keyword'          => PageDocument::plain( $brief['primary_keyword'] ?? '', 120 ),
			),
			'links'    => array_slice( array_values( array_filter( (array) ( $data['link_suggestions'] ?? array() ), 'is_array' ) ), 0, 20 ),
			'report'   => $report,
		);
	}

	/**
	 * Apply the call-to-action URL to buttons, enforce the image limit and drop empty sections.
	 *
	 * @param list<array<string, mixed>> $sections Sections.
	 * @param string                     $cta      Button URL ("" = "#").
	 * @param int                        $images   Image limit.
	 * @param array<string, mixed>       $report   Report.
	 * @return list<array<string, mixed>>
	 */
	private static function finish( array $sections, string $cta, int $images, array &$report ): array {
		$count = 0;
		$fix   = static function ( array $elements ) use ( &$fix, &$count, $images, $cta, &$report ): array {
			$out = array();
			foreach ( $elements as $e ) {
				if ( 'group' === $e['type'] ) {
					$e['elements'] = $fix( $e['elements'] );
					if ( array() === $e['elements'] ) {
						continue;
					}
				} elseif ( '_image' === $e['type'] ) {
					if ( ++$count > $images ) {
						++$report['images_removed'];
						continue;
					}
					if ( '' === $e['prompt'] ) {
						$e['prompt'] = '' !== $e['alt'] ? $e['alt'] : 'A clean, modern professional photograph';
					}
				} elseif ( 'button' === $e['type'] && '' !== $cta ) {
					$e['url'] = $cta;
				}
				$out[] = $e;
			}
			return $out;
		};
		foreach ( $sections as $i => $section ) {
			$sections[ $i ]['elements'] = $fix( $section['elements'] );
		}
		return array_values( array_filter( $sections, static fn ( array $s ): bool => array() !== $s['elements'] ) );
	}

	/**
	 * A safe button URL: http(s), a site-relative path or an in-page anchor; else "".
	 *
	 * @param string $url URL.
	 */
	public static function ctaUrl( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '/^#[A-Za-z][\w-]{0,60}$/', $url ) ) {
			return $url;
		}
		$clean = HtmlFragment::url( $url );
		return preg_match( '#^(https?://|/(?!/))#i', $clean ) ? $clean : '';
	}

	/**
	 * Facts the user must check before publishing: numbers in the text and [placeholders].
	 *
	 * @param array<string, mixed> $doc Document.
	 * @return array{numbers: list<array{section: int, text: string}>, placeholders: list<array{section: int, text: string}>}
	 */
	public static function factCheck( array $doc ): array {
		$out = array(
			'numbers'      => array(),
			'placeholders' => array(),
		);
		foreach ( (array) ( $doc['sections'] ?? array() ) as $si => $section ) {
			foreach ( self::texts( (array) $section['elements'] ) as $text ) {
				if ( preg_match_all( '/\[[^\[\]]{2,80}\]/u', $text, $m ) ) {
					foreach ( $m[0] as $placeholder ) {
						$out['placeholders'][] = array(
							'section' => $si,
							'text'    => $placeholder,
						);
					}
				}
				foreach ( (array) preg_split( '/(?<=[.!?])\s+/u', $text ) as $sentence ) {
					// Digits, percentages, prices and years are facts the model may have made up.
					if ( preg_match( '/\d/u', (string) $sentence ) && ! preg_match( '/^\s*\[[^\]]*\]\s*$/u', (string) $sentence ) ) {
						$out['numbers'][] = array(
							'section' => $si,
							'text'    => mb_substr( (string) $sentence, 0, 200 ),
						);
					}
				}
			}
		}
		$out['numbers']      = array_slice( $out['numbers'], 0, 30 );
		$out['placeholders'] = array_slice( $out['placeholders'], 0, 30 );
		return $out;
	}

	/**
	 * Visible plain texts of elements (groups included).
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @return list<string>
	 */
	public static function texts( array $elements ): array {
		$out = array();
		foreach ( $elements as $e ) {
			switch ( $e['type'] ?? '' ) {
				case 'group':
					$out = array_merge( $out, self::texts( (array) $e['elements'] ) );
					break;
				case 'heading':
				case 'button':
					$out[] = (string) $e['text'];
					break;
				case 'text':
					$out[] = HtmlFragment::text( (string) $e['html'] );
					break;
				case 'list':
					$out = array_merge( $out, array_map( 'strval', (array) $e['items'] ) );
					break;
				case 'accordion':
					foreach ( (array) $e['items'] as $item ) {
						$out[] = (string) $item['title'];
						$out[] = HtmlFragment::text( (string) $item['html'] );
					}
					break;
			}
		}
		return $out;
	}

	/**
	 * Image slots (`_image` elements) in document order, with their position.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @return list<array{id: string, section: int, section_type: string, aspect: string, alt: string, prompt: string}>
	 */
	public static function imageSlots( array $doc ): array {
		$out  = array();
		$walk = static function ( array $elements, int $si, string $type ) use ( &$walk, &$out ): void {
			foreach ( $elements as $e ) {
				if ( 'group' === ( $e['type'] ?? '' ) ) {
					$walk( (array) $e['elements'], $si, $type );
				} elseif ( '_image' === ( $e['type'] ?? '' ) ) {
					$out[] = array(
						'id'           => 'img-' . ( count( $out ) + 1 ),
						'section'      => $si,
						'section_type' => $type,
						'aspect'       => (string) $e['aspect'],
						'alt'          => (string) $e['alt'],
						'prompt'       => (string) ( $e['prompt'] ?? '' ),
					);
				}
			}
		};
		foreach ( (array) ( $doc['sections'] ?? array() ) as $si => $section ) {
			$walk( (array) $section['elements'], $si, (string) $section['type'] );
		}
		return $out;
	}
}
