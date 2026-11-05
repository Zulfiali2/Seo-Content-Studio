<?php
/**
 * Scopes a project's stylesheets to one page root.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites imported CSS so it applies only inside the imported page's root element, without
 * changing what the rules mean:
 *
 * - Every selector gets the root id in front (`#aiscs-p-ab12cd34 .card`). The same prefix on every
 *   rule keeps the rules' order and relative specificity, and the id lets them win over theme
 *   and Elementor rules, which use classes.
 * - `:root`, `html` and `body` (alone or at the start of a selector) become the root itself, so
 *   custom properties and body typography land on the page root. Selectors that start with a
 *   class of the original `<html>`/`<body>` get both forms (`#root.dta-home …` and `#root .dta-home …`).
 * - `@media`, `@supports`, `@container`, `@layer` and `@scope` blocks are scoped inside.
 *   `@keyframes` are renamed with a project prefix (and every `animation` that uses them),
 *   so they cannot collide with the theme's. `@font-face` is kept (its `url()`s rewritten).
 *   Nested rules inside a rule body are relative to the scoped parent and are kept as written.
 * - Every `url()` goes through a callback (asset tokens, allowed external URLs or removal).
 *
 * The parser respects strings, comments, escapes and nested parentheses, never evaluates
 * anything and has a size limit. Output is plain text; the caller stores and prints it.
 */
final class CssScoper {

	/** Blocks whose content is a list of rules that must be scoped too. */
	private const GROUPING = array( 'media', 'supports', 'container', 'layer', 'scope', 'document', '-moz-document' );

	/** Statement at-rules that are dropped (they have no effect inside a page or are unsafe). */
	private const DROPPED = array( 'charset', 'namespace' );

	/** Structural depth limit for nested blocks. */
	private const MAX_DEPTH = 12;

	/**
	 * Keyframe names renamed in this run: original => scoped.
	 *
	 * @var array<string, string>
	 */
	private array $keyframes = array();

	/** @var array{rules: int, media: int, keyframes: int, font_faces: int, variables: int, imports: int, dropped: int, supports: int} */
	private array $stats;

	/** @var string[] */
	private array $warnings = array();

	/** Font size set on <html> by the project (for rem units). */
	private string $htmlFontSize = '';

	/**
	 * Constructor.
	 *
	 * @param string   $scope        Root selector, e.g. "#aiscs-p-ab12cd34".
	 * @param string[] $root_classes Classes of the original <html> and <body> (they move to the root).
	 * @param string   $prefix       Keyframe name prefix, e.g. "aiscs-ab12cd34-".
	 */
	public function __construct( private readonly string $scope, private readonly array $root_classes = array(), private readonly string $prefix = 'aiscs-' ) {
		$this->resetStats();
	}

	/**
	 * Scope one stylesheet.
	 *
	 * @param string                       $css     CSS text.
	 * @param callable(string, string): ?string $urls Receives (url, kind: "url"|"import") and returns the
	 *                                              replacement url, CSS text for an inlined import, or null to drop it.
	 * @param callable(string): ?string|null $import Receives an @import url and returns the CSS to inline
	 *                                              (already scoped is not expected: it is scoped here), or null.
	 * @return string Scoped CSS.
	 */
	public function scope( string $css, callable $urls, ?callable $import = null ): string {
		$css = self::stripComments( $css );
		$out = $this->block( $css, $urls, $import, 0 );
		return $this->renameAnimations( $out );
	}

	/**
	 * Statistics of everything scoped by this instance.
	 *
	 * @return array<string, int>
	 */
	public function stats(): array {
		return $this->stats;
	}

	/**
	 * Warnings (dropped rules and the like).
	 *
	 * @return string[]
	 */
	public function warnings(): array {
		return array_values( array_unique( $this->warnings ) );
	}

	/**
	 * Keyframe renames (original => scoped).
	 *
	 * @return array<string, string>
	 */
	public function keyframes(): array {
		return $this->keyframes;
	}

