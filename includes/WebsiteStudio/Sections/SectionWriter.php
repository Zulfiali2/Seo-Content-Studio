<?php
/**
 * AI rewrite, SEO improvement or redesign of one section.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Sections;

use AiSeoContentStudio\Content\QualityGuidelines;
use AiSeoContentStudio\Providers\Contracts\Message;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\WebsiteStudio\Engine\HtmlFragment;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;
use AiSeoContentStudio\WebsiteStudio\Generator\ContentLayoutMapper;
use AiSeoContentStudio\WebsiteStudio\Generator\PageGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * One strict-JSON request that returns a single section in the page generator's section
 * format. Only that section is sent and returned; the rest of the page is described by its
 * section types only, so it cannot change. Images are never invented: image slots in the
 * answer reuse the section's current images, in order.
 */
final class SectionWriter {

	public const PURPOSE = 'studio_section_regenerate';

	public const ACTIONS = array( 'rewrite', 'seo', 'redesign' );

	/**
	 * The request.
	 *
	 * @param string               $action      rewrite|seo|redesign.
	 * @param array<string, mixed> $section     Normalised section.
	 * @param array<string, mixed> $page        {title, types: list of section types, index}.
	 * @param array<string, mixed> $brief       Brief (topic, primary_keyword, ...).
	 * @param string               $instruction User instruction (≤1000).
	 * @param array<string, mixed> $context     WritingProfile::context().
	 */
	public static function request( string $action, array $section, array $page, array $brief, string $instruction, array $context ): TextRequest {
		$images = count( self::images( (array) $section['elements'] ) );
		$task   = match ( $action ) {
			'seo'      => 'Improve this section for search without changing its structure: keep the same elements in the same order, the same layout and the same number of cards, buttons and images. Use the primary keyword (and secondary keywords) where they read naturally, make the heading descriptive of what the searcher wants, and make the text answer the search intent. Never stuff keywords.',
			'redesign' => "Design a better version of this section for the same purpose. You may change the layout, the number and order of cards and elements, and the wording. Use at most $images image element(s): images reuse the section's existing pictures.",
			default    => 'Rewrite the text of this section. Keep the structure exactly: the same elements in the same order, the same layout, and the same number of cards, buttons and images. Keep each text close to its current length.',
		};
		$rules = array_merge(
			array(
				'You are a senior conversion copywriter and web designer improving ONE section of an existing landing page. Return only that section, in the given JSON format, and a one-sentence summary of what you changed.',
				'- ' . $task,
				'- The user instruction, when given, takes priority over style choices (never over the rules about facts).',
				'- The section is section ' . ( (int) ( $page['index'] ?? 0 ) + 1 ) . ' of the page. Use h2 for its main heading unless it is the hero (the only h1 of the page) and h3 for card titles.',
			),
			PageGenerator::contentRules(),
			QualityGuidelines::writing( false )
		);
		$payload = array(
			'action'          => $action,
			'instruction'     => $instruction,
			'brief'           => array(
				'topic'              => (string) ( $brief['topic'] ?? '' ),
				'primary_keyword'    => (string) ( $brief['primary_keyword'] ?? '' ),
				'secondary_keywords' => array_values( (array) ( $brief['secondary_keywords'] ?? array() ) ),
				'audience'           => (string) ( $brief['audience'] ?? '' ),
				'location'           => (string) ( $brief['location'] ?? '' ),
				'tone'               => (string) ( $brief['tone'] ?? '' ),
				'notes'              => (string) ( $brief['notes'] ?? '' ),
			),
			'page'            => array(
				'title'         => (string) ( $page['title'] ?? '' ),
				'section_types' => array_values( (array) ( $page['types'] ?? array() ) ),
			),
			'business'        => $context['business'] ?? new \stdClass(),
			'current_section' => self::toAnswer( $section ),
		);
		$schema  = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'section', 'summary' ),
			'properties'           => array(
				'section' => PageGenerator::sectionSchema(),
				'summary' => array( 'type' => 'string' ),
			),
		);
		return new TextRequest(
			array( new Message( 'user', (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ),
			implode( "\n", $rules ),
			8000,
			null,
			$schema,
			'page_section',
			self::PURPOSE
		);
	}

	/**
	 * The answer as a normalised section with the old id, layout (unless redesigned), H1 role
	 * and images.
	 *
	 * @param mixed                $data    Parsed JSON.
	 * @param string               $action  Action.
	 * @param array<string, mixed> $old     Current normalised section.
	 * @param array<string, mixed> $brief   Brief (cta_url).
	 * @return array{section: array<string, mixed>, summary: string, report: array<string, mixed>}
	 * @throws \UnexpectedValueException When the answer holds no valid section.
	 */
	public static function parse( mixed $data, string $action, array $old, array $brief = array() ): array {
		$data = is_array( $data ) ? $data : array();
		$gen  = PageGenerator::toDocument( array( 'sections' => array( $data['section'] ?? null ) ), $brief + array( 'images' => PageGenerator::MAX_IMAGES ) );
		$new  = $gen['document']['sections'][0] ?? null;
		if ( null === $new ) {
			throw new \UnexpectedValueException( __( 'The AI answer did not contain a usable section. Try again.', 'ai-seo-content-studio' ) );
		}
		$report = array(
			'images_dropped' => 0,
			'icons_replaced' => (int) $gen['report']['icons_replaced'],
		);
		$new['id'] = (string) $old['id'];
		if ( 'redesign' !== $action ) {
			$new['layout'] = $old['layout'];
			$new['style']  = $old['style'];
		} else {
			$new['style']['padding'] = $old['style']['padding'] ?? array();
			$new['layout']           = ContentLayoutMapper::adapt( array( 'sections' => array( $new ) ), false )['document']['sections'][0]['layout'];
		}
		// The page keeps its H1 where it was.
		$had_h1 = self::hasH1( (array) $old['elements'] );
		$first  = true;
		$fix    = static function ( array $elements ) use ( &$fix, $had_h1, &$first ): array {
			foreach ( $elements as $i => $e ) {
				if ( 'group' === $e['type'] ) {
					$elements[ $i ]['elements'] = $fix( (array) $e['elements'] );
				} elseif ( 'heading' === $e['type'] ) {
					$elements[ $i ]['level'] = $had_h1 && $first ? 'h1' : ( 'h1' === $e['level'] ? 'h2' : $e['level'] );
					$first                   = false;
				}
			}
			return $elements;
		};
		$new['elements'] = $fix( (array) $new['elements'] );
		// Images: reuse the current pictures in order.
		$pictures = self::images( (array) $old['elements'] );
		$swap     = static function ( array $elements ) use ( &$swap, &$pictures, &$report ): array {
			$out = array();
			foreach ( $elements as $e ) {
				if ( 'group' === $e['type'] ) {
					$e['elements'] = $swap( (array) $e['elements'] );
					if ( array() === $e['elements'] ) {
						continue;
					}
				} elseif ( '_image' === $e['type'] ) {
					$picture = array_shift( $pictures );
					if ( null === $picture ) {
						++$report['images_dropped'];
						continue;
					}
					$e = array(
						'type'          => 'image',
						'attachment_id' => (int) $picture['attachment_id'],
						'url'           => (string) $picture['url'],
						'alt'           => '' !== (string) ( $e['alt'] ?? '' ) ? (string) $e['alt'] : (string) $picture['alt'],
						'caption'       => (string) ( $picture['caption'] ?? '' ),
					);
				}
				unset( $e['_words'] );
				$out[] = $e;
			}
			return $out;
		};
		$new['elements'] = $swap( (array) $new['elements'] );
		$errors          = array();
		$doc             = PageDocument::normalize(
			array(
				'schema_version' => PageDocument::VERSION,
				'page'           => array(
					'title' => 'Section',
					'slug'  => '',
				),
				'sections'       => array( $new ),
			),
			$errors
		);
		if ( array() !== $errors || empty( $doc['sections'][0] ) ) {
			throw new \UnexpectedValueException( __( 'The AI answer did not form a valid section:', 'ai-seo-content-studio' ) . ' ' . implode( ' ', array_slice( $errors, 0, 3 ) ) );
		}
		return array(
			'section' => $doc['sections'][0],
			'summary' => PageDocument::plain( $data['summary'] ?? '', 200 ),
			'report'  => $report,
		);
	}

	/**
	 * Images of a section, in order.
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @return list<array<string, mixed>>
	 */
	public static function images( array $elements ): array {
		$out = array();
		foreach ( $elements as $e ) {
			if ( 'group' === ( $e['type'] ?? '' ) ) {
				$out = array_merge( $out, self::images( (array) $e['elements'] ) );
			} elseif ( 'image' === ( $e['type'] ?? '' ) ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	/**
	 * Whether elements contain an H1.
	 *
	 * @param array<int, mixed> $elements Elements.
	 */
	private static function hasH1( array $elements ): bool {
		foreach ( $elements as $e ) {
			if ( ( 'heading' === ( $e['type'] ?? '' ) && 'h1' === ( $e['level'] ?? '' ) ) || ( 'group' === ( $e['type'] ?? '' ) && self::hasH1( (array) $e['elements'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A normalised section in the answer format (what the model sees as "current_section").
	 *
	 * @param array<string, mixed> $section Section.
	 * @return array<string, mixed>
	 */
	public static function toAnswer( array $section ): array {
		$leaf   = static function ( array $e ): ?array {
			$base = array(
				'type'    => $e['type'],
				'level'   => '',
				'text'    => '',
				'items'   => array(),
				'answers' => array(),
				'variant' => '',
				'icon'    => '',
				'aspect'  => '',
				'prompt'  => '',
			);
			switch ( $e['type'] ) {
				case 'heading':
					return array_merge( $base, array( 'level' => $e['level'], 'text' => $e['text'] ) );
				case 'text':
					return array_merge( $base, array( 'text' => trim( HtmlFragment::text( (string) preg_replace( '#</(p|li|h3|h4)>#i', "\n\n", (string) $e['html'] ) ) ) ) );
				case 'button':
					return array_merge( $base, array( 'text' => $e['text'], 'variant' => $e['variant'] ) );
				case 'image':
					return array_merge( $base, array( 'text' => $e['alt'], 'aspect' => '3:2' ) );
				case 'icon':
					return array_merge( $base, array( 'icon' => $e['icon'] ) );
				case 'list':
					return array_merge( $base, array( 'items' => array_values( (array) $e['items'] ) ) );
				case 'accordion':
					return array_merge(
						$base,
						array(
							'items'   => array_column( (array) $e['items'], 'title' ),
							'answers' => array_map( static fn ( array $i ): string => HtmlFragment::text( (string) $i['html'] ), (array) $e['items'] ),
						)
					);
				case 'divider':
					return $base;
			}
			return null;
		};
		$header = array();
		$groups = array();
		foreach ( (array) $section['elements'] as $e ) {
			if ( 'group' === $e['type'] ) {
				$groups[] = array(
					'card'     => '' !== (string) ( $e['style']['background'] ?? '' ) || '' !== (string) ( $e['style']['shadow'] ?? '' ),
					'elements' => array_values( array_filter( array_map( $leaf, (array) $e['elements'] ) ) ),
				);
			} else {
				$item = $leaf( $e );
				if ( null !== $item ) {
					$header[] = $item;
				}
			}
		}
		return array(
			'type'       => $section['type'],
			'layout'     => $section['layout']['kind'],
			'columns'    => (int) $section['layout']['columns']['desktop'],
			'background' => '',
			'align'      => 'center' === $section['layout']['align'] ? 'center' : 'left',
			'header'     => $header,
			'groups'     => $groups,
		);
	}
}
