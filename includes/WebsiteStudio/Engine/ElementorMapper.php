<?php
/**
 * PageDocument → native Elementor elements.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Engine;

use AiSeoContentStudio\WebsiteStudio\Design\DesignTokens;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a normalised PageDocument and a brand profile's tokens to an Elementor element
 * tree built only from native, editable Free widgets.
 *
 * Containers (when active): section = boxed flex container (column direction); grid and
 * split sections hold a row container whose children are card containers with responsive
 * widths of calc((100% - (n-1)·gap) / n) per device. Legacy fallback (containers off):
 * section > column(s) > widgets, with responsive column sizes.
 *
 * Styling comes from the tokens: colours that match an Elementor global colour are written
 * as a `__globals__` reference (so changing Site Settings restyles the page), other colours
 * as values. Typography sizes come from the token scale per device; when a profile has no
 * tablet or mobile size, sizes are reduced by fixed ratios with a readable minimum, and
 * section padding likewise, so mobile is never just the desktop layout squeezed.
 */
final class ElementorMapper {

	/** Fallback when a profile has no section padding. */
	private const DEFAULT_PADDING = array(
		'desktop' => '80px 0',
		'tablet'  => '60px 0',
		'mobile'  => '40px 0',
	);

	/** Fallback type scale (px) when a profile has none. */
	public const DEFAULT_SCALE = array(
		'h1'   => 48,
		'h2'   => 36,
		'h3'   => 26,
		'h4'   => 21,
		'h5'   => 18,
		'h6'   => 16,
		'body' => 16,
	);

	private const DEVICE_RATIO = array(
		'tablet' => 0.85,
		'mobile' => 0.72,
	);

	private const MIN_FONT_PX = 15;

	private const DEFAULT_GAP_PX = 24;

	/**
	 * Tokens.
	 *
	 * @var array<string, mixed>
	 */
	private array $t;

	/**
	 * Kit colour hex => global id.
	 *
	 * @var array<string, string>
	 */
	private array $globals;

	/**
	 * Notes about fallbacks used.
	 *
	 * @var string[]
	 */
	private array $notes = array();

	/**
	 * Constructor.
	 *
	 * @param ElementorCompat       $compat       Installed Elementor.
	 * @param IdGenerator           $ids          ID generator.
	 * @param array<string, mixed>  $tokens       Brand tokens (DesignTokens schema; [] = none).
	 * @param array<string, string> $kit_colors   Kit colour id => hex (system and custom colours).
	 */
	public function __construct(
		private readonly ElementorCompat $compat,
		private readonly IdGenerator $ids,
		array $tokens = array(),
		array $kit_colors = array(),
	) {
		$this->t       = DesignTokens::normalize( $tokens );
		$this->globals = array();
		foreach ( $kit_colors as $id => $hex ) {
			$clean = DesignTokens::color( (string) $hex );
			if ( is_string( $id ) && preg_match( '/^[a-z0-9_-]{1,32}$/i', $id ) && null !== $clean && '' !== $clean && ! isset( $this->globals[ $clean ] ) ) {
				$this->globals[ $clean ] = $id;
			}
		}
	}

	/**
	 * Map a document.
	 *
	 * @param array<string, mixed> $doc Normalised PageDocument.
	 * @return array{elements: list<array<string, mixed>>, section_map: array<string, string>, notes: string[]}
	 */
	public function map( array $doc ): array {
		$this->notes = array();
		$elements    = array();
		$map         = array();
		foreach ( (array) $doc['sections'] as $index => $section ) {
			$mapped                = $this->mapSection( $section, 0 === $index );
			$map[ $section['id'] ] = $mapped[0]['id'];
			array_push( $elements, ...$mapped );
		}
		if ( ! $this->compat->containers ) {
			$this->notes[] = 'legacy_sections';
		}
		return array(
			'elements'    => $elements,
			'section_map' => $map,
			'notes'       => array_values( array_unique( $this->notes ) ),
		);
	}

	/**
	 * Map one section on its own (section regeneration, design library insertion).
	 *
	 * One section maps to one top-level element, except a legacy-layout grid section with a
	 * header, which becomes a header section followed by a grid section.
	 *
	 * @param array<string, mixed> $section Normalised section.
	 * @param bool                 $first   Whether it is the first section on the page.
	 * @return list<array<string, mixed>> Top-level elements (the first one carries the section).
	 */
	public function mapSection( array $section, bool $first = false ): array {
		return $this->compat->containers ? array( $this->sectionContainer( $section, $first ) ) : $this->sectionLegacy( $section, $first );
	}

	/* ------------------------------------------------------------ Containers */

