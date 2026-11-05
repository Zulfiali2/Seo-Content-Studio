<?php
/**
 * Builds an Elementor document from a sanitised project page.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

use AiSeoContentStudio\WebsiteStudio\Engine\IdGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * The design is not rebuilt from extracted values: the original markup keeps its classes and
 * ids and the original CSS (scoped by CssScoper) styles it. The builder only decides how each
 * part of the page is held in Elementor:
 *
 * - Container: a layout element (div, section, header, footer, main, article, aside, nav) becomes
 *   an Elementor container with the same tag, id and classes. Containers add no wrapper element,
 *   so every CSS relation between them still holds.
 * - Native widget: a heading without its own class/id/style becomes a Heading widget; a plain
 *   image becomes an Image widget; text blocks (p, lists, blockquote, figure, tables …) and runs of
 *   inline content become Text Editor widgets that hold the original element with its classes.
 * - HTML widget: anything else (SVG, forms, embeds, interactive components, markup a script uses,
 *   or elements whose CSS depends on their exact parent or siblings) keeps its original markup.
 *
 * Modes: "fidelity" (sections as HTML widgets inside containers for the page structure),
 * "hybrid" (default: native where the result is the same as the original, HTML elsewhere) and
 * "native" (native wherever structurally possible, even when some CSS may then differ; those
 * places are reported).
 *
 * A widget's top element gets Elementor wrappers around it, so an element is only made the top
 * of a widget when no rule needs its exact parent or siblings (SelectorIndex). Otherwise its
 * parent keeps it in place by becoming one HTML widget.
 */
final class HybridBuilder {

	public const MODES = array( 'fidelity', 'hybrid', 'native' );

	/** Elements that may become Elementor containers (Elementor's html_tag options). */
	private const CONTAINER_TAGS = array( 'div', 'section', 'header', 'footer', 'main', 'article', 'aside', 'nav' );

