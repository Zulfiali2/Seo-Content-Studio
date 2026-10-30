<?php
/**
 * Normalized page design document: schema, validation and normalisation.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Engine;

use AiSeoContentStudio\WebsiteStudio\Design\DesignTokens;

defined( 'ABSPATH' ) || exit;

/**
 * The provider-neutral intermediate representation every design workflow produces
 * (AI generation, HTML conversion, screenshot analysis). No workflow writes Elementor JSON
 * directly: it produces a PageDocument, which is validated here, mapped by ElementorMapper
 * and validated again by DocumentValidator before anything is saved.
 *
 * Schema version 1 (all strings are plain text unless marked HTML):
 *
 *   schema_version  1
 *   page            {title: 1–200 chars, slug: "" | sanitize_title-style slug}
 *   sections[1–40]  {
 *     id:       /^[a-z0-9][a-z0-9-]{0,40}$/, unique (stable section identifier)
 *     type:     hero|features|cards|about|cta|faq|testimonials|contact|image_grid|statistics|content
 *     layout:   {kind: stack|split|grid, columns: {desktop 1–6, tablet 1–6, mobile 1–6},
 *                align: left|center|right, content_width: boxed|full, gap: length|"", min_height: length|""}
 *     style:    {background: colour|token|"" , text_color: colour|token|"",
 *                padding: {desktop, tablet, mobile}: box|""}
 *     elements[1–60]: Element
 *   }
 *   Element = {type: heading,   text ≤300, level h1–h6, align?}
 *           | {type: text,      html (sanitised, ≤20000), align?}
 *           | {type: button,    text ≤80, url, variant primary|secondary, align?}
 *           | {type: image,     attachment_id ≥0, url, alt ≤250, caption ≤300}
 *           | {type: icon,      icon (Font Awesome solid name from ICONS), align?}
 *           | {type: list,      items[1–30] ≤300}
 *           | {type: accordion, items[1–30] {title ≤300, html}}
 *           | {type: spacer,    height length}
 *           | {type: divider}
 *           | {type: group,     style {background, radius, shadow, padding}, elements[1–20] (no nested groups)}
 *
 * Colours may be "#RRGGBB" or a token reference "token:<role>" (DesignTokens::COLOR_ROLES).
 * In split layouts every element is a group (exactly two). In grid layouts leading
 * non-group elements form the section header and the groups form the grid.
 */
final class PageDocument {

	public const VERSION = 1;

	public const SECTION_TYPES = array( 'hero', 'features', 'cards', 'about', 'cta', 'faq', 'testimonials', 'contact', 'image_grid', 'statistics', 'content' );

	public const ELEMENT_TYPES = array( 'heading', 'text', 'button', 'image', 'icon', 'list', 'accordion', 'spacer', 'divider', 'group' );

	/** Font Awesome 5 solid icons shipped with Elementor Free. */
	public const ICONS = array( 'check', 'check-circle', 'star', 'heart', 'phone', 'envelope', 'map-marker-alt', 'clock', 'calendar-alt', 'user', 'users', 'briefcase', 'chart-line', 'chart-bar', 'cog', 'shield-alt', 'lock', 'lightbulb', 'rocket', 'thumbs-up', 'handshake', 'globe', 'home', 'building', 'truck', 'tools', 'leaf', 'graduation-cap', 'award', 'comments', 'question-circle', 'info-circle', 'arrow-right', 'calculator', 'file-alt', 'search', 'laptop', 'mobile-alt', 'wrench', 'paint-brush', 'camera', 'gift', 'shopping-cart', 'credit-card', 'money-bill-wave', 'percent', 'balance-scale', 'stethoscope', 'utensils', 'car' );

	private const MAX_SECTIONS = 40;
	private const MAX_ELEMENTS = 60;
	private const MAX_GROUP    = 20;

