<?php
/**
 * Design analysis of an Elementor document.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Analysis;

defined( 'ABSPATH' ) || exit;

/**
 * Reads an Elementor element tree (as returned by Document::get_elements_data()) and the
 * active kit's settings, and reports structure, section patterns and style signals.
 *
 * Pure: no WordPress or Elementor calls, so it is unit-testable and safe to run on any
 * stored document. Global references ("__globals__" => "globals/colors?id=primary") are
 * resolved against the kit, and every use of a global strengthens that kit value.
 */
final class ElementorDocumentAnalyzer {

	private const KIT_WEIGHT    = 5.0;
	private const GLOBAL_WEIGHT = 2.0;
	private const MAX_DEPTH     = 40;

	private const DEVICE_SUFFIX = array(
		'desktop' => '',
		'tablet'  => '_tablet',
		'mobile'  => '_mobile',
	);

	/** Classic widget types that indicate a section pattern. */
	private const FAQ_WIDGETS         = array( 'accordion', 'toggle', 'nested-accordion' );
	private const TESTIMONIAL_WIDGETS = array( 'testimonial', 'testimonial-carousel', 'reviews' );
	private const CONTACT_WIDGETS     = array( 'form', 'google_maps', 'login' );
	private const NAV_WIDGETS         = array( 'nav-menu', 'theme-site-logo', 'site-logo', 'search-form' );

	/**
	 * Kit colours by id.
	 *
	 * @var array<string, string>
	 */
	private array $kitColors = array();

	/**
	 * Kit typography by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $kitTypography = array();

	/**
	 * Running structure statistics.
	 *
	 * @var array<string, mixed>
	 */
	private array $stats = array();

	/**
	 * Analyze a document.
	 *
	 * @param array<int, mixed>    $elements          Element tree.
	 * @param array<string, mixed> $kit               Active kit settings ([] when none).
	 * @param string[]             $available_widgets Widget types registered on this site ([] = unknown).
	 * @return array{structure: array<string, mixed>, sections: list<array<string, mixed>>, signals: StyleSignals, kit_signals: StyleSignals, notes: string[]}
	 */
	public function analyze( array $elements, array $kit = array(), array $available_widgets = array() ): array {
		$signals     = new StyleSignals();
		$kit_signals = new StyleSignals();
		$notes       = array();
		$this->loadKit( $kit, $kit_signals );

		$this->stats = array(
			'element_count'       => 0,
			'widget_count'        => 0,
			'containers'          => 0,
			'sections'            => 0,
			'max_depth'           => 0,
			'widgets'             => array(),
			'responsive_settings' => 0,
			'custom_css'          => false,
			'global_colors'       => 0,
			'global_typography'   => 0,
			'atomic_elements'     => 0,
		);

		$sections = array();
		foreach ( array_values( $elements ) as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$facts = $this->emptyFacts();
			$this->walk( $element, 1, true, $signals, $facts );
			$sections[] = array(
				'index'     => $index,
				'id'        => is_string( $element['id'] ?? null ) ? $element['id'] : '',
				'el_type'   => (string) ( $element['elType'] ?? '' ),
				'type'      => self::classify( $facts, $index, count( $elements ) ),
				'heading'   => $facts['first_heading'],
				'widgets'   => $facts['widgets'],
				'columns'   => $facts['columns'],
				'images'    => $facts['images'],
				'buttons'   => $facts['buttons'],
				'has_background' => $facts['has_background'],
				'text_length'    => $facts['text_length'],
			);
		}

		$widgets = $this->stats['widgets'];
		arsort( $widgets );
		$this->stats['widgets'] = $widgets;
		$unavailable            = array() === $available_widgets ? array() : array_values( array_diff( array_keys( $widgets ), $available_widgets ) );

		if ( $this->stats['atomic_elements'] > 0 ) {
			$notes[] = 'atomic_elements';
		}
		if ( array() !== $unavailable ) {
			$notes[] = 'unavailable_widgets';
		}
		if ( array() === $kit ) {
			$notes[] = 'no_kit';
		}

		$structure = array_merge(
			$this->stats,
			array(
				'builder'             => 'elementor',
				'uses_containers'     => $this->stats['containers'] > 0,
				'uses_legacy_sections' => $this->stats['sections'] > 0,
				'unavailable_widgets' => $unavailable,
			)
		);
		return array(
			'structure'   => $structure,
			'sections'    => $sections,
			'signals'     => $signals,
			'kit_signals' => $kit_signals,
			'notes'       => $notes,
		);
	}

