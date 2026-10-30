<?php
/**
 * Screenshot → design structure (vision request) → PageDocument.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Vision;

use AiSeoContentStudio\Providers\Contracts\ImageInput;
use AiSeoContentStudio\Providers\Contracts\Message;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\WebsiteStudio\Design\DesignTokens;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorMapper;
use AiSeoContentStudio\WebsiteStudio\Engine\HtmlFragment;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;

defined( 'ABSPATH' ) || exit;

/**
 * Output A of the screenshot workflow: the page's structure and visual appearance, as seen
 * by a vision model, turned into a PageDocument plus brand tokens. Output B (new content for
 * the same layout) is written afterwards from slots(), with a separate text request.
 *
 * A screenshot cannot show source code, exact fonts, hidden interactions or real responsive
 * behaviour: everything here is a visual approximation, and the report says what was guessed.
 */
final class VisionDesign {

	public const PURPOSE = 'studio_vision_layout';

	private const LEAF_TYPES = array( 'heading', 'text', 'button', 'image', 'icon', 'list', 'accordion', 'divider' );

	private const SHAPES = array(
		'square'  => '0',
		'rounded' => '8px',
		'pill'    => '999px',
	);

	private const GENERIC_FONTS = array( 'sans-serif', 'serif', 'display', 'script', 'monospace', 'unknown', 'system', 'system-ui' );

	/**
	 * The vision request.
	 *
	 * @param list<ImageInput> $tiles   Screenshot tiles, top to bottom.
	 * @param string           $model   Model override ("" = provider default).
	 * @param int              $max_out Max output tokens.
	 */
	public static function request( array $tiles, string $model = '', int $max_out = 12000 ): TextRequest {
		$rules = array(
			'You are a senior web designer rebuilding a web page from a screenshot. The images are consecutive vertical slices of ONE page, top to bottom.',
			'Describe the page as sections from top to bottom. Skip the site header/navigation bar and the footer: the website theme provides them.',
			'For each section give its layout: "stack" (one column), "split" (exactly two side-by-side halves) or "grid" (repeated cards/columns). Put elements above the columns (a section heading and intro) in "header", and each column/card in "groups".',
			'Elements: heading (with level h1-h6; only one h1, the main hero heading), text (paragraphs), button (variant primary = filled, secondary = outlined), image (with aspect 3:2, 1:1 or 2:3), icon (a Font Awesome solid name such as check, star, phone, shield-alt), list (bullet items), accordion (FAQ questions in items, answers in answers), divider.',
			'Copy the visible text exactly into "text" (and "items"/"answers"). If text is too small to read, describe its purpose in a few words in square brackets, e.g. "[short paragraph about the service]". Set approx_words to the length of the text you see.',
			'Colours: give hex values you see (#RRGGBB) for the palette and each section background; use "" when unsure. dark_background is true when text on the section is light.',
			'Fonts: name a font only if you clearly recognise it (e.g. "Poppins"); otherwise use "" and set font_style.',
			'Be faithful to what is visible. Do not invent sections, testimonials, numbers or logos that are not in the screenshot.',
		);
		return new TextRequest(
			array( new Message( 'user', 'Analyse this page screenshot (' . count( $tiles ) . ' slice(s), top to bottom) and return its structure.', $tiles ) ),
			implode( "\n", $rules ),
			$max_out,
			'' === $model ? null : $model,
			self::schema(),
			'page_layout',
			self::PURPOSE
		);
	}

