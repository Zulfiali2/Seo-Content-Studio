<?php
/**
 * Replaceable text in an Elementor page, and its replacement.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Cloner;

use AiSeoContentStudio\WebsiteStudio\Analysis\ElementorDocumentAnalyzer;
use AiSeoContentStudio\WebsiteStudio\Engine\HtmlFragment;

defined( 'ABSPATH' ) || exit;

/**
 * Finds every text field of a page ("slot") and decides whether it is content to rewrite or
 * something to keep. Kept by default, with the reason shown to the user:
 *
 * - site_chrome: text in header or footer sections (navigation labels, footer links)
 * - brand:       short text that is the business name
 * - legal:       copyright, rights reserved, privacy, terms, cookies, disclaimers
 * - contact:     text with an e-mail address or phone number (never invented)
 * - testimonial: testimonial and review widgets (real customers' words are never invented)
 * - statistic:   counters, progress bars, pricing tables (numbers are never invented)
 * - dynamic:     fields filled by a dynamic tag
 *
 * Every slot has a stable key ("<element id>|<field>" or "<element id>|<repeater>|<index>|<field>")
 * computed on the source page, so the user's choices and the AI's answers can be applied to
 * an in-memory copy of the source tree before IDs are regenerated.
 */
final class ContentSlots {

	/** Widget fields that hold rewritable text: widget => [field => [role, html?]]. */
	private const FIELDS = array(
		'heading'          => array( 'title' => array( 'heading', false ) ),
		'text-editor'      => array( 'editor' => array( 'paragraph', true ) ),
		'button'           => array( 'text' => array( 'button', false ) ),
		'icon-box'         => array(
			'title_text'       => array( 'card_title', false ),
			'description_text' => array( 'card_text', false ),
		),
		'image-box'        => array(
			'title_text'       => array( 'card_title', false ),
			'description_text' => array( 'card_text', false ),
		),
		'image'            => array( 'caption' => array( 'caption', false ) ),
		'call-to-action'   => array(
			'title'       => array( 'heading', false ),
			'description' => array( 'card_text', false ),
			'button'      => array( 'button', false ),
		),
		'flip-box'         => array(
			'title_text_a'       => array( 'card_title', false ),
			'description_text_a' => array( 'card_text', false ),
			'title_text_b'       => array( 'card_title', false ),
			'description_text_b' => array( 'card_text', false ),
			'button_text'        => array( 'button', false ),
		),
		'animated-headline' => array(
			'before_text' => array( 'heading', false ),
			'after_text'  => array( 'heading', false ),
		),
	);

	/** Repeater fields: widget => [repeater, [field => [role, html?]]]. */
	private const REPEATERS = array(
		'icon-list'        => array( 'icon_list', array( 'text' => array( 'list_item', false ) ) ),
		'accordion'        => array(
			'tabs',
			array(
				'tab_title'   => array( 'faq_question', false ),
				'tab_content' => array( 'faq_answer', true ),
			),
		),
		'toggle'           => array(
			'tabs',
			array(
				'tab_title'   => array( 'faq_question', false ),
				'tab_content' => array( 'faq_answer', true ),
			),
		),
		'tabs'             => array(
			'tabs',
			array(
				'tab_title'   => array( 'tab_title', false ),
				'tab_content' => array( 'paragraph', true ),
			),
		),
		'nested-accordion' => array( 'items', array( 'item_title' => array( 'faq_question', false ) ) ),
		'nested-tabs'      => array( 'tabs', array( 'tab_title' => array( 'tab_title', false ) ) ),
	);

	/** Widgets whose words or numbers are real-world facts: kept, never rewritten. */
	private const PROTECTED_WIDGETS = array(
		'testimonial'          => 'testimonial',
		'testimonial-carousel' => 'testimonial',
		'reviews'              => 'testimonial',
		'blockquote'           => 'testimonial',
		'counter'              => 'statistic',
		'progress'             => 'statistic',
		'price-table'          => 'statistic',
		'price-list'           => 'statistic',
		'star-rating'          => 'statistic',
	);

	private const LEGAL = '/©|&copy;|\bcopyright\b|all rights reserved|\bprivacy\b|\bterms (of|and)\b|\bcookie|\bdisclaimer\b|\bregistered in\b|\bcompany (no|number)\b|\bvat\b/i';

	private const CONTACT = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|(?:\+?\d[\d\s().-]{7,}\d)/i';

