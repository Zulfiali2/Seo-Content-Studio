<?php
/**
 * Blog post templates for Elementor drafts.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Elementor;

use AiSeoContentStudio\Content\ContentModel;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a complete blog post layout from free Elementor widgets:
 *
 * - magazine: breadcrumbs, then the article (category badge, H1, author/date/reading time,
 *   summary, featured image, table of contents, numbered sections, callout boxes, FAQs) beside
 *   a sidebar (author, latest posts, categories, optional button and form), then related posts.
 * - centered: the same article in one narrow centred column, author box and related posts below.
 * - hero: a full-width coloured header band, a narrow article column and a closing
 *   call-to-action band, then related posts.
 *
 * Parts that depend on the published post (breadcrumbs, category, dates, author, related and
 * latest posts) are shortcode widgets rendered by the plugin, so they stay correct after
 * publishing and when posts are added. Layout and colours come from CSS classes
 * (assets/frontend/blog-templates.css) plus the accent colour stored with the post.
 * The theme's own header and footer are kept.
 */
final class BlogTemplateBuilder extends LayoutBuilder {

	/**
	 * Constructor.
	 *
	 * @param bool          $containers     Use containers (true) or sections/columns (false).
	 * @param string        $template       magazine | centered | hero.
	 * @param callable|null $id_source      Returns candidate ids.
	 * @param bool          $own_h1         The layout carries the H1 (the theme title is replaced by
	 *                                      Elementor's full-width page layout).
	 * @param string        $form_shortcode Optional form shortcode for the sidebar or closing band.
	 */
	public function __construct(
		bool $containers,
		string $template,
		?callable $id_source = null,
		bool $own_h1 = true,
		private readonly string $form_shortcode = '',
	) {
		parent::__construct( $containers, BlogTemplates::isTemplate( $template ) ? $template : BlogTemplates::MAGAZINE, $id_source, $own_h1 );
	}