	/**
	 * Strict-mode schema (no recursion: groups hold leaf elements only).
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$str  = array( 'type' => 'string' );
		$strs = array(
			'type'  => 'array',
			'items' => $str,
		);
		$enum = static fn ( array $values ): array => array(
			'type' => 'string',
			'enum' => $values,
		);
		$obj  = static fn ( array $props ): array => array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array_keys( $props ),
			'properties'           => $props,
		);
		$leaf    = $obj(
			array(
				'type'         => $enum( self::LEAF_TYPES ),
				'level'        => $enum( array( '', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) ),
				'text'         => $str,
				'items'        => $strs,
				'answers'      => $strs,
				'variant'      => $enum( array( '', 'primary', 'secondary' ) ),
				'icon'         => $str,
				'aspect'       => $enum( array( '', '3:2', '1:1', '2:3' ) ),
				'approx_words' => array( 'type' => 'integer' ),
			)
		);
		$leaves  = array(
			'type'  => 'array',
			'items' => $leaf,
		);
		$group   = $obj(
			array(
				'background' => $str,
				'shadow'     => array( 'type' => 'boolean' ),
				'rounded'    => array( 'type' => 'boolean' ),
				'elements'   => $leaves,
			)
		);
		$section = $obj(
			array(
				'type'                => $enum( PageDocument::SECTION_TYPES ),
				'layout'              => $enum( array( 'stack', 'split', 'grid' ) ),
				'columns'             => array( 'type' => 'integer' ),
				'background'          => $str,
				'dark_background'     => array( 'type' => 'boolean' ),
				'background_is_photo' => array( 'type' => 'boolean' ),
				'align'               => $enum( array( 'left', 'center', 'right' ) ),
				'tall'                => array( 'type' => 'boolean' ),
				'header'              => $leaves,
				'groups'              => array(
					'type'  => 'array',
					'items' => $group,
				),
			)
		);
		$roles   = array();
		foreach ( array( 'primary', 'secondary', 'accent', 'background', 'surface', 'text', 'heading' ) as $role ) {
			$roles[ $role ] = $str;
		}
		return $obj(
			array(
				'page_title'        => $str,
				'palette'           => $obj( $roles ),
				'heading_font'      => $str,
				'body_font'         => $str,
				'font_style'        => $obj(
					array(
						'heading' => $enum( array( 'sans-serif', 'serif', 'display', 'script', 'monospace' ) ),
						'body'    => $enum( array( 'sans-serif', 'serif', 'display', 'script', 'monospace' ) ),
					)
				),
				'button_shape'      => $enum( array_keys( self::SHAPES ) ),
				'card_shape'        => $enum( array_keys( self::SHAPES ) ),
				'sections'          => array(
					'type'  => 'array',
					'items' => $section,
				),
				'observations'      => $strs,
			)
		);
	}

	/**
	 * Turn the model's answer into a PageDocument input, brand tokens and a report.
	 *
	 * @param mixed $data Parsed JSON.
	 * @return array{document: array<string, mixed>, tokens: array<string, mixed>, report: array<string, mixed>}
	 */
	public static function toDocument( mixed $data ): array {
		$data    = is_array( $data ) ? $data : array();
		$palette = array();
		foreach ( (array) ( $data['palette'] ?? array() ) as $role => $hex ) {
			$clean = DesignTokens::color( is_string( $hex ) ? $hex : '' );
			if ( is_string( $role ) && null !== $clean && 7 === strlen( $clean ) ) {
				$palette[ $role ] = $clean;
			}
		}
		$report = array(
			'approximations' => array( 'visual_approximation' ),
			'placeholders'   => 0,
			'photo_backgrounds' => 0,
			'icons_replaced' => 0,
			'observations'   => array_slice( array_map( static fn ( $o ): string => PageDocument::plain( $o, 200 ), (array) ( $data['observations'] ?? array() ) ), 0, 10 ),
			'dropped'        => 0,
		);

		$sections = array();
		$h1_used  = false;
		foreach ( array_slice( (array) ( $data['sections'] ?? array() ), 0, 40 ) as $i => $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$header = self::leaves( (array) ( $s['header'] ?? array() ), $report, $h1_used );
			$groups = array();
			foreach ( array_slice( (array) ( $s['groups'] ?? array() ), 0, 12 ) as $g ) {
				if ( ! is_array( $g ) ) {
					continue;
				}
				$leaves = self::leaves( (array) ( $g['elements'] ?? array() ), $report, $h1_used );
				if ( array() === $leaves ) {
					continue;
				}
				$bg       = DesignTokens::color( is_string( $g['background'] ?? null ) ? $g['background'] : '' ) ?? '';
				$groups[] = array(
					'type'     => 'group',
					'style'    => array(
						'background' => self::colorRef( $bg, $palette ),
						'radius'     => ! empty( $g['rounded'] ) ? '12px' : '',
						'shadow'     => ! empty( $g['shadow'] ) ? '0 4px 16px 0 #0000001A' : '',
						'padding'    => '' !== $bg || ! empty( $g['shadow'] ) ? '32px' : '',
					),
					'elements' => array_slice( $leaves, 0, 20 ),
				);
			}
			if ( array() === $header && array() === $groups ) {
				continue;
			}
			$layout = in_array( $s['layout'] ?? '', array( 'stack', 'split', 'grid' ), true ) ? $s['layout'] : 'stack';
			if ( array() === $groups ) {
				$layout = 'stack';
			} elseif ( 'split' === $layout && ( 2 !== count( $groups ) || array() !== $header ) ) {
				$layout = 'grid';
			} elseif ( 'stack' === $layout ) {
				$layout = 'grid';
			}
			$desktop = 'split' === $layout ? 2 : max( 1, min( 6, (int) ( $s['columns'] ?? 0 ) ?: count( $groups ) ) );
			$bg      = DesignTokens::color( is_string( $s['background'] ?? null ) ? $s['background'] : '' ) ?? '';
			$bg      = 7 === strlen( $bg ) ? $bg : '';
			if ( ! empty( $s['background_is_photo'] ) ) {
				++$report['photo_backgrounds'];
			}
			$type       = in_array( $s['type'] ?? '', PageDocument::SECTION_TYPES, true ) ? $s['type'] : 'content';
			$sections[] = array(
				'id'       => 'section-' . ( count( $sections ) + 1 ),
				'type'     => $type,
				'layout'   => array(
					'kind'          => $layout,
					'columns'       => array(
						'desktop' => $desktop,
						'tablet'  => min( 2, $desktop ),
						'mobile'  => 1,
					),
					'align'         => in_array( $s['align'] ?? '', array( 'left', 'center', 'right' ), true ) ? $s['align'] : 'left',
					'content_width' => 'boxed',
					'min_height'    => ! empty( $s['tall'] ) && 'hero' === $type ? '560px' : '',
				),
				'style'    => array(
					'background' => self::colorRef( $bg, $palette ),
					'text_color' => '' !== $bg && ! empty( $s['dark_background'] ) ? ElementorMapper::contrastText( $bg ) : '',
				),
				'elements' => array_slice( array_merge( $header, $groups ), 0, 60 ),
			);
		}

		$tokens = array(
			'colors'     => array(
				'primary'    => $palette['primary'] ?? '',
				'secondary'  => $palette['secondary'] ?? '',
				'accent'     => $palette['accent'] ?? '',
				'background' => $palette['background'] ?? '',
				'surface'    => $palette['surface'] ?? '',
				'text'       => $palette['text'] ?? '',
				'heading'    => $palette['heading'] ?? ( $palette['text'] ?? '' ),
			),
			'typography' => array(
				'heading' => array( 'family' => self::font( $data['heading_font'] ?? '' ) ),
				'body'    => array( 'family' => self::font( $data['body_font'] ?? '' ) ),
			),
			'components' => array(
				'button' => array( 'radius' => self::SHAPES[ $data['button_shape'] ?? '' ] ?? '' ),
				'card'   => array( 'radius' => self::SHAPES[ $data['card_shape'] ?? '' ] ?? '' ),
			),
			'sources'    => array(
				'colors'     => 'user',
				'typography' => 'user',
			),
		);
		if ( '' !== $tokens['typography']['heading']['family'] || '' !== $tokens['typography']['body']['family'] ) {
			$report['approximations'][] = 'fonts_guessed';
		} else {
			$report['approximations'][] = 'fonts_unknown';
		}
		if ( $report['photo_backgrounds'] > 0 ) {
			$report['approximations'][] = 'photo_backgrounds';
		}

		$title = PageDocument::plain( $data['page_title'] ?? '', 200 );
		return array(
			'document' => array(
				'schema_version' => PageDocument::VERSION,
				'page'           => array(
					'title' => '' === $title ? 'Page from screenshot' : $title,
					'slug'  => '',
				),
				'sections'       => $sections,
			),
			'tokens'   => DesignTokens::normalize( $tokens ),
			'report'   => $report,
		);
	}

