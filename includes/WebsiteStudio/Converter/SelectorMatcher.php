<?php
/**
 * CSS selector matching against DOM elements.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Converter;

defined( 'ABSPATH' ) || exit;

/**
 * Supports the selectors real page stylesheets use for layout and typography:
 * type, universal, #id, .class, [attr], [attr=v], [attr~=v], [attr^=v], [attr$=v], [attr*=v],
 * :root, :first-child, :last-child, descendant (" ") and child (">") combinators.
 *
 * Selectors with anything else (state pseudo-classes such as :hover, pseudo-elements,
 * sibling combinators, :not(), :nth-child()) are reported as unsupported and never match:
 * they describe interaction or decoration, not the resting layout being converted.
 */
final class SelectorMatcher {

	/**
	 * Parsed selector cache.
	 *
	 * @var array<string, array{parts: list<array<string, mixed>>, specificity: int}|null>
	 */
	private array $cache = array();

	/**
	 * Parse a selector (null when unsupported).
	 *
	 * @param string $selector Selector.
	 * @return array{parts: list<array<string, mixed>>, specificity: int}|null
	 */
	public function parse( string $selector ): ?array {
		if ( array_key_exists( $selector, $this->cache ) ) {
			return $this->cache[ $selector ];
		}
		$parts       = array();
		$specificity = 0;
		$combinator  = ' ';
		$s           = trim( $selector );
		$pos         = 0;
		$len         = strlen( $s );
		$result      = null;
		while ( $pos < $len ) {
			if ( preg_match( '/\G\s*>\s*/A', $s, $m, 0, $pos ) ) {
				$combinator = '>';
				$pos       += strlen( $m[0] );
				continue;
			}
			if ( preg_match( '/\G\s+/A', $s, $m, 0, $pos ) ) {
				$pos += strlen( $m[0] );
				continue;
			}
			if ( preg_match( '/\G[+~]/A', $s, $m, 0, $pos ) ) {
				return $this->cache[ $selector ] = null;
			}
			$compound = array(
				'combinator' => $combinator,
				'tag'        => '',
				'id'         => '',
				'classes'    => array(),
				'attrs'      => array(),
				'pseudo'     => array(),
			);
			$matched  = false;
			while ( $pos < $len ) {
				if ( preg_match( '/\G(\*|[a-zA-Z][a-zA-Z0-9-]*)/A', $s, $m, 0, $pos ) && ! $matched ) {
					$compound['tag'] = '*' === $m[1] ? '' : strtolower( $m[1] );
					$specificity    += '*' === $m[1] ? 0 : 1;
				} elseif ( preg_match( '/\G#([a-zA-Z0-9_-]+)/A', $s, $m, 0, $pos ) ) {
					$compound['id'] = $m[1];
					$specificity   += 10000;
				} elseif ( preg_match( '/\G\.([a-zA-Z0-9_-]+)/A', $s, $m, 0, $pos ) ) {
					$compound['classes'][] = $m[1];
					$specificity          += 100;
				} elseif ( preg_match( '/\G\[\s*([a-zA-Z_:-][a-zA-Z0-9_:.-]*)\s*(?:([~^$*|]?=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]+))\s*)?\]/A', $s, $m, 0, $pos ) ) {
					$compound['attrs'][] = array(
						'name'  => strtolower( $m[1] ),
						'op'    => $m[2] ?? '',
						'value' => ( $m[3] ?? '' ) . ( $m[4] ?? '' ) . ( $m[5] ?? '' ),
					);
					$specificity        += 100;
				} elseif ( preg_match( '/\G:(root|first-child|last-child)(?![a-z(-])/A', $s, $m, 0, $pos ) ) {
					$compound['pseudo'][] = $m[1];
					$specificity         += 100;
				} else {
					break;
				}
				$pos    += strlen( $m[0] );
				$matched = true;
			}
			if ( ! $matched ) {
				return $this->cache[ $selector ] = null; // Unsupported token (":hover", "::before", ...).
			}
			$parts[]    = $compound;
			$combinator = ' ';
		}
		if ( array() !== $parts ) {
			$result = array(
				'parts'       => $parts,
				'specificity' => $specificity,
			);
		}
		return $this->cache[ $selector ] = $result;
	}

