<?php
/**
 * Build Elementor element data from plugin content using native free widgets.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Elementor;

use AiSeoContentStudio\Content\ContentModel;

defined( 'ABSPATH' ) || exit;

/**
 * Produces the `elements` array Elementor stores in `_elementor_data`.
 *
 * Only core widgets that ship with Elementor (free) are used: heading, text-editor,
 * button and icon-list, inside containers (flexbox) or, when the container feature is
 * off, inside sections with one full-width column. Setting names were checked against
 * Elementor 4.0 source (includes/widgets/*.php, includes/elements/*.php).
 *
 * Two styles:
 * - content: plain widgets that inherit the theme and global kit styles; the theme shows the title.
 * - designed: a header band, padded sections with alternating backgrounds, a FAQ band and a
 *   closing call to action. When the theme title can be hidden, the band carries the H1 and the
 *   document hides the theme title; otherwise the theme's title stays the only H1.
 */
class LayoutBuilder {

	public const STYLE_CONTENT  = 'content';
	public const STYLE_DESIGNED = 'designed';

	/** Widgets this builder may emit. */
	public const WIDGETS = array( 'heading', 'text-editor', 'button', 'icon-list', 'image', 'shortcode' );

	/** Light neutral and accent backgrounds for the designed style. */
	private const BG_ALT    = '#F5F7FA';
	private const BG_ACCENT = '#EEF3FB';

	/**
	 * Element ids used in this document.
	 *
	 * @var array<string, true>
	 */
	protected array $ids = array();

	/**
	 * Id generator (injectable for tests).
	 *
	 * @var callable(): string
	 */
	protected $id_source;

	/**
	 * Constructor.
	 *
	 * @param bool          $containers Use containers (true) or sections/columns (false).
	 * @param string        $style      content | designed.
	 * @param callable|null $id_source  Returns candidate ids.
	 * @param bool          $own_h1     Designed style may replace the theme title with its own H1.
	 */
	public function __construct(
		protected readonly bool $containers = true,
		protected readonly string $style = self::STYLE_CONTENT,
		?callable $id_source = null,
		protected readonly bool $own_h1 = true,
	) {
		$this->id_source = $id_source ?? static fn (): string => substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
	}

	/**
	 * Build the document.
	 *
	 * @param array<string, mixed>                                                     $content  Content model.
	 * @param array<string, mixed>                                                     $cta      Optional call to action: text, url.
	 * @param array<string, array{id: int, url: string, alt: string, caption: string}> $images Images to place after sections, keyed by section id.
	 * @return array{elements: array<int, array<string, mixed>>, settings: array<string, string>}
	 */
	public function build( array $content, array $cta = array(), array $images = array() ): array {
		$designed = self::STYLE_DESIGNED === $this->style;
		$cta_text = trim( (string) ( $cta['text'] ?? '' ) );
		$cta_url  = trim( (string) ( $cta['url'] ?? '' ) );
		$button   = '' !== $cta_text && '' !== $cta_url ? $this->button( $cta_text, $cta_url ) : null;
		$blocks   = array();

		// Introduction (and, in the designed style, the page H1 and a first CTA).
		$intro = array();
		if ( $designed && $this->own_h1 ) {
			$h1 = trim( (string) ( '' !== (string) ( $content['h1'] ?? '' ) ? $content['h1'] : ( $content['title'] ?? '' ) ) );
			if ( '' !== $h1 ) {
				$intro[] = $this->heading( $h1, 'h1' );
			}
		}
		$intro = array_merge( $intro, $this->chunks( (string) ( $content['intro']['html'] ?? '' ) ) );
		if ( $designed && $button ) {
			$intro[] = $button;
		}
		if ( array() !== $intro ) {
			$blocks[] = $this->wrap( $intro, $designed ? self::BG_ACCENT : '', $designed ? 'header' : '' );
		}

		// Body sections.
		$index = 0;
		foreach ( (array) ( $content['sections'] ?? array() ) as $section ) {
			$heading = trim( (string) ( $section['heading'] ?? '' ) );
			$html    = (string) ( $section['html'] ?? '' );
			if ( '' === $heading && '' === trim( $html ) ) {
				continue;
			}
			$widgets = '' !== $heading ? array( $this->heading( $heading, 'h2' ) ) : array();
			$widgets = array_merge( $widgets, $this->chunks( $html ) );
			if ( isset( $images[ (string) ( $section['id'] ?? '' ) ] ) ) {
				$widgets[] = $this->image( $images[ (string) $section['id'] ] );
			}
			$blocks[] = $this->wrap( $widgets, $designed && 1 === $index % 2 ? self::BG_ALT : '', $designed ? 'section' : '' );
			++$index;
		}

		// FAQs as headings and text (works on every Elementor install; no deprecated accordion).
		$faqs = ContentModel::publishedFaqs( $content );
		if ( array() !== $faqs ) {
			$widgets = array( $this->heading( __( 'Frequently asked questions', 'ai-seo-content-studio' ), 'h2' ) );
			foreach ( $faqs as $faq ) {
				$widgets[] = $this->heading( (string) $faq['question'], 'h3' );
				$widgets[] = $this->textEditor( (string) $faq['answer_html'] );
			}
			$blocks[] = $this->wrap( $widgets, $designed ? self::BG_ALT : '', $designed ? 'section' : '' );
		}

		// Conclusion and closing CTA.
		$closing = $this->chunks( (string) ( $content['conclusion']['html'] ?? '' ) );
		if ( $button ) {
			$closing[] = $this->button( $cta_text, $cta_url );
		}
		if ( array() !== $closing ) {
			$blocks[] = $this->wrap( $closing, $designed ? self::BG_ACCENT : '', $designed ? 'section' : '' );
		}

		return array(
			'elements' => $blocks,
			'settings' => $designed && $this->own_h1 ? array( 'hide_title' => 'yes' ) : array(),
		);
	}

