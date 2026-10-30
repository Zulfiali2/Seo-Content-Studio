<?php
/**
 * Allow-list sanitiser for rich-text fragments written into Text Editor widgets.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Parses a fragment with DOMDocument and rebuilds it from allowed elements only.
 *
 * Allowed: p, br, strong, b, em, i, u, ul, ol, li, blockquote, code, h3, h4, a[href].
 * Links keep http(s), mailto, tel, site-relative and fragment URLs; everything else
 * (javascript:, data:, vbscript:, protocol-relative) is dropped and the text kept.
 * Disallowed elements are unwrapped (text kept), except script/style/iframe/object/embed/
 * template/noscript/svg/math, which are removed whole. Attributes other than href are
 * removed. When WordPress is loaded, wp_kses_post() runs on the result as well.
 */
final class HtmlFragment {

	private const ALLOWED = array( 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'blockquote', 'code', 'h3', 'h4', 'a', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption' );

	/** Extra elements kept in fallback mode (HTML widget of the converter). */
	private const FALLBACK = array( 'div', 'span', 'section', 'article', 'figure', 'figcaption', 'img', 'h1', 'h2', 'h5', 'h6', 'small', 'sup', 'sub', 'mark', 'hr', 'dl', 'dt', 'dd', 'pre', 'address' );

	/** Inline style properties kept in fallback mode. */
	private const FALLBACK_STYLE = array( 'color', 'background-color', 'font-size', 'font-weight', 'font-style', 'text-align', 'line-height', 'padding', 'margin', 'border', 'border-radius', 'width', 'max-width', 'height', 'display', 'gap', 'flex-direction', 'justify-content', 'align-items', 'flex-wrap' );

	private static bool $fallback = false;

	private const DROP = array( 'script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'head', 'title' );

	public const MAX_LENGTH = 20000;

	/**
	 * Sanitise.
	 *
	 * @param string $html     Untrusted HTML.
	 * @param bool   $fallback Fallback mode: also keep layout elements, images and safe inline styles.
	 */
	public static function clean( string $html, bool $fallback = false ): string {
		self::$fallback = $fallback;
		$html = trim( mb_substr( $html, 0, self::MAX_LENGTH * 2 ) );
		if ( '' === $html ) {
			return '';
		}
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		// The XML declaration makes libxml read the input as UTF-8; the wrapper gives one root.
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="aiscs-root">' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = null;
		foreach ( $doc->getElementsByTagName( 'div' ) as $div ) {
			if ( 'aiscs-root' === $div->getAttribute( 'id' ) ) {
				$root = $div;
				break;
			}
		}
		if ( null === $root ) {
			return '';
		}
		$out = trim( self::children( $root ) );
		// Bare text becomes a paragraph, so the Text Editor widget gets block content.
		if ( '' !== $out && ! $fallback && ! preg_match( '/^<(p|ul|ol|blockquote|h3|h4|table)\b/', $out ) ) {
			$out = '<p>' . $out . '</p>';
		}
		$out = mb_substr( $out, 0, self::MAX_LENGTH );
		if ( function_exists( 'wp_kses_post' ) ) {
			$out = wp_kses_post( $out );
		}
		return $out;
	}

	/**
	 * Plain text of a fragment (for headings, buttons and length checks).
	 *
	 * @param string $html HTML.
	 */
	public static function text( string $html ): string {
		$text = html_entity_decode( strip_tags( (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Serialise allowed children of a node.
	 *
	 * @param \DOMNode $node Node.
	 */
	private static function children( \DOMNode $node ): string {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= self::node( $child );
		}
		return $out;
	}

	/**
	 * Serialise one node.
	 *
	 * @param \DOMNode $node Node.
	 */
	private static function node( \DOMNode $node ): string {
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			return htmlspecialchars( (string) $node->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8', false );
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType || ! $node instanceof \DOMElement ) {
			return ''; // Comments, processing instructions.
		}
		$tag = strtolower( $node->tagName );
		if ( in_array( $tag, self::DROP, true ) ) {
			return '';
		}
		$inner = self::children( $node );
		if ( self::$fallback && in_array( $tag, self::FALLBACK, true ) ) {
			if ( 'img' === $tag ) {
				$src = self::url( $node->getAttribute( 'src' ) );
				return preg_match( '#^https?://#i', $src ) ? '<img src="' . htmlspecialchars( $src, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '" alt="' . htmlspecialchars( $node->getAttribute( 'alt' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '">' : '';
			}
			if ( 'hr' === $tag ) {
				return '<hr>';
			}
			return "<$tag" . self::style( $node ) . ">$inner</$tag>";
		}
		if ( ! in_array( $tag, self::ALLOWED, true ) ) {
			// Unwrap; block-level wrappers keep a paragraph break.
			return in_array( $tag, array( 'div', 'section', 'article', 'header', 'footer', 'h1', 'h2', 'h5', 'h6' ), true ) && '' !== trim( $inner ) ? '<p>' . $inner . '</p>' : $inner;
		}
		if ( 'br' === $tag ) {
			return '<br>';
		}
		if ( 'a' === $tag ) {
			$href = self::url( $node->getAttribute( 'href' ) );
			return '' === $href ? $inner : '<a href="' . htmlspecialchars( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '">' . $inner . '</a>';
		}
		if ( in_array( $tag, array( 'th', 'td' ), true ) ) {
			$span = '';
			foreach ( array( 'colspan', 'rowspan' ) as $attr ) {
				$n = (int) $node->getAttribute( $attr );
				if ( $n > 1 && $n <= 50 ) {
					$span .= " $attr=\"$n\"";
				}
			}
			return "<$tag$span>$inner</$tag>";
		}
		if ( in_array( $tag, array( 'table', 'thead', 'tbody', 'tfoot', 'tr' ), true ) ) {
			return "<$tag>$inner</$tag>";
		}
		return '' === trim( $inner ) && 'li' !== $tag ? '' : "<$tag" . ( self::$fallback ? self::style( $node ) : '' ) . ">$inner</$tag>";
	}

	/**
	 * Safe inline style attribute (fallback mode only).
	 *
	 * @param \DOMElement $node Element.
	 */
	private static function style( \DOMElement $node ): string {
		if ( ! self::$fallback || '' === $node->getAttribute( 'style' ) ) {
			return '';
		}
		$keep = array();
		foreach ( \AiSeoContentStudio\WebsiteStudio\Css\CssParser::declarations( $node->getAttribute( 'style' ) ) as $prop => $value ) {
			if ( in_array( $prop, self::FALLBACK_STYLE, true ) && preg_match( '/^[#a-zA-Z0-9.,%() \/-]{1,120}$/', $value ) && ! preg_match( '/url|expression|javascript|@import|\\\\/i', $value ) ) {
				$keep[] = "$prop: $value";
			}
		}
		return array() === $keep ? '' : ' style="' . htmlspecialchars( implode( '; ', $keep ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"';
	}

	/**
	 * A safe link target, or "".
	 *
	 * @param string $url URL.
	 */
	public static function url( string $url ): string {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		// Control characters and whitespace inside a scheme are a classic filter bypass.
		if ( '' === $url || preg_match( '/[\x00-\x1F\x7F]/', $url ) || strlen( $url ) > 2048 ) {
			return '';
		}
		if ( str_starts_with( $url, '#' ) || ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) ) {
			return $url;
		}
		return preg_match( '#^(https?://[^\s/$.?\#][^\s]*|mailto:[^\s]+|tel:[0-9+\-(). ]+)$#i', $url ) ? $url : '';
	}
}
