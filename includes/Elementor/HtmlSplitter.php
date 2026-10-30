<?php
/**
 * Split sanitised content HTML into Elementor widget chunks.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a section's HTML into an ordered list of chunks: sub-headings (h3/h4) become
 * their own heading widgets, everything between them is grouped into one rich-text chunk.
 *
 * Input is the plugin's already-sanitised content HTML (allow-listed tags only).
 */
final class HtmlSplitter {

	/**
	 * Split HTML.
	 *
	 * @param string $html           Sanitised HTML.
	 * @param bool   $separate_lists Put each top-level <ul> in its own chunk.
	 * @param bool   $callouts       Turn each top-level <blockquote> into a "callout" chunk.
	 * @return array<int, array{type: string, html?: string, text?: string, level?: string, kind?: string}>
	 */
	public static function split( string $html, bool $separate_lists = false, bool $callouts = false ): array {
		$html = trim( $html );
		if ( '' === $html ) {
			return array();
		}

		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="aiscs-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $doc->getElementById( 'aiscs-root' );
		if ( null === $root ) {
			return array(
				array(
					'type' => 'text',
					'html' => $html,
				),
			);
		}

		$chunks = array();
		$buffer = '';
		foreach ( iterator_to_array( $root->childNodes ) as $node ) {
			if ( $node instanceof \DOMElement && in_array( strtolower( $node->nodeName ), array( 'h2', 'h3', 'h4' ), true ) ) {
				self::flush( $chunks, $buffer );
				$text = trim( preg_replace( '/\s+/u', ' ', $node->textContent ) ?? '' );
				if ( '' !== $text ) {
					$chunks[] = array(
						'type'  => 'heading',
						'text'  => $text,
						'level' => 'h4' === strtolower( $node->nodeName ) ? 'h4' : 'h3',
					);
				}
				continue;
			}
			if ( $node instanceof \DOMText && '' === trim( $node->textContent ) ) {
				continue;
			}
			if ( $callouts && $node instanceof \DOMElement && 'blockquote' === strtolower( $node->nodeName ) ) {
				self::flush( $chunks, $buffer );
				$inner = '';
				foreach ( iterator_to_array( $node->childNodes ) as $child ) {
					$inner .= (string) $doc->saveHTML( $child );
				}
				if ( '' !== trim( $inner ) ) {
					$chunks[] = array(
						'type' => 'callout',
						'kind' => self::calloutKind( $node->textContent ),
						'html' => trim( $inner ),
					);
				}
				continue;
			}
			if ( $separate_lists && $node instanceof \DOMElement && 'ul' === strtolower( $node->nodeName ) ) {
				self::flush( $chunks, $buffer );
				$buffer = (string) $doc->saveHTML( $node );
				self::flush( $chunks, $buffer );
				continue;
			}
			$buffer .= (string) $doc->saveHTML( $node );
		}
		self::flush( $chunks, $buffer );
		return $chunks;
	}

	/**
	 * Callout style from the label the text starts with: "Pro tip:", "Note:", "Example:"…
	 *
	 * @param string $text Plain text of the blockquote.
	 * @return string tip | note | example | quote
	 */
	public static function calloutKind( string $text ): string {
		$start = mb_strtolower( ltrim( $text ) );
		foreach ( array(
			'tip'     => array( 'pro tip', 'tip', 'quick tip' ),
			'note'    => array( 'note', 'important', 'remember', 'key takeaway' ),
			'example' => array( 'example', 'for example' ),
		) as $kind => $labels ) {
			foreach ( $labels as $label ) {
				if ( preg_match( '/^' . preg_quote( $label, '/' ) . 's?\s*[:\-–—]/u', $start ) ) {
					return $kind;
				}
			}
		}
		return 'quote';
	}

	/**
	 * Move buffered HTML into a text chunk.
	 *
	 * @param array<int, array<string, string>> $chunks Chunks.
	 * @param string                            $buffer Buffer (reset).
	 */
	private static function flush( array &$chunks, string &$buffer ): void {
		$buffer = trim( $buffer );
		if ( '' !== $buffer ) {
			$chunks[] = array(
				'type' => 'text',
				'html' => $buffer,
			);
		}
		$buffer = '';
	}

	/**
	 * Plain list items when a chunk is exactly one simple list (no links or markup inside items).
	 *
	 * @param string $html Chunk HTML.
	 * @return string[]|null Items, or null when the chunk is not a simple list.
	 */
	public static function simpleListItems( string $html ): ?array {
		if ( ! preg_match( '#^<ul>(.*)</ul>$#is', trim( $html ), $m ) ) {
			return null;
		}
		if ( ! preg_match_all( '#<li>(.*?)</li>#is', $m[1], $items ) ) {
			return null;
		}
		$rest = trim( (string) preg_replace( '#<li>.*?</li>#is', '', $m[1] ) );
		if ( '' !== $rest ) {
			return null;
		}
		$out = array();
		foreach ( $items[1] as $item ) {
			if ( preg_match( '/<[a-z]/i', $item ) ) {
				return null; // Links or inline markup would be lost in an icon list.
			}
			$text = trim( html_entity_decode( $item, ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $text ) {
				return null;
			}
			$out[] = $text;
		}
		return $out;
	}
}