	/**
	 * Section as a flexbox container.
	 *
	 * @param array<string, mixed> $s     Section.
	 * @param bool                 $first First section.
	 * @return array<string, mixed>
	 */
	private function sectionContainer( array $s, bool $first ): array {
		$layout   = $s['layout'];
		$gap      = $this->gapPx( $layout['gap'] );
		$settings = array(
			'content_width'  => $layout['content_width'],
			'flex_direction' => 'column',
			'flex_gap'       => self::gaps( $gap ),
		);
		if ( 'boxed' === $layout['content_width'] && '' !== $this->t['layout']['container_width'] ) {
			$settings['boxed_width'] = self::slider( $this->t['layout']['container_width'] );
		}
		if ( 'center' === $layout['align'] ) {
			$settings['flex_align_items'] = 'center';
		}
		if ( '' !== $layout['min_height'] ) {
			$settings['min_height'] = self::slider( $layout['min_height'] );
			$settings['flex_justify_content'] = 'center';
		}
		$this->padding( $settings, 'padding', $this->sectionPadding( $s['style']['padding'] ) );
		$this->background( $settings, $this->resolveColor( $s['style']['background'] ) );
		$this->backgroundImage( $settings, (string) ( $s['style']['background_image'] ?? '' ) );

		$text_color = $this->resolveColor( $s['style']['text_color'] );
		$children   = array();
		$kind       = $layout['kind'];
		$groups     = array();
		foreach ( $s['elements'] as $element ) {
			if ( 'stack' !== $kind && 'group' === $element['type'] ) {
				$groups[] = $element;
				continue;
			}
			$children[] = 'group' === $element['type']
				? $this->groupContainer( $element, null, $gap, $text_color, $layout['align'], $s['type'] )
				: $this->widget( $element, $text_color, $layout['align'], $s['type'], $first );
		}
		if ( array() !== $groups ) {
			$row      = array(
				'content_width'  => 'full',
				'flex_direction' => 'row',
				'flex_wrap'      => 'wrap',
				'flex_gap'       => self::gaps( $gap ),
				'padding'        => self::dims( '0' ),
			);
			$row_kids = array();
			foreach ( $groups as $group ) {
				$row_kids[] = $this->groupContainer( $group, $layout['columns'], $gap, $text_color, $layout['align'], $s['type'] );
			}
			$children[] = $this->element( 'container', $row, $row_kids, true );
		}
		return $this->element( 'container', $settings, $children );
	}

	/**
	 * A group (card or column) as a child container.
	 *
	 * @param array<string, mixed>      $g          Group.
	 * @param array<string, int>|null   $columns    Columns per device (null = full width).
	 * @param int                       $gap        Gap in px.
	 * @param string                    $text_color Section text colour.
	 * @param string                    $align      Alignment.
	 * @param string                    $type       Section type.
	 * @return array<string, mixed>
	 */
	private function groupContainer( array $g, ?array $columns, int $gap, string $text_color, string $align, string $type ): array {
		$settings = array(
			'content_width'  => 'full',
			'flex_direction' => 'column',
			'flex_gap'       => self::gaps( (int) round( $gap * 0.66 ) ),
		);
		if ( null !== $columns ) {
			foreach ( array( 'desktop' => '', 'tablet' => '_tablet', 'mobile' => '_mobile' ) as $device => $suffix ) {
				$settings[ 'width' . $suffix ] = self::columnWidth( $columns[ $device ], $gap );
			}
		}
		$style = $g['style'];
		$is_card = in_array( $type, array( 'cards', 'features', 'testimonials', 'statistics' ), true );
		$bg      = $this->resolveColor( $style['background'] ) ?: ( $is_card ? ( $this->t['components']['card']['background'] ?: $this->t['colors']['surface'] ) : '' );
		$radius  = $style['radius'] ?: ( $is_card ? $this->t['components']['card']['radius'] : '' );
		$shadow  = $style['shadow'] ?: ( $is_card ? $this->t['components']['card']['shadow'] : '' );
		$padding = $style['padding'] ?: ( $is_card || '' !== $bg ? ( $this->t['components']['card']['padding'] ?: '32px' ) : '' );
		$this->background( $settings, $bg );
		if ( '' !== $radius ) {
			$settings['border_radius'] = self::dims( $radius );
		}
		if ( '' !== $shadow && 'none' !== $shadow ) {
			$parsed = self::shadow( $shadow );
			if ( array() !== $parsed ) {
				$settings['box_shadow_box_shadow_type'] = 'yes';
				$settings['box_shadow_box_shadow']      = $parsed;
			}
		}
		if ( '' !== $padding ) {
			$settings['padding'] = self::dims( $padding );
			// Cards keep a little less padding on phones.
			$mobile = self::scaleBox( $padding, 0.75 );
			if ( '' !== $mobile ) {
				$settings['padding_mobile'] = self::dims( $mobile );
			}
		}
		if ( 'center' === $align ) {
			$settings['flex_align_items'] = 'center';
		}
		$kids = array();
		foreach ( $g['elements'] as $element ) {
			$kids[] = $this->widget( $element, $text_color, $align, $type, false );
		}
		return $this->element( 'container', $settings, $kids, true );
	}

	/* ---------------------------------------------------------------- Legacy */

