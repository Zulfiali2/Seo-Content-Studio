<?php
/**
 * Design analysis of block-editor and classic pages, plus theme.json styles.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Analysis;

defined( 'ABSPATH' ) || exit;

/**
 * Reads parsed blocks (parse_blocks() output) and the theme's global settings/styles
 * (wp_get_global_settings() / wp_get_global_styles()) and reports structure, sections and
 * style signals in the same shape as ElementorDocumentAnalyzer. Pure: callers pass data in.
 */
final class BlockDocumentAnalyzer {

	private const THEME_WEIGHT = 4.0;
	private const MAX_BLOCKS   = 3000;

	/**
	 * Palette slug => colour.
	 *
	 * @var array<string, string>
	 */
	private array $palette = array();

	/**
	 * Font family slug => family.
	 *
	 * @var array<string, string>
	 */
	private array $families = array();

	/**
	 * Font size slug => size.
	 *
	 * @var array<string, string>
	 */
	private array $sizes = array();

	private int $count = 0;

	/**
	 * Analyze theme styles only (used for every page, and for the site-wide profile).
	 *
	 * @param array<string, mixed> $settings Global settings.
	 * @param array<string, mixed> $styles   Global styles.
	 */
	public function analyzeTheme( array $settings, array $styles ): StyleSignals {
		$signals = new StyleSignals();
		$this->indexPresets( $settings );
		foreach ( $this->palette as $slug => $hex ) {
			$signals->add( 'colors.palette', $hex, self::THEME_WEIGHT / 2 );
			// Common theme slugs map directly to roles.
			$role = match ( true ) {
				in_array( $slug, array( 'primary', 'brand' ), true ) => 'primary',
				'secondary' === $slug => 'secondary',
				in_array( $slug, array( 'accent', 'tertiary', 'accent-1' ), true ) => 'accent',
				in_array( $slug, array( 'contrast', 'foreground', 'text', 'dark' ), true ) => 'text',
				in_array( $slug, array( 'base', 'background', 'light', 'white' ), true ) => 'background',
				default => '',
			};
			if ( '' !== $role ) {
				$signals->add( "colors.$role", $hex, self::THEME_WEIGHT );
			}
		}
		foreach ( array_values( $this->families ) as $i => $family ) {
			$signals->add( 'fonts.body', $family, 0 === $i ? 1.0 : 0.5 );
		}
		$layout = (array) ( $settings['layout'] ?? array() );
		$signals->add( 'layout.container_width', is_string( $layout['wideSize'] ?? null ) ? $layout['wideSize'] : '', self::THEME_WEIGHT / 2 );
		$signals->add( 'layout.container_width', is_string( $layout['contentSize'] ?? null ) ? $layout['contentSize'] : '', self::THEME_WEIGHT / 4 );

		$signals->add( 'colors.background', $this->value( $styles['color']['background'] ?? '' ), self::THEME_WEIGHT );
		$signals->add( 'colors.text', $this->value( $styles['color']['text'] ?? '' ), self::THEME_WEIGHT );
		$signals->add( 'fonts.body', $this->value( $styles['typography']['fontFamily'] ?? '' ), self::THEME_WEIGHT );
		$signals->add( 'sizes.body.desktop', $this->value( $styles['typography']['fontSize'] ?? '' ), self::THEME_WEIGHT );
		$signals->add( 'line_heights.body', $this->value( $styles['typography']['lineHeight'] ?? '' ), self::THEME_WEIGHT );
		$signals->add( 'spacing.gap', $this->value( $styles['spacing']['blockGap'] ?? '' ), self::THEME_WEIGHT / 2 );

		$elements = (array) ( $styles['elements'] ?? array() );
		$heading  = (array) ( $elements['heading'] ?? array() );
		$signals->add( 'colors.heading', $this->value( $heading['color']['text'] ?? '' ), self::THEME_WEIGHT );
		$signals->add( 'fonts.heading', $this->value( $heading['typography']['fontFamily'] ?? '' ), self::THEME_WEIGHT );
		$signals->add( 'weights.heading', $this->value( $heading['typography']['fontWeight'] ?? '' ), self::THEME_WEIGHT );
		$signals->add( 'line_heights.heading', $this->value( $heading['typography']['lineHeight'] ?? '' ), self::THEME_WEIGHT );
		for ( $h = 1; $h <= 6; $h++ ) {
			$el = (array) ( $elements[ "h$h" ] ?? array() );
			$signals->add( "sizes.h$h.desktop", $this->value( $el['typography']['fontSize'] ?? '' ), self::THEME_WEIGHT );
			$signals->add( 'fonts.heading', $this->value( $el['typography']['fontFamily'] ?? '' ), self::THEME_WEIGHT / 2 );
		}
		$button = (array) ( $elements['button'] ?? array() );
		$signals->add( 'colors.button_bg', $this->value( $button['color']['background'] ?? '' ), self::THEME_WEIGHT );
		$signals->add( 'colors.button_text', $this->value( $button['color']['text'] ?? '' ), self::THEME_WEIGHT );
		$signals->add( 'radii.button', $this->value( $button['border']['radius'] ?? '' ), self::THEME_WEIGHT );
		$signals->add( 'paddings.button', $this->box( $button['spacing']['padding'] ?? null ), self::THEME_WEIGHT );
		$signals->add( 'fonts.button', $this->value( $button['typography']['fontFamily'] ?? '' ), self::THEME_WEIGHT );
		$signals->add( 'colors.link', $this->value( $elements['link']['color']['text'] ?? '' ), self::THEME_WEIGHT );
		return $signals;
	}

