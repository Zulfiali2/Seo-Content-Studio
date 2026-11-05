<?php
/**
 * Which elements depend on their exact parent or siblings in the project's CSS.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor puts every widget inside wrapper elements. An element that becomes the top of a
 * widget therefore gets a new parent, and its siblings become the wrappers' siblings. Rules such
 * as `.card > h3`, `h2 + p` or `li:first-child` stop matching it, and in a flex or grid parent the
 * wrapper (not the element) becomes the flex or grid item.
 *
 * The index records, from every selector in the project:
 * - "position" entries: compounds on the right of `>`, on either side of `+` / `~`, or with a
 *   structural pseudo-class (`:nth-*`, `:first-*`, `:last-*`, `:only-*`, `:empty`), together with
 *   the ancestors the selector requires (so `.card h3 + p` concerns only paragraphs in a card);
 * - "layout" entries: the elements a rule gives `display: flex | grid` (their children are flex or
 *   grid items).
 * Leading `html`/`body`/`:root` compounds are ignored: they become the page root.
 */
final class SelectorIndex {

	/**
	 * @var array<int, array{self: array<string, mixed>, ancestors: array<int, array<string, mixed>>}>
	 */
	private array $position = array();

	/**
	 * @var array<int, array{self: array<string, mixed>, ancestors: array<int, array<string, mixed>>}>
	 */
	private array $layout = array();

	private int $selectors = 0;