	/**
	 * Section as legacy section > column(s). Legacy rows cannot hold a full-width header
	 * above a row of columns, so a grid section with a header becomes two sections.
	 *
	 * @param array<string, mixed> $s     Section.
	 * @param bool                 $first First section.
	 * @return list<array<string, mixed>>
	 */
	private function sectionLegacy( array $s, bool $first ): array {
		$layout   = $s['layout'];
		$settings = array( 'layout' => 'full' === $layout['content_width'] ? 'full_width' : 'boxed' );
		if ( 'boxed' === $layout['content_width'] && '' !== $this->t['layout']['container_width'] ) {
			$settings['content_width'] = self::slider( $this->t['layout']['container_width'] );
		}
		if ( '' !== $layout['min_height'] ) {
			$settings['height']        = 'min-height';
			$settings['custom_height'] = self::slider( $layout['min_height'] );
		}
		$this->background( $settings, $this->resolveColor( $s['style']['background'] ) );
		$this->backgroundImage( $settings, (string) ( $s['style']['background_image'] ?? '' ) );
		$padding    = $this->sectionPadding( $s['style']['padding'] );
		$text_color = $this->resolveColor( $s['style']['text_color'] );

		$header = array();
		$groups = array();
		foreach ( $s['elements'] as $element ) {
			if ( 'stack' !== $layout['kind'] && 'group' === $element['type'] ) {
				$groups[] = $element;
			} else {
				$header[] = $element;
			}
		}

		$sections = array();
		if ( array() !== $header ) {
			$widgets = array();
			foreach ( $header as $element ) {
				// Groups inside a stack are flattened: inner sections are not generated.
				foreach ( 'group' === $element['type'] ? $element['elements'] : array( $element ) as $item ) {
					$widgets[] = $this->widget( $item, $text_color, $layout['align'], $s['type'], $first );
				}
				if ( 'group' === $element['type'] ) {
					$this->notes[] = 'legacy_flattened_group';
				}
			}
			$own = $settings;
			// With a grid below, the header keeps the top padding and the grid the bottom one.
			$this->padding( $own, 'padding', array() === $groups ? $padding : self::edges( $padding, true ) );
			$sections[] = $this->element( 'section', $own, array( $this->element( 'column', array( '_column_size' => 100 ), $widgets ) ) );
		}
		if ( array() !== $groups ) {
			$own = $settings;
			$this->padding( $own, 'padding', array() === $header ? $padding : self::edges( $padding, false ) );
			$sections[] = $this->element( 'section', $own, $this->legacyColumns( $groups, $layout, $text_color, $s['type'] ) );
			if ( array() !== $header ) {
				$this->notes[] = 'legacy_two_sections';
			}
		}
		return $sections;
	}

	/**
	 * Keep only the top (or bottom) part of vertical padding per device.
	 *
	 * @param array<string, string> $boxes Boxes.
	 * @param bool                  $top   Keep top (else bottom).
	 * @return array<string, string>
	 */
	private static function edges( array $boxes, bool $top ): array {
		$out = array();
		foreach ( $boxes as $device => $box ) {
			$d              = self::dims( $box );
			$out[ $device ] = $top ? "{$d['top']}{$d['unit']} {$d['right']}{$d['unit']} 0 {$d['left']}{$d['unit']}" : "0 {$d['right']}{$d['unit']} {$d['bottom']}{$d['unit']} {$d['left']}{$d['unit']}";
			$out[ $device ] = (string) preg_replace( '/\b0(px|em|rem|%|vw|vh)\b/', '0', $out[ $device ] );
		}
		return $out;
	}

	/**
	 * Legacy columns for groups.
	 *
	 * @param array<int, array<string, mixed>> $groups     Groups.
	 * @param array<string, mixed>             $layout     Layout.
	 * @param string                           $text_color Text colour.
	 * @param string                           $type       Section type.
	 * @return list<array<string, mixed>>
	 */
	private function legacyColumns( array $groups, array $layout, string $text_color, string $type ): array {
		$columns = array();
		$per_row = max( 1, (int) $layout['columns']['desktop'] );
		foreach ( $groups as $group ) {
			$settings = array(
				'_column_size'        => (int) floor( 100 / min( $per_row, count( $groups ) ) ),
				'_inline_size'        => round( 100 / min( $per_row, count( $groups ) ), 3 ),
				'_inline_size_tablet' => round( 100 / max( 1, (int) $layout['columns']['tablet'] ), 3 ),
				'_inline_size_mobile' => 100,
			);
			$bg = $this->resolveColor( $group['style']['background'] ) ?: ( 'cards' === $type ? $this->t['components']['card']['background'] : '' );
			$this->background( $settings, $bg );
			$radius = $group['style']['radius'] ?: ( 'cards' === $type ? $this->t['components']['card']['radius'] : '' );
			if ( '' !== $radius ) {
				$settings['border_radius'] = self::dims( $radius );
			}
			$padding = $group['style']['padding'] ?: ( '' !== $bg ? '32px' : '' );
			if ( '' !== $padding ) {
				$settings['padding'] = self::dims( $padding );
			}
			$widgets = array();
			foreach ( $group['elements'] as $element ) {
				$widgets[] = $this->widget( $element, $text_color, $layout['align'], $type, false );
			}
			$columns[] = $this->element( 'column', $settings, $widgets );
		}
		return $columns;
	}

	/* --------------------------------------------------------------- Widgets */

	/**
	 * One element as a widget.
	 *
	 * @param array<string, mixed> $e          Element.
	 * @param string               $text_color Section text colour override.
	 * @param string               $align      Section alignment.
	 * @param string               $type       Section type.
	 * @param bool                 $first      In the first section.
	 * @return array<string, mixed>
	 */
	private function widget( array $e, string $text_color, string $align, string $type, bool $first ): array {
		$widget = $this->widgetCore( $e, $text_color, $align, $type, $first );
		if ( isset( $e['style'] ) && is_array( $e['style'] ) ) {
			$this->applyStyle( $widget, $e['style'] );
		}
		return $widget;
	}