	/**
	 * Baseline rules printed before the project's CSS, so theme and Elementor styles do not leak
	 * into the imported design. The project was designed against the browser's defaults, and
	 * `revert` brings a property back to exactly that (inherited properties inherit again):
	 *
	 * - Elementor's own wrappers (containers, widget wrappers, heading titles) get `all: revert`,
	 *   which removes Elementor's flex layout, gaps, padding and kit typography from them.
	 * - Every other element gets `revert` only for the properties themes commonly style (margins,
	 *   padding, borders, backgrounds, colours, fonts, text and list styles). Width, height, display
	 *   and position are left alone: reverting them would also cancel HTML attributes such as
	 *   <img width> or SVG presentation attributes (fill, stroke, width …).
	 * - SVG content and Elementor's editor overlays are not touched.
	 *
	 * The project's own rules are more specific and come later, so they win. `:where()` keeps the
	 * baseline at the specificity of the root id alone.
	 *
	 * @param string $scope Root selector.
	 */
	public static function baseline( string $scope ): string {
		$skip     = 'svg,svg *,.elementor-element-overlay,.elementor-element-overlay *,[class*="elementor-editor-"],[class*="elementor-editor-"] *,.elementor-add-section,.elementor-add-section *,.elementor-empty-view,.elementor-empty-view *,.elementor-widget-empty,.elementor-widget-empty *';
		$wrappers = '.e-con,.e-con-inner,.elementor-element,.elementor-widget-container,.elementor-heading-title,.elementor-text-editor,.elementor-image,.elementor-widget-html';
		$props    = array( 'margin', 'padding', 'border', 'border-radius', 'outline', 'background', 'box-shadow', 'color', 'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'line-height', 'letter-spacing', 'word-spacing', 'text-transform', 'text-decoration', 'text-align', 'text-indent', 'text-shadow', 'list-style', 'max-width', 'min-width', 'float', 'clear', 'vertical-align', 'transition', 'opacity', 'filter', 'quotes', 'cursor' );
		$reverted = implode( ':revert;', $props ) . ':revert';
		// font-size: medium is the browser default the project's <html> and <body> started from.
		return $scope . '{all:revert;display:block;position:relative;width:100%;max-width:none;margin:0;padding:0;box-sizing:content-box;min-height:0;font-size:medium}'
			. $scope . ' :where(' . $wrappers . '):where(:not(' . $skip . ')){all:revert}'
			. $scope . ' :where(*:not(' . $skip . ')){' . $reverted . '}'
			. $scope . ' :where(*:not(' . $skip . '))::before,' . $scope . ' :where(*:not(' . $skip . '))::after{content:revert;' . $reverted . '}';
	}

	/**
	 * Remove comments (outside strings).
	 *
	 * @param string $css CSS.
	 */
	public static function stripComments( string $css ): string {
		$out = '';
		$len = strlen( $css );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $css[ $i ];
			if ( '"' === $c || "'" === $c ) {
				$end  = self::stringEnd( $css, $i );
				$out .= substr( $css, $i, $end - $i + 1 );
				$i    = $end;
				continue;
			}
			if ( '/' === $c && '*' === ( $css[ $i + 1 ] ?? '' ) ) {
				$close = strpos( $css, '*/', $i + 2 );
				$i     = false === $close ? $len : $close + 1;
				$out  .= ' ';
				continue;
			}
			$out .= $c;
		}
		return $out;
	}

	/**
	 * Count rules, media queries, keyframes, font faces and custom properties without scoping.
	 *
	 * @param string $css CSS.
	 * @return array<string, int>
	 */
	public static function measure( string $css ): array {
		$scoper = new self( '#x' );
		$scoper->scope( $css, static fn ( string $u ): string => $u );
		return $scoper->stats();
	}

	/* ------------------------------------------------------------ Parsing */