	/**
	 * Leaf elements from the model's list.
	 *
	 * @param array<int, mixed>    $list    Elements.
	 * @param array<string, mixed> $report  Report.
	 * @param bool                 $h1_used Whether an h1 was already placed.
	 * @return list<array<string, mixed>>
	 */
	private static function leaves( array $list, array &$report, bool &$h1_used ): array {
		$out = array();
		foreach ( array_slice( $list, 0, 30 ) as $e ) {
			if ( ! is_array( $e ) || ! in_array( $e['type'] ?? '', self::LEAF_TYPES, true ) ) {
				++$report['dropped'];
				continue;
			}
			$text  = PageDocument::plain( $e['text'] ?? '', 1000 );
			$words = max( 0, min( 400, (int) ( $e['approx_words'] ?? 0 ) ) );
			$items = array_values( array_filter( array_map( static fn ( $i ): string => PageDocument::plain( $i, 300 ), array_slice( (array) ( $e['items'] ?? array() ), 0, 30 ) ) ) );
			switch ( $e['type'] ) {
				case 'heading':
					$level = in_array( $e['level'] ?? '', array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $e['level'] : 'h2';
					if ( 'h1' === $level && $h1_used ) {
						$level = 'h2'; // One H1 per page.
					}
					$h1_used = $h1_used || 'h1' === $level;
					if ( '' !== $text ) {
						$out[] = array(
							'type'  => 'heading',
							'text'  => mb_substr( $text, 0, 300 ),
							'level' => $level,
							'_words' => $words,
						);
					}
					break;
				case 'text':
					if ( '' !== $text ) {
						$out[] = array(
							'type'   => 'text',
							'html'   => '<p>' . htmlspecialchars( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '</p>',
							'_words' => $words,
						);
					}
					break;
				case 'button':
					$out[] = array(
						'type'    => 'button',
						'text'    => mb_substr( '' !== $text ? $text : 'Learn more', 0, 80 ),
						'url'     => '#',
						'variant' => 'secondary' === ( $e['variant'] ?? '' ) ? 'secondary' : 'primary',
					);
					break;
				case 'image':
					$out[] = array(
						'type'   => '_image',
						'aspect' => in_array( $e['aspect'] ?? '', array( '3:2', '1:1', '2:3' ), true ) ? $e['aspect'] : '3:2',
						'alt'    => mb_substr( $text, 0, 250 ),
					);
					break;
				case 'icon':
					$icon = strtolower( (string) preg_replace( '/^(fas|fa-solid|fa)\s+|fa-/', '', (string) ( $e['icon'] ?? '' ) ) );
					if ( ! in_array( $icon, PageDocument::ICONS, true ) ) {
						++$report['icons_replaced'];
						$icon = 'check';
					}
					$out[] = array(
						'type' => 'icon',
						'icon' => $icon,
					);
					break;
				case 'list':
					if ( array() !== $items ) {
						$out[] = array(
							'type'  => 'list',
							'items' => $items,
						);
					}
					break;
				case 'accordion':
					$answers = array_values( array_map( static fn ( $a ): string => PageDocument::plain( $a, 1000 ), (array) ( $e['answers'] ?? array() ) ) );
					$faq     = array();
					foreach ( $items as $j => $question ) {
						$answer = $answers[ $j ] ?? '';
						$faq[]  = array(
							'title' => $question,
							// A visible placeholder, never an invented answer; the content step or the user fills it.
							'html'  => '<p>' . htmlspecialchars( '' !== $answer ? $answer : '[Answer to this question]', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '</p>',
						);
					}
					if ( array() !== $faq ) {
						$out[] = array(
							'type'  => 'accordion',
							'items' => $faq,
						);
					}
					break;
				case 'divider':
					$out[] = array( 'type' => 'divider' );
					break;
			}
		}
		return $out;
	}

	/**
	 * Text slots of the document (Output B is written for these).
	 *
	 * @param array<string, mixed> $doc Document from toDocument() (before images are resolved).
	 * @return list<array<string, mixed>> Slots in the ContentSlots format.
	 */
	public static function slots( array $doc ): array {
		$slots = array();
		$add   = static function ( string $key, string $role, string $text, int $words, bool $html, string $level, int $section, string $type ) use ( &$slots ): void {
			$plain   = HtmlFragment::text( $text );
			$slots[] = array(
				'key'           => $key,
				'element_id'    => '',
				'widget'        => '',
				'role'          => $role,
				'html'          => $html,
				'level'         => $level,
				'section_index' => $section,
				'section_type'  => $type,
				'text'          => mb_substr( $plain, 0, 600 ),
				'words'         => $words > 0 ? $words : ( '' === $plain ? 0 : count( preg_split( '/\s+/u', $plain ) ) ),
				'replace'       => true,
				'reason'        => '',
				'locked'        => false,
			);
		};
		foreach ( (array) ( $doc['sections'] ?? array() ) as $si => $section ) {
			$walk = static function ( array $elements, string $prefix, bool $in_group ) use ( &$walk, $add, $si, $section ): void {
				foreach ( $elements as $ei => $e ) {
					$key = $prefix . $ei;
					switch ( $e['type'] ) {
						case 'group':
							$walk( $e['elements'], $key . '.', true );
							break;
						case 'heading':
							$add( "$key|text", $in_group ? 'card_title' : 'heading', $e['text'], (int) ( $e['_words'] ?? 0 ), false, $e['level'], $si, $section['type'] );
							break;
						case 'text':
							$add( "$key|html", $in_group ? 'card_text' : 'paragraph', $e['html'], (int) ( $e['_words'] ?? 0 ), true, '', $si, $section['type'] );
							break;
						case 'button':
							$add( "$key|text", 'button', $e['text'], 0, false, '', $si, $section['type'] );
							break;
						case 'list':
							foreach ( $e['items'] as $ii => $item ) {
								$add( "$key|items|$ii", 'list_item', $item, 0, false, '', $si, $section['type'] );
							}
							break;
						case 'accordion':
							foreach ( $e['items'] as $ii => $item ) {
								$add( "$key|items|$ii|title", 'faq_question', $item['title'], 0, false, '', $si, $section['type'] );
								$add( "$key|items|$ii|html", 'faq_answer', $item['html'], 0, true, '', $si, $section['type'] );
							}
							break;
					}
				}
			};
			$walk( (array) $section['elements'], "$si.", false );
		}
		return $slots;
	}

	/**
	 * Apply written texts to the document by slot key.
	 *
	 * @param array<string, mixed>  $doc   Document.
	 * @param array<string, string> $texts Key => text (validated by CloneContentGenerator::parse()).
	 * @return array<string, mixed>
	 */
	public static function applyTexts( array $doc, array $texts ): array {
		foreach ( $texts as $key => $text ) {
			if ( ! is_string( $key ) || ! preg_match( '/^(\d+)\.(\d+)(?:\.(\d+))?\|(text|html|items)(?:\|(\d+))?(?:\|(title|html))?$/', $key, $m ) ) {
				continue;
			}
			[ $si, $ei ] = array( (int) $m[1], (int) $m[2] );
			// Check before taking a reference: a reference to a missing key would create it.
			if ( ! isset( $doc['sections'][ $si ]['elements'][ $ei ] ) || ( '' !== $m[3] && ! isset( $doc['sections'][ $si ]['elements'][ $ei ]['elements'][ (int) $m[3] ] ) ) ) {
				continue;
			}
			$el = &$doc['sections'][ $si ]['elements'][ $ei ];
			if ( '' !== $m[3] ) {
				$el = &$el['elements'][ (int) $m[3] ];
			}
			if ( 'items' === $m[4] && ( ! isset( $m[5] ) || ! isset( $el['items'][ (int) $m[5] ] ) ) ) {
				unset( $el );
				continue;
			}
			if ( 'items' === $m[4] && isset( $m[5] ) ) {
				if ( isset( $m[6] ) && '' !== $m[6] ) {
					$el['items'][ (int) $m[5] ][ $m[6] ] = 'html' === $m[6] ? HtmlFragment::clean( $text ) : PageDocument::plain( $text, 300 );
				} else {
					$el['items'][ (int) $m[5] ] = PageDocument::plain( $text, 300 );
				}
			} elseif ( 'html' === $m[4] ) {
				$el['html'] = HtmlFragment::clean( $text );
			} else {
				$el['text'] = PageDocument::plain( $text, 'button' === ( $el['type'] ?? '' ) ? 80 : 300 );
			}
			unset( $el );
		}
		return $doc;
	}

	/**
	 * Replace image slots with Media Library images, or remove them.
	 *
	 * @param array<string, mixed> $doc      Document.
	 * @param callable             $resolver fn( string $aspect, string $alt ): int attachment ID (0 = none).
	 * @param array<string, mixed> $report   Report (placeholders counted).
	 * @return array<string, mixed>
	 */
	public static function resolveImages( array $doc, callable $resolver, array &$report ): array {
		$resolve = function ( array $elements ) use ( &$resolve, $resolver, &$report ): array {
			$out = array();
			foreach ( $elements as $e ) {
				if ( 'group' === $e['type'] ) {
					$e['elements'] = $resolve( $e['elements'] );
					if ( array() === $e['elements'] ) {
						continue;
					}
				} elseif ( '_image' === $e['type'] ) {
					$id = (int) $resolver( $e['aspect'], $e['alt'] );
					if ( $id <= 0 ) {
						continue;
					}
					++$report['placeholders'];
					$e = array(
						'type'          => 'image',
						'attachment_id' => $id,
						'url'           => '',
						'alt'           => $e['alt'],
						'caption'       => '',
					);
				}
				unset( $e['_words'] );
				$out[] = $e;
			}
			return $out;
		};
		foreach ( $doc['sections'] as $i => $section ) {
			$doc['sections'][ $i ]['elements'] = $resolve( $section['elements'] );
		}
		$doc['sections'] = array_values( array_filter( $doc['sections'], static fn ( array $s ): bool => array() !== $s['elements'] ) );
		return $doc;
	}

	/**
	 * Colour as a token reference when it is one of the screenshot's palette colours (so a chosen
	 * brand profile restyles it), else the literal colour.
	 *
	 * @param string                $hex     Colour.
	 * @param array<string, string> $palette Palette.
	 */
	private static function colorRef( string $hex, array $palette ): string {
		if ( '' === $hex ) {
			return '';
		}
		foreach ( array( 'surface', 'primary', 'secondary', 'accent', 'background', 'heading', 'text' ) as $role ) {
			if ( ( $palette[ $role ] ?? '' ) === $hex ) {
				return 'token:' . $role;
			}
		}
		return $hex;
	}

	/**
	 * A recognised font name, or "".
	 *
	 * @param mixed $value Name.
	 */
	private static function font( mixed $value ): string {
		$name = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $name || in_array( strtolower( $name ), self::GENERIC_FONTS, true ) ) {
			return '';
		}
		return (string) ( DesignTokens::fontFamily( $name ) ?? '' );
	}
}