	/**
	 * Index kit globals and record the kit's theme-style settings as strong signals.
	 *
	 * @param array<string, mixed> $kit     Kit settings.
	 * @param StyleSignals         $signals Signals.
	 */
	private function loadKit( array $kit, StyleSignals $signals ): void {
		$this->kitColors     = array();
		$this->kitTypography = array();
		foreach ( array( 'system_colors', 'custom_colors' ) as $group ) {
			foreach ( (array) ( $kit[ $group ] ?? array() ) as $item ) {
				if ( is_array( $item ) && is_string( $item['_id'] ?? null ) && is_string( $item['color'] ?? null ) && '' !== $item['color'] ) {
					$this->kitColors[ $item['_id'] ] = $item['color'];
					$signals->add( 'colors.palette', $item['color'], 'system_colors' === $group ? self::KIT_WEIGHT : self::KIT_WEIGHT / 2 );
				}
			}
		}
		foreach ( (array) ( $kit['system_typography'] ?? array() ) as $item ) {
			if ( is_array( $item ) && is_string( $item['_id'] ?? null ) ) {
				$this->kitTypography[ $item['_id'] ] = $item;
			}
		}
		foreach ( (array) ( $kit['custom_typography'] ?? array() ) as $item ) {
			if ( is_array( $item ) && is_string( $item['_id'] ?? null ) ) {
				$this->kitTypography[ $item['_id'] ] = $item;
			}
		}

		// Elementor's default system colours: primary, secondary, text, accent.
		foreach ( array( 'primary', 'secondary', 'text', 'accent' ) as $id ) {
			if ( isset( $this->kitColors[ $id ] ) ) {
				$signals->add( "colors.$id", $this->kitColors[ $id ], self::KIT_WEIGHT );
			}
		}
		// System typography roles: primary = headings, secondary = sub-headings, text = body, accent = buttons/links.
		$roles = array(
			'primary' => 'heading',
			'text'    => 'body',
			'accent'  => 'button',
		);
		foreach ( $roles as $id => $group ) {
			$typo = $this->kitTypography[ $id ] ?? array();
			$signals->add( "fonts.$group", $typo['typography_font_family'] ?? '', self::KIT_WEIGHT );
			$signals->add( "weights.$group", $typo['typography_font_weight'] ?? '', self::KIT_WEIGHT );
		}

		// Theme Style tab.
		$signals->add( 'colors.text', $kit['body_color'] ?? '', self::KIT_WEIGHT );
		$signals->add( 'fonts.body', $kit['body_typography_font_family'] ?? '', self::KIT_WEIGHT );
		$signals->add( 'weights.body', $kit['body_typography_font_weight'] ?? '', self::KIT_WEIGHT );
		$signals->add( 'colors.link', $kit['link_normal_color'] ?? '', self::KIT_WEIGHT );
		if ( 'classic' === ( $kit['body_background_background'] ?? '' ) ) {
			$signals->add( 'colors.background', $kit['body_background_color'] ?? '', self::KIT_WEIGHT );
		}
		foreach ( self::DEVICE_SUFFIX as $device => $suffix ) {
			$signals->add( "sizes.body.$device", self::slider( $kit[ 'body_typography_font_size' . $suffix ] ?? null ), self::KIT_WEIGHT );
		}
		$signals->add( 'line_heights.body', self::slider( $kit['body_typography_line_height'] ?? null, true ), self::KIT_WEIGHT );
		for ( $h = 1; $h <= 6; $h++ ) {
			$signals->add( 'colors.heading', $kit[ "h{$h}_color" ] ?? '', self::KIT_WEIGHT / 2 );
			$signals->add( 'fonts.heading', $kit[ "h{$h}_typography_font_family" ] ?? '', self::KIT_WEIGHT / 2 );
			foreach ( self::DEVICE_SUFFIX as $device => $suffix ) {
				$signals->add( "sizes.h$h.$device", self::slider( $kit[ "h{$h}_typography_font_size" . $suffix ] ?? null ), self::KIT_WEIGHT );
			}
		}
		$signals->add( 'colors.button_bg', $kit['button_background_color'] ?? '', self::KIT_WEIGHT );
		$signals->add( 'colors.button_text', $kit['button_text_color'] ?? '', self::KIT_WEIGHT );
		$signals->add( 'radii.button', self::dimensions( $kit['button_border_radius'] ?? null ), self::KIT_WEIGHT );
		$signals->add( 'paddings.button', self::dimensions( $kit['button_padding'] ?? null ), self::KIT_WEIGHT );
		$signals->add( 'fonts.button', $kit['button_typography_font_family'] ?? '', self::KIT_WEIGHT );
		$signals->add( 'weights.button', $kit['button_typography_font_weight'] ?? '', self::KIT_WEIGHT );
		$signals->add( 'radii.image', self::dimensions( $kit['image_border_radius'] ?? null ), self::KIT_WEIGHT );
		$signals->add( 'layout.container_width', self::slider( $kit['container_width'] ?? null ), self::KIT_WEIGHT );
		$signals->add( 'spacing.gap', self::slider( $kit['space_between_widgets'] ?? null ), self::KIT_WEIGHT / 2 );
		foreach ( self::DEVICE_SUFFIX as $device => $suffix ) {
			$signals->add( "paddings.section.$device", self::dimensions( $kit[ 'container_padding' . $suffix ] ?? null ), self::KIT_WEIGHT / 2 );
		}

		// Breakpoints: current kits store the max width per device; older ones store the start of the next range.
		$tablet = (int) ( $kit['viewport_tablet'] ?? 0 ) ?: ( (int) ( $kit['viewport_lg'] ?? 0 ) > 0 ? (int) $kit['viewport_lg'] - 1 : 0 );
		$mobile = (int) ( $kit['viewport_mobile'] ?? 0 ) ?: ( (int) ( $kit['viewport_md'] ?? 0 ) > 0 ? (int) $kit['viewport_md'] - 1 : 0 );
		$signals->add( 'breakpoints.tablet', $tablet > 0 ? (string) $tablet : '', self::KIT_WEIGHT );
		$signals->add( 'breakpoints.mobile', $mobile > 0 ? (string) $mobile : '', self::KIT_WEIGHT );
	}

