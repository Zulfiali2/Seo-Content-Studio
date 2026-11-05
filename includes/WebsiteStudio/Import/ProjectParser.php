<?php
/**
 * Parses and sanitises a project's entry page.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the entry HTML (DOMDocument, no network, no entities), records its stylesheets, styles
 * and scripts in document order, and sanitises the body in place:
 *
 * - <script> elements are removed (their code is collected for analysis and optional, approved
 *   front-end use); <noscript>, <template>, <base>, <meta>, <link>, <object>, <embed>, <applet>,
 *   frames and <portal> are removed; <style> blocks in the body are collected like head styles;
 * - <iframe> is kept only for YouTube, Vimeo and Google Maps embeds over https;
 * - inline <svg> goes through SvgSanitizer (its <style> rules join the page CSS);
 * - every on* attribute, srcdoc and formaction is removed; style attributes that can run code
 *   are removed; javascript:/vbscript: and unknown schemes in any URL attribute are removed;
 * - references to project files become asset tokens; links to other pages of the project
 *   become "#" and are reported (WordPress has no page for them yet).
 *
 * Nothing is ever executed or fetched.
 */
final class ProjectParser {

	/** Removed whole, with their content. */
	private const REMOVED = array( 'script', 'noscript', 'template', 'base', 'meta', 'link', 'title', 'object', 'embed', 'applet', 'frame', 'frameset', 'portal', 'param' );

	/** Embeds that may stay as iframes. */
	private const IFRAME_HOSTS = '#^https://(www\.)?(youtube\.com/embed/|youtube-nocookie\.com/embed/|player\.vimeo\.com/video/|google\.com/maps/embed|maps\.google\.com/maps)#i';

	/** URL attributes (srcset handled separately). */
	private const URL_ATTRS = array( 'href', 'src', 'poster', 'action', 'cite', 'background', 'longdesc', 'data', 'xlink:href' );

	public \DOMDocument $doc;

	public \DOMElement $body;

	/** @var string[] Classes of <html> and <body> (they move to the page root). */
	public array $rootClasses = array();

	/** @var string[] */
	public array $htmlClasses = array();

	/** @var string[] */
	public array $bodyClasses = array();

	public string $title = '';

	public string $lang = '';

	/**
	 * CSS sources in cascade order: {type: file|inline, path, from}.
	 *
	 * @var array<int, array{type: string, path: string, css?: string}>
	 */
	public array $styles = array();

	/**
	 * Scripts in document order: {type: file|inline|external|data, path, code?, url?, module}.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $scripts = array();

	/** @var array<string, int> What was removed, counted by kind. */
	public array $removed = array();

	/** @var string[] */
	public array $notes = array();

	/** SVG <style> blocks found inline (part of the page CSS). */
	public string $svgCss = '';

	/**
	 * Parse.
	 *
	 * @param ProjectArchive     $archive Archive.
	 * @param string             $entry   Entry HTML path.
	 * @param AssetRefs          $refs    Reference policy.
	 * @param array<string, int> $limits  Limits.
	 * @throws ImportException When the entry cannot be read or parsed.
	 */
	public function __construct( private readonly ProjectArchive $archive, public readonly string $entry, private readonly AssetRefs $refs, private readonly array $limits ) {
		$html = $archive->read( $entry, $limits['html_bytes'] );
		$html = self::toUtf8( $html );

		$this->doc = new \DOMDocument();
		$previous  = libxml_use_internal_errors( true );
		$wrapped   = preg_match( '/<html[\s>]/i', $html ) ? $html : '<!DOCTYPE html><html><head></head><body>' . $html . '</body></html>';
		$this->doc->loadHTML( '<?xml encoding="UTF-8">' . $wrapped, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT | LIBXML_PARSEHUGE );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( $this->doc->getElementsByTagName( '*' )->length > $limits['nodes'] ) {
			/* translators: %d: element limit. */
			throw new ImportException( 'html_too_large', sprintf( __( 'The page has more than %d HTML elements.', 'ai-seo-content-studio' ), $limits['nodes'] ) );
		}
		$body = $this->doc->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body instanceof \DOMElement ) {
			throw new ImportException( 'html_no_body', sprintf( __( '%s has no page body.', 'ai-seo-content-studio' ), $entry ) );
		}
		$this->body = $body;