	/**
	 * Apply an element's own style overrides to the widget built for it.
	 *
	 * @param array<string, mixed> $w     Widget element (modified).
	 * @param array<string, mixed> $style PageDocument element style.
	 */
	private function applyStyle( array &$w, array $style ): void {
		$type = (string) ( $w['widgetType'] ?? '' );
		$s    = &$w['settings'];
		$keys = array(
			'heading'     => array( 'color' => 'title_color', 'typography' => 'typography' ),
			'text-editor' => array( 'color' => 'text_color', 'typography' => 'typography' ),
			'button'      => array( 'color' => 'button_text_color', 'typography' => 'typography' ),
			'icon-list'   => array( 'color' => 'text_color', 'typography' => 'icon_typography' ),
			'icon'        => array( 'color' => 'primary_color' ),
			'image'       => array(),
		)[ $type ] ?? null;
		if ( null === $keys ) {
			return; // A fallback replaced the element (for example a list rendered as text).
		}
		$color = $this->resolveColor( (string) ( $style['color'] ?? '' ) );
		if ( '' !== $color && isset( $keys['color'] ) ) {
			unset( $s['__globals__'][ $keys['color'] ] );
			$this->color( $s, $keys['color'], $color );
		}
		if ( isset( $keys['typography'] ) ) {
			$prefix = $keys['typography'];
			$font   = array(
				'family' => (string) ( $style['font_family'] ?? '' ),
				'weight' => (string) ( $style['font_weight'] ?? '' ),
			);
			$sizes  = (array) ( $style['font_size'] ?? array() );
			if ( '' !== $font['family'] || '' !== $font['weight'] || '' !== (string) ( $sizes['desktop'] ?? '' ) ) {
				$s[ $prefix . '_typography' ] = 'custom';
				if ( '' !== $font['family'] ) {
					$s[ $prefix . '_font_family' ] = explode( ',', $font['family'] )[0];
				}
				if ( '' !== $font['weight'] ) {
					$s[ $prefix . '_font_weight' ] = $font['weight'];
				}
				$desktop = (string) ( $sizes['desktop'] ?? '' );
				if ( '' !== $desktop ) {
					$s[ $prefix . '_font_size' ] = self::slider( $desktop );
					foreach ( array( 'tablet', 'mobile' ) as $device ) {
						$value = (string) ( $sizes[ $device ] ?? '' );
						// Large type only shrinks on small screens; body-size text keeps its size.
						if ( '' === $value && 'heading' === $type ) {
							$value = self::scaleLength( $desktop, self::DEVICE_RATIO[ $device ], self::MIN_FONT_PX );
						}
						if ( '' !== $value ) {
							$s[ $prefix . '_font_size_' . $device ] = self::slider( $value );
						}
					}
				}
			}
			if ( '' !== (string) ( $style['line_height'] ?? '' ) ) {
				$s[ $prefix . '_line_height' ] = self::lineHeight( (string) $style['line_height'] );
			}
		}
		if ( 'icon' === $type && '' !== (string) ( $style['font_size']['desktop'] ?? '' ) ) {
			$s['size'] = self::slider( (string) $style['font_size']['desktop'] );
		}
		if ( 'button' === $type ) {
			$bg = $this->resolveColor( (string) ( $style['background'] ?? '' ) );
			if ( '' !== $bg ) {
				unset( $s['__globals__']['background_color'] );
				$s['background_background'] = 'classic';
				$this->color( $s, 'background_color', $bg );
				if ( '' === $color ) {
					unset( $s['__globals__']['button_text_color'] );
					$s['button_text_color'] = self::contrastText( substr( $bg, 0, 7 ) );
				}
			}
			if ( '' !== (string) ( $style['padding'] ?? '' ) ) {
				$s['text_padding'] = self::dims( (string) $style['padding'] );
			}
			$border = $this->resolveColor( (string) ( $style['border_color'] ?? '' ) );
			if ( '' !== $border && '' !== (string) ( $style['border_width'] ?? '' ) ) {
				$s['border_border'] = 'solid';
				$s['border_width']  = self::dims( (string) $style['border_width'] );
				unset( $s['__globals__']['border_color'] );
				$this->color( $s, 'border_color', $border );
			}
		}
		if ( '' !== (string) ( $style['radius'] ?? '' ) ) {
			$key = 'image' === $type ? 'image_border_radius' : ( 'button' === $type ? 'border_radius' : '' );
			if ( '' !== $key ) {
				$s[ $key ] = self::dims( (string) $style['radius'] );
			}
		}
		if ( in_array( $type, array( 'heading', 'text-editor', 'button', 'image', 'icon' ), true ) ) {
			foreach ( array( 'tablet', 'mobile' ) as $device ) {
				$a = (string) ( $style['align'][ $device ] ?? '' );
				if ( '' !== $a ) {
					$s[ 'align_' . $device ] = $a;
				}
			}
		}
	}