	/**
	 * Whether an element matches a parsed selector.
	 *
	 * @param \DOMElement                                                    $el     Element.
	 * @param array{parts: list<array<string, mixed>>, specificity: int} $parsed Parsed selector.
	 */
	public function matches( \DOMElement $el, array $parsed ): bool {
		return $this->matchFrom( $el, $parsed['parts'], count( $parsed['parts'] ) - 1 );
	}

	/**
	 * Match parts[0..$i] ending at $el.
	 *
	 * @param \DOMElement                    $el    Element.
	 * @param list<array<string, mixed>>     $parts Parts.
	 * @param int                            $i     Index of the part $el must match.
	 */
	private function matchFrom( \DOMElement $el, array $parts, int $i ): bool {
		if ( ! $this->compound( $el, $parts[ $i ] ) ) {
			return false;
		}
		if ( 0 === $i ) {
			return true;
		}
		$parent = $el->parentNode;
		if ( '>' === $parts[ $i ]['combinator'] ) {
			return $parent instanceof \DOMElement && $this->matchFrom( $parent, $parts, $i - 1 );
		}
		while ( $parent instanceof \DOMElement ) {
			if ( $this->matchFrom( $parent, $parts, $i - 1 ) ) {
				return true;
			}
			$parent = $parent->parentNode;
		}
		return false;
	}

	/**
	 * Match one compound selector.
	 *
	 * @param \DOMElement          $el Element.
	 * @param array<string, mixed> $c  Compound.
	 */
	private function compound( \DOMElement $el, array $c ): bool {
		if ( '' !== $c['tag'] && strtolower( $el->tagName ) !== $c['tag'] ) {
			return false;
		}
		if ( '' !== $c['id'] && $el->getAttribute( 'id' ) !== $c['id'] ) {
			return false;
		}
		if ( array() !== $c['classes'] ) {
			$classes = preg_split( '/\s+/', trim( $el->getAttribute( 'class' ) ) );
			foreach ( $c['classes'] as $class ) {
				if ( ! in_array( $class, (array) $classes, true ) ) {
					return false;
				}
			}
		}
		foreach ( $c['attrs'] as $a ) {
			if ( ! $el->hasAttribute( $a['name'] ) ) {
				return false;
			}
			$v = $el->getAttribute( $a['name'] );
			$ok = match ( $a['op'] ) {
				''      => true,
				'='     => $v === $a['value'],
				'~='    => in_array( $a['value'], preg_split( '/\s+/', $v ), true ),
				'^='    => '' !== $a['value'] && str_starts_with( $v, $a['value'] ),
				'$='    => '' !== $a['value'] && str_ends_with( $v, $a['value'] ),
				'*='    => '' !== $a['value'] && str_contains( $v, $a['value'] ),
				'|='    => $v === $a['value'] || str_starts_with( $v, $a['value'] . '-' ),
				default => false,
			};
			if ( ! $ok ) {
				return false;
			}
		}
		foreach ( $c['pseudo'] as $pseudo ) {
			if ( 'root' === $pseudo && 'html' !== strtolower( $el->tagName ) ) {
				return false;
			}
			if ( 'first-child' === $pseudo || 'last-child' === $pseudo ) {
				$sibling = 'first-child' === $pseudo ? $el->previousSibling : $el->nextSibling;
				while ( null !== $sibling && ! $sibling instanceof \DOMElement ) {
					$sibling = 'first-child' === $pseudo ? $sibling->previousSibling : $sibling->nextSibling;
				}
				if ( null !== $sibling ) {
					return false;
				}
			}
		}
		return true;
	}
}
