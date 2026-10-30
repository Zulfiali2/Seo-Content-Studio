<?php
/**
 * Converts sanitised HTML into block editor markup.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Produces core/paragraph, core/heading and core/list(+list-item) blocks with the
 * same markup those blocks save (WordPress 6.6+), so the editor opens them as
 * valid, individually editable blocks. Anything else (tables, quotes) is left as
 * plain HTML, which the editor loads as a Classic block rather than an invalid one.
 */
final class BlockSerializer {

	/**
	 * Serialise an HTML fragment.
	 *
	 * @param string $html Sanitised HTML.
	 */
	public static function serialize( string $html ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="aiscs-root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $doc->getElementById( 'aiscs-root' );
		if ( ! $root ) {
			return $html;
		}

		$blocks = array();
		$loose  = '';
		foreach ( iterator_to_array( $root->childNodes ) as $node ) {
			if ( $node instanceof \DOMText ) {
				$loose .= $node->textContent;
				continue;
			}
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$tag    = strtolower( $node->nodeName );
			$inline = in_array( $tag, array( 'strong', 'em', 'b', 'i', 'a', 'code', 'br' ), true );
			if ( $inline ) {
				$loose .= $doc->saveHTML( $node );
				continue;
			}
			if ( '' !== trim( $loose ) ) {
				$blocks[] = self::paragraph( trim( $loose ) );
			}
			$loose = '';

			$inner = self::innerHtml( $node );
			switch ( $tag ) {
				case 'p':
					if ( '' !== trim( $inner ) ) {
						$blocks[] = self::paragraph( $inner );
					}
					break;
				case 'h2':
				case 'h3':
				case 'h4':
					$blocks[] = self::heading( (int) $tag[1], $inner );
					break;
				case 'ul':
				case 'ol':
					$blocks[] = self::listBlock( $node, 'ol' === $tag );
					break;
				case 'figure':
					$image    = self::imageFromFigure( $node );
					$blocks[] = null !== $image ? $image : $doc->saveHTML( $node );
					break;
				default:
					// Tables, quotes and anything else: classic (freeform) content.
					$blocks[] = $doc->saveHTML( $node );
			}
		}
		if ( '' !== trim( $loose ) ) {
			$blocks[] = self::paragraph( trim( $loose ) );
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * Core image block for a Media Library image, in the markup the block editor saves.
	 *
	 * @param int    $id      Attachment id.
	 * @param string $url     Image URL (large size).
	 * @param string $alt     Alt text.
	 * @param string $caption Caption (plain text).
	 */
	public static function image( int $id, string $url, string $alt, string $caption ): string {
		$figcaption = '' !== $caption ? '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>' : '';
		return '<!-- wp:image {"id":' . $id . ',"sizeSlug":"large","linkDestination":"none"} -->' . "\n"
			. '<figure class="wp-block-image size-large"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $id . '"/>' . $figcaption . "</figure>\n"
			. '<!-- /wp:image -->';
	}

	/**
	 * Image block from a figure produced by ImagePlan::figureHtml(), or null for other figures.
	 *
	 * @param \DOMElement $figure Figure.
	 */
	private static function imageFromFigure( \DOMElement $figure ): ?string {
		$img = $figure->getElementsByTagName( 'img' )->item( 0 );
		if ( ! $img instanceof \DOMElement || ! preg_match( '/\bwp-image-(\d+)\b/', $img->getAttribute( 'class' ), $m ) ) {
			return null;
		}
		$caption = $figure->getElementsByTagName( 'figcaption' )->item( 0 );
		return self::image( (int) $m[1], $img->getAttribute( 'src' ), $img->getAttribute( 'alt' ), $caption ? trim( $caption->textContent ) : '' );
	}

	/**
	 * Paragraph block.
	 *
	 * @param string $inner Inner HTML.
	 */
	public static function paragraph( string $inner ): string {
		return "<!-- wp:paragraph -->\n<p>" . $inner . "</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * Heading block (level 2 is the block default and has no attribute).
	 *
	 * @param int    $level Level 2–4.
	 * @param string $inner Inner HTML.
	 */
	public static function heading( int $level, string $inner ): string {
		$attrs = 2 === $level ? '' : ' {"level":' . $level . '}';
		return "<!-- wp:heading{$attrs} -->\n<h{$level} class=\"wp-block-heading\">" . $inner . "</h{$level}>\n<!-- /wp:heading -->";
	}

	/**
	 * List block with list-item inner blocks. Nested lists are flattened into text.
	 *
	 * @param \DOMElement $element List element.
	 * @param bool        $ordered Ordered list.
	 */
	private static function listBlock( \DOMElement $element, bool $ordered ): string {
		$items = array();
		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'li' === strtolower( $child->nodeName ) ) {
				foreach ( iterator_to_array( $child->getElementsByTagName( 'ul' ) ) as $nested ) {
					$nested->parentNode?->removeChild( $nested );
				}
				foreach ( iterator_to_array( $child->getElementsByTagName( 'ol' ) ) as $nested ) {
					$nested->parentNode?->removeChild( $nested );
				}
				$items[] = "<!-- wp:list-item -->\n<li>" . trim( self::innerHtml( $child ) ) . "</li>\n<!-- /wp:list-item -->";
			}
		}
		$tag   = $ordered ? 'ol' : 'ul';
		$attrs = $ordered ? ' {"ordered":true}' : '';
		return "<!-- wp:list{$attrs} -->\n<{$tag} class=\"wp-block-list\">" . implode( '', $items ) . "</{$tag}>\n<!-- /wp:list -->";
	}

	/**
	 * Inner HTML of an element.
	 *
	 * @param \DOMElement $node Element.
	 */
	private static function innerHtml( \DOMElement $node ): string {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}
}