	/**
	 * Build the widget for an element (styles from the brand profile).
	 *
	 * @param array<string, mixed> $e          Element.
	 * @param string               $text_color Section text colour override.
	 * @param string               $align      Section alignment.
	 * @param string               $type       Section type.
	 * @param bool                 $first      In the first section.
	 * @return array<string, mixed>
	 */
	private function widgetCore( array $e, string $text_color, string $align, string $type, bool $first ): array {
		$align = '' !== ( $e['align'] ?? '' ) ? $e['align'] : $align;
		switch ( $e['type'] ) {
			case 'heading':
				$s = array(
					'title'       => self::escape( $e['text'] ),
					'header_size' => $e['level'],
				);
				if ( 'left' !== $align ) {
					$s['align'] = $align;
				}
				$this->color( $s, 'title_color', $text_color ?: $this->t['colors']['heading'] );
				$this->typography( $s, 'typography', $this->t['typography']['heading'], $e['level'] );
				if ( '' !== $this->t['typography']['line_height']['heading'] ) {
					$s['typography_line_height'] = self::lineHeight( $this->t['typography']['line_height']['heading'] );
				}
				return $this->element( 'widget', $s, array(), false, 'heading' );

			case 'text':
				$s = array( 'editor' => $e['html'] );
				if ( 'left' !== $align ) {
					$s['align'] = $align;
				}
				$this->color( $s, 'text_color', $text_color ?: $this->t['colors']['text'] );
				$this->typography( $s, 'typography', $this->t['typography']['body'], 'body' );
				if ( '' !== $this->t['typography']['line_height']['body'] ) {
					$s['typography_line_height'] = self::lineHeight( $this->t['typography']['line_height']['body'] );
				}
				return $this->element( 'widget', $s, array(), false, 'text-editor' );

			case 'button':
				return $this->button( $e, $align, $type, $first );

			case 'image':
				$s = array(
					'image'      => array(
						'url'    => $e['url'],
						'id'     => $e['attachment_id'] > 0 ? $e['attachment_id'] : '',
						'alt'    => $e['alt'],
						'source' => $e['attachment_id'] > 0 ? 'library' : 'url',
					),
					'image_size' => 'large',
				);
				if ( '' !== $e['caption'] ) {
					$s['caption_source'] = 'custom';
					$s['caption']        = self::escape( $e['caption'] );
				}
				if ( '' !== $this->t['components']['image']['radius'] ) {
					$s['image_border_radius'] = self::dims( $this->t['components']['image']['radius'] );
				}
				if ( 'left' !== $align ) {
					$s['align'] = $align;
				}
				if ( 0 === $e['attachment_id'] ) {
					$this->notes[] = 'external_image';
				}
				return $this->element( 'widget', $s, array(), false, 'image' );

			case 'icon':
				if ( ! $this->compat->has( 'icon' ) ) {
					$this->notes[] = 'icon_skipped';
					return $this->spacerWidget( '8px' );
				}
				$s = array(
					'selected_icon' => array(
						'value'   => 'fas fa-' . $e['icon'],
						'library' => 'fa-solid',
					),
					'align'         => $align,
				);
				$this->color( $s, 'primary_color', $this->t['colors']['accent'] ?: $this->t['colors']['primary'] );
				return $this->element( 'widget', $s, array(), false, 'icon' );

			case 'list':
				if ( ! $this->compat->has( 'icon-list' ) ) {
					$this->notes[] = 'list_as_text';
					return $this->widget(
						array(
							'type'  => 'text',
							'html'  => '<ul>' . implode( '', array_map( static fn ( string $i ): string => '<li>' . self::escape( $i ) . '</li>', $e['items'] ) ) . '</ul>',
							'align' => '',
						),
						$text_color,
						$align,
						$type,
						$first
					);
				}
				$items = array();
				foreach ( $e['items'] as $item ) {
					$items[] = array(
						'_id'           => $this->ids->next(),
						'text'          => self::escape( $item ),
						'selected_icon' => array(
							'value'   => 'fas fa-check',
							'library' => 'fa-solid',
						),
					);
				}
				$s = array( 'icon_list' => $items );
				$this->color( $s, 'icon_color', $this->t['colors']['accent'] ?: $this->t['colors']['primary'] );
				$this->color( $s, 'text_color', $text_color ?: $this->t['colors']['text'] );
				return $this->element( 'widget', $s, array(), false, 'icon-list' );

			case 'accordion':
				if ( ! $this->compat->has( 'accordion' ) ) {
					// Fallback: question headings with answers, still native and editable.
					$this->notes[] = 'accordion_as_headings';
					$html = '';
					foreach ( $e['items'] as $item ) {
						$html .= '<h3>' . self::escape( $item['title'] ) . '</h3>' . $item['html'];
					}
					return $this->widget(
						array(
							'type'  => 'text',
							'html'  => $html,
							'align' => '',
						),
						$text_color,
						$align,
						$type,
						$first
					);
				}
				$tabs = array();
				foreach ( $e['items'] as $item ) {
					$tabs[] = array(
						'_id'         => $this->ids->next(),
						'tab_title'   => self::escape( $item['title'] ),
						'tab_content' => $item['html'],
					);
				}
				$s = array(
					'tabs'           => $tabs,
					'title_html_tag' => 'h3',
				);
				if ( 'faq' === $type ) {
					$s['faq_schema'] = 'yes';
				}
				$this->color( $s, 'title_color', $this->t['colors']['heading'] );
				$this->color( $s, 'tab_active_color', $this->t['colors']['primary'] );
				$this->color( $s, 'content_color', $this->t['colors']['text'] );
				$this->typography( $s, 'title_typography', $this->t['typography']['heading'], '' );
				$this->typography( $s, 'content_typography', $this->t['typography']['body'], '' );
				return $this->element( 'widget', $s, array(), false, 'accordion' );

			case 'spacer':
				return $this->spacerWidget( $e['height'] );

			case 'video':
				if ( ! $this->compat->has( 'video' ) ) {
					$this->notes[] = 'video_skipped';
					return $this->spacerWidget( '8px' );
				}
				$s = array( 'video_type' => $e['provider'] );
				$s[ 'youtube' === $e['provider'] ? 'youtube_url' : 'vimeo_url' ] = $e['url'];
				return $this->element( 'widget', $s, array(), false, 'video' );

			case 'html':
				$this->notes[] = 'html_fallback';
				return $this->element( 'widget', array( 'html' => $e['html'] ), array(), false, 'html' );

			case 'divider':
				if ( ! $this->compat->has( 'divider' ) ) {
					return $this->spacerWidget( '24px' );
				}
				$s = array();
				$this->color( $s, 'color', $this->t['colors']['border'] );
				return $this->element( 'widget', $s, array(), false, 'divider' );
		}
		throw new \InvalidArgumentException( 'Unmappable element type.' );
	}

