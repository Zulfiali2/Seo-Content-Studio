<?php
/**
 * Computed styles of DOM elements per device.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Converter;

use AiSeoContentStudio\WebsiteStudio\Css\CssParser;

defined( 'ABSPATH' ) || exit;

/**
 * A small CSS cascade: rules from the stylesheets (in source order), ordered by specificity,
 * then the element's inline style. Media queries are resolved per device the way
 * max-width breakpoints work: tablet = desktop + tablet rules, mobile = desktop + tablet +
 * mobile rules. Typography and colour inherit from the parent. `!important` is not modelled.
 *
 * Rules are indexed by their rightmost compound (id, class, tag or universal), so an element
 * only checks rules that can possibly match it.
 */
final class StyleResolver {

	public const DEVICES = array( 'desktop', 'tablet', 'mobile' );

	private const INHERITED = array( 'color', 'font-family', 'font-size', 'font-weight', 'line-height', 'text-align', 'letter-spacing', 'text-transform', 'font-style' );

	/**
	 * Rules: list of {parsed, declarations, device, order}.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $rules = array();

	/**
	 * Rule indexes by key ("#id", ".class", "tag", "*").
	 *
	 * @var array<string, int[]>
	 */
	private array $index = array();

	/**
	 * Computed and declared style caches: element => device => properties.
	 *
	 * WeakMaps, not spl_object_id(): DOM wrapper objects are created on demand and freed, and a
	 * freed wrapper's object id is reused by the next one, which would return another node's styles.
	 *
	 * @var \WeakMap<\DOMElement, array<string, array<string, string>>>
	 */
	private \WeakMap $computed;

	/**
	 * Declared style cache (see $computed).
	 *
	 * @var \WeakMap<\DOMElement, array<string, array<string, string>>>
	 */
	private \WeakMap $declared;

	/** Selectors that could not be used (reported). */
	public int $unsupportedSelectors = 0;

	/** CSS features seen that do not convert (reported). */
	public array $unsupportedFeatures = array();

	/**
	 * Constructor.
	 *
	 * @param string          $css     Stylesheet text.
	 * @param SelectorMatcher $matcher Matcher.
	 */
	public function __construct( string $css, private readonly SelectorMatcher $matcher = new SelectorMatcher() ) {
		$this->computed = new \WeakMap();
		$this->declared = new \WeakMap();
		$parsed         = ( new CssParser() )->parse( $css );
		$order  = 0;
		foreach ( $parsed['rules'] as $rule ) {
			if ( ! in_array( $rule['device'], self::DEVICES, true ) ) {
				continue;
			}
			foreach ( $rule['declarations'] as $prop => $value ) {
				if ( in_array( $prop, array( 'position', 'transform', 'animation', 'transition', 'float', 'clip-path', 'filter' ), true ) && ! in_array( $value, array( 'static', 'relative', 'none', 'initial' ), true ) ) {
					$this->unsupportedFeatures[ $prop ] = true;
				}
			}
			foreach ( $rule['selectors'] as $selector ) {
				$p = $this->matcher->parse( $selector );
				if ( null === $p ) {
					++$this->unsupportedSelectors;
					continue;
				}
				$last = end( $p['parts'] );
				$key  = '' !== $last['id'] ? '#' . $last['id'] : ( array() !== $last['classes'] ? '.' . $last['classes'][0] : ( '' !== $last['tag'] ? $last['tag'] : '*' ) );
				$this->rules[]         = array(
					'parsed'       => $p,
					'declarations' => $rule['declarations'],
					'device'       => $rule['device'],
					'order'        => $order++,
				);
				$this->index[ $key ][] = count( $this->rules ) - 1;
			}
		}
		$this->unsupportedFeatures = array_keys( $this->unsupportedFeatures );
	}