	/**
	 * Analyze a page's blocks.
	 *
	 * @param array<int, mixed>    $blocks   parse_blocks() output.
	 * @param array<string, mixed> $settings Global settings (for preset slugs).
	 * @param string               $html     Raw post content (for classic pages).
	 * @return array{structure: array<string, mixed>, sections: list<array<string, mixed>>, signals: StyleSignals, notes: string[]}
	 */
	public function analyze( array $blocks, array $settings = array(), string $html = '' ): array {
		$this->indexPresets( $settings );
		$this->count = 0;
		$signals     = new StyleSignals();
		$named       = array_values( array_filter( $blocks, static fn ( $b ): bool => is_array( $b ) && ! empty( $b['blockName'] ) ) );
		$classic     = array() === $named;
		$counts      = array();
		$sections    = array();
		$depth       = 0;

		if ( $classic ) {
			$sections = $this->classicSections( $html );
		} else {
			foreach ( $named as $index => $block ) {
				$facts = array(
					'blocks'    => array(),
					'heading'   => '',
					'h1'        => false,
					'headings'  => 0,
					'questions' => 0,
					'images'    => 0,
					'buttons'   => 0,
					'columns'   => 0,
					'text'      => 0,
				);
				$d     = $this->walk( $block, 1, $signals, $facts, $counts );
				$depth = max( $depth, $d );
				$sections[] = array(
					'index'          => $index,
					'id'             => '',
					'el_type'        => (string) $block['blockName'],
					'type'           => self::classify( (string) $block['blockName'], $facts, $index ),
					'heading'        => $facts['heading'],
					'widgets'        => $facts['blocks'],
					'columns'        => $facts['columns'],
					'images'         => $facts['images'],
					'buttons'        => $facts['buttons'],
					'has_background' => isset( $block['attrs']['backgroundColor'] ) || isset( $block['attrs']['style']['color']['background'] ) || 'core/cover' === $block['blockName'],
					'text_length'    => $facts['text'],
				);
			}
		}
		arsort( $counts );
		return array(
			'structure' => array(
				'builder'       => $classic ? 'classic' : 'blocks',
				'element_count' => $this->count,
				'max_depth'     => $depth,
				'widgets'       => $counts,
			),
			'sections'  => $sections,
			'signals'   => $signals,
			'notes'     => $classic ? array( 'classic_content' ) : array(),
		);
	}