	/**
	 * Button widget styled from the tokens.
	 *
	 * @param array<string, mixed> $e     Button element.
	 * @param string               $align Alignment.
	 * @param string               $type  Section type.
	 * @param bool                 $first In the first section.
	 * @return array<string, mixed>
	 */
	private function button( array $e, string $align, string $type, bool $first ): array {
		$s = array(
			'text' => self::escape( $e['text'] ),
			'link' => array(
				'url'               => $e['url'],
				'is_external'       => '',
				'nofollow'          => '',
				'custom_attributes' => '',
			),
		);
		if ( 'left' !== $align ) {
			$s['align'] = $align;
		}
		// Hero and CTA buttons span the width on phones (stacked, easy to tap).
		if ( $first || in_array( $type, array( 'hero', 'cta' ), true ) ) {
			$s['align_mobile'] = 'justify';
		}
		$button  = $this->t['components']['button'];
		$primary = $button['background'] ?: ( $this->t['colors']['primary'] ?: $this->t['colors']['accent'] );
		if ( 'secondary' === $e['variant'] ) {
			$s['background_background'] = 'classic';
			$s['background_color']      = '#00000000';
			$this->color( $s, 'button_text_color', $primary );
			$s['border_border'] = 'solid';
			$s['border_width']  = self::dims( '2px' );
			$this->color( $s, 'border_color', $primary );
		} elseif ( '' !== $primary ) {
			$s['background_background'] = 'classic';
			$this->color( $s, 'background_color', $primary );
			$this->color( $s, 'button_text_color', $button['color'] ?: self::contrastText( $primary ) );
		}
		if ( '' !== $button['radius'] ) {
			$s['border_radius'] = self::dims( $button['radius'] );
		}
		if ( '' !== $button['padding'] ) {
			$s['text_padding'] = self::dims( $button['padding'] );
		}
		$this->typography( $s, 'typography', $this->t['typography']['button'], '' );
		return $this->element( 'widget', $s, array(), false, 'button' );
	}

	/**
	 * Spacer widget.
	 *
	 * @param string $height Height.
	 * @return array<string, mixed>
	 */
	private function spacerWidget( string $height ): array {
		return $this->element( 'widget', array( 'space' => self::slider( $height ) ), array(), false, 'spacer' );
	}

	/* --------------------------------------------------------------- Helpers */

	/**
	 * Build an element.
	 *
	 * @param string               $type     elType.
	 * @param array<string, mixed> $settings Settings.
	 * @param array<int, mixed>    $children Children.
	 * @param bool                 $inner    Inner container.
	 * @param string               $widget   Widget type (widgets only).
	 * @return array<string, mixed>
	 */
	private function element( string $type, array $settings, array $children = array(), bool $inner = false, string $widget = '' ): array {
		$el = array(
			'id'       => $this->ids->next(),
			'elType'   => $type,
			'settings' => $settings,
			'elements' => array_values( $children ),
			'isInner'  => $inner,
		);
		if ( 'widget' === $type ) {
			$el['widgetType'] = $widget;
			unset( $el['isInner'] );
		}
		return $el;
	}

	/**
	 * Set a colour: as a global reference when it is a kit colour.
	 *
	 * @param array<string, mixed> $s   Settings.
	 * @param string               $key Setting key.
	 * @param string               $hex Colour.
	 */
	private function color( array &$s, string $key, string $hex ): void {
		if ( '' === $hex ) {
			return;
		}
		if ( isset( $this->globals[ $hex ] ) ) {
			$s['__globals__'][ $key ] = 'globals/colors?id=' . $this->globals[ $hex ];
			return;
		}
		$s[ $key ] = $hex;
	}

	/**
	 * Classic background colour.
	 *
	 * @param array<string, mixed> $s   Settings.
	 * @param string               $hex Colour.
	 */
	private function background( array &$s, string $hex ): void {
		if ( '' === $hex ) {
			return;
		}
		$s['background_background'] = 'classic';
		$this->color( $s, 'background_color', $hex );
	}

	/**
	 * Background image (cover, centred) from an http(s) URL.
	 *
	 * @param array<string, mixed> $s   Settings.
	 * @param string               $url Image URL ("" = none).
	 */
	private function backgroundImage( array &$s, string $url ): void {
		if ( '' === $url ) {
			return;
		}
		$s['background_background'] = 'classic';
		$s['background_image']      = array(
			'url'    => $url,
			'id'     => '',
			'source' => 'url',
		);
		$s['background_size']       = 'cover';
		$s['background_position']   = 'center center';
		$this->notes[]              = 'external_image';
	}

	/**
	 * Responsive padding settings.
	 *
	 * @param array<string, mixed>  $s     Settings.
	 * @param string                $key   Key.
	 * @param array<string, string> $boxes Box per device.
	 */
	private function padding( array &$s, string $key, array $boxes ): void {
		foreach ( array( 'desktop' => '', 'tablet' => '_tablet', 'mobile' => '_mobile' ) as $device => $suffix ) {
			if ( '' !== ( $boxes[ $device ] ?? '' ) ) {
				$s[ $key . $suffix ] = self::dims( $boxes[ $device ] );
			}
		}
	}

