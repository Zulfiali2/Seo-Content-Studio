<?php
/**
 * Blog post layout templates for Elementor drafts.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * The selectable layouts and their options. "content" and "designed" are the original
 * general-purpose styles; the others are full blog post templates (header with category,
 * meta and title, table of contents, callout boxes, author box and related posts).
 */
final class BlogTemplates {

	public const MAGAZINE = 'magazine';
	public const CENTERED = 'centered';
	public const HERO     = 'hero';

	/** Blog post templates built by BlogTemplateBuilder. */
	public const TEMPLATES = array( self::MAGAZINE, self::CENTERED, self::HERO );

	/** Every style the Output step can store. */
	public const STYLES = array( LayoutBuilder::STYLE_CONTENT, LayoutBuilder::STYLE_DESIGNED, self::MAGAZINE, self::CENTERED, self::HERO );

	/** Default accent colour (indigo). */
	public const DEFAULT_ACCENT = '#4f46e5';

	/** Post meta read by the front-end styles. */
	public const META_TEMPLATE = '_aiscs_template';
	public const META_ACCENT   = '_aiscs_accent';

	/**
	 * Whether a style is one of the blog post templates.
	 *
	 * @param string $style Style.
	 */
	public static function isTemplate( string $style ): bool {
		return in_array( $style, self::TEMPLATES, true );
	}

	/**
	 * A #rrggbb colour, or the default.
	 *
	 * @param mixed $value Value.
	 */
	public static function accent( mixed $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : self::DEFAULT_ACCENT;
	}

	/**
	 * A single shortcode tag such as [mc4wp_form id="12"], or ''.
	 * Only the shape is checked; WordPress runs it like any shortcode in post content.
	 *
	 * @param mixed $value Value.
	 */
	public static function formShortcode( mixed $value ): string {
		$value = is_string( $value ) ? trim( wp_strip_all_tags( $value ) ) : '';
		if ( mb_strlen( $value ) > 200 || ! preg_match( '/^\[[A-Za-z0-9_-]+(\s[^\[\]]*)?\]$/', $value ) ) {
			return '';
		}
		return $value;
	}
}