	/**
	 * Build the document.
	 *
	 * @param array<string, mixed>                                                     $content Content model.
	 * @param array<string, mixed>                                                     $cta     Optional call to action: text, url.
	 * @param array<string, array{id: int, url: string, alt: string, caption: string}> $images  Images to place after sections, keyed by section id.
	 * @return array{elements: array<int, array<string, mixed>>, settings: array<string, string>}
	 */
	public function build( array $content, array $cta = array(), array $images = array() ): array {
		$cta_text = trim( (string) ( $cta['text'] ?? '' ) );
		$cta_url  = trim( (string) ( $cta['url'] ?? '' ) );
		$has_cta  = '' !== $cta_text && '' !== $cta_url;

		$header  = $this->header( $content, BlogTemplates::HERO !== $this->style );
		$article = $this->article( $content, $images, BlogTemplates::HERO !== $this->style );
		$related = array( $this->shortcodeWidget( '[aiscs_related_posts]' ) );

		switch ( $this->style ) {
			case BlogTemplates::CENTERED:
				$main = array_merge( $header, $article, array( $this->shortcodeWidget( '[aiscs_author_box]' ) ) );
				if ( $has_cta ) {
					$main[] = $this->classed( $this->button( $cta_text, $cta_url ), 'aiscs-tpl__cta-button' );
				}
				$rows = array(
					$this->row( 'aiscs-tpl aiscs-tpl--centered', true, array( array( 'aiscs-tpl__main', 100, $main ) ) ),
					$this->row( 'aiscs-tpl aiscs-tpl--centered aiscs-tpl__related-row', true, array( array( '', 100, $related ) ) ),
				);
				break;

			case BlogTemplates::HERO:
				$rows   = array( $this->row( 'aiscs-tpl aiscs-tpl--hero aiscs-tpl__hero e-no-lazyload', true, array( array( '', 100, array_merge( array( $this->shortcodeWidget( '[aiscs_breadcrumbs]' ) ), $header ) ) ) ) );
				$rows[] = $this->row( 'aiscs-tpl aiscs-tpl--hero aiscs-tpl__body', true, array( array( 'aiscs-tpl__main', 100, array_merge( $article, array( $this->shortcodeWidget( '[aiscs_author_box]' ) ) ) ) ) );
				$band   = array();
				if ( $has_cta ) {
					$band[] = $this->classed( $this->button( $cta_text, $cta_url ), 'aiscs-tpl__cta-button' );
				}
				if ( '' !== $this->form_shortcode ) {
					$band[] = $this->shortcodeWidget( $this->form_shortcode, 'aiscs-tpl__form' );
				}
				if ( array() !== $band ) {
					$rows[] = $this->row( 'aiscs-tpl aiscs-tpl--hero aiscs-tpl__band e-no-lazyload', true, array( array( '', 100, $band ) ) );
				}
				$rows[] = $this->row( 'aiscs-tpl aiscs-tpl--hero aiscs-tpl__related-row', false, array( array( '', 100, $related ) ) );
				break;

			default:
				$sidebar = array(
					$this->shortcodeWidget( '[aiscs_author_box]' ),
					$this->shortcodeWidget( '[aiscs_recent_posts]' ),
					$this->shortcodeWidget( '[aiscs_categories]' ),
				);
				if ( $has_cta ) {
					$sidebar[] = $this->classed( $this->button( $cta_text, $cta_url ), 'aiscs-tpl__card aiscs-tpl__cta-button' );
				}
				if ( '' !== $this->form_shortcode ) {
					$sidebar[] = $this->shortcodeWidget( $this->form_shortcode, 'aiscs-tpl__card aiscs-tpl__form' );
				}
				$rows = array(
					$this->row( 'aiscs-tpl aiscs-tpl--magazine aiscs-tpl__crumbs-row', false, array( array( '', 100, array( $this->shortcodeWidget( '[aiscs_breadcrumbs]' ) ) ) ) ),
					$this->row(
						'aiscs-tpl aiscs-tpl--magazine aiscs-tpl__columns',
						false,
						array(
							array( 'aiscs-tpl__main', 68, array_merge( $header, $article ) ),
							array( 'aiscs-tpl__sidebar', 32, $sidebar ),
						)
					),
					$this->row( 'aiscs-tpl aiscs-tpl--magazine aiscs-tpl__related-row', false, array( array( '', 100, $related ) ) ),
				);
		}

		$settings = array();
		if ( $this->own_h1 ) {
			// Elementor's own "Full Width" layout: the theme header and footer stay, the theme's
			// title, featured image and sidebar make way for this design.
			$settings['template'] = 'elementor_header_footer';
		}
		return array(
			'elements' => $rows,
			'settings' => $settings,
		);
	}

	/**
	 * Category badge, H1, author/date line and summary.
	 *
	 * @param array<string, mixed> $content    Content.
	 * @param bool                 $with_badge Show the category badge.
	 * @return array<int, array<string, mixed>>
	 */
	private function header( array $content, bool $with_badge ): array {
		$widgets = array();
		if ( $with_badge ) {
			$widgets[] = $this->shortcodeWidget( '[aiscs_post_badge]' );
		} else {
			$widgets[] = $this->shortcodeWidget( '[aiscs_post_badge]', 'aiscs-tpl__badge--light' );
		}
		$h1 = trim( (string) ( '' !== (string) ( $content['h1'] ?? '' ) ? $content['h1'] : ( $content['title'] ?? '' ) ) );
		if ( $this->own_h1 && '' !== $h1 ) {
			$widgets[] = $this->classed( $this->heading( $h1, 'h1' ), 'aiscs-tpl__title' );
		}
		$widgets[] = $this->shortcodeWidget( '[aiscs_post_meta]' );
		$summary   = trim( (string) ( $content['meta']['meta_description'] ?? '' ) );
		if ( '' !== $summary ) {
			$widgets[] = $this->classed( $this->textEditor( '<p>' . esc_html( $summary ) . '</p>' ), 'aiscs-tpl__lede' );
		}
		return $widgets;
	}