	/**
	 * Validate and normalise.
	 *
	 * @param mixed    $input  Untrusted document.
	 * @param string[] $errors Errors ("path: reason"); the document is usable only when empty.
	 * @return array<string, mixed>
	 */
	public static function normalize( mixed $input, array &$errors = array() ): array {
		$out = array(
			'schema_version' => self::VERSION,
			'page'           => array(
				'title' => '',
				'slug'  => '',
			),
			'sections'       => array(),
		);
		if ( ! is_array( $input ) ) {
			$errors[] = 'document: must be an object';
			return $out;
		}
		if ( isset( $input['schema_version'] ) && self::VERSION !== (int) $input['schema_version'] ) {
			$errors[] = 'schema_version: unsupported version';
		}

		$title = self::plain( $input['page']['title'] ?? '', 200 );
		if ( '' === $title ) {
			$errors[] = 'page.title: required';
		}
		$out['page']['title'] = $title;
		$slug                 = strtolower( trim( (string) ( $input['page']['slug'] ?? '' ) ) );
		if ( '' !== $slug && ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			$errors[] = 'page.slug: use lowercase letters, digits and hyphens';
			$slug     = '';
		}
		$out['page']['slug'] = substr( $slug, 0, 190 );

		$sections = is_array( $input['sections'] ?? null ) ? array_values( $input['sections'] ) : array();
		if ( array() === $sections ) {
			$errors[] = 'sections: at least one section is required';
		}
		if ( count( $sections ) > self::MAX_SECTIONS ) {
			$errors[] = 'sections: at most ' . self::MAX_SECTIONS . ' sections';
			$sections = array_slice( $sections, 0, self::MAX_SECTIONS );
		}
		$ids = array();
		foreach ( $sections as $i => $section ) {
			$path = "sections[$i]";
			if ( ! is_array( $section ) ) {
				$errors[] = "$path: must be an object";
				continue;
			}
			$clean = self::section( $section, $path, $errors );
			if ( isset( $ids[ $clean['id'] ] ) ) {
				$errors[] = "$path.id: duplicate id \"{$clean['id']}\"";
			}
			$ids[ $clean['id'] ] = true;
			$out['sections'][]   = $clean;
		}
		return $out;
	}

	/**
	 * One section.
	 *
	 * @param array<string, mixed> $s      Section.
	 * @param string               $path   Path.
	 * @param string[]             $errors Errors.
	 * @return array<string, mixed>
	 */
	private static function section( array $s, string $path, array &$errors ): array {
		$id = strtolower( (string) ( $s['id'] ?? '' ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,40}$/', $id ) ) {
			$errors[] = "$path.id: invalid id";
			$id       = 'section-' . substr( md5( $path ), 0, 6 );
		}
		$type = (string) ( $s['type'] ?? '' );
		if ( ! in_array( $type, self::SECTION_TYPES, true ) ) {
			$errors[] = "$path.type: unknown section type";
			$type     = 'content';
		}

		$layout = is_array( $s['layout'] ?? null ) ? $s['layout'] : array();
		$kind   = self::enum( $layout['kind'] ?? 'stack', array( 'stack', 'split', 'grid' ), "$path.layout.kind", $errors, 'stack' );
		$cols   = array();
		$defaults = array(
			'desktop' => 'stack' === $kind ? 1 : ( 'split' === $kind ? 2 : 3 ),
			'tablet'  => 'stack' === $kind ? 1 : 2,
			'mobile'  => 1,
		);
		foreach ( $defaults as $device => $default ) {
			$n = (int) ( $layout['columns'][ $device ] ?? $default );
			if ( $n < 1 || $n > 6 ) {
				$errors[] = "$path.layout.columns.$device: 1 to 6";
				$n        = $default;
			}
			$cols[ $device ] = $n;
		}
		$clean_layout = array(
			'kind'          => $kind,
			'columns'       => $cols,
			'align'         => self::enum( $layout['align'] ?? 'left', array( 'left', 'center', 'right' ), "$path.layout.align", $errors, 'left' ),
			'content_width' => self::enum( $layout['content_width'] ?? 'boxed', array( 'boxed', 'full' ), "$path.layout.content_width", $errors, 'boxed' ),
			'gap'           => self::token( $layout['gap'] ?? '', array( DesignTokens::class, 'length' ), "$path.layout.gap", $errors ),
			'min_height'    => self::token( $layout['min_height'] ?? '', array( DesignTokens::class, 'length' ), "$path.layout.min_height", $errors ),
		);

		$style       = is_array( $s['style'] ?? null ) ? $s['style'] : array();
		$clean_style = array(
			'background' => self::colorRef( $style['background'] ?? '', "$path.style.background", $errors ),
			'text_color' => self::colorRef( $style['text_color'] ?? '', "$path.style.text_color", $errors ),
			'padding'    => array(),
		);
		foreach ( DesignTokens::DEVICES as $device ) {
			$clean_style['padding'][ $device ] = self::token( $style['padding'][ $device ] ?? '', array( DesignTokens::class, 'box' ), "$path.style.padding.$device", $errors );
		}

		$elements = is_array( $s['elements'] ?? null ) ? array_values( $s['elements'] ) : array();
		if ( array() === $elements ) {
			$errors[] = "$path.elements: at least one element is required";
		}
		if ( count( $elements ) > self::MAX_ELEMENTS ) {
			$errors[] = "$path.elements: at most " . self::MAX_ELEMENTS;
			$elements = array_slice( $elements, 0, self::MAX_ELEMENTS );
		}
		$clean_elements = array();
		foreach ( $elements as $i => $element ) {
			$clean = self::element( $element, "$path.elements[$i]", $errors, true );
			if ( null !== $clean ) {
				$clean_elements[] = $clean;
			}
		}

		$groups = array_values( array_filter( $clean_elements, static fn ( array $e ): bool => 'group' === $e['type'] ) );
		if ( 'split' === $kind && ( 2 !== count( $groups ) || count( $groups ) !== count( $clean_elements ) ) ) {
			$errors[] = "$path.layout.kind: a split section needs exactly two groups and nothing else";
		}
		if ( 'grid' === $kind ) {
			if ( array() === $groups ) {
				$errors[] = "$path.layout.kind: a grid section needs at least one group";
			}
			$seen_group = false;
			foreach ( $clean_elements as $e ) {
				if ( 'group' === $e['type'] ) {
					$seen_group = true;
				} elseif ( $seen_group ) {
					$errors[] = "$path.elements: in a grid, elements other than groups must come before the groups";
					break;
				}
			}
		}

		return array(
			'id'       => $id,
			'type'     => $type,
			'layout'   => $clean_layout,
			'style'    => $clean_style,
			'elements' => $clean_elements,
		);
	}