	/**
	 * Find slots.
	 *
	 * @param array<int, mixed> $elements Source tree.
	 * @param string            $brand    Business name ("" = unknown).
	 * @return list<array<string, mixed>>
	 */
	public function extract( array $elements, string $brand = '' ): array {
		$analysis = ( new ElementorDocumentAnalyzer() )->analyze( $elements );
		$slots    = array();
		foreach ( array_values( $elements ) as $index => $element ) {
			if ( is_array( $element ) ) {
				$type = (string) ( $analysis['sections'][ $index ]['type'] ?? 'content' );
				$this->walk( $element, $index, $type, $brand, $slots );
			}
		}
		return $slots;
	}

	/**
	 * Walk an element.
	 *
	 * @param array<string, mixed> $el      Element.
	 * @param int                  $section Section index.
	 * @param string               $type    Section type.
	 * @param string               $brand   Brand.
	 * @param array<int, mixed>    $slots   Slots (out).
	 */
	private function walk( array $el, int $section, string $type, string $brand, array &$slots ): void {
		if ( count( $slots ) >= 400 ) {
			return;
		}
		$settings = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
		$id       = (string) ( $el['id'] ?? '' );
		if ( 'widget' === ( $el['elType'] ?? '' ) ) {
			$widget  = (string) ( $el['widgetType'] ?? '' );
			$dynamic = (array) ( $settings['__dynamic__'] ?? array() );
			foreach ( self::FIELDS[ $widget ] ?? array() as $field => [ $role, $html ] ) {
				$this->add( $slots, $settings[ $field ] ?? null, "$id|$field", $id, $widget, $role, $html, $settings, $section, $type, $brand, isset( $dynamic[ $field ] ) );
			}
			if ( isset( self::REPEATERS[ $widget ] ) ) {
				[ $repeater, $fields ] = self::REPEATERS[ $widget ];
				foreach ( array_values( (array) ( $settings[ $repeater ] ?? array() ) ) as $i => $item ) {
					foreach ( $fields as $field => [ $role, $html ] ) {
						$this->add( $slots, is_array( $item ) ? ( $item[ $field ] ?? null ) : null, "$id|$repeater|$i|$field", $id, $widget, $role, $html, $settings, $section, $type, $brand, false );
					}
				}
			}
			if ( isset( self::PROTECTED_WIDGETS[ $widget ] ) ) {
				$slots[] = array(
					'key'           => "$id|*",
					'element_id'    => $id,
					'widget'        => $widget,
					'role'          => self::PROTECTED_WIDGETS[ $widget ],
					'html'          => false,
					'level'         => '',
					'section_index' => $section,
					'section_type'  => $type,
					'text'          => '',
					'words'         => 0,
					'replace'       => false,
					'reason'        => self::PROTECTED_WIDGETS[ $widget ],
					'locked'        => true,
				);
			}
			return;
		}
		foreach ( (array) ( $el['elements'] ?? array() ) as $child ) {
			if ( is_array( $child ) ) {
				$this->walk( $child, $section, $type, $brand, $slots );
			}
		}
	}

	/**
	 * Add one slot when the field has text.
	 *
	 * @param array<int, mixed>    $slots    Slots.
	 * @param mixed                $value    Field value.
	 * @param string               $key      Key.
	 * @param string               $id       Element ID.
	 * @param string               $widget   Widget.
	 * @param string               $role     Role.
	 * @param bool                 $html     HTML field.
	 * @param array<string, mixed> $settings Widget settings.
	 * @param int                  $section  Section index.
	 * @param string               $type     Section type.
	 * @param string               $brand    Brand.
	 * @param bool                 $dynamic  Filled by a dynamic tag.
	 */
	private function add( array &$slots, mixed $value, string $key, string $id, string $widget, string $role, bool $html, array $settings, int $section, string $type, string $brand, bool $dynamic ): void {
		if ( ! is_string( $value ) ) {
			return;
		}
		$text = HtmlFragment::text( $value );
		if ( '' === $text && ! $dynamic ) {
			return;
		}
		$reason = self::protection( $text, $type, $brand, $dynamic );
		$slots[] = array(
			'key'           => $key,
			'element_id'    => $id,
			'widget'        => $widget,
			'role'          => $role,
			'html'          => $html,
			'level'         => 'heading' === $widget && is_string( $settings['header_size'] ?? null ) ? $settings['header_size'] : ( 'heading' === $widget ? 'h2' : '' ),
			'section_index' => $section,
			'section_type'  => $type,
			'text'          => mb_substr( $text, 0, 600 ),
			'words'         => '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) ),
			'replace'       => '' === $reason,
			'reason'        => $reason,
			'locked'        => 'dynamic' === $reason,
		);
	}