	/**
	 * Featured image, introduction, table of contents, numbered sections, FAQs and conclusion.
	 *
	 * @param array<string, mixed>                                                     $content  Content.
	 * @param array<string, array{id: int, url: string, alt: string, caption: string}> $images   Images.
	 * @param bool                                                                     $numbered Number the H2 headings.
	 * @return array<int, array<string, mixed>>
	 */
	private function article( array $content, array $images, bool $numbered ): array {
		$widgets = array();
		// The post's current featured image (live, so one set later in WordPress also shows).
		// Without the full-width layout the theme already shows it.
		if ( $this->own_h1 ) {
			$widgets[] = $this->shortcodeWidget( '[aiscs_featured_image]', 'aiscs-tpl__featured' );
		}
		$widgets = array_merge( $widgets, $this->chunks( (string) ( $content['intro']['html'] ?? '' ) ) );

		// Headings in page order, with anchors for the table of contents.
		$entries = array();
		foreach ( (array) ( $content['sections'] ?? array() ) as $section ) {
			$heading = trim( (string) ( $section['heading'] ?? '' ) );
			if ( '' !== $heading || '' !== trim( (string) ( $section['html'] ?? '' ) ) ) {
				$entries[] = array(
					'heading' => $heading,
					'html'    => (string) ( $section['html'] ?? '' ),
					'image'   => $images[ (string) ( $section['id'] ?? '' ) ] ?? null,
				);
			}
		}
		$faqs = ContentModel::publishedFaqs( $content );
		if ( array() !== $faqs ) {
			$entries[] = array(
				'heading' => __( 'Frequently asked questions', 'ai-seo-content-studio' ),
				'faqs'    => $faqs,
			);
		}
		$conclusion = (string) ( $content['conclusion']['html'] ?? '' );
		if ( '' !== trim( $conclusion ) ) {
			$entries[] = array(
				'heading' => __( 'Conclusion', 'ai-seo-content-studio' ),
				'html'    => $conclusion,
			);
		}

		$toc = '';
		$n   = 0;
		foreach ( $entries as $i => $entry ) {
			if ( '' === $entry['heading'] ) {
				continue;
			}
			++$n;
			$entries[ $i ]['anchor'] = 'aiscs-section-' . $n;
			$entries[ $i ]['title']  = $numbered ? $n . '. ' . $entry['heading'] : $entry['heading'];
			$toc                    .= '<li><a href="#aiscs-section-' . $n . '">' . esc_html( $entry['heading'] ) . '</a></li>';
		}
		if ( $n >= 3 ) {
			$widgets[] = $this->classed( $this->textEditor( '<p class="aiscs-toc__title">' . esc_html__( 'Table of contents', 'ai-seo-content-studio' ) . '</p><ol>' . $toc . '</ol>' ), 'aiscs-tpl__toc' );
		}

		foreach ( $entries as $entry ) {
			if ( isset( $entry['anchor'] ) ) {
				$heading                            = $this->classed( $this->heading( $entry['title'], 'h2' ), 'aiscs-tpl__h2' );
				$heading['settings']['_element_id'] = $entry['anchor'];
				$widgets[]                          = $heading;
			}
			if ( isset( $entry['faqs'] ) ) {
				$faq_html = '';
				foreach ( $entry['faqs'] as $faq ) {
					$faq_html .= '<h3>' . esc_html( (string) $faq['question'] ) . '</h3>' . $faq['answer_html'];
				}
				$widgets[] = $this->classed( $this->textEditor( $faq_html ), 'aiscs-tpl__faqs' );
				continue;
			}
			$widgets = array_merge( $widgets, $this->chunks( (string) $entry['html'] ) );
			if ( ! empty( $entry['image'] ) ) {
				$widgets[] = $this->image( $entry['image'] );
			}
		}
		return $widgets;
	}

	/**
	 * Widgets for a piece of HTML: sub-headings, check-mark lists and callout boxes.
	 *
	 * @param string $html Sanitised HTML.
	 * @return array<int, array<string, mixed>>
	 */
	protected function chunks( string $html ): array {
		$widgets = array();
		foreach ( HtmlSplitter::split( $html, true, true ) as $chunk ) {
			if ( 'heading' === $chunk['type'] ) {
				$widgets[] = $this->heading( $chunk['text'], $chunk['level'] );
				continue;
			}
			if ( 'callout' === $chunk['type'] ) {
				$widgets[] = $this->classed( $this->textEditor( $chunk['html'] ), 'aiscs-callout aiscs-callout--' . $chunk['kind'] );
				continue;
			}
			$items     = HtmlSplitter::simpleListItems( $chunk['html'] );
			$widgets[] = null !== $items ? $this->classed( $this->iconList( $items ), 'aiscs-tpl__checks' ) : $this->textEditor( $chunk['html'] );
		}
		return $widgets;
	}