	/**
	 * Walk a block.
	 *
	 * @param array<string, mixed> $block   Block.
	 * @param int                  $depth   Depth.
	 * @param StyleSignals         $signals Signals.
	 * @param array<string, mixed> $facts   Facts.
	 * @param array<string, int>   $counts  Block counts.
	 * @return int Max depth.
	 */
	private function walk( array $block, int $depth, StyleSignals $signals, array &$facts, array &$counts ): int {
		if ( ++$this->count > self::MAX_BLOCKS || $depth > 30 ) {
			return $depth;
		}
		$name  = (string) ( $block['blockName'] ?? '' );
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$inner = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
		$html  = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
		if ( '' !== $name ) {
			$counts[ $name ]          = ( $counts[ $name ] ?? 0 ) + 1;
			$facts['blocks'][ $name ] = ( $facts['blocks'][ $name ] ?? 0 ) + 1;
		}
		$bg   = $this->presetOr( $attrs['backgroundColor'] ?? '', $attrs['style']['color']['background'] ?? '' );
		$text = $this->presetOr( $attrs['textColor'] ?? '', $attrs['style']['color']['text'] ?? '' );
		$size = isset( $attrs['fontSize'] ) && is_string( $attrs['fontSize'] ) ? ( $this->sizes[ $attrs['fontSize'] ] ?? '' ) : $this->value( $attrs['style']['typography']['fontSize'] ?? '' );
		$font = isset( $attrs['fontFamily'] ) && is_string( $attrs['fontFamily'] ) ? ( $this->families[ $attrs['fontFamily'] ] ?? '' ) : '';

		switch ( $name ) {
			case 'core/heading':
				$level = 'h' . max( 1, min( 6, (int) ( $attrs['level'] ?? 2 ) ) );
				$title = trim( wp_strip_all_tags( $html ) );
				++$facts['headings'];
				if ( '' === $facts['heading'] ) {
					$facts['heading'] = mb_substr( $title, 0, 120 );
				}
				$facts['h1']    = $facts['h1'] || 'h1' === $level;
				$facts['text'] += mb_strlen( $title );
				if ( str_ends_with( $title, '?' ) ) {
					++$facts['questions'];
				}
				$signals->add( 'colors.heading', $text );
				$signals->add( "sizes.$level.desktop", $size );
				$signals->add( 'fonts.heading', $font );
				break;
			case 'core/paragraph':
			case 'core/list':
				$facts['text'] += mb_strlen( trim( wp_strip_all_tags( $html ) ) );
				$signals->add( 'colors.text', $text );
				$signals->add( 'fonts.body', $font );
				break;
			case 'core/button':
				++$facts['buttons'];
				$signals->add( 'colors.button_bg', $bg );
				$signals->add( 'colors.button_text', $text );
				$signals->add( 'radii.button', $this->value( $attrs['style']['border']['radius'] ?? '' ) );
				$signals->add( 'paddings.button', $this->box( $attrs['style']['spacing']['padding'] ?? null ) );
				break;
			case 'core/image':
			case 'core/cover':
			case 'core/media-text':
				++$facts['images'];
				$signals->add( 'radii.image', $this->value( $attrs['style']['border']['radius'] ?? '' ) );
				break;
			case 'core/gallery':
				$facts['images'] += max( 3, count( $inner ) );
				break;
			case 'core/details':
				$facts['questions'] += 2;
				break;
			case 'core/columns':
				$facts['columns'] = max( $facts['columns'], count( $inner ) );
				break;
		}
		if ( in_array( $name, array( 'core/group', 'core/cover', 'core/column', 'core/columns' ), true ) ) {
			$signals->add( 1 === $depth ? 'colors.section_bg' : 'colors.card_bg', $bg );
			if ( 1 === $depth ) {
				$signals->add( 'paddings.section.desktop', $this->box( $attrs['style']['spacing']['padding'] ?? null ) );
			} elseif ( '' !== $bg || isset( $attrs['style']['border']['radius'] ) ) {
				$signals->add( 'radii.card', $this->value( $attrs['style']['border']['radius'] ?? '' ) );
			}
		}
		$max = $depth;
		foreach ( $inner as $child ) {
			if ( is_array( $child ) ) {
				$max = max( $max, $this->walk( $child, $depth + 1, $signals, $facts, $counts ) );
			}
		}
		return $max;
	}