	/**
	 * Widgets for a piece of HTML.
	 *
	 * @param string $html Sanitised HTML.
	 * @return array<int, array<string, mixed>>
	 */
	protected function chunks( string $html ): array {
		$widgets = array();
		foreach ( HtmlSplitter::split( $html, self::STYLE_DESIGNED === $this->style ) as $chunk ) {
			if ( 'heading' === $chunk['type'] ) {
				$widgets[] = $this->heading( $chunk['text'], $chunk['level'] );
				continue;
			}
			$items     = self::STYLE_DESIGNED === $this->style ? HtmlSplitter::simpleListItems( $chunk['html'] ) : null;
			$widgets[] = null !== $items ? $this->iconList( $items ) : $this->textEditor( $chunk['html'] );
		}
		return $widgets;
	}

	/**
	 * Top-level wrapper: a container, or a section with one full-width column.
	 *
	 * @param array<int, array<string, mixed>> $widgets    Widgets.
	 * @param string                           $background Background colour ('' = none).
	 * @param string                           $tag        HTML tag ('' = default div).
	 * @return array<string, mixed>
	 */
	protected function wrap( array $widgets, string $background, string $tag ): array {
		$style = array();
		if ( self::STYLE_DESIGNED === $this->style ) {
			$style['padding'] = array(
				'unit'     => 'px',
				'top'      => '48',
				'right'    => '24',
				'bottom'   => '48',
				'left'     => '24',
				'isLinked' => false,
			);
		}
		if ( '' !== $background ) {
			$style['background_background'] = 'classic';
			$style['background_color']      = $background;
		}
		if ( '' !== $tag ) {
			$style['html_tag'] = $tag;
		}

		if ( $this->containers ) {
			return array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => array(
					'content_width'  => 'boxed',
					'flex_direction' => 'column',
				) + $style,
				'elements' => $widgets,
				'isInner'  => false,
			);
		}

		return array(
			'id'       => $this->id(),
			'elType'   => 'section',
			'settings' => $style,
			'elements' => array(
				array(
					'id'       => $this->id(),
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => $widgets,
					'isInner'  => false,
				),
			),
			'isInner'  => false,
		);
	}

	/**
	 * Heading widget.
	 *
	 * @param string $text  Plain text.
	 * @param string $level h1-h4.
	 * @return array<string, mixed>
	 */
	protected function heading( string $text, string $level ): array {
		return $this->widget(
			'heading',
			array(
				'title'       => esc_html( $text ),
				'header_size' => in_array( $level, array( 'h1', 'h2', 'h3', 'h4' ), true ) ? $level : 'h2',
			)
		);
	}

	/**
	 * Text editor widget.
	 *
	 * @param string $html Sanitised HTML.
	 * @return array<string, mixed>
	 */
	protected function textEditor( string $html ): array {
		return $this->widget( 'text-editor', array( 'editor' => $html ) );
	}

	/**
	 * Button widget.
	 *
	 * @param string $text Label.
	 * @param string $url  URL.
	 * @return array<string, mixed>
	 */
	protected function button( string $text, string $url ): array {
		return $this->widget(
			'button',
			array(
				'text' => esc_html( $text ),
				'link' => array(
					'url'               => esc_url_raw( $url ),
					'is_external'       => '',
					'nofollow'          => '',
					'custom_attributes' => '',
				),
			)
		);
	}

	/**
	 * Image widget for a Media Library image (Elementor renders the attachment's alt text).
	 *
	 * @param array{id: int, url: string, alt: string, caption: string} $img Image.
	 * @return array<string, mixed>
	 */
	protected function image( array $img ): array {
		$settings = array(
			'image'      => array(
				'url'    => esc_url_raw( $img['url'] ),
				'id'     => (int) $img['id'],
				'alt'    => sanitize_text_field( $img['alt'] ),
				'source' => 'library',
			),
			'image_size' => 'large',
		);
		if ( '' !== $img['caption'] ) {
			$settings['caption_source'] = 'custom';
			$settings['caption']        = esc_html( $img['caption'] );
		}
		return $this->widget( 'image', $settings );
	}

	/**
	 * Icon list widget with check icons (Font Awesome solid ships with Elementor).
	 *
	 * @param string[] $items Plain items.
	 * @return array<string, mixed>
	 */
	protected function iconList( array $items ): array {
		$list = array();
		foreach ( $items as $item ) {
			$list[] = array(
				'text'          => esc_html( $item ),
				'selected_icon' => array(
					'value'   => 'fas fa-check',
					'library' => 'fa-solid',
				),
				'_id'           => $this->id(),
			);
		}
		return $this->widget( 'icon-list', array( 'icon_list' => $list ) );
	}

	/**
	 * Generic widget.
	 *
	 * @param string               $type     Widget type.
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	protected function widget( string $type, array $settings ): array {
		return array(
			'id'         => $this->id(),
			'elType'     => 'widget',
			'settings'   => $settings,
			'elements'   => array(),
			'widgetType' => $type,
			'isInner'    => false,
		);
	}

	/**
	 * Unique element id (7 lowercase hex characters, the format Elementor's editor uses).
	 *
	 * @throws \RuntimeException When the id source keeps returning duplicates.
	 */
	protected function id(): string {
		for ( $i = 0; $i < 100; $i++ ) {
			$id = strtolower( (string) ( $this->id_source )() );
			if ( preg_match( '/^[a-f0-9]{7}$/', $id ) && ! isset( $this->ids[ $id ] ) ) {
				$this->ids[ $id ] = true;
				return $id;
			}
		}
		throw new \RuntimeException( 'Could not generate a unique element id.' );
	}
}
