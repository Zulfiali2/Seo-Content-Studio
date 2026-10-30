<?php
/**
 * Generated content structure and HTML safety.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Content;

use AiSeoContentStudio\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Structured content: intro, ordered sections, FAQs, conclusion/CTA and
 * metadata. Each part has its own approval/lock flag so regenerating one part
 * never touches approved parts.
 *
 * HTML from the AI (or the user) is restricted to a small set of body tags.
 * Headings H1/H2 are not allowed inside parts (the structure supplies them), and
 * links are only kept when they point to URLs the plugin supplied (internal links
 * from the brief or the source post), so the AI cannot inject invented links.
 */
final class ContentModel {

	public const SCHEMA_TYPES = array( 'BlogPosting', 'Article', 'WebPage', 'Service', 'Product' );

	/** Statuses of a cited source (see SourceVerifier). */
	public const SOURCE_STATUSES = array( 'verified', 'not_found', 'untrusted', 'no_url', 'not_checked' );

	/**
	 * Allowed tags inside a content part.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowedTags(): array {
		$none = array();
		return array(
			'p'          => $none,
			'h3'         => $none,
			'h4'         => $none,
			'ul'         => $none,
			'ol'         => $none,
			'li'         => $none,
			'strong'     => $none,
			'em'         => $none,
			'b'          => $none,
			'i'          => $none,
			'br'         => $none,
			'blockquote' => $none,
			'table'      => $none,
			'thead'      => $none,
			'tbody'      => $none,
			'tr'         => $none,
			'th'         => array( 'scope' => true ),
			'td'         => $none,
			'a'          => array( 'href' => true ),
			'code'       => $none,
		);
	}

	/**
	 * Sanitise an HTML fragment.
	 *
	 * @param string   $html          Untrusted HTML.
	 * @param string[] $allowed_links URLs that may be linked.
	 */
	public static function cleanHtml( string $html, array $allowed_links = array() ): string {
		// Demote stray H1/H2 to H3 so the document outline stays valid.
		$html = (string) preg_replace( '#<(/?)h[12](\s[^>]*)?>#i', '<$1h3>', $html );
		// wp_kses drops disallowed tags but keeps their text, so remove executable/embedded blocks whole.
		$html = (string) preg_replace( '#<(script|style|iframe|noscript|object|embed|template)\b[^>]*>.*?</\1\s*>#is', '', $html );
		$html = wp_kses( $html, self::allowedTags() );

		$allowed = array_map( static fn ( string $u ): string => untrailingslashit( strtolower( $u ) ), array_filter( $allowed_links ) );
		$html    = (string) preg_replace_callback(
			'#<a\b([^>]*)>(.*?)</a>#is',
			static function ( array $m ) use ( $allowed ): string {
				$href = preg_match( '#href="([^"]*)"#i', $m[1], $h ) ? html_entity_decode( $h[1] ) : '';
				if ( '' !== $href && in_array( untrailingslashit( strtolower( $href ) ), $allowed, true ) ) {
					return '<a href="' . esc_url( $href ) . '">' . $m[2] . '</a>';
				}
				return $m[2]; // Unknown link: keep the text, drop the link.
			},
			$html
		);

		return trim( mb_substr( $html, 0, 60000 ) );
	}

	/**
	 * Empty content skeleton built from a brief.
	 *
	 * @param array<string, mixed> $brief Brief.
	 * @return array<string, mixed>
	 */
	public static function fromBrief( array $brief ): array {
		$sections = array();
		$current  = null;
		foreach ( (array) ( $brief['outline'] ?? array() ) as $item ) {
			if ( '' === trim( (string) ( $item['heading'] ?? '' ) ) ) {
				continue; // Empty rows of a hand-written outline that was not filled in.
			}
			if ( 2 === (int) $item['level'] || null === $current ) {
				if ( null !== $current ) {
					$sections[] = $current;
				}
				$current = self::part(
					'section-' . ( count( $sections ) + 1 ),
					array(
						'heading'     => (string) $item['heading'],
						'notes'       => (string) ( $item['notes'] ?? '' ),
						'subheadings' => array(),
					)
				);
			} else {
				$current['subheadings'][] = array(
					'heading' => (string) $item['heading'],
					'notes'   => (string) ( $item['notes'] ?? '' ),
				);
			}
		}
		if ( null !== $current ) {
			$sections[] = $current;
		}

		return array(
			'version'          => 1,
			'title'            => (string) ( $brief['title'] ?? '' ),
			'h1'               => (string) ( $brief['h1'] ?? $brief['title'] ?? '' ),
			'intro'            => self::part( 'intro' ),
			'sections'         => $sections,
			'faqs'             => array_map(
				static fn ( array $f ): array => array(
					'question'    => (string) $f['question'],
					'answer_html' => '',
				),
				(array) ( $brief['faqs'] ?? array() )
			),
			'faqs_status'      => 'draft',
			'faqs_enabled'     => true,
			'faqs_edited'      => false,
			'conclusion'       => self::part( 'conclusion' ),
			'meta'             => array(
				'meta_title'            => (string) ( $brief['meta_title'] ?? '' ),
				'meta_description'      => (string) ( $brief['meta_description'] ?? '' ),
				'slug'                  => (string) ( $brief['slug'] ?? '' ),
				'schema_type'           => '',
				'schema_reason'         => '',
				'og_title'              => '',
				'og_description'        => '',
				'featured_image_prompt' => '',
				'featured_image_alt'    => '',
				'image_placements'      => array(),
				'faq_schema'            => true,
				'title_options'         => array(),
				'description_options'   => array(),
			),
			'claims_to_verify' => array(),
			'sources'          => array(),
			'edit_report'      => null,
			'generated'        => null,
		);
	}