	/**
	 * Why a slot is kept by default ("" = rewrite it).
	 *
	 * @param string $text    Text.
	 * @param string $type    Section type.
	 * @param string $brand   Brand.
	 * @param bool   $dynamic Dynamic tag.
	 */
	public static function protection( string $text, string $type, string $brand, bool $dynamic ): string {
		if ( $dynamic ) {
			return 'dynamic';
		}
		if ( in_array( $type, array( 'header', 'footer' ), true ) ) {
			return 'site_chrome';
		}
		if ( preg_match( self::LEGAL, $text ) ) {
			return 'legal';
		}
		if ( preg_match( self::CONTACT, $text ) ) {
			return 'contact';
		}
		$words = count( preg_split( '/\s+/u', trim( $text ) ) );
		if ( '' !== $brand && $words <= 6 && false !== mb_stripos( $text, $brand ) ) {
			return 'brand';
		}
		return '';
	}

	/**
	 * Apply replacements to a copy of the source tree.
	 *
	 * @param array<int, mixed>    $elements Source tree (not modified; a copy is returned).
	 * @param array<string, mixed> $texts    Key => new text (HTML for html slots, plain otherwise).
	 * @param array<int, array<string, mixed>> $slots Slots from extract() (only replace=true keys are applied).
	 * @return array{elements: list<array<string, mixed>>, applied: string[]}
	 */
	public function apply( array $elements, array $texts, array $slots ): array {
		$allowed = array();
		foreach ( $slots as $slot ) {
			if ( ! empty( $slot['replace'] ) && empty( $slot['locked'] ) ) {
				$allowed[ $slot['key'] ] = (bool) $slot['html'];
			}
		}
		$applied = array();
		$out     = array();
		foreach ( $elements as $element ) {
			if ( is_array( $element ) ) {
				$out[] = $this->replaceIn( $element, $texts, $allowed, $applied );
			}
		}
		return array(
			'elements' => $out,
			'applied'  => $applied,
		);
	}

	/**
	 * Replace in one element.
	 *
	 * @param array<string, mixed> $el      Element.
	 * @param array<string, mixed> $texts   Replacements.
	 * @param array<string, bool>  $allowed Allowed keys => html.
	 * @param string[]             $applied Applied keys (out).
	 * @return array<string, mixed>
	 */
	private function replaceIn( array $el, array $texts, array $allowed, array &$applied ): array {
		$id = (string) ( $el['id'] ?? '' );
		if ( 'widget' === ( $el['elType'] ?? '' ) && is_array( $el['settings'] ?? null ) ) {
			foreach ( $texts as $key => $text ) {
				if ( ! is_string( $key ) || ! isset( $allowed[ $key ] ) || ! is_string( $text ) || ! str_starts_with( $key, $id . '|' ) ) {
					continue;
				}
				$value = $allowed[ $key ] ? HtmlFragment::clean( $text ) : htmlspecialchars( mb_substr( HtmlFragment::text( $text ), 0, 500 ), ENT_QUOTES | ENT_HTML5, 'UTF-8', false );
				if ( '' === HtmlFragment::text( $value ) ) {
					continue; // Never blank out existing content.
				}
				$parts = explode( '|', $key );
				if ( 2 === count( $parts ) && array_key_exists( $parts[1], $el['settings'] ) ) {
					$el['settings'][ $parts[1] ] = $value;
					$applied[]                   = $key;
				} elseif ( 4 === count( $parts ) && is_array( $el['settings'][ $parts[1] ] ?? null ) ) {
					$list = array_values( $el['settings'][ $parts[1] ] );
					$i    = (int) $parts[2];
					if ( isset( $list[ $i ] ) && is_array( $list[ $i ] ) && array_key_exists( $parts[3], $list[ $i ] ) ) {
						$list[ $i ][ $parts[3] ]       = $value;
						$el['settings'][ $parts[1] ] = $list;
						$applied[]                     = $key;
					}
				}
			}
			return $el;
		}
		$children = array();
		foreach ( (array) ( $el['elements'] ?? array() ) as $child ) {
			$children[] = is_array( $child ) ? $this->replaceIn( $child, $texts, $allowed, $applied ) : $child;
		}
		$el['elements'] = $children;
		return $el;
	}
}