	/**
	 * One element.
	 *
	 * @param mixed    $e           Element.
	 * @param string   $path        Path.
	 * @param string[] $errors      Errors.
	 * @param bool     $allow_group Whether a group may appear here.
	 * @return array<string, mixed>|null
	 */
	private static function element( mixed $e, string $path, array &$errors, bool $allow_group ): ?array {
		if ( ! is_array( $e ) ) {
			$errors[] = "$path: must be an object";
			return null;
		}
		$type = (string) ( $e['type'] ?? '' );
		if ( ! in_array( $type, self::ELEMENT_TYPES, true ) ) {
			$errors[] = "$path.type: unknown element type";
			return null;
		}
		$align = isset( $e['align'] ) ? self::enum( $e['align'], array( 'left', 'center', 'right' ), "$path.align", $errors, '' ) : '';

		switch ( $type ) {
			case 'heading':
				$text = self::plain( $e['text'] ?? '', 300 );
				if ( '' === $text ) {
					$errors[] = "$path.text: required";
				}
				return array(
					'type'  => 'heading',
					'text'  => $text,
					'level' => self::enum( $e['level'] ?? 'h2', array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), "$path.level", $errors, 'h2' ),
					'align' => $align,
				);

			case 'text':
				$html = HtmlFragment::clean( (string) ( is_scalar( $e['html'] ?? null ) ? $e['html'] : '' ) );
				if ( '' === HtmlFragment::text( $html ) ) {
					$errors[] = "$path.html: required";
				}
				return array(
					'type'  => 'text',
					'html'  => $html,
					'align' => $align,
				);

			case 'button':
				$text = self::plain( $e['text'] ?? '', 80 );
				$url  = HtmlFragment::url( (string) ( is_scalar( $e['url'] ?? null ) ? $e['url'] : '' ) );
				if ( '' === $text ) {
					$errors[] = "$path.text: required";
				}
				if ( '' !== (string) ( $e['url'] ?? '' ) && '' === $url ) {
					$errors[] = "$path.url: not an allowed link";
				}
				return array(
					'type'    => 'button',
					'text'    => $text,
					'url'     => '' === $url ? '#' : $url,
					'variant' => self::enum( $e['variant'] ?? 'primary', array( 'primary', 'secondary' ), "$path.variant", $errors, 'primary' ),
					'align'   => $align,
				);

			case 'image':
				$id  = max( 0, (int) ( $e['attachment_id'] ?? 0 ) );
				$url = HtmlFragment::url( (string) ( is_scalar( $e['url'] ?? null ) ? $e['url'] : '' ) );
				if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
					$url = ''; // Images need an absolute URL (no mailto/tel/fragments).
				}
				if ( 0 === $id && '' === $url ) {
					$errors[] = "$path: an image needs attachment_id or url";
				}
				return array(
					'type'          => 'image',
					'attachment_id' => $id,
					'url'           => $url,
					'alt'           => self::plain( $e['alt'] ?? '', 250 ),
					'caption'       => self::plain( $e['caption'] ?? '', 300 ),
				);

			case 'icon':
				$icon = (string) ( $e['icon'] ?? '' );
				if ( ! in_array( $icon, self::ICONS, true ) ) {
					$errors[] = "$path.icon: not an available icon";
					$icon     = 'check';
				}
				return array(
					'type'  => 'icon',
					'icon'  => $icon,
					'align' => $align,
				);

			case 'list':
				$items = array_values( array_filter( array_map( static fn ( $i ): string => self::plain( $i, 300 ), array_slice( (array) ( $e['items'] ?? array() ), 0, 30 ) ) ) );
				if ( array() === $items ) {
					$errors[] = "$path.items: at least one item";
				}
				return array(
					'type'  => 'list',
					'items' => $items,
				);