		$html_el           = $this->doc->documentElement;
		$this->lang        = $html_el instanceof \DOMElement ? substr( (string) preg_replace( '/[^A-Za-z0-9-]/', '', $html_el->getAttribute( 'lang' ) ), 0, 20 ) : '';
		$this->htmlClasses = self::classes( $html_el instanceof \DOMElement ? $html_el : null );
		$this->bodyClasses = self::classes( $body );
		$this->rootClasses = array_values( array_unique( array_merge( $this->htmlClasses, $this->bodyClasses ) ) );
		$title             = $this->doc->getElementsByTagName( 'title' )->item( 0 );
		$this->title       = $title ? trim( preg_replace( '/\s+/', ' ', (string) $title->textContent ) ) : '';

		$this->collect( $this->doc->documentElement );
		$this->sanitize( $body );
		$this->stripComments( $body );
	}

	/* ------------------------------------------------------------ Collection */

	/**
	 * Collect stylesheets, styles and scripts in document order (head and body).
	 *
	 * @param \DOMNode|null $root Root.
	 */
	private function collect( ?\DOMNode $root ): void {
		if ( null === $root ) {
			return;
		}
		$xpath = new \DOMXPath( $this->doc );
		foreach ( $xpath->query( '//link | //style | //script' ) as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$tag = strtolower( $node->tagName );
			if ( 'link' === $tag ) {
				$rel = ' ' . strtolower( (string) $node->getAttribute( 'rel' ) ) . ' ';
				if ( ! str_contains( $rel, ' stylesheet ' ) ) {
					continue;
				}
				$mapped = $this->refs->map( $this->entry, $node->getAttribute( 'href' ), 'stylesheet' );
				if ( null === $mapped ) {
					$this->count( 'stylesheet_not_used' );
					continue;
				}
				$media = trim( (string) $node->getAttribute( 'media' ) );
				if ( str_starts_with( $mapped, 'https://' ) ) {
					$this->styles[] = array(
						'type' => 'external',
						'path' => $mapped,
					);
					continue;
				}
				$this->styles[] = array(
					'type'  => 'file',
					'path'  => $mapped,
					'media' => '' !== $media && 'all' !== strtolower( $media ) ? $media : '',
				);
			} elseif ( 'style' === $tag ) {
				$this->styles[] = array(
					'type' => 'inline',
					'path' => $this->entry,
					'css'  => (string) $node->textContent,
				);
			} else {
				$type = strtolower( trim( (string) $node->getAttribute( 'type' ) ) );
				if ( in_array( $type, array( 'application/ld+json', 'application/json', 'text/template', 'text/x-template', 'importmap' ), true ) ) {
					$this->scripts[] = array(
						'type' => 'data',
						'path' => $this->entry,
						'kind' => $type,
					);
					continue;
				}
				$src = trim( (string) $node->getAttribute( 'src' ) );
				$module = 'module' === $type;
				if ( '' === $src ) {
					$this->scripts[] = array(
						'type'   => 'inline',
						'path'   => $this->entry,
						'code'   => (string) $node->textContent,
						'module' => $module,
						'defer'  => false,
					);
					continue;
				}
				if ( preg_match( '#^(https?:)?//#i', $src ) ) {
					$this->scripts[] = array(
						'type'   => 'external',
						'url'    => mb_substr( $src, 0, 300 ),
						'module' => $module,
					);
					continue;
				}
				$path = $this->archive->find( ProjectArchive::resolve( $this->entry, (string) strtok( $src, '?#' ) ) );
				$this->scripts[] = null === $path
					? array(
						'type'    => 'missing',
						'ref'     => mb_substr( $src, 0, 300 ),
						'module'  => $module,
					)
					: array(
						'type'   => 'file',
						'path'   => $path,
						'module' => $module,
						'defer'  => $node->hasAttribute( 'defer' ) || $module,
					);
			}
		}
	}

	/* ------------------------------------------------------------ Sanitising */

	/**
	 * Sanitise a subtree in place.
	 *
	 * @param \DOMElement $el Element.
	 */
	private function sanitize( \DOMElement $el ): void {
		foreach ( iterator_to_array( $el->childNodes ) as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				continue;
			}
			$tag = strtolower( $child->tagName );
			if ( 'style' === $tag ) {
				$el->removeChild( $child ); // Already collected.
				continue;
			}
			if ( in_array( $tag, self::REMOVED, true ) ) {
				$this->count( $tag );
				$el->removeChild( $child );
				continue;
			}
			if ( 'iframe' === $tag ) {
				$src = trim( (string) $child->getAttribute( 'src' ) );
				if ( ! preg_match( self::IFRAME_HOSTS, $src ) ) {
					$this->count( 'iframe' );
					$this->notes[] = sprintf( __( 'An embedded frame (%s) was removed: only YouTube, Vimeo and Google Maps embeds are kept.', 'ai-seo-content-studio' ), mb_substr( '' !== $src ? $src : 'srcdoc', 0, 120 ) );
					$el->removeChild( $child );
					continue;
				}
				foreach ( iterator_to_array( $child->attributes ) as $attr ) {
					if ( ! in_array( strtolower( $attr->nodeName ), array( 'src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'loading', 'referrerpolicy', 'class', 'id', 'style', 'frameborder' ), true ) ) {
						$child->removeAttributeNode( $attr );
					}
				}
				$this->cleanAttributes( $child );
				continue;
			}
			if ( 'svg' === $tag ) {
				foreach ( iterator_to_array( $child->getElementsByTagName( 'style' ) ) as $style ) {
					$this->svgCss .= "\n" . $style->textContent;
					$style->parentNode->removeChild( $style );
				}
				$svg = new SvgSanitizer();
				$svg->element( $child, fn ( string $ref ): ?string => $this->refs->map( $this->entry, $ref ) );
				foreach ( $svg->removed as $what ) {
					$this->count( 'svg: ' . $what );
				}
				continue;
			}
			$this->cleanAttributes( $child );
			$this->sanitize( $child );
		}
	}

	/**
	 * Clean the attributes of one HTML element.
	 *
	 * @param \DOMElement $el Element.
	 */
	private function cleanAttributes( \DOMElement $el ): void {
		$tag = strtolower( $el->tagName );
		foreach ( iterator_to_array( $el->attributes ) as $attr ) {
			$name  = strtolower( $attr->nodeName );
			$value = (string) $attr->nodeValue;
			if ( str_starts_with( $name, 'on' ) || in_array( $name, array( 'srcdoc', 'formaction', 'ping' ), true ) ) {
				$el->removeAttributeNode( $attr );
				$this->count( 'event or unsafe attribute' );
				continue;
			}
			if ( 'style' === $name ) {
				if ( SvgSanitizer::unsafeStyle( $value ) ) {
					$el->removeAttributeNode( $attr );
					$this->count( 'unsafe style attribute' );
					continue;
				}
				$el->setAttribute( $attr->nodeName, $this->styleUrls( $value ) );
				continue;
			}
			if ( in_array( $name, array( 'srcset', 'imagesrcset' ), true ) ) {
				$el->setAttribute( $attr->nodeName, $this->srcset( $value ) );
				continue;
			}
			if ( in_array( $name, self::URL_ATTRS, true ) ) {
				$context = ( 'href' === $name && in_array( $tag, array( 'a', 'area' ), true ) ) || 'action' === $name || 'cite' === $name ? 'link' : 'asset';
				$mapped  = 'action' === $name && '' === trim( $value ) ? '' : $this->refs->map( $this->entry, $value, $context );
				if ( null === $mapped ) {
					if ( 'link' === $context ) {
						$attr->nodeValue = '#';
						$this->count( 'link not kept' );
					} else {
						$el->removeAttributeNode( $attr );
						$this->count( 'missing or unsafe file reference' );
					}
					continue;
				}
				$el->setAttribute( $attr->nodeName, $mapped );
			}
		}
		if ( 'a' === $tag && '_blank' === strtolower( $el->getAttribute( 'target' ) ) ) {
			$rel = trim( $el->getAttribute( 'rel' ) . ' noopener' );
			$el->setAttribute( 'rel', $rel );
		}
	}

	/**
	 * Rewrite url() in an inline style.
	 *
	 * @param string $style Style attribute.
	 */
	private function styleUrls( string $style ): string {
		return (string) preg_replace_callback(
			'/url\(\s*([\'"]?)(.*?)\1\s*\)/i',
			function ( array $m ): string {
				$mapped = $this->refs->map( $this->entry, $m[2] );
				return null === $mapped ? 'none' : 'url(\'' . str_replace( '\'', '%27', $mapped ) . '\')';
			},
			$style
		);
	}

	/**
	 * Rewrite a srcset list.
	 *
	 * @param string $value srcset.
	 */
	private function srcset( string $value ): string {
		$out = array();
		foreach ( preg_split( '/,(?=\s*\S)(?![^(]*\))/', $value ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			$parts  = preg_split( '/\s+/', $candidate, 2 );
			$mapped = $this->refs->map( $this->entry, $parts[0] );
			if ( null !== $mapped ) {
				$out[] = $mapped . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' );
			}
		}
		return implode( ', ', $out );
	}

	/**
	 * Remove HTML comments (conditional comments can hide markup).
	 *
	 * @param \DOMNode $node Node.
	 */
	private function stripComments( \DOMNode $node ): void {
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( $child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction ) {
				$node->removeChild( $child );
			} elseif ( $child->hasChildNodes() ) {
				$this->stripComments( $child );
			}
		}
	}

	/* ------------------------------------------------------------ CSS */

	/**
	 * The page's CSS in cascade order, scoped (or only URL-rewritten when $scoper is null-scoped).
	 *
	 * @param CssScoper $scoper Scoper.
	 * @param string[]  $notes  Collects notes (missing files, refused imports).
	 */
	public function css( CssScoper $scoper, array &$notes ): string {
		$out    = '';
		$budget = $this->limits['css_bytes'];
		$seen   = array();
		foreach ( $this->styles as $source ) {
			if ( 'external' === $source['type'] ) {
				continue;
			}
			if ( 'inline' === $source['type'] ) {
				$css  = (string) $source['css'];
				$from = $this->entry;
			} else {
				if ( isset( $seen[ $source['path'] ] ) ) {
					continue;
				}
				$seen[ $source['path'] ] = true;
				try {
					$css = self::toUtf8( $this->archive->read( $source['path'], $budget ) );
				} catch ( ImportException $e ) {
					$notes[] = $e->getMessage();
					continue;
				}
				$from = $source['path'];
			}
			$budget -= strlen( $css );
			if ( $budget < 0 ) {
				$notes[] = __( 'The project has more CSS than the import limit; the remaining stylesheets were left out.', 'ai-seo-content-studio' );
				break;
			}
			$scoped = $scoper->scope(
				$css,
				function ( string $url, string $kind ) use ( $from, &$notes ): ?string {
					if ( 'import' === $kind ) {
						$mapped = $this->refs->map( $from, $url, 'stylesheet' );
						if ( null === $mapped ) {
							$notes[] = sprintf( __( 'The stylesheet import "%s" was not used.', 'ai-seo-content-studio' ), mb_substr( $url, 0, 120 ) );
						}
						return $mapped;
					}
					return $this->refs->map( $from, $url );
				},
				function ( string $url ) use ( $from, &$seen ): ?string {
					$path = $this->refs->map( $from, $url, 'stylesheet' );
					if ( null === $path || str_starts_with( $path, 'https://' ) || isset( $seen[ $path ] ) ) {
						return null;
					}
					$seen[ $path ] = true;
					try {
						return self::toUtf8( $this->archive->read( $path, $this->limits['css_bytes'] ) );
					} catch ( ImportException ) {
						return null;
					}
				}
			);
			$out .= ( '' !== ( $source['media'] ?? '' ) ? '@media ' . $source['media'] . '{' . $scoped . '}' : $scoped ) . "\n";
		}
		if ( '' !== trim( $this->svgCss ) ) {
			$out .= $scoper->scope( $this->svgCss, fn ( string $url ): ?string => $this->refs->map( $this->entry, $url ) ) . "\n";
		}
		return $out;
	}

	/**
	 * External stylesheets that may be loaded (web fonts and public CDNs), in order.
	 *
	 * @return string[]
	 */
	public function externalStylesheets(): array {
		$out = array();
		foreach ( $this->styles as $source ) {
			if ( 'external' === $source['type'] ) {
				$out[] = $source['path'];
			}
		}
		return array_values( array_unique( array_merge( $out, $this->refs->stylesheets() ) ) );
	}

	/* ------------------------------------------------------------ Helpers */

	/**
	 * Count a removed thing.
	 *
	 * @param string $what Kind.
	 */
	private function count( string $what ): void {
		$this->removed[ $what ] = ( $this->removed[ $what ] ?? 0 ) + 1;
	}

	/**
	 * Classes of an element.
	 *
	 * @param \DOMElement|null $el Element.
	 * @return string[]
	 */
	public static function classes( ?\DOMElement $el ): array {
		if ( null === $el ) {
			return array();
		}
		return array_values( array_filter( preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ) ), static fn ( string $c ): bool => (bool) preg_match( '/^[A-Za-z_-][A-Za-z0-9_-]*$/', $c ) ) );
	}

	/**
	 * Text as UTF-8 (BOM removed; Windows-1252 converted).
	 *
	 * @param string $text Text.
	 */
	public static function toUtf8( string $text ): string {
		if ( str_starts_with( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
		}
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = (string) mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		return $text;
	}
}