	/**
	 * A row: one or more columns, as a container (with inner containers) or a section with columns.
	 *
	 * @param string                                                                    $classes   CSS classes.
	 * @param bool                                                                      $narrow  Limit the content width for reading.
	 * @param array<int, array{0: string, 1: int, 2: array<int, array<string, mixed>>}> $columns Class, width %, widgets.
	 * @return array<string, mixed>
	 */
	private function row( string $classes, bool $narrow, array $columns ): array {
		$single = 1 === count( $columns );
		if ( $this->containers ) {
			$settings = array(
				'content_width'  => 'boxed',
				'flex_direction' => $single ? 'column' : 'row',
				'css_classes'    => trim( $classes . ( $single ? ' ' . $columns[0][0] : '' ) ),
			);
			if ( $narrow ) {
				$settings['boxed_width'] = array(
					'unit'  => 'px',
					'size'  => 800,
					'sizes' => array(),
				);
			}
			if ( ! $single ) {
				$settings['flex_direction_tablet'] = 'column';
				$settings['flex_gap']              = array(
					'column'   => '48',
					'row'      => '48',
					'isLinked' => true,
					'unit'     => 'px',
					'size'     => 48,
				);
			}
			$children = $single ? $columns[0][2] : array_map(
				fn ( array $col ): array => array(
					'id'       => $this->id(),
					'elType'   => 'container',
					'settings' => array(
						'content_width'  => 'full',
						'flex_direction' => 'column',
						'width'          => array(
							'unit'  => '%',
							'size'  => $col[1],
							'sizes' => array(),
						),
						'width_tablet'   => array(
							'unit'  => '%',
							'size'  => 100,
							'sizes' => array(),
						),
						'css_classes'    => $col[0],
					),
					'elements' => $col[2],
					'isInner'  => true,
				),
				$columns
			);
			return array(
				'id'       => $this->id(),
				'elType'   => 'container',
				'settings' => $settings,
				'elements' => $children,
				'isInner'  => false,
			);
		}

		$settings = array(
			'css_classes' => $classes,
			'gap'         => $single ? 'default' : 'wider',
		);
		if ( $narrow ) {
			$settings['layout']        = 'boxed';
			$settings['content_width'] = array(
				'unit'  => 'px',
				'size'  => 800,
				'sizes' => array(),
			);
		}
		return array(
			'id'       => $this->id(),
			'elType'   => 'section',
			'settings' => $settings,
			'elements' => array_map(
				fn ( array $col ): array => array(
					'id'       => $this->id(),
					'elType'   => 'column',
					'settings' => array(
						'_column_size' => $col[1],
						'_inline_size' => $single ? null : $col[1],
						'css_classes'  => $col[0],
					),
					'elements' => $col[2],
					'isInner'  => false,
				),
				$columns
			),
			'isInner'  => false,
		);
	}

	/**
	 * Shortcode widget.
	 *
	 * @param string $shortcode Shortcode.
	 * @param string $classes     Extra CSS classes.
	 * @return array<string, mixed>
	 */
	private function shortcodeWidget( string $shortcode, string $classes = '' ): array {
		$widget = $this->widget( 'shortcode', array( 'shortcode' => $shortcode ) );
		return '' === $classes ? $widget : $this->classed( $widget, $classes );
	}

	/**
	 * Add CSS classes to a widget (Elementor's Advanced → CSS Classes).
	 *
	 * @param array<string, mixed> $widget Widget.
	 * @param string               $classes  Classes.
	 * @return array<string, mixed>
	 */
	private function classed( array $widget, string $classes ): array {
		$widget['settings']['_css_classes'] = trim( ( $widget['settings']['_css_classes'] ?? '' ) . ' ' . $classes );
		return $widget;
	}
}