	/**
	 * Process a list of rules.
	 *
	 * @param string        $css    CSS.
	 * @param callable      $urls   URL callback.
	 * @param callable|null $import Import callback.
	 * @param int           $depth  Depth.
	 */
	private function block( string $css, callable $urls, ?callable $import, int $depth ): string {
		$out = '';
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			// Skip whitespace and stray semicolons or closing braces.
			if ( ctype_space( $css[ $i ] ) || ';' === $css[ $i ] || '}' === $css[ $i ] ) {
				++$i;
				continue;
			}
			// Prelude: up to "{" or ";" at nesting level 0.
			$stop = self::preludeEnd( $css, $i );
			if ( false === $stop ) {
				// Unterminated tail: drop it (it has no effect in a browser either).
				++$this->stats['dropped'];
				break;
			}
			$prelude = trim( substr( $css, $i, $stop - $i ) );
			if ( ';' === $css[ $stop ] ) {
				$out .= $this->statement( $prelude, $urls, $import, $depth );
				$i    = $stop + 1;
				continue;
			}
			$close = self::matchingBrace( $css, $stop );
			$body  = substr( $css, $stop + 1, ( false === $close ? $len : $close ) - $stop - 1 );
			$i     = false === $close ? $len : $close + 1;
			if ( '' === $prelude ) {
				continue;
			}
			$out .= str_starts_with( $prelude, '@' )
				? $this->atBlock( $prelude, $body, $urls, $import, $depth )
				: $this->rule( $prelude, $body, $urls );
		}
		return $out;
	}

	/**
	 * A statement at-rule such as `@import url(x.css);`.
	 *
	 * @param string        $prelude Prelude.
	 * @param callable      $urls    URL callback.
	 * @param callable|null $import  Import callback.
	 * @param int           $depth   Depth.
	 */
	private function statement( string $prelude, callable $urls, ?callable $import, int $depth ): string {
		if ( ! preg_match( '/^@([a-z-]+)\s*(.*)$/is', $prelude, $m ) ) {
			++$this->stats['dropped'];
			return '';
		}
		$name = strtolower( $m[1] );
		if ( in_array( $name, self::DROPPED, true ) ) {
			return '';
		}
		if ( 'import' === $name ) {
			++$this->stats['imports'];
			$target = '';
			if ( preg_match( '/^(?:url\(\s*([\'"]?)(.*?)\1\s*\)|([\'"])(.*?)\3)\s*(.*)$/is', trim( $m[2] ), $im ) ) {
				$target = '' !== $im[2] ? $im[2] : $im[4];
				$media  = trim( $im[5] );
			}
			if ( '' === $target ) {
				++$this->stats['dropped'];
				return '';
			}
			$inline = null !== $import && $depth < 4 ? $import( $target ) : null;
			if ( is_string( $inline ) ) {
				$scoped = $this->block( self::stripComments( $inline ), $urls, $import, $depth + 1 );
				return '' !== ( $media ?? '' ) ? '@media ' . $media . '{' . $scoped . '}' : $scoped;
			}
			// Not inlined: an allowed external stylesheet is enqueued by the caller, others are dropped.
			$urls( $target, 'import' );
			return '';
		}
		// Other statements (for example `@layer a, b;`) are kept as written.
		return $prelude . ';';
	}

	/**
	 * A block at-rule.
	 *
	 * @param string        $prelude Prelude (with the @).
	 * @param string        $body    Body.
	 * @param callable      $urls    URL callback.
	 * @param callable|null $import  Import callback.
	 * @param int           $depth   Depth.
	 */
	private function atBlock( string $prelude, string $body, callable $urls, ?callable $import, int $depth ): string {
		preg_match( '/^@([a-z-]+)\s*(.*)$/is', $prelude, $m );
		$name = strtolower( $m[1] ?? '' );
		$rest = trim( $m[2] ?? '' );

		if ( in_array( $name, self::GROUPING, true ) ) {
			if ( $depth >= self::MAX_DEPTH ) {
				++$this->stats['dropped'];
				return '';
			}
			if ( 'media' === $name ) {
				++$this->stats['media'];
			} elseif ( 'supports' === $name ) {
				++$this->stats['supports'];
			}
			// @scope (.a) to (.b): the scope start is a selector and must be scoped too.
			if ( 'scope' === $name && preg_match( '/^\((.*?)\)(.*)$/s', $rest, $sm ) ) {
				$rest = '(' . $this->selectors( $sm[1] ) . ')' . $sm[2];
			}
			return '@' . $name . ( '' !== $rest ? ' ' . $rest : '' ) . '{' . $this->block( $body, $urls, $import, $depth + 1 ) . '}';
		}
		if ( preg_match( '/^(-webkit-|-moz-|-o-)?keyframes$/', $name ) ) {
			++$this->stats['keyframes'];
			$ident = trim( $rest, " \t\n\r\"'" );
			if ( '' === $ident || ! preg_match( '/^[A-Za-z_-][A-Za-z0-9_-]*$/', $ident ) ) {
				++$this->stats['dropped'];
				return '';
			}
			$scoped                    = $this->prefix . $ident;
			$this->keyframes[ $ident ] = $scoped;
			return '@' . $name . ' ' . $scoped . '{' . $this->urls( $body, $urls ) . '}';
		}
		if ( 'font-face' === $name ) {
			++$this->stats['font_faces'];
			return '@font-face{' . $this->urls( $body, $urls ) . '}';
		}
		if ( in_array( $name, array( 'page', 'property', 'counter-style', 'font-feature-values', 'font-palette-values', 'position-try', 'view-transition' ), true ) ) {
			return $prelude . '{' . $this->urls( $body, $urls ) . '}';
		}
		++$this->stats['dropped'];
		$this->warnings[] = sprintf( 'The @%s rule is not supported and was left out.', $name );
		return '';
	}

	/**
	 * A style rule.
	 *
	 * @param string   $prelude Selector list.
	 * @param string   $body    Declarations (and possibly nested rules).
	 * @param callable $urls    URL callback.
	 */
	private function rule( string $prelude, string $body, callable $urls ): string {
		++$this->stats['rules'];
		if ( '' !== $this->scope && preg_match( '/^\s*(html|:root)\s*$/i', $prelude ) ) {
			$body = $this->rootFontSize( $body );
		}
		$this->stats['variables'] += preg_match_all( '/(?:^|[;{\s])--[A-Za-z0-9_-]+\s*:/', $body );
		$selectors = $this->selectors( $prelude );
		if ( '' === $selectors ) {
			++$this->stats['dropped'];
			return '';
		}
		return $selectors . '{' . $this->urls( $body, $urls ) . '}';
	}

	/* ------------------------------------------------------------ Selectors */

	/**
	 * Scope a selector list.
	 *
	 * @param string $list Selector list.
	 */
	public function selectors( string $list ): string {
		$out = array();
		foreach ( self::splitTopLevel( $list, ',' ) as $selector ) {
			$selector = trim( $selector );
			if ( '' === $selector ) {
				continue;
			}
			// Keyframe-like percentages or from/to never reach here; anything with a brace is invalid.
			if ( str_contains( $selector, '{' ) || str_contains( $selector, '}' ) ) {
				continue;
			}
			foreach ( $this->scopeOne( $selector ) as $scoped ) {
				$out[] = $scoped;
			}
		}
		return implode( ',', array_unique( $out ) );
	}

	/**
	 * Scope one complex selector (may return two forms).
	 *
	 * @param string $selector Selector.
	 * @return string[]
	 */
	private function scopeOne( string $selector ): array {
		// Already scoped (for example an inlined import of an already scoped file).
		if ( str_starts_with( $selector, $this->scope ) ) {
			return array( $selector );
		}
		if ( '*' === $selector ) {
			return array( $this->scope, $this->scope . ' *' );
		}

		// Leading :root / html / body compounds (for example "html body.home > main") become the root.
		// Their classes and ids stay on the root; attributes and pseudo-classes of <html>/<body>
		// (such as [lang] or :hover) cannot match the root, so they are dropped.
		$rest  = $selector;
		$extra = '';
		$hit   = false;
		while ( '' !== $rest && preg_match( '/^(?::root|html|body)(?![\w-])((?:\.[\w-]+|#[\w-]+|\[[^\]]*\]|::?[\w-]+(?:\([^)]*\))?)*)\s*(?:>\s*)?/i', $rest, $m ) ) {
			$hit = true;
			preg_match_all( '/[.#][\w-]+/', $m[1], $quals );
			$extra .= implode( '', $quals[0] );
			$rest   = (string) substr( $rest, strlen( $m[0] ) );
		}
		if ( $hit ) {
			$rest = ltrim( $rest );
			return array( $this->scope . $extra . ( '' !== $rest ? ' ' . $rest : '' ) );
		}

		// A leading class of the original <html>/<body>: that element is now the root.
		if ( array() !== $this->root_classes && preg_match( '/^((?:\.[A-Za-z0-9_-]+)+)(.*)$/s', $selector, $m ) ) {
			$classes = array_filter( explode( '.', $m[1] ) );
			if ( array() === array_diff( $classes, $this->root_classes ) ) {
				$tail = $m[2];
				return array( $this->scope . $m[1] . $tail, $this->scope . ' ' . $selector );
			}
		}
		return array( $this->scope . ' ' . $selector );
	}

	/**
	 * In a rule for <html>, a relative font size (100%, 1.125em) was relative to the browser
	 * default. On the page root it would be relative to the theme's body size, so it becomes
	 * pixels (16px default). The original value is kept for the "project root font size" option.
	 *
	 * @param string $body Declarations.
	 */
	private function rootFontSize( string $body ): string {
		return (string) preg_replace_callback(
			'/(^|[;{\s])font-size\s*:\s*([0-9.]+)(%|em|rem)\s*(!important)?/i',
			function ( array $m ): string {
				$this->htmlFontSize = $m[2] . $m[3];
				$px                 = '%' === $m[3] ? (float) $m[2] * 0.16 : (float) $m[2] * 16;
				return $m[1] . 'font-size:' . round( $px, 3 ) . 'px' . ( isset( $m[4] ) ? ' ' . $m[4] : '' );
			},
			$body
		);
	}

	/**
	 * The font size the project sets on <html> ("" when none): rem units depend on it.
	 */
	public function htmlFontSize(): string {
		return $this->htmlFontSize;
	}

	/* ------------------------------------------------------------ url() and animations */

	/**
	 * Rewrite url() references in declarations.
	 *
	 * @param string   $body Declarations.
	 * @param callable $urls URL callback.
	 */
	private function urls( string $body, callable $urls ): string {
		return (string) preg_replace_callback(
			'/url\(\s*(?:"((?:[^"\\\\]|\\\\.)*)"|\'((?:[^\'\\\\]|\\\\.)*)\'|([^)\s]*))\s*\)/i',
			static function ( array $m ) use ( $urls ): string {
				$raw = '' !== ( $m[1] ?? '' ) ? $m[1] : ( '' !== ( $m[2] ?? '' ) ? $m[2] : ( $m[3] ?? '' ) );
				$new = $urls( stripcslashes( $raw ), 'url' );
				if ( null === $new || '' === $new ) {
					return 'none';
				}
				return 'url("' . str_replace( array( '\\', '"', "\n", "\r" ), array( '\\\\', '\\"', '', '' ), $new ) . '")';
			},
			$body
		);
	}

	/**
	 * Point animation and animation-name declarations at the renamed keyframes.
	 *
	 * @param string $css Scoped CSS.
	 */
	private function renameAnimations( string $css ): string {
		if ( array() === $this->keyframes ) {
			return $css;
		}
		$names = $this->keyframes;
		return (string) preg_replace_callback(
			'/((?:-webkit-|-moz-)?animation(?:-name)?\s*:)([^;{}]+)/i',
			static function ( array $m ) use ( $names ): string {
				$value = (string) preg_replace_callback(
					'/(?<![\w-])([A-Za-z_-][A-Za-z0-9_-]*)(?![\w-])/',
					static fn ( array $w ): string => $names[ $w[1] ] ?? $w[1],
					$m[2]
				);
				return $m[1] . $value;
			},
			$css
		);
	}

	/* ------------------------------------------------------------ Low-level helpers */

	/**
	 * Split on a delimiter at nesting level 0 (outside strings, brackets and parentheses).
	 *
	 * @param string $text      Text.
	 * @param string $delimiter One character.
	 * @return string[]
	 */
	public static function splitTopLevel( string $text, string $delimiter ): array {
		$parts = array();
		$depth = 0;
		$start = 0;
		$len   = strlen( $text );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $text[ $i ];
			if ( '\\' === $c ) {
				++$i;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$i = self::stringEnd( $text, $i );
				continue;
			}
			if ( '(' === $c || '[' === $c ) {
				++$depth;
			} elseif ( ')' === $c || ']' === $c ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( $c === $delimiter && 0 === $depth ) {
				$parts[] = substr( $text, $start, $i - $start );
				$start   = $i + 1;
			}
		}
		$parts[] = substr( $text, $start );
		return $parts;
	}

	/**
	 * Position of the "{" or ";" that ends a prelude, or false.
	 *
	 * @param string $css CSS.
	 * @param int    $from Start.
	 * @return int|false
	 */
	private static function preludeEnd( string $css, int $from ): int|false {
		$len   = strlen( $css );
		$paren = 0;
		for ( $i = $from; $i < $len; $i++ ) {
			$c = $css[ $i ];
			if ( '\\' === $c ) {
				++$i;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$i = self::stringEnd( $css, $i );
				continue;
			}
			if ( '(' === $c ) {
				++$paren;
			} elseif ( ')' === $c ) {
				$paren = max( 0, $paren - 1 );
			} elseif ( 0 === $paren && ( '{' === $c || ';' === $c ) ) {
				return $i;
			} elseif ( 0 === $paren && '}' === $c ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * Position of the "}" matching the "{" at $open, or false.
	 *
	 * @param string $css  CSS.
	 * @param int    $open Position of "{".
	 * @return int|false
	 */
	private static function matchingBrace( string $css, int $open ): int|false {
		$len   = strlen( $css );
		$depth = 0;
		for ( $i = $open; $i < $len; $i++ ) {
			$c = $css[ $i ];
			if ( '\\' === $c ) {
				++$i;
				continue;
			}
			if ( '"' === $c || "'" === $c ) {
				$i = self::stringEnd( $css, $i );
				continue;
			}
			if ( '{' === $c ) {
				++$depth;
			} elseif ( '}' === $c && 0 === --$depth ) {
				return $i;
			}
		}
		return false;
	}

	/**
	 * Position of the closing quote of the string starting at $start (or the end of the text).
	 *
	 * @param string $text  Text.
	 * @param int    $start Position of the opening quote.
	 */
	private static function stringEnd( string $text, int $start ): int {
		$quote = $text[ $start ];
		$len   = strlen( $text );
		for ( $i = $start + 1; $i < $len; $i++ ) {
			if ( '\\' === $text[ $i ] ) {
				++$i;
				continue;
			}
			if ( $text[ $i ] === $quote || "\n" === $text[ $i ] ) {
				return $i;
			}
		}
		return $len - 1;
	}

	/**
	 * Reset statistics.
	 */
	private function resetStats(): void {
		$this->stats = array(
			'rules'      => 0,
			'media'      => 0,
			'keyframes'  => 0,
			'font_faces' => 0,
			'variables'  => 0,
			'imports'    => 0,
			'dropped'    => 0,
			'supports'   => 0,
		);
	}
}