	/**
	 * Section padding per device: section style, then profile, then defaults; missing
	 * smaller devices are derived from the next larger one.
	 *
	 * @param array<string, string> $own Section's own padding.
	 * @return array<string, string>
	 */
	private function sectionPadding( array $own ): array {
		$source = '' !== ( $own['desktop'] ?? '' ) ? $own : $this->t['spacing']['section_padding'];
		if ( '' === ( $source['desktop'] ?? '' ) ) {
			return self::DEFAULT_PADDING;
		}
		$out = array( 'desktop' => $source['desktop'] );
		$out['tablet'] = '' !== ( $source['tablet'] ?? '' ) ? $source['tablet'] : self::scaleBox( $out['desktop'], 0.75 );
		$out['mobile'] = '' !== ( $source['mobile'] ?? '' ) ? $source['mobile'] : self::scaleBox( $out['desktop'], 0.5 );
		return $out;
	}

	/**
	 * Typography group settings for a role.
	 *
	 * @param array<string, mixed>  $s      Settings.
	 * @param string                $prefix Group prefix ("typography", "title_typography", ...).
	 * @param array<string, string> $font   Font {family, weight}.
	 * @param string                $size   Scale key (h1..h6, body, or "" for no size).
	 */
	private function typography( array &$s, string $prefix, array $font, string $size ): void {
		$sizes = '' === $size ? array() : $this->scale( $size );
		if ( '' === $font['family'] && '' === $font['weight'] && array() === $sizes ) {
			return;
		}
		$s[ $prefix . '_typography' ] = 'custom';
		if ( '' !== $font['family'] ) {
			$s[ $prefix . '_font_family' ] = explode( ',', $font['family'] )[0];
		}
		if ( '' !== $font['weight'] ) {
			$s[ $prefix . '_font_weight' ] = $font['weight'];
		}
		foreach ( $sizes as $suffix => $value ) {
			$s[ $prefix . '_font_size' . $suffix ] = self::slider( $value );
		}
	}