	/**
	 * Declared (not inherited) properties of an element on a device.
	 *
	 * @param \DOMElement $el     Element.
	 * @param string      $device Device.
	 * @return array<string, string>
	 */
	public function own( \DOMElement $el, string $device = 'desktop' ): array {
		if ( isset( $this->declared[ $el ][ $device ] ) ) {
			return $this->declared[ $el ][ $device ];
		}
		$devices = array_slice( self::DEVICES, 0, array_search( $device, self::DEVICES, true ) + 1 );
		$keys    = array( '*', strtolower( $el->tagName ) );
		if ( '' !== $el->getAttribute( 'id' ) ) {
			$keys[] = '#' . $el->getAttribute( 'id' );
		}
		foreach ( preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ) ) as $class ) {
			if ( '' !== $class ) {
				$keys[] = '.' . $class;
			}
		}
		$hits = array();
		foreach ( array_unique( $keys ) as $key ) {
			foreach ( $this->index[ $key ] ?? array() as $i ) {
				$rule = $this->rules[ $i ];
				if ( in_array( $rule['device'], $devices, true ) && $this->matcher->matches( $el, $rule['parsed'] ) ) {
					$hits[ $i ] = $rule;
				}
			}
		}
		// Device order first (mobile rules override desktop ones), then specificity, then source order.
		uasort(
			$hits,
			static fn ( array $a, array $b ): int => array( array_search( $a['device'], self::DEVICES, true ), $a['parsed']['specificity'], $a['order'] ) <=> array( array_search( $b['device'], self::DEVICES, true ), $b['parsed']['specificity'], $b['order'] )
		);
		$out = array();
		foreach ( $hits as $rule ) {
			$out = array_merge( $out, self::expand( $rule['declarations'] ) );
		}
		if ( '' !== $el->getAttribute( 'style' ) ) {
			$out = array_merge( $out, self::expand( CssParser::declarations( $el->getAttribute( 'style' ) ) ) );
		}
		$this->declared[ $el ] = array( $device => $out ) + ( $this->declared[ $el ] ?? array() );
		return $out;
	}

	/**
	 * Computed properties (own + inherited typography and colour).
	 *
	 * @param \DOMElement $el     Element.
	 * @param string      $device Device.
	 * @return array<string, string>
	 */
	public function computed( \DOMElement $el, string $device = 'desktop' ): array {
		if ( isset( $this->computed[ $el ][ $device ] ) ) {
			return $this->computed[ $el ][ $device ];
		}
		$own     = $this->own( $el, $device );
		$parent  = $el->parentNode instanceof \DOMElement ? $this->computed( $el->parentNode, $device ) : array();
		$out     = $own;
		foreach ( self::INHERITED as $prop ) {
			if ( ! isset( $out[ $prop ] ) && isset( $parent[ $prop ] ) ) {
				$out[ $prop ] = $parent[ $prop ];
			} elseif ( isset( $out[ $prop ] ) && 'inherit' === $out[ $prop ] ) {
				$out[ $prop ] = $parent[ $prop ] ?? '';
			}
		}
		$this->computed[ $el ] = array( $device => $out ) + ( $this->computed[ $el ] ?? array() );
		return $out;
	}

	/**
	 * Expand the shorthands the converter reads.
	 *
	 * @param array<string, string> $d Declarations.
	 * @return array<string, string>
	 */
	public static function expand( array $d ): array {
		if ( isset( $d['background'] ) ) {
			$bg = $d['background'];
			if ( preg_match( '/(#[0-9a-f]{3,8}\b|rgba?\([^)]*\))/i', $bg, $m ) && ! str_contains( $bg, 'gradient' ) ) {
				$d['background-color'] ??= $m[1];
			}
			if ( preg_match( '/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $bg, $m ) ) {
				$d['background-image'] ??= 'url(' . $m[1] . ')';
			}
			if ( str_contains( $bg, 'gradient' ) ) {
				$d['background-gradient'] = $bg;
			}
		}
		if ( isset( $d['font'] ) && preg_match( '/(?:(bold|[1-9]00)\s+)?(\d+(?:\.\d+)?(?:px|rem|em))(?:\/([^\s]+))?\s+(.+)$/i', $d['font'], $m ) ) {
			if ( '' !== $m[1] ) {
				$d['font-weight'] ??= $m[1];
			}
			$d['font-size'] ??= $m[2];
			if ( ! empty( $m[3] ) ) {
				$d['line-height'] ??= $m[3];
			}
			$d['font-family'] ??= $m[4];
		}
		if ( isset( $d['gap'] ) && ! isset( $d['column-gap'] ) ) {
			$parts           = preg_split( '/\s+/', trim( $d['gap'] ) );
			$d['column-gap'] = (string) end( $parts );
			$d['row-gap']    = (string) $parts[0];
		}
		if ( isset( $d['flex-flow'] ) ) {
			foreach ( preg_split( '/\s+/', $d['flex-flow'] ) as $v ) {
				if ( in_array( $v, array( 'row', 'column', 'row-reverse', 'column-reverse' ), true ) ) {
					$d['flex-direction'] ??= $v;
				} elseif ( in_array( $v, array( 'wrap', 'nowrap' ), true ) ) {
					$d['flex-wrap'] ??= $v;
				}
			}
		}
		if ( isset( $d['border'] ) && preg_match( '/(\d+(?:\.\d+)?px)\s+(solid|dashed|dotted)\s+(#[0-9a-f]{3,8}|rgba?\([^)]*\))/i', $d['border'], $m ) ) {
			$d['border-width'] ??= $m[1];
			$d['border-style'] ??= $m[2];
			$d['border-color'] ??= $m[3];
		}
		return $d;
	}
}
