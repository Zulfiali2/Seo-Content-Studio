<?php
/**
 * Allow-list sanitiser for SVG markup.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * SVG can carry scripts (<script>, event handlers, javascript: links, <foreignObject> HTML,
 * external references). Only drawing elements and presentation attributes are kept. Used for
 * inline <svg> in the page and for .svg files, which are then inlined as data URIs (never
 * uploaded as files, so no SVG can run in the site's origin).
 */
final class SvgSanitizer {

	/** Elements kept. */
	private const ELEMENTS = array( 'svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'text', 'tspan', 'textpath', 'defs', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'symbol', 'use', 'title', 'desc', 'marker', 'image', 'filter', 'fegaussianblur', 'feoffset', 'feblend', 'fecolormatrix', 'fecomposite', 'feflood', 'femerge', 'femergenode', 'femorphology', 'fedropshadow', 'feturbulence', 'fedisplacementmap', 'fecomponenttransfer', 'fefunca', 'fefuncr', 'fefuncg', 'fefuncb', 'feimage', 'fetile', 'switch', 'metadata', 'animate', 'animatetransform', 'animatemotion', 'set', 'mpath', 'view' );

	/** Attribute prefixes never kept. */
	private const BLOCKED_ATTR = '/^(on|formaction|xmlns:(?!xlink$))/i';

	/** Attributes whose value is a reference. */
	private const REF_ATTRS = array( 'href', 'xlink:href', 'src' );

	/** @var string[] */
	public array $removed = array();

	/** Keep <style> inside the SVG (files shown as images, where it cannot reach the page). */
	private bool $keepStyle = false;

	/**
	 * Sanitise an <svg> element in place.
	 *
	 * @param \DOMElement                    $svg Element.
	 * @param callable(string): ?string|null $ref Maps an image reference (null drops it).
	 */
	public function element( \DOMElement $svg, ?callable $ref = null ): void {
		$this->walk( $svg, $ref );
	}

	/**
	 * Sanitise an SVG file and return clean markup, or "" when nothing usable is left.
	 *
	 * @param string $markup SVG file contents.
	 */
	public function file( string $markup ): string {
		if ( preg_match( '/<!ENTITY|<!DOCTYPE[^>]*\[/i', $markup ) ) {
			$this->removed[] = 'entity declarations';
			$markup          = (string) preg_replace( '/<!DOCTYPE[^>]*(\[[^\]]*\])?\s*>/is', '', $markup );
		}
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$ok       = $doc->loadXML( $markup, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $ok || ! $doc->documentElement || 'svg' !== strtolower( $doc->documentElement->localName ) ) {
			return '';
		}
		$this->keepStyle = true;
		$this->walk( $doc->documentElement, null );
		$this->keepStyle = false;
		return (string) $doc->saveXML( $doc->documentElement );
	}

	/**
	 * Recursive clean.
	 *
	 * @param \DOMElement   $el  Element.
	 * @param callable|null $ref Reference mapper.
	 */
	private function walk( \DOMElement $el, ?callable $ref ): void {
		foreach ( iterator_to_array( $el->attributes ) as $attr ) {
			$name  = strtolower( $attr->nodeName );
			$value = (string) $attr->nodeValue;
			if ( preg_match( self::BLOCKED_ATTR, $name ) ) {
				$el->removeAttributeNode( $attr );
				$this->removed[] = 'attribute ' . $name;
				continue;
			}
			if ( in_array( $name, self::REF_ATTRS, true ) ) {
				$clean = trim( $value );
				if ( str_starts_with( $clean, '#' ) ) {
					continue;
				}
				$mapped = 'image' === strtolower( $el->localName ) && null !== $ref ? $ref( $clean ) : null;
				if ( null === $mapped && 'image' === strtolower( $el->localName ) && preg_match( '#^data:image/(png|jpe?g|gif|webp)[;,]#i', $clean ) ) {
					$mapped = $clean;
				}
				if ( null === $mapped ) {
					$el->removeAttributeNode( $attr );
					$this->removed[] = 'external reference';
					continue;
				}
				$el->setAttribute( $attr->nodeName, $mapped );
				continue;
			}
			if ( 'style' === $name && self::unsafeStyle( $value ) ) {
				$el->removeAttributeNode( $attr );
				$this->removed[] = 'unsafe style';
				continue;
			}
			if ( preg_match( '/(javascript|vbscript)\s*:|data\s*:\s*text\/html/i', $value ) ) {
				$el->removeAttributeNode( $attr );
				$this->removed[] = 'script URL';
			}
		}
		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			if ( $child instanceof \DOMElement ) {
				$tag = strtolower( $child->localName );
				if ( 'style' === $tag && $this->keepStyle ) {
					$css = (string) $child->textContent;
					if ( SvgSanitizer::unsafeStyle( $css ) || preg_match( '/url\(\s*[\'"]?\s*(?!#|data:image)/i', $css ) ) {
						$el->removeChild( $child );
						$this->removed[] = '<style> with external references';
					}
					continue;
				}
				if ( ! in_array( $tag, self::ELEMENTS, true ) ) {
					$el->removeChild( $child );
					$this->removed[] = '<' . $tag . '>';
					continue;
				}
				// Animations that set href or event attributes can bring scripts back.
				if ( in_array( $tag, array( 'animate', 'set', 'animatetransform', 'animatemotion' ), true ) && preg_match( '/^(href|xlink:href|on)/i', (string) $child->getAttribute( 'attributeName' ) ) ) {
					$el->removeChild( $child );
					$this->removed[] = 'animation of a link';
					continue;
				}
				$this->walk( $child, $ref );
			} elseif ( $child instanceof \DOMProcessingInstruction ) {
				$el->removeChild( $child );
			}
		}
	}

	/**
	 * Whether an inline style can run code or load untrusted content.
	 *
	 * @param string $style Style.
	 */
	public static function unsafeStyle( string $style ): bool {
		$s = strtolower( html_entity_decode( (string) preg_replace( '/\\\\[0-9a-f]{1,6}\s?|\/\*.*?\*\//is', '', $style ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return (bool) preg_match( '/expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding|@import|data\s*:\s*text\/html/', $s );
	}
}