	/**
	 * Add every rule of a stylesheet.
	 *
	 * @param string $css CSS (unscoped).
	 */
	public function addCss( string $css ): void {
		$css = CssScoper::stripComments( $css );
		// The delimiter before a rule is matched by a lookbehind, so it is not consumed and the next rule is not skipped.
		if ( ! preg_match_all( '/(?:(?<=[{};])|^)\s*([^@{};\s][^{};]*)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
			return;
		}
		foreach ( $m as $rule ) {
			$is_layout = (bool) preg_match( '/(?:^|;|\s)display\s*:\s*(?:inline-)?(?:flex|grid)\b/i', $rule[2] );
			foreach ( CssScoper::splitTopLevel( $rule[1], ',' ) as $selector ) {
				$this->add( trim( $selector ), $is_layout );
			}
		}
	}

	/**
	 * Add one selector.
	 *
	 * @param string $selector Selector.
	 * @param bool   $layout   The rule makes its subject a flex or grid container.
	 */
	public function add( string $selector, bool $layout = false ): void {
		if ( '' === $selector || preg_match( '/^(from|to|\d+(\.\d+)?%)$/i', $selector ) ) {
			return;
		}
		++$this->selectors;
		[ $compounds, $combinators ] = self::split( $selector );
		while ( array() !== $compounds && preg_match( '/^(?::root|html|body)(?![\w-])/i', $compounds[0] ) ) {
			array_shift( $compounds );
			array_shift( $combinators );
		}
		$n = count( $compounds );
		for ( $i = 0; $i < $n; $i++ ) {
			$before    = $i > 0 ? ( $combinators[ $i - 1 ] ?? ' ' ) : '';
			$after     = $combinators[ $i ] ?? '';
			$sensitive = in_array( $before, array( '>', '+', '~' ), true ) || in_array( $after, array( '+', '~' ), true )
				|| preg_match( '/:(nth-|first-child|last-child|only-child|first-of-type|last-of-type|only-of-type|empty)/i', $compounds[ $i ] );
			if ( $sensitive ) {
				$this->position[] = array(
					'self'      => self::tokens( $compounds[ $i ] ),
					'ancestors' => self::ancestors( $compounds, $combinators, $i ),
				);
			}
		}
		if ( $layout && $n > 0 ) {
			$this->layout[] = array(
				'self'      => self::tokens( $compounds[ $n - 1 ] ),
				'ancestors' => self::ancestors( $compounds, $combinators, $n - 1 ),
			);
		}
	}

	/**
	 * Whether an element would lose rules when it becomes the top of a widget.
	 *
	 * @param \DOMElement $el Element.
	 */
	public function sensitive( \DOMElement $el ): bool {
		foreach ( $this->position as $entry ) {
			if ( self::matches( $entry, $el ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether CSS makes an element a flex or grid container.
	 *
	 * @param \DOMElement $el Element.
	 */
	public function isLayout( \DOMElement $el ): bool {
		foreach ( $this->layout as $entry ) {
			if ( self::matches( $entry, $el ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Number of selectors read.
	 */
	public function count(): int {
		return $this->selectors;
	}

	/**
	 * Entry match: the element matches the compound and has the required ancestors (in order).
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @param \DOMElement          $el    Element.
	 */
	private static function matches( array $entry, \DOMElement $el ): bool {
		if ( ! self::compoundMatches( $entry['self'], $el ) ) {
			return false;
		}
		$node = $el->parentNode;
		foreach ( array_reverse( $entry['ancestors'] ) as $need ) {
			while ( $node instanceof \DOMElement && ! self::compoundMatches( $need, $node ) ) {
				$node = $node->parentNode;
			}
			if ( ! $node instanceof \DOMElement ) {
				return false;
			}
			$node = $node->parentNode;
		}
		return true;
	}

	/**
	 * Whether an element matches a compound's tag, classes and id (pseudo-classes and attributes
	 * are ignored, so a match is a "may match": the check stays on the safe side).
	 *
	 * @param array<string, mixed> $t  Tokens.
	 * @param \DOMElement          $el Element.
	 */
	private static function compoundMatches( array $t, \DOMElement $el ): bool {
		if ( '' !== $t['tag'] && '*' !== $t['tag'] && strtolower( $el->tagName ) !== $t['tag'] ) {
			return false;
		}
		if ( '' !== $t['id'] && $el->getAttribute( 'id' ) !== $t['id'] ) {
			return false;
		}
		if ( array() !== $t['classes'] ) {
			$have = ProjectParser::classes( $el );
			foreach ( $t['classes'] as $class ) {
				if ( ! in_array( $class, $have, true ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Compounds that must be ancestors of compound $i (joined by descendant or child combinators).
	 *
	 * @param string[] $compounds   Compounds.
	 * @param string[] $combinators Combinators.
	 * @param int      $i           Index.
	 * @return array<int, array<string, mixed>>
	 */
	private static function ancestors( array $compounds, array $combinators, int $i ): array {
		$out = array();
		for ( $j = 0; $j < $i; $j++ ) {
			// A compound followed (anywhere before $i) by a sibling combinator is a sibling, not an ancestor.
			if ( in_array( $combinators[ $j ] ?? ' ', array( '+', '~' ), true ) ) {
				continue;
			}
			$out[] = self::tokens( $compounds[ $j ] );
		}
		return $out;
	}

	/**
	 * Tag, classes and id of a compound (inside :is()/:where()/:not() is ignored).
	 *
	 * @param string $compound Compound.
	 * @return array{tag: string, classes: string[], id: string}
	 */
	private static function tokens( string $compound ): array {
		$plain = (string) preg_replace( '/::?[\w-]+\((?:[^()]|\([^()]*\))*\)|\[[^\]]*\]|::?[\w-]+/', '', $compound );
		preg_match( '/^([a-zA-Z][a-zA-Z0-9-]*|\*)/', $plain, $tag );
		preg_match_all( '/\.([A-Za-z_-][A-Za-z0-9_-]*)/', $plain, $classes );
		preg_match( '/#([A-Za-z_-][A-Za-z0-9_-]*)/', $plain, $id );
		return array(
			'tag'     => strtolower( $tag[1] ?? '' ),
			'classes' => $classes[1],
			'id'      => $id[1] ?? '',
		);
	}

	/**
	 * Split a selector into compounds and the combinators between them.
	 *
	 * @param string $selector Selector.
	 * @return array{0: string[], 1: string[]}
	 */
	public static function split( string $selector ): array {
		$compounds   = array();
		$combinators = array();
		$current     = '';
		$depth       = 0;
		$len         = strlen( $selector );
		$pending     = null;
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $selector[ $i ];
			if ( '\\' === $ch ) {
				$current .= $ch . ( $selector[ $i + 1 ] ?? '' );
				++$i;
				continue;
			}
			if ( '(' === $ch || '[' === $ch ) {
				++$depth;
			} elseif ( ')' === $ch || ']' === $ch ) {
				$depth = max( 0, $depth - 1 );
			}
			if ( 0 === $depth && ( ctype_space( $ch ) || in_array( $ch, array( '>', '+', '~' ), true ) ) ) {
				if ( '' !== $current ) {
					$compounds[] = $current;
					$current     = '';
					$pending     = ' ';
				}
				if ( ! ctype_space( $ch ) ) {
					$pending = $ch;
				}
				continue;
			}
			if ( null !== $pending && array() !== $compounds ) {
				$combinators[] = $pending;
			}
			$pending  = null;
			$current .= $ch;
		}
		if ( '' !== $current ) {
			$compounds[] = $current;
		}
		return array( $compounds, $combinators );
	}
}