	/**
	 * Font size per device for a scale key.
	 *
	 * @param string $key h1..h6|body.
	 * @return array<string, string> Suffix => length.
	 */
	private function scale( string $key ): array {
		$scale   = $this->t['typography']['scale'][ $key ];
		$desktop = $scale['desktop'];
		if ( '' === $desktop ) {
			if ( 'body' === $key && '' === $scale['tablet'] && '' === $scale['mobile'] ) {
				return array(); // Body text inherits the theme's size.
			}
			$desktop = self::DEFAULT_SCALE[ $key ] . 'px';
		}
		$out = array( '' => $desktop );
		foreach ( array( 'tablet', 'mobile' ) as $device ) {
			$value = $scale[ $device ];
			if ( '' === $value && 'body' !== $key ) {
				$value = self::scaleLength( $desktop, self::DEVICE_RATIO[ $device ], self::MIN_FONT_PX );
			}
			if ( '' !== $value ) {
				$out[ '_' . $device ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Resolve "token:role" or a colour.
	 *
	 * @param string $ref Reference.
	 */
	private function resolveColor( string $ref ): string {
		if ( str_starts_with( $ref, 'token:' ) ) {
			return (string) ( $this->t['colors'][ substr( $ref, 6 ) ] ?? '' );
		}
		return $ref;
	}

	/**
	 * Gap in px from a layout gap or the profile.
	 *
	 * @param string $gap Layout gap.
	 */
	private function gapPx( string $gap ): int {
		foreach ( array( $gap, $this->t['spacing']['element_gap'] ) as $value ) {
			if ( preg_match( '/^(\d+(?:\.\d+)?)px$/', $value, $m ) ) {
				return (int) round( (float) $m[1] );
			}
			if ( preg_match( '/^(\d+(?:\.\d+)?)r?em$/', $value, $m ) ) {
				return (int) round( (float) $m[1] * 16 );
			}
		}
		return self::DEFAULT_GAP_PX;
	}

	/**
	 * Width of one of n columns separated by gaps.
	 *
	 * @param int $n   Columns.
	 * @param int $gap Gap px.
	 * @return array<string, mixed>
	 */
	public static function columnWidth( int $n, int $gap ): array {
		if ( $n <= 1 ) {
			return array(
				'unit'  => '%',
				'size'  => 100,
				'sizes' => array(),
			);
		}
		return array(
			'unit'  => 'custom',
			'size'  => sprintf( 'calc((100%% - %dpx) / %d)', ( $n - 1 ) * $gap, $n ),
			'sizes' => array(),
		);
	}

	/**
	 * Elementor GAPS control value.
	 *
	 * @param int $px Gap.
	 * @return array<string, mixed>
	 */
	public static function gaps( int $px ): array {
		return array(
			'column'   => (string) $px,
			'row'      => (string) $px,
			'isLinked' => true,
			'unit'     => 'px',
			'size'     => $px,
		);
	}

	/**
	 * CSS length → slider value.
	 *
	 * @param string $length Length.
	 * @return array<string, mixed>
	 */
	public static function slider( string $length ): array {
		if ( preg_match( '/^(-?\d+(?:\.\d+)?)(px|em|rem|%|vw|vh)?$/', $length, $m ) ) {
			return array(
				'unit'  => $m[2] ?? 'px',
				'size'  => (float) $m[1] == (int) $m[1] ? (int) $m[1] : (float) $m[1], // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- integral check.
				'sizes' => array(),
			);
		}
		return array(
			'unit'  => 'px',
			'size'  => '',
			'sizes' => array(),
		);
	}

	/**
	 * Line height (unitless numbers are em in Elementor).
	 *
	 * @param string $value Value.
	 * @return array<string, mixed>
	 */
	private static function lineHeight( string $value ): array {
		return preg_match( '/^\d+(\.\d+)?$/', $value ) ? array(
			'unit'  => 'em',
			'size'  => (float) $value,
			'sizes' => array(),
		) : self::slider( $value );
	}

	/**
	 * CSS box (1–4 lengths) → dimensions value. Mixed units fall back to the first unit.
	 *
	 * @param string $box Box.
	 * @return array<string, mixed>
	 */
	public static function dims( string $box ): array {
		$parts = preg_split( '/\s+/', trim( $box ) );
		$parts = match ( count( $parts ) ) {
			1 => array( $parts[0], $parts[0], $parts[0], $parts[0] ),
			2 => array( $parts[0], $parts[1], $parts[0], $parts[1] ),
			3 => array( $parts[0], $parts[1], $parts[2], $parts[1] ),
			default => array_slice( $parts, 0, 4 ),
		};
		$unit   = 'px';
		$values = array();
		foreach ( $parts as $part ) {
			if ( preg_match( '/^(-?\d+(?:\.\d+)?)(px|em|rem|%|vw|vh)?$/', $part, $m ) ) {
				if ( isset( $m[2] ) && '0' !== $m[1] ) {
					$unit = $m[2];
				}
				$values[] = $m[1];
			} else {
				$values[] = '0';
			}
		}
		return array(
			'unit'     => $unit,
			'top'      => $values[0],
			'right'    => $values[1],
			'bottom'   => $values[2],
			'left'     => $values[3],
			'isLinked' => count( array_unique( $values ) ) === 1,
		);
	}

	/**
	 * Shadow token → Elementor box-shadow value.
	 *
	 * @param string $shadow "x y blur spread #colour" (DesignTokens::shadow format).
	 * @return array<string, mixed>
	 */
	public static function shadow( string $shadow ): array {
		if ( ! preg_match( '/^(inset\s+)?((?:-?\d+(?:\.\d+)?(?:px)?\s+){2,4})(#[0-9A-F]{6,8})$/i', trim( $shadow ), $m ) ) {
			return array();
		}
		$n   = array_map( static fn ( string $v ): float => (float) $v, preg_split( '/\s+/', trim( $m[2] ) ) );
		$hex = strtoupper( $m[3] );
		$a   = 9 === strlen( $hex ) ? round( hexdec( substr( $hex, 7, 2 ) ) / 255, 2 ) : 1;
		return array(
			'horizontal' => $n[0],
			'vertical'   => $n[1],
			'blur'       => $n[2] ?? 0,
			'spread'     => $n[3] ?? 0,
			'color'      => sprintf( 'rgba(%d,%d,%d,%s)', hexdec( substr( $hex, 1, 2 ) ), hexdec( substr( $hex, 3, 2 ) ), hexdec( substr( $hex, 5, 2 ) ), (string) $a ),
		);
	}

	/**
	 * Scale a length (px/rem/em) by a factor, with a minimum in px.
	 *
	 * @param string $length Length.
	 * @param float  $factor Factor.
	 * @param int    $min_px Minimum (px equivalent).
	 */
	public static function scaleLength( string $length, float $factor, int $min_px = 0 ): string {
		if ( ! preg_match( '/^(\d+(?:\.\d+)?)(px|rem|em)$/', $length, $m ) ) {
			return '';
		}
		$value = (float) $m[1] * $factor;
		$min   = 'px' === $m[2] ? $min_px : $min_px / 16;
		$value = max( $value, $min, 0 );
		return ( 'px' === $m[2] ? (string) (int) round( $value ) : rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' ) ) . $m[2];
	}

	/**
	 * Scale every length of a box.
	 *
	 * @param string $box    Box.
	 * @param float  $factor Factor.
	 */
	public static function scaleBox( string $box, float $factor ): string {
		$out = array();
		foreach ( preg_split( '/\s+/', trim( $box ) ) as $part ) {
			if ( '0' === $part ) {
				$out[] = '0';
				continue;
			}
			$scaled = self::scaleLength( $part, $factor );
			if ( '' === $scaled ) {
				return '';
			}
			$out[] = $scaled;
		}
		return implode( ' ', $out );
	}

	/**
	 * Readable text colour on a background (WCAG relative luminance).
	 *
	 * @param string $hex Background.
	 */
	public static function contrastText( string $hex ): string {
		$c = array();
		foreach ( array( 1, 3, 5 ) as $i ) {
			$v   = hexdec( substr( $hex, $i, 2 ) ) / 255;
			$c[] = $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4;
		}
		$l = 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
		// Contrast with white vs. with black.
		return ( 1.05 / ( $l + 0.05 ) ) >= ( ( $l + 0.05 ) / 0.05 ) ? '#FFFFFF' : '#111111';
	}

	/**
	 * Escape plain text for a widget text setting.
	 *
	 * @param string $text Text.
	 */
	private static function escape( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8', false );
	}
}