	/**
	 * Per-section facts used by the classifier.
	 *
	 * @return array<string, mixed>
	 */
	private function emptyFacts(): array {
		return array(
			'widgets'        => array(),
			'headings'       => array(),
			'first_heading'  => '',
			'h1'             => false,
			'questions'      => 0,
			'images'         => 0,
			'buttons'        => 0,
			'columns'        => 0,
			'card_children'  => 0,
			'has_background' => false,
			'text_length'    => 0,
		);
	}

	/**
	 * Walk an element.
	 *
	 * @param array<string, mixed> $el      Element.
	 * @param int                  $depth   Depth (1 = top level).
	 * @param bool                 $top     Whether this is a top-level element.
	 * @param StyleSignals         $signals Signals.
	 * @param array<string, mixed> $facts   Section facts.
	 */
	private function walk( array $el, int $depth, bool $top, StyleSignals $signals, array &$facts ): void {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}
		++$this->stats['element_count'];
		$this->stats['max_depth'] = max( $this->stats['max_depth'], $depth );
		$type                     = (string) ( $el['elType'] ?? '' );
		$settings                 = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
		$children                 = is_array( $el['elements'] ?? null ) ? $el['elements'] : array();

		foreach ( array_keys( $settings ) as $key ) {
			if ( is_string( $key ) && preg_match( '/_(tablet|mobile)$/', $key ) ) {
				++$this->stats['responsive_settings'];
			}
		}
		if ( '' !== trim( (string) ( is_string( $settings['custom_css'] ?? null ) ? $settings['custom_css'] : '' ) ) ) {
			$this->stats['custom_css'] = true;
		}
		foreach ( (array) ( $settings['__globals__'] ?? array() ) as $ref ) {
			if ( is_string( $ref ) && str_starts_with( $ref, 'globals/colors' ) ) {
				++$this->stats['global_colors'];
			} elseif ( is_string( $ref ) && str_starts_with( $ref, 'globals/typography' ) ) {
				++$this->stats['global_typography'];
			}
		}

