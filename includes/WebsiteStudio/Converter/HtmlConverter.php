<?php
/**
 * HTML/CSS → PageDocument.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Converter;

use AiSeoContentStudio\WebsiteStudio\Analysis\CssStyleAnalyzer;
use AiSeoContentStudio\WebsiteStudio\Analysis\StyleSignals;
use AiSeoContentStudio\WebsiteStudio\Design\BrandExtractor;
use AiSeoContentStudio\WebsiteStudio\Design\DesignTokens;
use AiSeoContentStudio\WebsiteStudio\Engine\HtmlFragment;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a user's HTML and CSS into a PageDocument (then mapped to native Elementor widgets
 * by the engine), and reports what could not be converted.
 *
 * Safety: the HTML is parsed with DOMDocument (no network, no entity loading); scripts,
 * <noscript>, <template>, <link>, <meta> and <base> are removed before anything else and never
 * executed; JavaScript input is only measured for the report. Stylesheets referenced by <link>
 * are not fetched. Every text and URL that reaches the document passes the PageDocument and
 * HtmlFragment sanitisers.
 *
 * Structure: top-level blocks become sections (site header/nav/footer are skipped and
 * reported). Inside a section, a flex row or CSS grid with two or more children becomes a
 * grid or split layout whose children become groups (cards); columns per device come from
 * grid-template-columns, flex-direction and child widths in each media query. Headings,
 * paragraphs and lists, buttons (links styled as buttons), images, figures, tables, YouTube/Vimeo
 * iframes, <details> (accordion), <hr> and Font Awesome icons map to native widgets.
 */
final class HtmlConverter {

	public const MAX_HTML_BYTES = 1048576;
	public const MAX_CSS_BYTES  = 1048576;
	public const MAX_NODES      = 8000;

	private const MAX_SECTIONS = 40;
	private const MAX_ELEMENTS = 60;

	private const BLOCK_TAGS  = array( 'div', 'section', 'article', 'aside', 'main', 'header', 'footer', 'nav', 'figure', 'form', 'ul', 'ol', 'table', 'details', 'blockquote', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'pre', 'dl', 'address' );
	private const TEXT_BLOCKS = array( 'p', 'ul', 'ol', 'table', 'blockquote' );
	private const INLINE      = array( 'a', 'strong', 'b', 'em', 'i', 'u', 'span', 'br', 'small', 'code', 'sup', 'sub', 'mark', 'abbr', 'time', 'cite', 'q', 's', 'del', 'ins', 'label' );
	private const FALLBACK    = array( 'dl', 'pre', 'address', 'progress', 'meter' );

	private const UNSUPPORTED = array(
		'form'     => 'form',
		'input'    => 'form',
		'select'   => 'form',
		'textarea' => 'form',
		'svg'      => 'svg',
		'canvas'   => 'canvas',
		'video'    => 'media',
		'audio'    => 'media',
		'object'   => 'embed',
		'embed'    => 'embed',
		'iframe'   => 'iframe',
		'picture'  => 'media',
		'dialog'   => 'interactive',
	);

	private StyleResolver $styles;

	/**
	 * Options.
	 *
	 * @var array{fallback: string, base_url: string, title: string, include_chrome: bool}
	 */
	private array $opt;

	/**
	 * Report under construction.
	 *
	 * @var array<string, mixed>
	 */
	private array $report;

	/**
	 * Section IDs issued.
	 *
	 * @var array<string, true>
	 */
	private array $ids = array();

	private int $converted = 0;