	/**
	 * A content part.
	 *
	 * @param string               $id    Id.
	 * @param array<string, mixed> $extra Extra fields.
	 * @return array<string, mixed>
	 */
	public static function part( string $id, array $extra = array() ): array {
		return array_merge(
			array(
				'id'        => $id,
				'html'      => '',
				'status'    => 'empty',
				'locked'    => false,
				'generated' => null,
			),
			$extra
		);
	}

	/**
	 * All parts in document order (intro, sections, conclusion).
	 *
	 * @param array<string, mixed> $content Content.
	 * @return array<int, array<string, mixed>>
	 */
	public static function parts( array $content ): array {
		return array_merge( array( $content['intro'] ), (array) $content['sections'], array( $content['conclusion'] ) );
	}

	/**
	 * Replace a part by id.
	 *
	 * @param array<string, mixed> $content Content.
	 * @param array<string, mixed> $part    Part.
	 * @return array<string, mixed>
	 */
	public static function withPart( array $content, array $part ): array {
		if ( 'intro' === $part['id'] || 'conclusion' === $part['id'] ) {
			$content[ $part['id'] ] = $part;
			return $content;
		}
		foreach ( $content['sections'] as $i => $section ) {
			if ( $section['id'] === $part['id'] ) {
				$content['sections'][ $i ] = $part;
			}
		}
		return $content;
	}

	/**
	 * Find a part.
	 *
	 * @param array<string, mixed> $content Content.
	 * @param string               $id      Id.
	 * @return array<string, mixed>|null
	 */
	public static function findPart( array $content, string $id ): ?array {
		foreach ( self::parts( $content ) as $part ) {
			if ( $part['id'] === $id ) {
				return $part;
			}
		}
		return null;
	}