		if ( str_starts_with( $type, 'e-' ) || str_starts_with( (string) ( $el['widgetType'] ?? '' ), 'e-' ) ) {
			// Elementor 4 atomic elements keep typed props ({"$$type": ..., "value": ...}); counted, not styled.
			++$this->stats['atomic_elements'];
		}

		if ( 'widget' === $type ) {
			$this->widget( (string) ( $el['widgetType'] ?? '' ), $settings, $signals, $facts );
			return;
		}

		if ( 'container' === $type || 'e-flexbox' === $type || 'e-div-block' === $type ) {
			++$this->stats['containers'];
		} elseif ( 'section' === $type ) {
			++$this->stats['sections'];
		}
		$this->layoutElement( $type, $settings, $top, $children, $signals, $facts );

		foreach ( $children as $child ) {
			if ( is_array( $child ) ) {
				$this->walk( $child, $depth + 1, false, $signals, $facts );
			}
		}
	}

	/**
	 * Style signals from a container, section or column.
	 *
	 * @param string               $type     Element type.
	 * @param array<string, mixed> $settings Settings.
	 * @param bool                 $top      Top level.
	 * @param array<int, mixed>    $children Children.
	 * @param StyleSignals         $signals  Signals.
	 * @param array<string, mixed> $facts    Facts.
	 */
	private function layoutElement( string $type, array $settings, bool $top, array $children, StyleSignals $signals, array &$facts ): void {
		$bg = 'classic' === ( $settings['background_background'] ?? '' ) ? $this->color( $settings, 'background_color', $signals ) : '';
		if ( '' !== $bg ) {
			$signals->add( $top ? 'colors.section_bg' : 'colors.card_bg', $bg );
			if ( $top ) {
				$facts['has_background'] = true;
			}
		} elseif ( $top && in_array( $settings['background_background'] ?? '', array( 'classic', 'gradient', 'video', 'slideshow' ), true ) ) {
			$facts['has_background'] = true; // Image, gradient or video background.
		}

		if ( $top ) {
			foreach ( self::DEVICE_SUFFIX as $device => $suffix ) {
				$signals->add( "paddings.section.$device", self::dimensions( $settings[ 'padding' . $suffix ] ?? null ) );
			}
			$signals->add( 'layout.container_width', self::slider( $settings['boxed_width'] ?? ( 'section' === $type ? ( $settings['content_width'] ?? null ) : null ) ) );
		} else {
			$radius = self::dimensions( $settings['border_radius'] ?? null );
			$shadow = 'yes' === ( $settings['box_shadow_box_shadow_type'] ?? '' ) ? self::shadow( $settings['box_shadow_box_shadow'] ?? null ) : '';
			if ( '' !== $radius || '' !== $shadow || '' !== $bg ) {
				++$facts['card_children'];
				$signals->add( 'radii.card', $radius );
				$signals->add( 'shadows.card', $shadow );
				$signals->add( 'paddings.card', self::dimensions( $settings['padding'] ?? null ) );
			}
		}
		$gap = $settings['flex_gap'] ?? null;
		if ( is_array( $gap ) && isset( $gap['column'] ) && '' !== (string) $gap['column'] ) {
			$signals->add( 'spacing.gap', (string) (float) $gap['column'] . (string) ( $gap['unit'] ?? 'px' ), 0.5 );
		}

		// Columns: direct child layout elements of the section's first row.
		$layout_children = count( array_filter( $children, static fn ( $c ): bool => is_array( $c ) && in_array( $c['elType'] ?? '', array( 'container', 'column', 'e-flexbox', 'e-div-block' ), true ) ) );
		$facts['columns'] = max( $facts['columns'], $layout_children );
	}

	/**
	 * Style signals and facts from a widget.
	 *
	 * @param string               $widget   Widget type.
	 * @param array<string, mixed> $settings Settings.
	 * @param StyleSignals         $signals  Signals.
	 * @param array<string, mixed> $facts    Facts.
	 */
	private function widget( string $widget, array $settings, StyleSignals $signals, array &$facts ): void {
		++$this->stats['widget_count'];
		if ( '' !== $widget && preg_match( '/^[a-z0-9_-]{1,64}$/', $widget ) ) {
			$this->stats['widgets'][ $widget ] = ( $this->stats['widgets'][ $widget ] ?? 0 ) + 1;
			$facts['widgets'][ $widget ]       = ( $facts['widgets'][ $widget ] ?? 0 ) + 1;
		}

		switch ( $widget ) {
			case 'heading':
				$level = is_string( $settings['header_size'] ?? null ) && preg_match( '/^h[1-6]$/', $settings['header_size'] ) ? $settings['header_size'] : 'h2';
				$title = trim( wp_strip_all_tags( (string) ( is_string( $settings['title'] ?? null ) ? $settings['title'] : '' ) ) );
				$facts['headings'][] = $level;
				if ( '' === $facts['first_heading'] && '' !== $title ) {
					$facts['first_heading'] = mb_substr( $title, 0, 120 );
				}
				if ( 'h1' === $level ) {
					$facts['h1'] = true;
				}
				if ( str_ends_with( $title, '?' ) ) {
					++$facts['questions'];
				}
				$facts['text_length'] += mb_strlen( $title );
				$signals->add( 'colors.heading', $this->color( $settings, 'title_color', $signals ) );
				$this->typography( $settings, 'heading', $level, $signals );
				break;

			case 'text-editor':
				$facts['text_length'] += mb_strlen( trim( wp_strip_all_tags( (string) ( is_string( $settings['editor'] ?? null ) ? $settings['editor'] : '' ) ) ) );
				$signals->add( 'colors.text', $this->color( $settings, 'text_color', $signals ) );
				$this->typography( $settings, 'body', 'body', $signals );
				break;

			case 'button':
				++$facts['buttons'];
				$signals->add( 'colors.button_bg', $this->color( $settings, 'background_color', $signals ) );
				$signals->add( 'colors.button_text', $this->color( $settings, 'button_text_color', $signals ) );
				$signals->add( 'radii.button', self::dimensions( $settings['border_radius'] ?? null ) );
				$signals->add( 'paddings.button', self::dimensions( $settings['text_padding'] ?? null ) );
				$this->typography( $settings, 'button', '', $signals );
				break;

			case 'image':
			case 'image-box':
			case 'image-gallery':
			case 'gallery':
			case 'image-carousel':
				$facts['images'] += 'image-gallery' === $widget || 'gallery' === $widget || 'image-carousel' === $widget ? 3 : 1;
				$signals->add( 'radii.image', self::dimensions( $settings['image_border_radius'] ?? null ) );
				break;

			case 'icon-box':
				$signals->add( 'colors.heading', $this->color( $settings, 'title_color', $signals ), 0.5 );
				$signals->add( 'colors.accent', $this->color( $settings, 'primary_color', $signals ), 0.5 );
				$facts['text_length'] += mb_strlen( (string) ( is_string( $settings['description_text'] ?? null ) ? $settings['description_text'] : '' ) );
				break;

			case 'icon':
			case 'icon-list':
				$signals->add( 'colors.accent', $this->color( $settings, 'primary_color', $signals ), 0.5 );
				$signals->add( 'colors.accent', $this->color( $settings, 'icon_color', $signals ), 0.5 );
				break;
		}
		if ( in_array( $widget, self::FAQ_WIDGETS, true ) ) {
			$facts['questions'] += 3;
		}
	}

	/**
	 * Record a widget's typography group.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $group    heading|body|button.
	 * @param string               $size_key Size slot key (h1..h6, body, or "" for none).
	 * @param StyleSignals         $signals  Signals.
	 */
	private function typography( array $settings, string $group, string $size_key, StyleSignals $signals ): void {
		$global = $settings['__globals__']['typography_typography'] ?? null;
		if ( is_string( $global ) && preg_match( '/^globals\/typography\?id=([a-z0-9_-]+)$/i', $global, $m ) && isset( $this->kitTypography[ $m[1] ] ) ) {
			$typo = $this->kitTypography[ $m[1] ];
			$signals->add( "fonts.$group", $typo['typography_font_family'] ?? '', self::GLOBAL_WEIGHT );
			$signals->add( "weights.$group", $typo['typography_font_weight'] ?? '', self::GLOBAL_WEIGHT );
			if ( '' !== $size_key ) {
				foreach ( self::DEVICE_SUFFIX as $device => $suffix ) {
					$signals->add( "sizes.$size_key.$device", self::slider( $typo[ 'typography_font_size' . $suffix ] ?? null ), self::GLOBAL_WEIGHT );
				}
			}
			return;
		}
		if ( 'custom' !== ( $settings['typography_typography'] ?? '' ) ) {
			return;
		}
		$signals->add( "fonts.$group", $settings['typography_font_family'] ?? '' );
		$signals->add( "weights.$group", $settings['typography_font_weight'] ?? '' );
		if ( '' !== $size_key ) {
			foreach ( self::DEVICE_SUFFIX as $device => $suffix ) {
				$signals->add( "sizes.$size_key.$device", self::slider( $settings[ 'typography_font_size' . $suffix ] ?? null ) );
			}
		}
		if ( 'button' !== $group ) {
			$signals->add( "line_heights.$group", self::slider( $settings['typography_line_height'] ?? null, true ) );
		}
	}

	/**
	 * A colour setting, resolved through the kit when it references a global.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $key      Setting key.
	 * @param StyleSignals         $signals  Signals (global use strengthens the kit colour).
	 */
	private function color( array $settings, string $key, StyleSignals $signals ): string {
		$global = $settings['__globals__'][ $key ] ?? null;
		if ( is_string( $global ) && preg_match( '/^globals\/colors\?id=([a-z0-9_-]+)$/i', $global, $m ) ) {
			$hex = $this->kitColors[ $m[1] ] ?? '';
			if ( '' !== $hex ) {
				$signals->add( 'colors.palette', $hex, self::GLOBAL_WEIGHT );
			}
			return $hex;
		}
		return is_string( $settings[ $key ] ?? null ) ? $settings[ $key ] : '';
	}

	/**
	 * Slider control value ({unit, size}) as a CSS length.
	 *
	 * @param mixed $value    Control value.
	 * @param bool  $unitless Allow a unitless value (line height in "em" with size only).
	 */
	public static function slider( mixed $value, bool $unitless = false ): string {
		if ( ! is_array( $value ) || ! isset( $value['size'] ) || '' === $value['size'] || ! is_numeric( $value['size'] ) ) {
			return '';
		}
		$unit = is_string( $value['unit'] ?? null ) ? $value['unit'] : 'px';
		$size = rtrim( rtrim( number_format( (float) $value['size'], 3, '.', '' ), '0' ), '.' );
		if ( $unitless && in_array( $unit, array( '', 'em' ), true ) ) {
			return $size;
		}
		return in_array( $unit, array( 'px', 'em', 'rem', '%', 'vw', 'vh' ), true ) ? $size . $unit : '';
	}

	/**
	 * Dimensions control value ({unit, top, right, bottom, left}) as a CSS box.
	 *
	 * @param mixed $value Control value.
	 */
	public static function dimensions( mixed $value ): string {
		if ( ! is_array( $value ) ) {
			return '';
		}
		$unit  = is_string( $value['unit'] ?? null ) && in_array( $value['unit'], array( 'px', 'em', 'rem', '%', 'vw', 'vh' ), true ) ? $value['unit'] : 'px';
		$sides = array();
		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			$v = $value[ $side ] ?? '';
			if ( '' === $v || ! is_numeric( $v ) ) {
				return '';
			}
			$n       = rtrim( rtrim( number_format( (float) $v, 3, '.', '' ), '0' ), '.' );
			$sides[] = '0' === $n ? '0' : $n . $unit;
		}
		if ( count( array_unique( $sides ) ) === 1 ) {
			return $sides[0];
		}
		if ( $sides[0] === $sides[2] && $sides[1] === $sides[3] ) {
			return $sides[0] . ' ' . $sides[1];
		}
		return implode( ' ', $sides );
	}

	/**
	 * Box-shadow control value as CSS.
	 *
	 * @param mixed $value Control value.
	 */
	public static function shadow( mixed $value ): string {
		if ( ! is_array( $value ) || ! is_string( $value['color'] ?? null ) ) {
			return '';
		}
		$parts = array();
		foreach ( array( 'horizontal', 'vertical', 'blur', 'spread' ) as $key ) {
			$n       = is_numeric( $value[ $key ] ?? null ) ? (float) $value[ $key ] : 0.0;
			$parts[] = 0.0 === $n ? '0' : rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' ) . 'px';
		}
		return implode( ' ', $parts ) . ' ' . $value['color'];
	}

	/**
	 * Name a top-level section's pattern from its facts.
	 *
	 * @param array<string, mixed> $f     Facts.
	 * @param int                  $index Position on the page.
	 * @param int                  $total Number of top-level sections.
	 */
	public static function classify( array $f, int $index, int $total ): string {
		$widgets = array_keys( $f['widgets'] );
		if ( array() !== array_intersect( $widgets, self::NAV_WIDGETS ) ) {
			return 0 === $index ? 'header' : 'footer';
		}
		if ( $f['questions'] >= 2 ) {
			return 'faq';
		}
		if ( array() !== array_intersect( $widgets, self::TESTIMONIAL_WIDGETS ) ) {
			return 'testimonials';
		}
		if ( array() !== array_intersect( $widgets, self::CONTACT_WIDGETS ) ) {
			return 'contact';
		}
		if ( ( 0 === $index || $f['h1'] ) && ( $f['buttons'] > 0 || $f['has_background'] || $f['images'] > 0 ) ) {
			return 'hero';
		}
		$item_widgets = (int) ( $f['widgets']['icon-box'] ?? 0 ) + (int) ( $f['widgets']['image-box'] ?? 0 ) + (int) ( $f['widgets']['price-table'] ?? 0 );
		if ( $item_widgets >= 3 || $f['card_children'] >= 3 || ( $f['columns'] >= 3 && count( $f['headings'] ) >= 3 ) ) {
			return 'cards';
		}
		if ( (int) ( $f['widgets']['counter'] ?? 0 ) >= 2 ) {
			return 'statistics';
		}
		if ( $f['images'] >= 3 && $f['text_length'] < 300 ) {
			return 'image_grid';
		}
		if ( $f['buttons'] > 0 && $f['text_length'] < 400 && count( $f['headings'] ) <= 2 && $index > 0 ) {
			return 'cta';
		}
		if ( 0 === $index && $total > 1 && count( $f['headings'] ) > 0 ) {
			return 'hero';
		}
		return 'content';
	}
}