			case 'accordion':
				$items = array();
				foreach ( array_slice( (array) ( $e['items'] ?? array() ), 0, 30 ) as $j => $item ) {
					$title = self::plain( is_array( $item ) ? ( $item['title'] ?? '' ) : '', 300 );
					$html  = HtmlFragment::clean( is_array( $item ) && is_scalar( $item['html'] ?? null ) ? (string) $item['html'] : '' );
					if ( '' === $title || '' === HtmlFragment::text( $html ) ) {
						$errors[] = "$path.items[$j]: title and html are required";
						continue;
					}
					$items[] = array(
						'title' => $title,
						'html'  => $html,
					);
				}
				if ( array() === $items ) {
					$errors[] = "$path.items: at least one item";
				}
				return array(
					'type'  => 'accordion',
					'items' => $items,
				);

			case 'spacer':
				$height = self::token( $e['height'] ?? '40px', array( DesignTokens::class, 'length' ), "$path.height", $errors );
				return array(
					'type'   => 'spacer',
					'height' => '' === $height ? '40px' : $height,
				);

			case 'divider':
				return array( 'type' => 'divider' );

			case 'group':
				if ( ! $allow_group ) {
					$errors[] = "$path: groups cannot be nested";
					return null;
				}
				$style    = is_array( $e['style'] ?? null ) ? $e['style'] : array();
				$children = array();
				$list     = is_array( $e['elements'] ?? null ) ? array_values( $e['elements'] ) : array();
				if ( array() === $list ) {
					$errors[] = "$path.elements: at least one element is required";
				}
				foreach ( array_slice( $list, 0, self::MAX_GROUP ) as $j => $child ) {
					$clean = self::element( $child, "$path.elements[$j]", $errors, false );
					if ( null !== $clean ) {
						$children[] = $clean;
					}
				}
				return array(
					'type'     => 'group',
					'style'    => array(
						'background' => self::colorRef( $style['background'] ?? '', "$path.style.background", $errors ),
						'radius'     => self::token( $style['radius'] ?? '', array( DesignTokens::class, 'box' ), "$path.style.radius", $errors ),
						'shadow'     => self::token( $style['shadow'] ?? '', array( DesignTokens::class, 'shadow' ), "$path.style.shadow", $errors ),
						'padding'    => self::token( $style['padding'] ?? '', array( DesignTokens::class, 'box' ), "$path.style.padding", $errors ),
					),
					'elements' => $children,
				);
		}
		return null;
	}

	/**
	 * Every section ID in document order.
	 *
	 * @param array<string, mixed> $doc Normalised document.
	 * @return string[]
	 */
	public static function sectionIds( array $doc ): array {
		return array_map( static fn ( array $s ): string => $s['id'], (array) ( $doc['sections'] ?? array() ) );
	}

	/**
	 * Plain single-line text.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Max characters.
	 */
	public static function plain( mixed $value, int $max ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return mb_substr( HtmlFragment::text( (string) $value ), 0, $max );
	}

	/**
	 * Enum value.
	 *
	 * @param mixed    $value    Value.
	 * @param string[] $allowed  Allowed.
	 * @param string   $path     Path.
	 * @param string[] $errors   Errors.
	 * @param string   $fallback Fallback.
	 */
	private static function enum( mixed $value, array $allowed, string $path, array &$errors, string $fallback ): string {
		if ( is_string( $value ) && in_array( $value, $allowed, true ) ) {
			return $value;
		}
		$errors[] = "$path: must be one of " . implode( ', ', $allowed );
		return $fallback;
	}

	/**
	 * Optional value through a DesignTokens validator.
	 *
	 * @param mixed    $value     Value.
	 * @param callable $validator Validator.
	 * @param string   $path      Path.
	 * @param string[] $errors    Errors.
	 */
	private static function token( mixed $value, callable $validator, string $path, array &$errors ): string {
		if ( '' === $value || null === $value ) {
			return '';
		}
		$clean = is_scalar( $value ) ? $validator( (string) $value ) : null;
		if ( null === $clean ) {
			$errors[] = "$path: invalid value";
			return '';
		}
		return (string) $clean;
	}

	/**
	 * A colour or a token reference ("token:primary").
	 *
	 * @param mixed    $value  Value.
	 * @param string   $path   Path.
	 * @param string[] $errors Errors.
	 */
	private static function colorRef( mixed $value, string $path, array &$errors ): string {
		if ( is_string( $value ) && preg_match( '/^token:([a-z]+)$/', $value, $m ) ) {
			if ( in_array( $m[1], DesignTokens::COLOR_ROLES, true ) ) {
				return $value;
			}
			$errors[] = "$path: unknown colour token";
			return '';
		}
		return self::token( $value, array( DesignTokens::class, 'color' ), $path, $errors );
	}
}