	/** Block elements held whole in a Text Editor widget. */
	private const TEXT_TAGS = array( 'p', 'ul', 'ol', 'dl', 'blockquote', 'figure', 'table', 'address', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/** Inline content (grouped into runs). */
	private const INLINE_TAGS = array( 'a', 'span', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'mark', 'sup', 'sub', 'code', 'abbr', 'time', 'cite', 'q', 'br', 'img', 'del', 'ins', 'kbd', 'var', 'label', 'picture', 'wbr', 'bdi', 'bdo', 'data' );

	/** Elements that need their original HTML (interactive, embedded or drawn). */
	private const HTML_ONLY = array( 'svg', 'form', 'input', 'select', 'textarea', 'button', 'iframe', 'video', 'audio', 'canvas', 'details', 'summary', 'dialog', 'picture', 'source', 'track', 'map', 'area', 'math', 'meter', 'progress', 'output', 'fieldset', 'datalist', 'optgroup', 'option', 'pre' );

	/** Attributes that only an HTML widget can keep. */
	private const BEHAVIOUR_ATTR = '/^(data-|aria-(controls|expanded|selected|hidden|haspopup|pressed|current|live)|role$|tabindex$|hidden$|contenteditable$|draggable$|popover)/i';

	private string $mode;

	/** @var array<string, true> */
	private array $hooks;


	/** @var array<string, int> */
	private array $counts = array(
		'containers'   => 0,
		'native'       => 0,
		'html'         => 0,
		'headings'     => 0,
		'text'         => 0,
		'images'       => 0,
		'differences'  => 0,
	);

	/** Rules for inline styles of containers (Elementor containers cannot carry a style attribute). */
	private string $css = '';

	private int $styleIndex = 0;

	/** @var string[] */
	private array $notes = array();

	/** Native-mode places where CSS may now differ. */
	private int $risky = 0;

	/**
	 * Constructor.
	 *
	 * @param SelectorIndex $index Selector index of the project CSS.
	 * @param IdGenerator   $ids   Element ids.
	 * @param string        $scope Root selector (for container style rules).
	 * @param string[]      $hooks Class/id names used by the project's scripts.
	 */
	public function __construct( private readonly SelectorIndex $index, private readonly IdGenerator $ids, private readonly string $scope, array $hooks = array() ) {
		$this->hooks = array_fill_keys( $hooks, true );
	}

	/**
	 * Build.
	 *
	 * @param \DOMElement          $body     Sanitised body.
	 * @param array<string, mixed> $settings mode, header_footer ("include"|"skip"), root_id, root_classes.
	 * @return array{elements: array<int, array<string, mixed>>, sections: array<int, array<string, mixed>>, counts: array<string, int>, css: string, notes: string[]}
	 */
	public function build( \DOMElement $body, array $settings ): array {
		$this->mode = in_array( $settings['mode'] ?? '', self::MODES, true ) ? $settings['mode'] : 'hybrid';
		if ( 'skip' === ( $settings['header_footer'] ?? 'include' ) ) {
			$this->skipChrome( $body );
		}

		$root_settings = array(
			'content_width' => 'full',
			'html_tag'      => 'div',
			'_element_id'   => (string) $settings['root_id'],
			'css_classes'   => trim( 'aiscs-imported-page ' . implode( ' ', (array) ( $settings['root_classes'] ?? array() ) ) ),
		);
		$children      = $this->children( $body, 0, true );
		if ( null === $children ) {
			// The body's children depend on their exact place: keep the whole body as one HTML widget.
			$children = array( $this->htmlWidget( self::innerHtml( $body ), __( 'The page layout depends on the exact order of its top-level elements.', 'ai-seo-content-studio' ) ) );
		}
		$root = $this->element( 'container', $root_settings, $children );

		if ( $this->risky > 0 ) {
			/* translators: %d: count. */
			$this->notes[] = sprintf( _n( 'Native mode: %d element was made a widget although some of its CSS depends on its exact parent or siblings. Compare the preview with the original.', 'Native mode: %d elements were made widgets although some of their CSS depends on their exact parent or siblings. Compare the preview with the original.', $this->risky, 'ai-seo-content-studio' ), $this->risky );
		}
		return array(
			'elements' => array( $root ),
			'sections' => self::classify( array( $root ) ),
			'counts'   => $this->counts,
			'css'      => $this->css,
			'notes'    => array_values( array_unique( $this->notes ) ),
		);
	}

	/* ------------------------------------------------------------ Structure */

	/**
	 * Elementor children for a container element, or null when they cannot be separated.
	 *
	 * @param \DOMElement $parent Parent.
	 * @param int         $depth  Depth.
	 * @param bool        $page   Children are page sections (reported).
	 * @return array<int, array<string, mixed>>|null
	 */
	private function children( \DOMElement $parent, int $depth, bool $page = false ): ?array {
		$plan = array();
		$run  = array();
		foreach ( iterator_to_array( $parent->childNodes ) as $node ) {
			if ( $node instanceof \DOMText ) {
				if ( '' !== trim( $node->textContent ) ) {
					$run[] = $node;
				} elseif ( array() !== $run ) {
					$run[] = $node;
				}
				continue;
			}
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			if ( in_array( strtolower( $node->tagName ), self::INLINE_TAGS, true ) && ! $this->standaloneImage( $node ) ) {
				$run[] = $node;
				continue;
			}
			if ( array() !== $run ) {
				$plan[] = $run;
				$run    = array();
			}
			$plan[] = $node;
		}
		if ( array() !== $run ) {
			$plan[] = $run;
		}

		// Each planned item becomes an Elementor element. An item that ends up as a widget gets
		// Elementor's wrappers around it, which is only safe when no rule needs its exact parent or
		// siblings; in a flex or grid parent the wrapper would also become the flex/grid item.
		$layout = $this->index->isLayout( $parent );
		$out    = array();
		foreach ( $plan as $item ) {
			if ( is_array( $item ) ) {
				$html = '';
				foreach ( $item as $n ) {
					$html .= $this->doc( $n )->saveHTML( $n );
				}
				$html = trim( $html );
				if ( '' === $html ) {
					continue;
				}
				$tops = array_values( array_filter( $item, static fn ( $n ) => $n instanceof \DOMElement ) );
				$risk = ( $layout && ( count( $plan ) > 1 || count( $tops ) > 1 ) ) || array() !== array_filter( $tops, fn ( \DOMElement $t ) => $this->index->sensitive( $t ) );
				if ( $risk && ! $this->accept() ) {
					return null;
				}
				$widget = 'fidelity' === $this->mode || $this->usesScript( $item )
					? $this->htmlWidget( $html, 'fidelity' === $this->mode ? __( 'High-fidelity mode keeps sections as original HTML.', 'ai-seo-content-studio' ) : __( 'Used by the page’s JavaScript.', 'ai-seo-content-studio' ) )
					: $this->textWidget( self::flatten( $html ) );
				$widget['_aiscs_label'] = mb_substr( trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $html ) ) ), 0, 80 );
				$out[]                  = $widget;
				continue;
			}
			$el = $this->place( $item, $depth + 1 );
			if ( 'widget' === $el['elType'] && ( $this->index->sensitive( $item ) || ( $layout && count( $plan ) > 1 ) ) && ! $this->accept() ) {
				return null;
			}
			$el['_aiscs_label'] = $this->label( $item );
			$el['_aiscs_tag']   = strtolower( $item->tagName );
			$out[]              = $el;
		}
		return $out;
	}

	/**
	 * One element as an Elementor element.
	 *
	 * @param \DOMElement $el    Element.
	 * @param int         $depth Depth.
	 * @return array<string, mixed>
	 */
	private function place( \DOMElement $el, int $depth ): array {
		$tag = strtolower( $el->tagName );

		if ( $this->becomesContainer( $el, $depth ) ) {
			$children = $this->children( $el, $depth );
			if ( null !== $children ) {
				return $this->container( $el, $children );
			}
			return $this->htmlWidget( $this->doc( $el )->saveHTML( $el ), __( 'Its children depend on their exact place in the CSS (child or sibling selectors).', 'ai-seo-content-studio' ) );
		}

		if ( 'fidelity' === $this->mode ) {
			return $this->htmlWidget( $this->doc( $el )->saveHTML( $el ), __( 'High-fidelity mode keeps sections as original HTML.', 'ai-seo-content-studio' ) );
		}
		$blocker = $this->nativeBlocker( $el );
		if ( '' !== $blocker ) {
			return $this->htmlWidget( $this->doc( $el )->saveHTML( $el ), $blocker );
		}
		if ( in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) && self::plain( $el ) && ! self::hasBlockChild( $el ) ) {
			return $this->headingWidget( $el );
		}
		if ( 'img' === $tag && self::plain( $el ) && ! $el->hasAttribute( 'width' ) && ! $el->hasAttribute( 'height' ) && str_starts_with( $el->getAttribute( 'src' ), AssetRefs::TOKEN ) ) {
			return $this->imageWidget( $el );
		}
		if ( in_array( $tag, self::TEXT_TAGS, true ) || in_array( $tag, self::INLINE_TAGS, true ) ) {
			return $this->textWidget( self::flatten( $this->doc( $el )->saveHTML( $el ) ), $tag );
		}
		return $this->htmlWidget( $this->doc( $el )->saveHTML( $el ), sprintf( __( 'No native widget for <%s>.', 'ai-seo-content-studio' ), $tag ) );
	}

	/**
	 * Whether an element becomes an Elementor container in the current mode.
	 *
	 * @param \DOMElement $el    Element.
	 * @param int         $depth Depth below the body.
	 */
	private function becomesContainer( \DOMElement $el, int $depth ): bool {
		$tag = strtolower( $el->tagName );
		if ( ! in_array( $tag, self::CONTAINER_TAGS, true ) || $depth > 30 ) {
			return false;
		}
		// Only element children (and whitespace): text directly inside stays with its element.
		$elements = 0;
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				++$elements;
			} elseif ( $child instanceof \DOMText && '' !== trim( $child->textContent ) && 'fidelity' === $this->mode ) {
				return false;
			}
		}
		if ( 0 === $elements ) {
			return false;
		}
		if ( 'native' !== $this->mode && ( $this->hasBehaviourAttr( $el ) || $this->usedByScript( $el ) ) ) {
			return false;
		}
		if ( 'fidelity' === $this->mode ) {
			// Only the page structure: wrappers that hold the page or several sections.
			return $depth <= 1 && ( 1 === $elements || $this->holdsSections( $el ) ) || $this->holdsSections( $el );
		}
		return true;
	}

	/**
	 * Whether an element holds several sections (a page wrapper such as <main>).
	 *
	 * @param \DOMElement $el Element.
	 */
	private function holdsSections( \DOMElement $el ): bool {
		$n = 0;
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement && in_array( strtolower( $child->tagName ), array( 'section', 'header', 'footer', 'article', 'main', 'nav', 'aside' ), true ) ) {
				++$n;
			}
		}
		return $n >= 2 || 'main' === strtolower( $el->tagName ) || ( 'body' === strtolower( (string) $el->parentNode?->nodeName ) && $n >= 1 );
	}

	/**
	 * Why an element cannot be a native widget ("" when it can).
	 *
	 * @param \DOMElement $el Element.
	 */
	private function nativeBlocker( \DOMElement $el ): string {
		$tag = strtolower( $el->tagName );
		if ( in_array( $tag, self::HTML_ONLY, true ) ) {
			return sprintf( __( 'Interactive or embedded element <%s>.', 'ai-seo-content-studio' ), $tag );
		}
		foreach ( $el->getElementsByTagName( '*' ) as $inner ) {
			if ( in_array( strtolower( $inner->tagName ), self::HTML_ONLY, true ) ) {
				return sprintf( __( 'Contains <%s>, which needs its original HTML.', 'ai-seo-content-studio' ), strtolower( $inner->tagName ) );
			}
		}
		if ( $this->usesScript( array( $el ) ) ) {
			return __( 'Used by the page’s JavaScript.', 'ai-seo-content-studio' );
		}
		return '';
	}

	/**
	 * Whether a script may look up any element in a list (or below it). Attributes such as data-*
	 * or ARIA stay in a Text Editor widget's HTML, so only script use keeps a leaf as HTML.
	 *
	 * @param array<int, \DOMNode> $nodes Nodes.
	 */
	private function usesScript( array $nodes ): bool {
		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			if ( $this->usedByScript( $node ) ) {
				return true;
			}
			foreach ( $node->getElementsByTagName( '*' ) as $inner ) {
				if ( $this->usedByScript( $inner ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Behaviour attributes (data-*, ARIA state, role, tabindex …), which Elementor Free cannot set.
	 *
	 * @param \DOMElement $el Element.
	 */
	private function hasBehaviourAttr( \DOMElement $el ): bool {
		foreach ( $el->attributes as $attr ) {
			if ( preg_match( self::BEHAVIOUR_ATTR, $attr->nodeName ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a script may look this element up by class or id.
	 *
	 * @param \DOMElement $el Element.
	 */
	private function usedByScript( \DOMElement $el ): bool {
		if ( array() === $this->hooks ) {
			return false;
		}
		$id = $el->getAttribute( 'id' );
		if ( '' !== $id && isset( $this->hooks[ $id ] ) ) {
			return true;
		}
		foreach ( ProjectParser::classes( $el ) as $class ) {
			if ( isset( $this->hooks[ $class ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * In native mode a risky widget is accepted (and counted); otherwise the caller falls back to
	 * keeping the parent's original HTML.
	 */
	private function accept(): bool {
		if ( 'native' === $this->mode ) {
			++$this->risky;
			return true;
		}
		return false;
	}

	/* ------------------------------------------------------------ Elements */

	/**
	 * Container element.
	 *
	 * @param \DOMElement                      $el       Element.
	 * @param array<int, array<string, mixed>> $children Children.
	 * @return array<string, mixed>
	 */
	private function container( \DOMElement $el, array $children ): array {
		++$this->counts['containers'];
		$settings = array(
			'content_width' => 'full',
			'html_tag'      => strtolower( $el->tagName ),
		);
		$classes = ProjectParser::classes( $el );
		$style   = trim( $el->getAttribute( 'style' ) );
		if ( '' !== $style ) {
			// Inline styles win over every rule in the original; !important keeps that true here.
			$class     = 'aiscs-s' . ( ++$this->styleIndex );
			$classes[] = $class;
			$decls     = array();
			foreach ( CssScoper::splitTopLevel( $style, ';' ) as $decl ) {
				$decl = trim( $decl );
				if ( '' !== $decl && str_contains( $decl, ':' ) ) {
					$decls[] = str_contains( strtolower( $decl ), '!important' ) ? $decl : $decl . ' !important';
				}
			}
			$this->css .= $this->scope . ' .' . $class . '{' . implode( ';', $decls ) . "}\n";
		}
		if ( array() !== $classes ) {
			$settings['css_classes'] = implode( ' ', $classes );
		}
		$id = $el->getAttribute( 'id' );
		if ( '' !== $id && preg_match( '/^[A-Za-z][\w-]{0,99}$/', $id ) ) {
			$settings['_element_id'] = $id;
		}
		$this->notesForDropped( $el );
		return $this->element( 'container', $settings, $children );
	}

	/**
	 * Heading widget.
	 *
	 * @param \DOMElement $el Heading.
	 * @return array<string, mixed>
	 */
	private function headingWidget( \DOMElement $el ): array {
		++$this->counts['native'];
		++$this->counts['headings'];
		return $this->element(
			'widget',
			array(
				'title'       => trim( self::flatten( self::innerHtml( $el ) ) ),
				'header_size' => strtolower( $el->tagName ),
			),
			array(),
			'heading'
		);
	}

	/**
	 * Image widget (the URL is a token until the asset is imported).
	 *
	 * @param \DOMElement $el Image.
	 * @return array<string, mixed>
	 */
	private function imageWidget( \DOMElement $el ): array {
		++$this->counts['native'];
		++$this->counts['images'];
		return $this->element(
			'widget',
			array(
				'image'      => array(
					'id'  => 0,
					'url' => $el->getAttribute( 'src' ),
					'alt' => $el->getAttribute( 'alt' ),
				),
				'image_size' => 'full',
			),
			array(),
			'image'
		);
	}

	/**
	 * Text Editor widget with original markup.
	 *
	 * @param string $html Markup.
	 * @param string $tag  Top element tag (for the count).
	 * @return array<string, mixed>
	 */
	private function textWidget( string $html, string $tag = '' ): array {
		++$this->counts['native'];
		++$this->counts['text'];
		return $this->element( 'widget', array( 'editor' => $html ), array(), 'text-editor' );
	}

	/**
	 * HTML widget with original markup.
	 *
	 * @param string $html   Markup.
	 * @param string $reason Why it is not native.
	 * @return array<string, mixed>
	 */
	private function htmlWidget( string $html, string $reason ): array {
		++$this->counts['html'];
		$el           = $this->element( 'widget', array( 'html' => trim( $html ) ), array(), 'html' );
		$el['_aiscs_reason'] = $reason;
		return $el;
	}

	/**
	 * Elementor element.
	 *
	 * @param string                           $type     elType.
	 * @param array<string, mixed>             $settings Settings.
	 * @param array<int, array<string, mixed>> $children Children.
	 * @param string                           $widget   Widget type.
	 * @return array<string, mixed>
	 */
	private function element( string $type, array $settings, array $children = array(), string $widget = '' ): array {
		$el = array(
			'id'       => $this->ids->next(),
			'elType'   => $type,
			'settings' => $settings,
			'elements' => array_values( $children ),
			'isInner'  => false,
		);
		if ( 'widget' === $type ) {
			$el['widgetType'] = $widget;
			unset( $el['isInner'] );
		}
		return $el;
	}

	/* ------------------------------------------------------------ Report */

	/**
	 * Short label for an element: its first heading, else its id, class or tag.
	 *
	 * @param \DOMElement $el Element.
	 */
	private function label( \DOMElement $el ): string {
		$tag = strtolower( $el->tagName );
		foreach ( array( 'h1', 'h2', 'h3', 'h4' ) as $h ) {
			$found = $tag === $h ? $el : $el->getElementsByTagName( $h )->item( 0 );
			if ( $found ) {
				$text = trim( (string) preg_replace( '/\s+/', ' ', (string) $found->textContent ) );
				if ( '' !== $text ) {
					return mb_substr( $text, 0, 80 );
				}
			}
		}
		$classes = ProjectParser::classes( $el );
		if ( '' !== $el->getAttribute( 'id' ) ) {
			return '<' . $tag . '#' . $el->getAttribute( 'id' ) . '>';
		}
		return '<' . $tag . ( array() !== $classes ? '.' . $classes[0] : '' ) . '>';
	}

	/**
	 * Classify the built tree for each reported section: native, html or hybrid.
	 *
	 * @param array<int, array<string, mixed>> $elements Built elements (the root).
	 * @return array<int, array<string, mixed>>
	 */
	public static function classify( array $elements ): array {
		$sections = self::sectionsOf( (array) ( ( $elements[0] ?? array() )['elements'] ?? array() ) );
		$out      = array();
		foreach ( $sections as $section ) {
			$kinds   = array();
			$reasons = array();
			self::walk( $section, $kinds, $reasons );
			$has_html   = isset( $kinds['html'] );
			$has_native = isset( $kinds['native'] );
			$out[]      = array(
				'label'   => (string) ( $section['_aiscs_label'] ?? '' ),
				'tag'     => (string) ( $section['_aiscs_tag'] ?? ( $section['settings']['html_tag'] ?? '' ) ),
				'type'    => $has_html && $has_native ? 'hybrid' : ( $has_html ? 'html' : 'native' ),
				'html'    => $kinds['html'] ?? 0,
				'native'  => $kinds['native'] ?? 0,
				'reasons' => array_values( array_unique( $reasons ) ),
			);
		}
		return $out;
	}

	/**
	 * Page sections: page wrappers (a single child, or <main>) are opened up.
	 *
	 * @param array<int, array<string, mixed>> $list Elements.
	 * @return array<int, array<string, mixed>>
	 */
	private static function sectionsOf( array $list ): array {
		$out = array();
		foreach ( $list as $el ) {
			$kids    = (array) ( $el['elements'] ?? array() );
			$wrapper = 'container' === ( $el['elType'] ?? '' ) && array() !== $kids
				&& ( 'main' === ( $el['settings']['html_tag'] ?? '' ) || ( 1 === count( $list ) && count( array_filter( $kids, static fn ( $k ) => 'container' === ( $k['elType'] ?? '' ) ) ) >= 1 ) );
			if ( $wrapper ) {
				array_push( $out, ...self::sectionsOf( $kids ) );
			} else {
				$out[] = $el;
			}
		}
		return $out;
	}

	/**
	 * Count widget kinds below an element.
	 *
	 * @param array<string, mixed> $el      Element.
	 * @param array<string, int>   $kinds   Counts.
	 * @param string[]             $reasons Reasons for HTML widgets.
	 */
	private static function walk( array $el, array &$kinds, array &$reasons ): void {
		if ( 'widget' === ( $el['elType'] ?? '' ) ) {
			$kind           = 'html' === $el['widgetType'] ? 'html' : 'native';
			$kinds[ $kind ] = ( $kinds[ $kind ] ?? 0 ) + 1;
			if ( isset( $el['_aiscs_reason'] ) ) {
				$reasons[] = (string) $el['_aiscs_reason'];
			}
			return;
		}
		foreach ( (array) ( $el['elements'] ?? array() ) as $child ) {
			self::walk( $child, $kinds, $reasons );
		}
	}

	/**
	 * Remove the builder's private keys before saving.
	 *
	 * @param array<int, array<string, mixed>> $elements Elements.
	 * @return array<int, array<string, mixed>>
	 */
	public static function strip( array $elements ): array {
		foreach ( $elements as &$el ) {
			unset( $el['_aiscs_reason'], $el['_aiscs_label'], $el['_aiscs_tag'] );
			if ( ! empty( $el['elements'] ) ) {
				$el['elements'] = self::strip( $el['elements'] );
			}
		}
		return $elements;
	}

	/* ------------------------------------------------------------ Helpers */

	/**
	 * Remove the page's own header, navigation and footer (setting "skip").
	 *
	 * @param \DOMElement $body Body.
	 */
	private function skipChrome( \DOMElement $body ): void {
		$xpath   = new \DOMXPath( $this->doc( $body ) );
		$removed = 0;
		foreach ( iterator_to_array( $xpath->query( '//header[not(ancestor::article) and not(ancestor::section)] | //footer[not(ancestor::article) and not(ancestor::section)] | //nav[not(ancestor::header) and not(ancestor::footer) and not(ancestor::main)] | //*[@role="banner" or @role="contentinfo"]' ) ) as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
				++$removed;
			}
		}
		if ( $removed > 0 ) {
			/* translators: %d: count. */
			$this->notes[] = sprintf( _n( '%d header, navigation or footer element of the project was left out (setting: use the site’s header and footer).', '%d header, navigation or footer elements of the project were left out (setting: use the site’s header and footer).', $removed, 'ai-seo-content-studio' ), $removed );
		}
	}

	/**
	 * Note attributes a container cannot keep.
	 *
	 * @param \DOMElement $el Element.
	 */
	private function notesForDropped( \DOMElement $el ): void {
		foreach ( $el->attributes as $attr ) {
			$name = strtolower( $attr->nodeName );
			if ( ! in_array( $name, array( 'class', 'id', 'style' ), true ) ) {
				++$this->counts['differences'];
				$this->notes[] = sprintf( __( 'The attribute "%1$s" of a <%2$s> container cannot be kept by an Elementor container.', 'ai-seo-content-studio' ), $name, strtolower( $el->tagName ) );
			}
		}
	}

	/**
	 * A heading or image with no class, id, style or attributes beyond basic ones.
	 *
	 * @param \DOMElement $el Element.
	 */
	private static function plain( \DOMElement $el ): bool {
		foreach ( $el->attributes as $attr ) {
			if ( ! in_array( strtolower( $attr->nodeName ), array( 'src', 'alt', 'loading', 'decoding' ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether an image stands on its own (a direct child block, not inline in text).
	 *
	 * @param \DOMElement $el Element.
	 */
	private function standaloneImage( \DOMElement $el ): bool {
		if ( 'img' !== strtolower( $el->tagName ) ) {
			return false;
		}
		foreach ( $el->parentNode->childNodes as $sibling ) {
			if ( $sibling instanceof \DOMText && '' !== trim( $sibling->textContent ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether an element has a block child.
	 *
	 * @param \DOMElement $el Element.
	 */
	private static function hasBlockChild( \DOMElement $el ): bool {
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof \DOMElement && ! in_array( strtolower( $child->tagName ), self::INLINE_TAGS, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Inner HTML.
	 *
	 * @param \DOMElement $el Element.
	 */
	public static function innerHtml( \DOMElement $el ): string {
		$html = '';
		foreach ( $el->childNodes as $child ) {
			$html .= $el->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * Collapse line breaks (Text Editor content passes through wpautop-like filters).
	 *
	 * @param string $html Markup.
	 */
	private static function flatten( string $html ): string {
		return trim( (string) preg_replace( '/\s*[\r\n]+\s*/', ' ', $html ) );
	}

	/**
	 * Owner document.
	 *
	 * @param \DOMNode $node Node.
	 */
	private function doc( \DOMNode $node ): \DOMDocument {
		return $node instanceof \DOMDocument ? $node : $node->ownerDocument;
	}
}