	/**
	 * Sanitise a content structure from untrusted input.
	 *
	 * @param array<string, mixed> $input         Input.
	 * @param string[]             $allowed_links Allowed link URLs.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input, array $allowed_links = array() ): array {
		$part = static function ( mixed $p, string $id, bool $section ) use ( $allowed_links ): array {
			$p   = is_array( $p ) ? $p : array();
			$out = self::part(
				$id,
				array(
					'html'      => self::cleanHtml( (string) ( $p['html'] ?? '' ), $allowed_links ),
					'status'    => Sanitize::enum( $p['status'] ?? '', array( 'empty', 'draft', 'approved' ), 'draft' ),
					'locked'    => ! empty( $p['locked'] ),
					'generated' => is_array( $p['generated'] ?? null ) ? array(
						'provider' => Sanitize::text( $p['generated']['provider'] ?? '', 40 ),
						'model'    => Sanitize::text( $p['generated']['model'] ?? '', 120 ),
						'at'       => (int) ( $p['generated']['at'] ?? 0 ),
					) : null,
				)
			);
			if ( '' === $out['html'] ) {
				$out['status'] = 'empty';
			} elseif ( 'empty' === $out['status'] ) {
				$out['status'] = 'draft';
			}
			if ( $section ) {
				$out['heading']     = Sanitize::text( $p['heading'] ?? '', 200 );
				$out['notes']       = Sanitize::textarea( $p['notes'] ?? '', 1000 );
				$out['subheadings'] = array();
				foreach ( array_slice( (array) ( $p['subheadings'] ?? array() ), 0, 12 ) as $sub ) {
					if ( is_array( $sub ) && '' !== Sanitize::text( $sub['heading'] ?? '' ) ) {
						$out['subheadings'][] = array(
							'heading' => Sanitize::text( $sub['heading'], 200 ),
							'notes'   => Sanitize::textarea( $sub['notes'] ?? '', 600 ),
						);
					}
				}
			}
			return $out;
		};

		$sections = array();
		foreach ( array_slice( (array) ( $input['sections'] ?? array() ), 0, 40 ) as $i => $section ) {
			$id         = is_array( $section ) && preg_match( '/^section-\d{1,3}$/', (string) ( $section['id'] ?? '' ) ) ? (string) $section['id'] : 'section-' . ( $i + 1 );
			$clean      = $part( $section, $id, true );
			$sections[] = $clean;
		}

		$faqs = array();
		foreach ( array_slice( (array) ( $input['faqs'] ?? array() ), 0, 20 ) as $faq ) {
			$q = is_array( $faq ) ? Sanitize::text( $faq['question'] ?? '', 300 ) : '';
			if ( '' !== $q ) {
				$faqs[] = array(
					'question'    => $q,
					'answer_html' => self::cleanHtml( (string) ( $faq['answer_html'] ?? '' ), $allowed_links ),
				);
			}
		}

		$meta       = is_array( $input['meta'] ?? null ) ? $input['meta'] : array();
		$placements = array();
		foreach ( array_slice( (array) ( $meta['image_placements'] ?? array() ), 0, 10 ) as $p ) {
			if ( is_array( $p ) && '' !== Sanitize::text( $p['prompt'] ?? '' ) ) {
				$placements[] = array(
					'after_section' => Sanitize::text( $p['after_section'] ?? '', 200 ),
					'prompt'        => Sanitize::textarea( $p['prompt'], 800 ),
					'alt'           => Sanitize::text( $p['alt'] ?? '', 250 ),
				);
			}
		}

		return array(
			'version'          => 1,
			'title'            => Sanitize::text( $input['title'] ?? '', 200 ),
			'h1'               => Sanitize::text( $input['h1'] ?? '', 200 ),
			'intro'            => $part( $input['intro'] ?? array(), 'intro', false ),
			'sections'         => $sections,
			'faqs'             => $faqs,
			'faqs_status'      => Sanitize::enum( $input['faqs_status'] ?? '', array( 'draft', 'approved' ), 'draft' ),
			'faqs_enabled'     => ! array_key_exists( 'faqs_enabled', $input ) || filter_var( $input['faqs_enabled'], FILTER_VALIDATE_BOOLEAN ),
			'faqs_edited'      => filter_var( $input['faqs_edited'] ?? false, FILTER_VALIDATE_BOOLEAN ),
			'conclusion'       => $part( $input['conclusion'] ?? array(), 'conclusion', false ),
			'meta'             => array(
				'meta_title'            => Sanitize::text( $meta['meta_title'] ?? '', 120 ),
				'meta_description'      => Sanitize::text( $meta['meta_description'] ?? '', 320 ),
				'slug'                  => sanitize_title( (string) ( $meta['slug'] ?? '' ) ),
				'schema_type'           => Sanitize::enum( $meta['schema_type'] ?? '', self::SCHEMA_TYPES ),
				'schema_reason'         => Sanitize::text( $meta['schema_reason'] ?? '', 400 ),
				'og_title'              => Sanitize::text( $meta['og_title'] ?? '', 120 ),
				'og_description'        => Sanitize::text( $meta['og_description'] ?? '', 320 ),
				'featured_image_prompt' => Sanitize::textarea( $meta['featured_image_prompt'] ?? '', 800 ),
				'featured_image_alt'    => Sanitize::text( $meta['featured_image_alt'] ?? '', 250 ),
				'image_placements'      => $placements,
				'faq_schema'            => ! array_key_exists( 'faq_schema', $meta ) || filter_var( $meta['faq_schema'], FILTER_VALIDATE_BOOLEAN ),
				'title_options'         => Sanitize::strings( $meta['title_options'] ?? array(), 5, 120 ),
				'description_options'   => Sanitize::strings( $meta['description_options'] ?? array(), 5, 320 ),
			),
			'claims_to_verify' => Sanitize::strings( $input['claims_to_verify'] ?? array(), 60, 600 ),
			'sources'          => self::sanitizeSources( $input['sources'] ?? array() ),
			'edit_report'      => is_array( $input['edit_report'] ?? null ) ? array(
				'at'      => (int) ( $input['edit_report']['at'] ?? 0 ),
				'applied' => ! empty( $input['edit_report']['applied'] ),
				'changes' => Sanitize::strings( $input['edit_report']['changes'] ?? array(), 30, 300 ),
				'note'    => Sanitize::text( $input['edit_report']['note'] ?? '', 300 ),
			) : null,
			'generated'        => is_array( $input['generated'] ?? null ) ? array(
				'provider' => Sanitize::text( $input['generated']['provider'] ?? '', 40 ),
				'model'    => Sanitize::text( $input['generated']['model'] ?? '', 120 ),
				'at'       => (int) ( $input['generated']['at'] ?? 0 ),
			) : null,
		);
	}

	/**
	 * Sanitise checked sources.
	 *
	 * @param mixed $rows Rows.
	 * @return array<int, array{claim: string, url: string, status: string}>
	 */
	public static function sanitizeSources( mixed $rows ): array {
		$out  = array();
		$seen = array();
		foreach ( array_slice( is_array( $rows ) ? $rows : array(), 0, 40 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$claim = Sanitize::text( $row['claim'] ?? '', 300 );
			$url   = esc_url_raw( (string) ( $row['url'] ?? '' ), array( 'https' ) );
			$key   = mb_strtolower( $claim ) . '|' . $url;
			if ( '' === $claim || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array(
				'claim'  => $claim,
				'url'    => $url,
				'status' => Sanitize::enum( $row['status'] ?? '', self::SOURCE_STATUSES, 'not_checked' ),
			);
		}
		return $out;
	}

	/**
	 * Official source pages that were verified, which may stay linked in the text.
	 *
	 * @param array<string, mixed>|null $content Content.
	 * @return string[]
	 */
	public static function verifiedSourceUrls( ?array $content ): array {
		$urls = array();
		foreach ( (array) ( $content['sources'] ?? array() ) as $source ) {
			if ( is_array( $source ) && 'verified' === ( $source['status'] ?? '' ) && '' !== (string) ( $source['url'] ?? '' ) ) {
				$urls[] = (string) $source['url'];
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Assemble body HTML (without the H1, which becomes the post title).
	 *
	 * @param array<string, mixed>  $content Content.
	 * @param bool                  $with_faq_heading Include FAQ section.
	 * @param array<string, string> $after_sections  Trusted HTML to insert after a section, keyed by section id (images).
	 */
	public static function bodyHtml( array $content, bool $with_faq_heading = true, array $after_sections = array() ): string {
		$html = (string) $content['intro']['html'];
		foreach ( (array) $content['sections'] as $section ) {
			if ( '' === trim( (string) $section['html'] ) && '' === trim( (string) $section['heading'] ) ) {
				continue;
			}
			$html .= "\n<h2>" . esc_html( (string) $section['heading'] ) . "</h2>\n" . $section['html'];
			if ( isset( $after_sections[ (string) ( $section['id'] ?? '' ) ] ) ) {
				$html .= "\n" . $after_sections[ (string) $section['id'] ];
			}
		}
		$faqs = self::publishedFaqs( $content );
		if ( $with_faq_heading && array() !== $faqs ) {
			$html .= "\n<h2>" . esc_html__( 'Frequently asked questions', 'ai-seo-content-studio' ) . "</h2>\n";
			foreach ( $faqs as $faq ) {
				$html .= '<h3>' . esc_html( (string) $faq['question'] ) . "</h3>\n" . $faq['answer_html'] . "\n";
			}
		}
		$html .= "\n" . $content['conclusion']['html'];
		return trim( $html );
	}

	/**
	 * FAQs that appear on the page: the FAQ section is switched on and the question is answered.
	 *
	 * @param array<string, mixed> $content Content.
	 * @return array<int, array{question: string, answer_html: string}>
	 */
	public static function publishedFaqs( array $content ): array {
		if ( false === ( $content['faqs_enabled'] ?? true ) ) {
			return array();
		}
		return array_values(
			array_filter(
				(array) ( $content['faqs'] ?? array() ),
				static fn ( $f ): bool => is_array( $f ) && '' !== trim( (string) ( $f['question'] ?? '' ) ) && '' !== trim( (string) ( $f['answer_html'] ?? '' ) )
			)
		);
	}

	/**
	 * Plain text of HTML.
	 *
	 * @param string $html HTML.
	 */
	public static function text( string $html ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( str_replace( '>', '> ', $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Word count of HTML.
	 *
	 * @param string $html HTML.
	 */
	public static function wordCount( string $html ): int {
		$text = self::text( $html );
		return '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) ?: array() );
	}
}
