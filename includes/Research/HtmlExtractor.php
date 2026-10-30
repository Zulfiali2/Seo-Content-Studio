<?php
/**
 * Extracts an outline from a competitor page.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls structural signals (title, meta description, headings, question
 * headings, approximate word count, schema types) from HTML.
 *
 * Only the outline is kept. Body text is never stored or sent to the AI, so
 * generated content cannot paraphrase competitor pages.
 */
final class HtmlExtractor {

	private const MAX_HEADINGS = 60;

	/**
	 * Extract signals.
	 *
	 * @param string $html Raw HTML.
	 * @return array{title: string, meta_description: string, h1: string[], headings: array<int, array{level: int, text: string}>, questions: string[], word_count: int, schema_types: string[], noindex: bool}
	 */
	public function extract( string $html ): array {
		$out = array(
			'title'            => '',
			'meta_description' => '',
			'h1'               => array(),
			'headings'         => array(),
			'questions'        => array(),
			'word_count'       => 0,
			'schema_types'     => array(),
			'noindex'          => false,
		);
		if ( '' === trim( $html ) ) {
			return $out;
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new \DOMDocument();
		// Force UTF-8 interpretation; LIBXML_NONET blocks any network access.
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		$xpath = new \DOMXPath( $doc );

		$title        = $xpath->query( '//title' );
		$out['title'] = $title && $title->length ? self::clean( $title->item( 0 )->textContent, 300 ) : '';

		foreach ( $xpath->query( '//meta[@name or @property]' ) ?: array() as $meta ) {
			$name    = strtolower( (string) $meta->getAttribute( 'name' ) );
			$content = (string) $meta->getAttribute( 'content' );
			if ( 'description' === $name && '' === $out['meta_description'] ) {
				$out['meta_description'] = self::clean( $content, 400 );
			}
			if ( 'robots' === $name && str_contains( strtolower( $content ), 'noindex' ) ) {
				$out['noindex'] = true;
			}
		}

		// JSON-LD schema types (names only).
		foreach ( $xpath->query( '//script[@type="application/ld+json"]' ) ?: array() as $script ) {
			$data = json_decode( (string) $script->textContent, true );
			if ( is_array( $data ) ) {
				$out['schema_types'] = array_merge( $out['schema_types'], self::schemaTypes( $data ) );
			}
		}
		$out['schema_types'] = array_slice( array_values( array_unique( $out['schema_types'] ) ), 0, 20 );

		// Drop non-content regions before reading headings and counting words.
		foreach ( array( 'script', 'style', 'noscript', 'nav', 'footer', 'header', 'aside', 'form', 'svg', 'iframe' ) as $tag ) {
			foreach ( iterator_to_array( $doc->getElementsByTagName( $tag ) ) as $node ) {
				$node->parentNode?->removeChild( $node );
			}
		}

		foreach ( $xpath->query( '//h1|//h2|//h3' ) ?: array() as $heading ) {
			$text = self::clean( $heading->textContent, 200 );
			if ( '' === $text ) {
				continue;
			}
			$level = (int) substr( strtolower( $heading->nodeName ), 1 );
			if ( 1 === $level ) {
				$out['h1'][] = $text;
			} elseif ( count( $out['headings'] ) < self::MAX_HEADINGS ) {
				$out['headings'][] = array(
					'level' => $level,
					'text'  => $text,
				);
			}
			if ( str_ends_with( $text, '?' ) ) {
				$out['questions'][] = $text;
			}
		}
		$out['h1']        = array_slice( $out['h1'], 0, 3 );
		$out['questions'] = array_slice( array_values( array_unique( $out['questions'] ) ), 0, 20 );

		$body              = $doc->getElementsByTagName( 'body' )->item( 0 );
		$text              = $body ? (string) $body->textContent : '';
		$out['word_count'] = count( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() );

		return $out;
	}

	/**
	 * Collapse whitespace and cap length.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 */
	private static function clean( string $text, int $max ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
		return mb_substr( sanitize_text_field( $text ), 0, $max );
	}

	/**
	 * Collect @type values recursively.
	 *
	 * @param array<mixed> $data JSON-LD.
	 * @param int          $depth Recursion guard.
	 * @return string[]
	 */
	private static function schemaTypes( array $data, int $depth = 0 ): array {
		if ( $depth > 5 ) {
			return array();
		}
		$types = array();
		foreach ( $data as $key => $value ) {
			if ( '@type' === $key ) {
				foreach ( (array) $value as $type ) {
					if ( is_string( $type ) && preg_match( '/^[A-Za-z]{2,60}$/', $type ) ) {
						$types[] = $type;
					}
				}
			} elseif ( is_array( $value ) ) {
				$types = array_merge( $types, self::schemaTypes( $value, $depth + 1 ) );
			}
		}
		return $types;
	}
}