	/**
	 * Convert.
	 *
	 * @param string               $html    HTML (fragment or full document).
	 * @param string               $css     CSS.
	 * @param string               $js      JavaScript (analysed only, never run).
	 * @param array<string, mixed> $options fallback: skip|html, base_url: absolute URL for relative links/images, title, include_chrome.
	 * @return array{document: array<string, mixed>, errors: string[], tokens: array<string, mixed>, report: array<string, mixed>}
	 */
	public function convert( string $html, string $css = '', string $js = '', array $options = array() ): array {
		$base      = (string) ( $options['base_url'] ?? '' );
		$this->opt = array(
			'fallback'       => 'html' === ( $options['fallback'] ?? '' ) ? 'html' : 'skip',
			'base_url'       => preg_match( '#^https?://[^/\s]+#i', $base ) ? rtrim( $base, '/' ) . '/' : '',
			'title'          => PageDocument::plain( $options['title'] ?? '', 200 ),
			'include_chrome' => ! empty( $options['include_chrome'] ),
		);
		$this->ids       = array();
		$this->converted = 0;
		$this->report    = array(
			'unsupported'          => array(),
			'skipped'              => array(),
			'fallbacks'            => 0,
			'scripts_removed'      => 0,
			'external_stylesheets' => array(),
			'relative_urls'        => array(),
			'approximations'       => array(),
			'javascript'           => self::javascriptReport( $js ),
			'truncated'            => false,
		);

		if ( strlen( $html ) > self::MAX_HTML_BYTES || strlen( $css ) > self::MAX_CSS_BYTES ) {
			return $this->failed( 'The HTML or CSS is larger than 1 MB.' );
		}
		if ( '' === trim( $html ) ) {
			return $this->failed( 'Paste or upload the HTML to convert.' );
		}

		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$wrapped  = preg_match( '/<html[\s>]/i', $html ) ? $html : '<!DOCTYPE html><html><head></head><body>' . $html . '</body></html>';
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $wrapped, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( $doc->getElementsByTagName( '*' )->length > self::MAX_NODES ) {
			return $this->failed( 'The HTML has too many elements (more than ' . self::MAX_NODES . ').' );
		}

		// Collect embedded CSS, then remove everything that could execute or load resources.
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'style' ) ) as $style ) {
			$css .= "\n" . $style->textContent;
			$style->parentNode?->removeChild( $style );
		}
		foreach ( iterator_to_array( $doc->getElementsByTagName( 'link' ) ) as $link ) {
			if ( str_contains( strtolower( $link->getAttribute( 'rel' ) ), 'stylesheet' ) ) {
				$this->report['external_stylesheets'][] = mb_substr( $link->getAttribute( 'href' ), 0, 200 );
			}
		}
		foreach ( array( 'script', 'noscript', 'template', 'link', 'meta', 'base' ) as $tag ) {
			foreach ( iterator_to_array( $doc->getElementsByTagName( $tag ) ) as $node ) {
				if ( 'script' === $tag ) {
					++$this->report['scripts_removed'];
				}
				$node->parentNode?->removeChild( $node );
			}
		}
		$handlers = 0;
		foreach ( $doc->getElementsByTagName( '*' ) as $el ) {
			foreach ( iterator_to_array( $el->attributes ) as $attr ) {
				if ( str_starts_with( strtolower( $attr->name ), 'on' ) ) {
					++$handlers;
					$el->removeAttribute( $attr->name );
				}
			}
		}
		$this->report['javascript']['inline_handlers_removed'] = $handlers;

		$this->styles                      = new StyleResolver( $css );
		$this->report['css']               = array(
			'unsupported_selectors' => $this->styles->unsupportedSelectors,
			'unsupported_features'  => $this->styles->unsupportedFeatures,
		);

		$title_node = $doc->getElementsByTagName( 'title' )->item( 0 );
		$body       = $doc->getElementsByTagName( 'body' )->item( 0 );
		$sections   = array();
		if ( $body instanceof \DOMElement ) {
			$root = $this->unwrap( $body, false );
			foreach ( $this->topLevel( $root ) as $node ) {
				foreach ( $this->section( $node, count( $sections ) ) as $section ) {
					if ( count( $sections ) >= self::MAX_SECTIONS ) {
						$this->report['truncated'] = true;
						break 2;
					}
					$sections[] = $section;
				}
			}
		}

		$title = $this->opt['title'];
		if ( '' === $title && $title_node ) {
			$title = PageDocument::plain( $title_node->textContent, 200 );
		}
		if ( '' === $title ) {
			foreach ( $sections as $section ) {
				foreach ( $section['elements'] as $e ) {
					if ( 'heading' === $e['type'] ) {
						$title = $e['text'];
						break 2;
					}
				}
			}
		}
		$input  = array(
			'schema_version' => PageDocument::VERSION,
			'page'           => array(
				'title' => '' === $title ? 'Converted page' : $title,
				'slug'  => '',
			),
			'sections'       => $sections,
		);
		$errors = array();
		$out    = PageDocument::normalize( $input, $errors, 'html' === $this->opt['fallback'] );

		$unsupported                       = count( $this->report['unsupported'] );
		$this->report['stats']             = self::stats( $out );
		$this->report['converted_elements'] = $this->converted;
		$this->report['coverage']          = $this->converted + $unsupported > 0 ? (int) round( 100 * $this->converted / ( $this->converted + $unsupported ) ) : 100;

		return array(
			'document' => $out,
			'errors'   => $errors,
			'tokens'   => self::tokensFromCss( $css ),
			'report'   => $this->report,
		);
	}

	/**
	 * Result for input that cannot be converted at all.
	 *
	 * @param string $message Reason.
	 * @return array{document: array<string, mixed>, errors: string[], tokens: array<string, mixed>, report: array<string, mixed>}
	 */
	private function failed( string $message ): array {
		return array(
			'document' => array(),
			'errors'   => array( $message ),
			'tokens'   => DesignTokens::blank(),
			'report'   => $this->report,
		);
	}

	/* ---------------------------------------------------------------- Sections */

	/**
	 * Descend through single-child wrappers (div.page, main, div.wrapper).
	 *
	 * @param \DOMElement $el         Element.
	 * @param bool        $keep_style Inside a section: also descend into section/article, but stop at a
	 *                                wrapper that draws a box. At page level a lone <section> is the
	 *                                page's only section and is not descended into.
	 */
	private function unwrap( \DOMElement $el, bool $keep_style ): \DOMElement {
		$tags = $keep_style ? array( 'div', 'main', 'section', 'article' ) : array( 'div', 'main' );
		for ( $i = 0; $i < 10; $i++ ) {
			$children = self::elementChildren( $el );
			if ( 1 !== count( $children ) || self::hasText( $el ) || ! in_array( strtolower( $children[0]->tagName ), $tags, true ) ) {
				return $el;
			}
			if ( $keep_style && $this->hasBox( $children[0] ) ) {
				return $el;
			}
			$el = $children[0];
		}
		return $el;
	}

	/**
	 * Top-level nodes of the page, skipping site chrome.
	 *
	 * @param \DOMElement $root Root.
	 * @return list<\DOMElement>
	 */
	private function topLevel( \DOMElement $root ): array {
		$out    = array();
		$inline = array();
		foreach ( $root->childNodes as $child ) {
			if ( $child instanceof \DOMText ) {
				if ( '' !== trim( $child->textContent ) ) {
					$inline[] = $child;
				}
				continue;
			}
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$tag  = strtolower( $child->tagName );
			$role = strtolower( $child->getAttribute( 'role' ) );
			if ( ! $this->opt['include_chrome'] && ( in_array( $tag, array( 'header', 'nav', 'footer' ), true ) || in_array( $role, array( 'banner', 'navigation', 'contentinfo' ), true ) ) ) {
				$this->report['skipped'][] = array(
					'tag'    => $tag,
					'reason' => 'site_chrome',
					'text'   => mb_substr( HtmlFragment::text( $child->textContent ), 0, 80 ),
				);
				continue;
			}
			$out[] = $child;
		}
		if ( array() !== $inline && array() === $out ) {
			return array( $root ); // Plain text page: one section.
		}
		return array() === $out && self::hasText( $root ) ? array( $root ) : $out;
	}

	/**
	 * Convert one top-level element into one or more sections.
	 *
	 * @param \DOMElement $el    Element.
	 * @param int         $index Section index.
	 * @return list<array<string, mixed>>
	 */
	private function section( \DOMElement $el, int $index ): array {
		$style = $this->sectionStyle( $el );
		$root  = $this->unwrap( $el, true );
		$inner = $root;
		// A boxed inner wrapper ("container") limits the content width.
		$content_width = 'full';
		foreach ( array( $el, $root ) as $candidate ) {
			$own = $this->styles->own( $candidate );
			if ( isset( $own['max-width'] ) && preg_match( '/^\d{3,4}px$/', $own['max-width'] ) ) {
				$content_width = 'boxed';
			}
		}
		if ( $root !== $el ) {
			// The wrapper may carry the visible box instead of the section element.
			$inner_style = $this->sectionStyle( $root );
			foreach ( array( 'background', 'background_image', 'text_color' ) as $key ) {
				if ( '' === $style[ $key ] && '' !== $inner_style[ $key ] ) {
					$style[ $key ] = $inner_style[ $key ];
				}
			}
			if ( '' === $style['padding']['desktop'] ) {
				$style['padding'] = $inner_style['padding'];
			}
		}
		$computed = $this->styles->computed( $inner );
		$align    = in_array( $computed['text-align'] ?? '', array( 'center', 'right' ), true ) ? $computed['text-align'] : 'left';

		$layout = array(
			'kind'          => 'stack',
			'align'         => $align,
			'content_width' => $content_width,
		);
		$own_el = $this->styles->own( $el );
		if ( isset( $own_el['min-height'] ) && null !== DesignTokens::length( $own_el['min-height'] ) && preg_match( '/^\d/', $own_el['min-height'] ) ) {
			$layout['min_height'] = $own_el['min-height'];
		}

		$row      = $this->findRow( $inner );
		$elements = array();
		$after    = array();
		if ( null !== $row ) {
			[ $columns, $gap ] = $this->columns( $row['row'] );
			$groups            = array();
			foreach ( $row['items'] as $item ) {
				$children = $this->leaves( $item, $el );
				if ( array() === $children ) {
					continue;
				}
				$groups[] = array(
					'type'     => 'group',
					'style'    => $this->cardStyle( $item ),
					'elements' => array_slice( $children, 0, 20 ),
				);
			}
			$before = array();
			foreach ( $row['before'] as $node ) {
				$before = array_merge( $before, $this->leaves( $node, $el ) );
			}
			foreach ( $row['after'] as $node ) {
				$after = array_merge( $after, $this->leaves( $node, $el ) );
			}
			if ( count( $groups ) >= 2 ) {
				$layout['kind']    = array() === $before && 2 === count( $groups ) && 2 === $columns['desktop'] ? 'split' : 'grid';
				$layout['columns'] = $columns;
				if ( '' !== $gap ) {
					$layout['gap'] = $gap;
				}
				$elements = array_merge( $before, $groups );
			} else {
				$elements = $this->leaves( $inner, $el );
				$after    = array();
			}
		} else {
			$elements = $this->leaves( $inner, $el );
		}
		if ( array() === $elements ) {
			return array();
		}

		$elements = $this->readable( $elements, $style['background'] );

		$id   = $this->sectionId( $el, $index );
		$type = self::sectionType( $elements, $index, $el );
		$out  = array();
		foreach ( array_chunk( $elements, self::MAX_ELEMENTS ) as $i => $chunk ) {
			$out[] = array(
				'id'       => 0 === $i ? $id : $this->uniqueId( $id . '-' . ( $i + 1 ) ),
				'type'     => $type,
				'layout'   => 0 === $i ? $layout : array_merge( $layout, array( 'kind' => 'grid' === $layout['kind'] && self::hasGroups( $chunk ) ? 'grid' : 'stack' ) ),
				'style'    => $style,
				'elements' => $chunk,
			);
		}
		if ( array() !== $after ) {
			// Elements after a grid (a "view all" button) continue in their own section.
			$style_after                       = $style;
			$style_after['padding']['desktop'] = '0 0 ' . ( self::edge( $style['padding']['desktop'], 2 ) ?: '40px' );
			$out[]                             = array(
				'id'       => $this->uniqueId( $id . '-more' ),
				'type'     => $type,
				'layout'   => array(
					'kind'          => 'stack',
					'align'         => $align,
					'content_width' => $content_width,
				),
				'style'    => $style_after,
				'elements' => array_slice( $after, 0, self::MAX_ELEMENTS ),
			);
		}
		return $out;
	}

	/**
	 * The row (flex row or grid with ≥2 element children) directly in, or one level inside, a root.
	 *
	 * @param \DOMElement $root Section content root.
	 * @return array{row: \DOMElement, items: list<\DOMElement>, before: list<\DOMNode>, after: list<\DOMNode>}|null
	 */
	private function findRow( \DOMElement $root ): ?array {
		if ( $this->isRow( $root ) ) {
			return array(
				'row'    => $root,
				'items'  => self::elementChildren( $root ),
				'before' => array(),
				'after'  => array(),
			);
		}
		$children = iterator_to_array( $root->childNodes );
		foreach ( $children as $i => $child ) {
			if ( $child instanceof \DOMElement && $this->isRow( $child ) ) {
				return array(
					'row'    => $child,
					'items'  => self::elementChildren( $child ),
					'before' => array_slice( $children, 0, $i ),
					'after'  => array_slice( $children, $i + 1 ),
				);
			}
		}
		return null;
	}

	/**
	 * Whether an element lays out its children side by side.
	 *
	 * @param \DOMElement $el Element.
	 */
	private function isRow( \DOMElement $el ): bool {
		$children = self::elementChildren( $el );
		if ( count( $children ) < 2 || in_array( strtolower( $el->tagName ), array( 'ul', 'ol', 'p', 'table', 'form' ), true ) ) {
			return false;
		}
		// Rows of buttons or inline items are not card layouts.
		foreach ( $children as $child ) {
			if ( in_array( strtolower( $child->tagName ), array( 'a', 'button', 'span', 'img', 'i' ), true ) ) {
				return false;
			}
		}
		$own     = $this->styles->own( $el );
		$display = $own['display'] ?? '';
		if ( in_array( $display, array( 'grid', 'inline-grid' ), true ) ) {
			return true;
		}
		return in_array( $display, array( 'flex', 'inline-flex' ), true ) && ! in_array( $own['flex-direction'] ?? 'row', array( 'column', 'column-reverse' ), true );
	}

	/**
	 * Columns per device and the gap of a row.
	 *
	 * @param \DOMElement $row Row.
	 * @return array{0: array{desktop: int, tablet: int, mobile: int}, 1: string}
	 */
	private function columns( \DOMElement $row ): array {
		$count = count( self::elementChildren( $row ) );
		$cols  = array();
		$prev  = 0;
		foreach ( StyleResolver::DEVICES as $device ) {
			$own = $this->styles->own( $row, $device );
			$n   = 0;
			if ( in_array( $own['display'] ?? '', array( 'grid', 'inline-grid' ), true ) && isset( $own['grid-template-columns'] ) ) {
				$n = self::gridColumns( $own['grid-template-columns'] );
			} elseif ( in_array( $own['display'] ?? '', array( 'flex', 'inline-flex' ), true ) ) {
				if ( in_array( $own['flex-direction'] ?? 'row', array( 'column', 'column-reverse' ), true ) ) {
					$n = 1;
				} else {
					$n = $this->flexColumns( $row, $device, $count );
				}
			} elseif ( in_array( $own['display'] ?? '', array( 'block', 'none' ), true ) && 'desktop' !== $device ) {
				$n = 1;
			}
			if ( 0 === $n ) {
				// No rule for this device: phones stack, tablets show at most two.
				$n = match ( $device ) {
					'desktop' => min( 6, $count ),
					'tablet'  => min( 2, $prev ?: $count ),
					default   => 1,
				};
			}
			$cols[ $device ] = max( 1, min( 6, $n ) );
			$prev            = $cols[ $device ];
		}
		$own = $this->styles->own( $row );
		$gap = '';
		foreach ( array( 'column-gap', 'gap' ) as $prop ) {
			if ( isset( $own[ $prop ] ) && preg_match( '/^(\d+(?:\.\d+)?(px|rem|em))\b/', $own[ $prop ], $m ) ) {
				$gap = $m[1];
				break;
			}
		}
		return array( $cols, $gap );
	}

	/**
	 * Column count from grid-template-columns.
	 *
	 * @param string $value Value.
	 */
	public static function gridColumns( string $value ): int {
		$value = trim( $value );
		if ( preg_match( '/^repeat\(\s*(\d+)\s*,/', $value, $m ) ) {
			return (int) $m[1];
		}
		if ( preg_match( '/^repeat\(\s*auto-(fit|fill)\s*,\s*minmax\(\s*(\d+)px/', $value, $m ) ) {
			// Approximation for a 1200px container; reported.
			return max( 1, min( 6, intdiv( 1200, max( 1, (int) $m[2] ) ) ) );
		}
		$tracks = preg_split( '/\s+(?![^(]*\))/', $value );
		return count( array_filter( (array) $tracks, static fn ( string $t ): bool => '' !== $t ) );
	}

	/**
	 * Column count of a flex row from its children's widths.
	 *
	 * @param \DOMElement $row    Row.
	 * @param string      $device Device.
	 * @param int         $count  Children.
	 */
	private function flexColumns( \DOMElement $row, string $device, int $count ): int {
		$own = $this->styles->own( $row, $device );
		if ( 'wrap' !== ( $own['flex-wrap'] ?? 'nowrap' ) ) {
			return 'desktop' === $device ? min( 6, $count ) : 0;
		}
		$first = self::elementChildren( $row )[0] ?? null;
		if ( null === $first ) {
			return 0;
		}
		$child = $this->styles->own( $first, $device );
		foreach ( array( 'flex-basis', 'width', 'max-width', 'flex' ) as $prop ) {
			if ( isset( $child[ $prop ] ) && preg_match( '/(\d+(?:\.\d+)?)%/', $child[ $prop ], $m ) && (float) $m[1] > 0 ) {
				return (int) max( 1, min( 6, round( 100 / (float) $m[1] ) ) );
			}
		}
		return 'desktop' === $device ? min( 6, $count ) : 0;
	}

	/* ---------------------------------------------------------------- Leaves */

	/**
	 * Convert a subtree into a flat list of elements (no groups).
	 *
	 * @param \DOMNode    $node    Node.
	 * @param \DOMElement $section Section element (style inheritance stops here).
	 * @param bool        $as_leaf A row item that is itself a heading, image, link, ... is converted as
	 *                             one element; recursive calls always walk the children instead.
	 * @return list<array<string, mixed>>
	 */
	private function leaves( \DOMNode $node, \DOMElement $section, bool $as_leaf = true ): array {
		$out    = array();
		$buffer = '';
		$flush  = function () use ( &$out, &$buffer ): void {
			if ( '' !== HtmlFragment::text( $buffer ) ) {
				$out[] = array(
					'type' => 'text',
					'html' => $buffer,
				);
				++$this->converted;
			}
			$buffer = '';
		};
		$nodes = $as_leaf && $node instanceof \DOMElement && $this->isLeafContainer( $node ) ? array( $node ) : iterator_to_array( $node->childNodes );
		foreach ( $nodes as $child ) {
			if ( $child instanceof \DOMText ) {
				$buffer .= htmlspecialchars( $child->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				continue;
			}
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->tagName );

			if ( isset( self::UNSUPPORTED[ $tag ] ) && ! ( 'iframe' === $tag && '' !== $this->videoUrl( $child ) ) ) {
				$flush();
				$this->unsupported( $child, self::UNSUPPORTED[ $tag ] );
				continue;
			}
			if ( preg_match( '/^h[1-6]$/', $tag ) ) {
				$flush();
				$out[] = array(
					'type'  => 'heading',
					'text'  => PageDocument::plain( $child->textContent, 300 ),
					'level' => $tag,
					'style' => $this->textStyle( $child, $section ),
				);
				++$this->converted;
				continue;
			}
			if ( ( 'a' === $tag && $this->isButton( $child ) ) || 'button' === $tag ) {
				$flush();
				$out[] = $this->button( $child, $section );
				continue;
			}
			if ( 'img' === $tag ) {
				$flush();
				$image = $this->image( $child, '' );
				if ( null !== $image ) {
					$out[] = $image;
				}
				continue;
			}
			if ( 'figure' === $tag && $child->getElementsByTagName( 'img' )->length > 0 ) {
				$flush();
				$caption = $child->getElementsByTagName( 'figcaption' )->item( 0 );
				$image   = $this->image( $child->getElementsByTagName( 'img' )->item( 0 ), $caption ? PageDocument::plain( $caption->textContent, 300 ) : '' );
				if ( null !== $image ) {
					$out[] = $image;
				}
				continue;
			}
			if ( 'iframe' === $tag ) {
				$flush();
				$out[] = array(
					'type' => 'video',
					'url'  => $this->videoUrl( $child ),
				);
				++$this->converted;
				continue;
			}
			if ( 'hr' === $tag ) {
				$flush();
				$out[] = array( 'type' => 'divider' );
				++$this->converted;
				continue;
			}
			if ( 'details' === $tag ) {
				$flush();
				$summary = $child->getElementsByTagName( 'summary' )->item( 0 );
				$title   = $summary ? PageDocument::plain( $summary->textContent, 300 ) : '';
				if ( $summary ) {
					$summary->parentNode?->removeChild( $summary );
				}
				$item = array(
					'title' => $title,
					'html'  => HtmlFragment::clean( self::innerHtml( $child ) ),
				);
				$last = count( $out ) - 1;
				if ( $last >= 0 && 'accordion' === $out[ $last ]['type'] ) {
					$out[ $last ]['items'][] = $item;
				} else {
					$out[] = array(
						'type'  => 'accordion',
						'items' => array( $item ),
					);
					++$this->converted;
				}
				continue;
			}
			$icon = $this->icon( $child );
			if ( '' !== $icon ) {
				$flush();
				$out[] = array(
					'type' => 'icon',
					'icon' => $icon,
				);
				++$this->converted;
				continue;
			}
			if ( in_array( $tag, self::FALLBACK, true ) ) {
				$flush();
				$this->fallback( $child, $out );
				continue;
			}
			if ( in_array( $tag, self::TEXT_BLOCKS, true ) ) {
				// A list or paragraph holding a button or image is split so they become widgets.
				if ( $this->containsWidgets( $child ) ) {
					$flush();
					$out = array_merge( $out, $this->leaves( $child, $section, false ) );
					continue;
				}
				$buffer .= $child->ownerDocument->saveHTML( $child );
				continue;
			}
			if ( in_array( $tag, self::INLINE, true ) && ! $this->hasBlocks( $child ) && ! $this->containsWidgets( $child ) ) {
				$buffer .= $child->ownerDocument->saveHTML( $child );
				continue;
			}
			// Generic container: convert its content in place.
			$flush();
			$out = array_merge( $out, $this->leaves( $child, $section, false ) );
		}
		$flush();
		foreach ( $out as $i => $e ) {
			if ( 'text' === $e['type'] ) {
				$out[ $i ]['html'] = HtmlFragment::clean( $e['html'] );
			}
		}
		return array_values( array_filter( $out, static fn ( array $e ): bool => 'text' !== $e['type'] || '' !== HtmlFragment::text( $e['html'] ) ) );
	}

	/**
	 * Whether a node itself should be converted as a single leaf (a heading or image passed as a row item).
	 *
	 * @param \DOMElement $el Element.
	 */
	private function isLeafContainer( \DOMElement $el ): bool {
		return in_array( strtolower( $el->tagName ), array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'figure', 'a', 'button', 'p', 'ul', 'ol', 'table', 'hr', 'iframe', 'details' ), true );
	}

	/**
	 * Button element from a link or <button>.
	 *
	 * @param \DOMElement $el      Element.
	 * @param \DOMElement $section Section.
	 * @return array<string, mixed>
	 */
	private function button( \DOMElement $el, \DOMElement $section ): array {
		$own   = $this->styles->own( $el );
		$style = $this->textStyle( $el, $section );
		$bg    = DesignTokens::color( $own['background-color'] ?? '' ) ?? '';
		$style['background'] = $bg;
		$style['padding']    = self::box( $own, 'padding' );
		$style['radius']     = DesignTokens::box( $own['border-radius'] ?? '' ) ?? '';
		$border              = DesignTokens::color( $own['border-color'] ?? '' ) ?? '';
		if ( '' !== $border && isset( $own['border-width'] ) ) {
			$style['border_color'] = $border;
			$style['border_width'] = DesignTokens::length( $own['border-width'] ) ?? '';
		}
		++$this->converted;
		return array(
			'type'    => 'button',
			'text'    => PageDocument::plain( $el->textContent, 80 ) ?: 'Learn more',
			'url'     => 'a' === strtolower( $el->tagName ) ? $this->url( $el->getAttribute( 'href' ), false ) : '#',
			'variant' => '' === $bg && '' !== $border ? 'secondary' : 'primary',
			'style'   => $style,
		);
	}

	/**
	 * Image element.
	 *
	 * @param \DOMElement|null $img     Image.
	 * @param string           $caption Caption.
	 * @return array<string, mixed>|null
	 */
	private function image( ?\DOMElement $img, string $caption ): ?array {
		if ( null === $img ) {
			return null;
		}
		$src = $this->url( $img->getAttribute( 'src' ) ?: $img->getAttribute( 'data-src' ), true );
		if ( '' === $src ) {
			$this->unsupported( $img, 'image_url' );
			return null;
		}
		++$this->converted;
		$own = $this->styles->own( $img );
		return array(
			'type'          => 'image',
			'attachment_id' => 0,
			'url'           => $src,
			'alt'           => PageDocument::plain( $img->getAttribute( 'alt' ), 250 ),
			'caption'       => $caption,
			'style'         => array( 'radius' => DesignTokens::box( $own['border-radius'] ?? '' ) ?? '' ),
		);
	}

	/**
	 * YouTube/Vimeo watch URL of an embed iframe, or "".
	 *
	 * @param \DOMElement $iframe Iframe.
	 */
	private function videoUrl( \DOMElement $iframe ): string {
		$src = $iframe->getAttribute( 'src' );
		if ( preg_match( '#^(?:https?:)?//(?:www\.)?(?:youtube(?:-nocookie)?\.com/embed/|youtu\.be/)([A-Za-z0-9_-]{6,20})#', $src, $m ) ) {
			return 'https://www.youtube.com/watch?v=' . $m[1];
		}
		if ( preg_match( '#^(?:https?:)?//player\.vimeo\.com/video/(\d{5,12})#', $src, $m ) ) {
			return 'https://vimeo.com/' . $m[1];
		}
		return '';
	}

	/**
	 * Font Awesome icon name of an <i>/<span>, if it is one of the available icons.
	 *
	 * @param \DOMElement $el Element.
	 */
	private function icon( \DOMElement $el ): string {
		if ( ! in_array( strtolower( $el->tagName ), array( 'i', 'span' ), true ) || '' !== trim( $el->textContent ) ) {
			return '';
		}
		if ( preg_match( '/\bfa-(?!solid\b|regular\b|brands\b|lg\b|2x\b|fw\b)([a-z0-9-]+)/', $el->getAttribute( 'class' ), $m ) ) {
			if ( in_array( $m[1], PageDocument::ICONS, true ) ) {
				return $m[1];
			}
			$this->unsupported( $el, 'icon' );
		}
		return '';
	}

	/**
	 * Element style for headings, text and buttons: only what this element or its ancestors
	 * inside the section declare (page-wide styles become the brand tokens instead).
	 *
	 * @param \DOMElement $el      Element.
	 * @param \DOMElement $section Section.
	 * @return array<string, mixed>
	 */
	private function textStyle( \DOMElement $el, \DOMElement $section ): array {
		$chain = array();
		for ( $n = $el; $n instanceof \DOMElement; $n = $n->parentNode ) {
			$chain[] = $n;
			if ( $n === $section ) {
				break;
			}
		}
		$pick  = function ( string $prop, string $device = 'desktop' ) use ( $chain ): string {
			foreach ( $chain as $n ) {
				$own = $this->styles->own( $n, $device );
				if ( isset( $own[ $prop ] ) && 'inherit' !== $own[ $prop ] ) {
					return $own[ $prop ];
				}
			}
			return '';
		};
		$own   = $this->styles->own( $el );
		$sizes = array();
		foreach ( StyleResolver::DEVICES as $device ) {
			$value            = $this->styles->own( $el, $device )['font-size'] ?? '';
			$sizes[ $device ] = 'desktop' !== $device && $value === ( $own['font-size'] ?? '' ) ? '' : ( DesignTokens::length( self::clampMax( $value ) ) ?? '' );
		}
		$align = array();
		foreach ( array( 'tablet', 'mobile' ) as $device ) {
			$a                = $pick( 'text-align', $device );
			$align[ $device ] = in_array( $a, array( 'left', 'center', 'right' ), true ) && $a !== $pick( 'text-align' ) ? $a : '';
		}
		return array(
			'color'       => DesignTokens::color( $pick( 'color' ) ) ?? '',
			'font_family' => DesignTokens::fontFamily( CssStyleAnalyzer::firstFamily( $pick( 'font-family' ) ) ) ?? '',
			'font_weight' => DesignTokens::fontWeight( $own['font-weight'] ?? '' ) ?? '',
			'font_size'   => $sizes,
			'line_height' => DesignTokens::lineHeight( $own['line-height'] ?? '' ) ?? '',
			'align'       => $align,
		);
	}

	/**
	 * Section style: background, image, text colour and padding per device.
	 *
	 * @param \DOMElement $el Element.
	 * @return array<string, mixed>
	 */
	private function sectionStyle( \DOMElement $el ): array {
		$own   = $this->styles->own( $el );
		$color = DesignTokens::color( $own['background-color'] ?? '' ) ?? '';
		if ( isset( $own['background-gradient'] ) ) {
			if ( '' === $color && preg_match( '/(#[0-9a-f]{3,8}\b|rgba?\([^)]*\))/i', $own['background-gradient'], $m ) ) {
				$color = DesignTokens::color( $m[1] ) ?? '';
			}
			$this->report['approximations'][] = 'gradient_as_colour';
		}
		$image = '';
		if ( isset( $own['background-image'] ) && preg_match( '/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $own['background-image'], $m ) ) {
			$image = $this->url( $m[1], true );
		}
		$padding = array();
		foreach ( StyleResolver::DEVICES as $device ) {
			$padding[ $device ] = self::box( $this->styles->own( $el, $device ), 'padding' );
		}
		if ( $padding['tablet'] === $padding['desktop'] ) {
			$padding['tablet'] = '';
		}
		if ( $padding['mobile'] === $padding['desktop'] ) {
			$padding['mobile'] = '';
		}
		return array(
			'background'       => 7 === strlen( $color ) || 9 === strlen( $color ) ? $color : '',
			'background_image' => $image,
			'text_color'       => DesignTokens::color( $own['color'] ?? '' ) ?? '',
			'padding'          => $padding,
		);
	}

	/**
	 * Card style of a row item.
	 *
	 * @param \DOMElement $el Item.
	 * @return array<string, string>
	 */
	private function cardStyle( \DOMElement $el ): array {
		$own    = $this->styles->own( $el );
		$single = isset( $own['box-shadow'] ) && 1 === count( \AiSeoContentStudio\WebsiteStudio\Css\CssParser::splitTopLevel( $own['box-shadow'], ',' ) );
		$shadow = $single ? ( DesignTokens::shadow( $own['box-shadow'] ) ?? '' ) : '';
		if ( isset( $own['box-shadow'] ) && '' === $shadow && 'none' !== $own['box-shadow'] ) {
			$this->report['approximations'][] = 'complex_shadow';
		}
		return array(
			'background' => DesignTokens::color( $own['background-color'] ?? '' ) ?? '',
			'radius'     => DesignTokens::box( $own['border-radius'] ?? '' ) ?? '',
			'shadow'     => $shadow,
			'padding'    => self::box( $own, 'padding' ),
		);
	}

	/**
	 * Replace text colours that would be unreadable on the section background (WCAG
	 * contrast below 3:1, typically after a gradient was approximated by one colour).
	 *
	 * @param list<array<string, mixed>> $elements   Elements.
	 * @param string                     $background Section background ("" = page background).
	 * @return list<array<string, mixed>>
	 */
	private function readable( array $elements, string $background ): array {
		foreach ( $elements as $i => $e ) {
			if ( 'group' === $e['type'] ) {
				$own_bg                    = $e['style']['background'] ?: $background;
				$elements[ $i ]['elements'] = $this->readable( $e['elements'], $own_bg );
				continue;
			}
			$bg    = 'button' === $e['type'] && '' !== ( $e['style']['background'] ?? '' ) ? $e['style']['background'] : $background;
			$color = (string) ( $e['style']['color'] ?? '' );
			if ( '' === $bg || '' === $color || self::contrast( $color, $bg ) >= 3.0 ) {
				continue;
			}
			$elements[ $i ]['style']['color'] = \AiSeoContentStudio\WebsiteStudio\Engine\ElementorMapper::contrastText( substr( $bg, 0, 7 ) );
			$this->report['approximations'][]  = 'contrast_fixed';
		}
		return $elements;
	}

	/**
	 * WCAG contrast ratio of two #RRGGBB colours.
	 *
	 * @param string $a Colour.
	 * @param string $b Colour.
	 */
	public static function contrast( string $a, string $b ): float {
		$lum = static function ( string $hex ): float {
			$c = array();
			foreach ( array( 1, 3, 5 ) as $i ) {
				$v   = hexdec( substr( $hex, $i, 2 ) ) / 255;
				$c[] = $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
			}
			return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
		};
		$l1 = $lum( $a );
		$l2 = $lum( $b );
		return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
	}

	/* ---------------------------------------------------------------- Helpers */

	/**
	 * Record an element that was not converted (or convert it to the HTML fallback).
	 *
	 * @param \DOMElement $el       Element.
	 * @param string      $category Category.
	 */
	private function unsupported( \DOMElement $el, string $category ): void {
		$this->report['unsupported'][] = array(
			'tag'      => strtolower( $el->tagName ),
			'category' => $category,
			'text'     => mb_substr( HtmlFragment::text( $el->textContent ), 0, 80 ),
			'fallback' => false,
		);
	}

	/**
	 * Controlled HTML fallback for elements without a native widget.
	 *
	 * @param \DOMElement                $el  Element.
	 * @param list<array<string, mixed>> $out Elements (appended).
	 */
	private function fallback( \DOMElement $el, array &$out ): void {
		$html = HtmlFragment::clean( $el->ownerDocument->saveHTML( $el ), true );
		if ( 'html' === $this->opt['fallback'] && '' !== HtmlFragment::text( $html ) ) {
			$out[] = array(
				'type' => 'html',
				'html' => $html,
			);
			++$this->report['fallbacks'];
			$this->report['unsupported'][] = array(
				'tag'      => strtolower( $el->tagName ),
				'category' => 'no_native_widget',
				'text'     => mb_substr( HtmlFragment::text( $el->textContent ), 0, 80 ),
				'fallback' => true,
			);
			return;
		}
		$this->unsupported( $el, 'no_native_widget' );
	}

	/**
	 * Resolve and check a URL.
	 *
	 * @param string $url   URL.
	 * @param bool   $image Images need an absolute http(s) URL.
	 */
	private function url( string $url, bool $image ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return $image ? '' : '#';
		}
		if ( str_starts_with( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) && ! str_starts_with( $url, '#' ) ) {
			if ( '' !== $this->opt['base_url'] ) {
				$url = str_starts_with( $url, '/' ) ? (string) preg_replace( '#^(https?://[^/]+).*$#i', '$1', $this->opt['base_url'] ) . $url : $this->opt['base_url'] . ltrim( $url, './' );
			} elseif ( $image || ! str_starts_with( $url, '/' ) ) {
				$this->report['relative_urls'][] = mb_substr( $url, 0, 200 );
				return $image ? '' : '#';
			}
		}
		$clean = HtmlFragment::url( $url );
		if ( $image ) {
			return preg_match( '#^https?://#i', $clean ) ? $clean : '';
		}
		return '' === $clean ? '#' : $clean;
	}

	/**
	 * Whether a link is styled as a button.
	 *
	 * @param \DOMElement $a Link.
	 */
	private function isButton( \DOMElement $a ): bool {
		if ( preg_match( '/(^|[\s_-])(btn|button|cta)([\s_-]|$)/i', $a->getAttribute( 'class' ) ) || 'button' === strtolower( $a->getAttribute( 'role' ) ) ) {
			return true;
		}
		$own = $this->styles->own( $a );
		$has_bg     = isset( $own['background-color'] ) && ! in_array( $own['background-color'], array( 'transparent', 'none' ), true );
		$has_border = isset( $own['border-width'] ) && '0' !== $own['border-width'];
		return ( $has_bg || $has_border ) && '' !== self::box( $own, 'padding' );
	}

	/**
	 * Whether an element contains descendants that become their own widgets.
	 *
	 * @param \DOMElement $el Element.
	 */
	private function containsWidgets( \DOMElement $el ): bool {
		foreach ( array( 'img', 'button', 'iframe', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'details' ) as $tag ) {
			if ( $el->getElementsByTagName( $tag )->length > 0 ) {
				return true;
			}
		}
		foreach ( $el->getElementsByTagName( 'a' ) as $a ) {
			if ( $this->isButton( $a ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an element has block-level children.
	 *
	 * @param \DOMElement $el Element.
	 */
	private function hasBlocks( \DOMElement $el ): bool {
		foreach ( self::elementChildren( $el ) as $child ) {
			if ( in_array( strtolower( $child->tagName ), self::BLOCK_TAGS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an element draws a box (background, border or shadow).
	 *
	 * @param \DOMElement $el Element.
	 */
	private function hasBox( \DOMElement $el ): bool {
		$own = $this->styles->own( $el );
		return isset( $own['background-color'] ) || isset( $own['background-image'] ) || isset( $own['box-shadow'] );
	}

	/**
	 * Section ID from the element's id or its position.
	 *
	 * @param \DOMElement $el    Element.
	 * @param int         $index Index.
	 */
	private function sectionId( \DOMElement $el, int $index ): string {
		$id = strtolower( (string) preg_replace( '/[^a-z0-9-]+/i', '-', $el->getAttribute( 'id' ) ) );
		$id = trim( substr( $id, 0, 36 ), '-' );
		return $this->uniqueId( '' !== $id ? $id : 'section-' . ( $index + 1 ) );
	}

	/**
	 * Make an ID unique.
	 *
	 * @param string $id Candidate.
	 */
	private function uniqueId( string $id ): string {
		$id   = (string) preg_replace( '/^[^a-z0-9]+/', '', $id );
		$id   = '' === $id ? 'section' : substr( $id, 0, 36 );
		$base = $id;
		for ( $i = 2; isset( $this->ids[ $id ] ); $i++ ) {
			$id = $base . '-' . $i;
		}
		$this->ids[ $id ] = true;
		return $id;
	}

	/**
	 * Guess a section type from its elements.
	 *
	 * @param list<array<string, mixed>> $elements Elements.
	 * @param int                        $index    Index.
	 * @param \DOMElement                $el       Element.
	 */
	private static function sectionType( array $elements, int $index, \DOMElement $el ): string {
		$types  = array_column( $elements, 'type' );
		$levels = array_column( array_filter( $elements, static fn ( array $e ): bool => 'heading' === $e['type'] ), 'level' );
		$hint   = strtolower( $el->getAttribute( 'id' ) . ' ' . $el->getAttribute( 'class' ) );
		foreach ( array( 'faq' => 'faq', 'testimonial' => 'testimonials', 'review' => 'testimonials', 'contact' => 'contact', 'about' => 'about', 'feature' => 'features', 'service' => 'cards', 'hero' => 'hero', 'cta' => 'cta', 'stat' => 'statistics', 'gallery' => 'image_grid' ) as $needle => $type ) {
			if ( str_contains( $hint, $needle ) ) {
				return $type;
			}
		}
		if ( in_array( 'accordion', $types, true ) ) {
			return 'faq';
		}
		if ( in_array( 'h1', $levels, true ) || ( 0 === $index && in_array( 'button', $types, true ) ) ) {
			return 'hero';
		}
		if ( count( array_keys( $types, 'group', true ) ) >= 3 ) {
			return 'cards';
		}
		if ( in_array( 'button', $types, true ) && count( $elements ) <= 4 ) {
			return 'cta';
		}
		return 'content';
	}

	/**
	 * Box value (padding) from shorthand or longhands.
	 *
	 * @param array<string, string> $own  Declarations.
	 * @param string                $prop padding|margin.
	 */
	public static function box( array $own, string $prop ): string {
		if ( isset( $own[ $prop ] ) ) {
			$box = DesignTokens::box( (string) preg_replace( '/\b0(px|rem|em|%)\b/', '0', $own[ $prop ] ) );
			if ( null !== $box ) {
				return $box;
			}
		}
		$sides = array();
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$v = $own[ "$prop-$side" ] ?? '0';
			if ( null === DesignTokens::length( $v ) ) {
				return '';
			}
			$sides[] = $v;
		}
		return array( '0', '0', '0', '0' ) === $sides ? '' : (string) DesignTokens::box( implode( ' ', $sides ) );
	}

	/**
	 * Largest value of clamp()/min()/max() font sizes (the desktop size).
	 *
	 * @param string $value Value.
	 */
	private static function clampMax( string $value ): string {
		if ( preg_match( '/^clamp\([^,]+,[^,]+,\s*([0-9.]+(?:px|rem|em))\s*\)$/', $value, $m ) ) {
			return $m[1];
		}
		return $value;
	}

	/**
	 * One edge of a CSS box (0 top … 3 left).
	 *
	 * @param string $box  Box.
	 * @param int    $edge Edge.
	 */
	private static function edge( string $box, int $edge ): string {
		$p = preg_split( '/\s+/', trim( $box ) );
		if ( ! is_array( $p ) || '' === $p[0] ) {
			return '';
		}
		$p = match ( count( $p ) ) {
			1       => array( $p[0], $p[0], $p[0], $p[0] ),
			2       => array( $p[0], $p[1], $p[0], $p[1] ),
			3       => array( $p[0], $p[1], $p[2], $p[1] ),
			default => $p,
		};
		return (string) ( $p[ $edge ] ?? '' );
	}

	/**
	 * Whether a list of elements contains a group.
	 *
	 * @param list<array<string, mixed>> $elements Elements.
	 */
	private static function hasGroups( array $elements ): bool {
		return in_array( 'group', array_column( $elements, 'type' ), true );
	}

	/**
	 * Element children.
	 *
	 * @param \DOMNode $node Node.
	 * @return list<\DOMElement>
	 */
	private static function elementChildren( \DOMNode $node ): array {
		$out = array();
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$out[] = $child;
			}
		}
		return $out;
	}

	/**
	 * Whether an element has direct non-whitespace text.
	 *
	 * @param \DOMElement $el Element.
	 */
	private static function hasText( \DOMElement $el ): bool {
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMText && '' !== trim( $child->textContent ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Inner HTML of an element.
	 *
	 * @param \DOMElement $el Element.
	 */
	private static function innerHtml( \DOMElement $el ): string {
		$html = '';
		foreach ( $el->childNodes as $child ) {
			$html .= $el->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Element counts by type.
	 *
	 * @param array<string, mixed> $doc Normalised document.
	 * @return array<string, mixed>
	 */
	private static function stats( array $doc ): array {
		$counts = array();
		$walk   = function ( array $elements ) use ( &$counts, &$walk ): void {
			foreach ( $elements as $e ) {
				$counts[ $e['type'] ] = ( $counts[ $e['type'] ] ?? 0 ) + 1;
				if ( 'group' === $e['type'] ) {
					$walk( $e['elements'] );
				}
			}
		};
		foreach ( (array) ( $doc['sections'] ?? array() ) as $section ) {
			$walk( $section['elements'] );
		}
		ksort( $counts );
		return array(
			'sections' => count( (array) ( $doc['sections'] ?? array() ) ),
			'elements' => $counts,
		);
	}

	/**
	 * Brand tokens from the page's own CSS (used when no brand profile is chosen).
	 *
	 * @param string $css CSS.
	 * @return array<string, mixed>
	 */
	public static function tokensFromCss( string $css ): array {
		$signals = new StyleSignals();
		( new CssStyleAnalyzer() )->analyze( $css, $signals );
		return ( new BrandExtractor() )->extract( array( 'css' => $signals ) );
	}

	/**
	 * What the JavaScript does (it is never run).
	 *
	 * @param string $js JavaScript.
	 * @return array<string, mixed>
	 */
	public static function javascriptReport( string $js ): array {
		$features = array();
		$patterns = array(
			'event_listeners' => '/addEventListener\s*\(|\.on\(\s*[\'"]|\.click\s*\(/',
			'sliders'         => '/\b(swiper|slick|splide|glide|owl\.?carousel|flickity)\b/i',
			'animations'      => '/\b(gsap|aos|anime|scrollreveal|wow\.js|lottie)\b/i',
			'network'         => '/\b(fetch|XMLHttpRequest|axios|\$\.ajax)\b/',
			'forms'           => '/\b(submit|FormData|preventDefault)\b/',
			'dom_changes'     => '/\b(innerHTML|appendChild|insertAdjacentHTML|createElement)\b/',
		);
		foreach ( $patterns as $name => $re ) {
			if ( preg_match( $re, $js ) ) {
				$features[] = $name;
			}
		}
		return array(
			'bytes'    => strlen( $js ),
			'executed' => false,
			'features' => $features,
		);
	}
}