	/**
	 * Sections of a classic (non-block) page: split at H2s.
	 *
	 * @param string $html Post content.
	 * @return list<array<string, mixed>>
	 */
	private function classicSections( string $html ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}
		$parts    = preg_split( '/(?=<h2\b)/i', $html );
		$sections = array();
		foreach ( (array) $parts as $index => $part ) {
			$heading = preg_match( '/<h[1-3]\b[^>]*>(.*?)<\/h[1-3]>/is', (string) $part, $m ) ? mb_substr( trim( wp_strip_all_tags( $m[1] ) ), 0, 120 ) : '';
			$images  = preg_match_all( '/<img\b/i', (string) $part );
			$text    = mb_strlen( trim( wp_strip_all_tags( (string) $part ) ) );
			if ( 0 === $text && 0 === $images ) {
				continue;
			}
			$sections[] = array(
				'index'          => count( $sections ),
				'id'             => '',
				'el_type'        => 'html',
				'type'           => 0 === $index ? 'hero' : ( str_ends_with( $heading, '?' ) ? 'faq' : 'content' ),
				'heading'        => $heading,
				'widgets'        => array(),
				'columns'        => 1,
				'images'         => (int) $images,
				'buttons'        => 0,
				'has_background' => false,
				'text_length'    => $text,
			);
		}
		return $sections;
	}

	/**
	 * Name a top-level block's pattern.
	 *
	 * @param string               $name  Block name.
	 * @param array<string, mixed> $f     Facts.
	 * @param int                  $index Position.
	 */
	private static function classify( string $name, array $f, int $index ): string {
		if ( in_array( $name, array( 'core/template-part', 'core/navigation' ), true ) ) {
			return 0 === $index ? 'header' : 'footer';
		}
		if ( $f['questions'] >= 2 ) {
			return 'faq';
		}
		if ( isset( $f['blocks']['core/quote'] ) || isset( $f['blocks']['core/pullquote'] ) ) {
			return 'testimonials';
		}
		if ( 'core/cover' === $name || ( ( 0 === $index || $f['h1'] ) && ( $f['buttons'] > 0 || $f['images'] > 0 ) ) ) {
			return 'hero';
		}
		if ( $f['columns'] >= 3 ) {
			return 'cards';
		}
		if ( $f['images'] >= 3 && $f['text'] < 300 ) {
			return 'image_grid';
		}
		if ( $f['buttons'] > 0 && $f['text'] < 400 && $index > 0 ) {
			return 'cta';
		}
		return 'content';
	}

	/**
	 * Index theme presets.
	 *
	 * @param array<string, mixed> $settings Global settings.
	 */
	private function indexPresets( array $settings ): void {
		$this->palette  = array();
		$this->families = array();
		$this->sizes    = array();
		// Theme presets override default ones; custom (user) presets override both.
		foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
			foreach ( (array) ( $settings['color']['palette'][ $origin ] ?? array() ) as $p ) {
				if ( is_array( $p ) && is_string( $p['slug'] ?? null ) && is_string( $p['color'] ?? null ) && 'default' !== $origin ) {
					$this->palette[ $p['slug'] ] = $p['color'];
				}
			}
			foreach ( (array) ( $settings['typography']['fontFamilies'][ $origin ] ?? array() ) as $f ) {
				if ( is_array( $f ) && is_string( $f['slug'] ?? null ) && is_string( $f['fontFamily'] ?? null ) ) {
					$this->families[ $f['slug'] ] = CssStyleAnalyzer::firstFamily( $f['fontFamily'] );
				}
			}
			foreach ( (array) ( $settings['typography']['fontSizes'][ $origin ] ?? array() ) as $s ) {
				if ( is_array( $s ) && is_string( $s['slug'] ?? null ) && is_string( $s['size'] ?? null ) ) {
					$this->sizes[ $s['slug'] ] = $s['size'];
				}
			}
		}
	}

	/**
	 * A preset slug's colour, or a literal colour.
	 *
	 * @param mixed $slug    Preset slug.
	 * @param mixed $literal Literal value.
	 */
	private function presetOr( mixed $slug, mixed $literal ): string {
		if ( is_string( $slug ) && '' !== $slug ) {
			return $this->palette[ $slug ] ?? '';
		}
		return $this->value( $literal );
	}

	/**
	 * Resolve a theme.json value: "var:preset|color|primary", "var(--wp--preset--color--primary)" or a literal.
	 *
	 * @param mixed $value Value.
	 */
	public function value( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		if ( preg_match( '/^var:preset\|([a-z-]+)\|([a-z0-9-]+)$/i', $value, $m ) || preg_match( '/^var\(--wp--preset--([a-z-]+)--([a-z0-9-]+)\)$/i', $value, $m ) ) {
			return match ( $m[1] ) {
				'color'       => $this->palette[ $m[2] ] ?? '',
				'font-family' => $this->families[ $m[2] ] ?? '',
				'font-size'   => $this->sizes[ $m[2] ] ?? '',
				default       => '',
			};
		}
		if ( str_contains( $value, 'var(' ) || str_contains( $value, 'clamp(' ) ) {
			// clamp()/min() fluid sizes: keep the maximum, which is the desktop size.
			if ( preg_match( '/clamp\([^,]+,[^,]+,\s*([0-9.]+(?:px|rem|em))\s*\)/', $value, $m ) ) {
				return $m[1];
			}
			return '';
		}
		// A font stack ("Inter, sans-serif") reduces to its first family; anything else is kept as given.
		return str_contains( $value, ',' ) && ! str_contains( $value, '(' ) ? CssStyleAnalyzer::firstFamily( $value ) : $value;
	}

	/**
	 * Spacing object ({top, right, bottom, left}) or string as a CSS box.
	 *
	 * @param mixed $value Value.
	 */
	private function box( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $this->value( $value );
		}
		if ( ! is_array( $value ) ) {
			return '';
		}
		$sides = array();
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$v = $this->value( $value[ $side ] ?? '' );
			if ( '' === $v ) {
				return '';
			}
			$sides[] = $v;
		}
		return count( array_unique( $sides ) ) === 1 ? $sides[0] : implode( ' ', $sides );
	}
}
