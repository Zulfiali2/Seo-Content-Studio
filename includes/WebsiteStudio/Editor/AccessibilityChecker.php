<?php
/**
 * Automated accessibility checks of a page's Elementor data.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Editor;

use AiSeoContentStudio\WebsiteStudio\Engine\ElementorMapper;
use AiSeoContentStudio\WebsiteStudio\Engine\HtmlFragment;

defined( 'ABSPATH' ) || exit;

/**
 * Checks common WCAG 2.2 AA problems that can be found in the stored page data: heading
 * hierarchy (1.3.1, 2.4.6), image text alternatives (1.1.1), empty or non-descriptive links
 * and buttons (2.4.4, 4.1.2), colour contrast of explicitly set colours (1.4.3), small text,
 * form labels (3.3.2) and markup that needs a manual keyboard check (2.1.1).
 *
 * Automated checks find only part of the issues; the report says so and never claims
 * compliance. Colour contrast is computed only where both colours are known: inherited theme
 * colours are reported for a manual check instead of guessed.
 */
final class AccessibilityChecker {

	public const CATEGORIES = array( 'headings', 'images', 'links', 'contrast', 'readability', 'forms', 'keyboard', 'media' );

	private const GENERIC = array( 'click here', 'here', 'read more', 'more', 'learn more', 'click', 'this', 'link', 'go', 'submit', 'details', 'continue' );

	/**
	 * Findings.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $findings = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $kit        Kit colour id => hex.
	 * @param callable(int): string $alt        Attachment id => alt text.
	 * @param string                $page_bg    Page background colour (#RRGGBB).
	 */
	public function __construct( private readonly array $kit, private readonly mixed $alt, private readonly string $page_bg = '#FFFFFF' ) {}

	/**
	 * Run the checks.
	 *
	 * @param array<int, mixed> $elements Page elements.
	 * @return array{categories: array<string, array{errors: int, warnings: int, recommendations: int}>, findings: list<array<string, mixed>>}
	 */
	public function check( array $elements ): array {
		$this->findings = array();
		foreach ( Headings::problems( $elements ) as $p ) {
			$this->add( 'headings', $p['severity'], $p['message'], $p['elements'], $p['fix_label'], $p['ops'] );
		}
		foreach ( Headings::outline( $elements ) as $h ) {
			if ( $h['level'] > 0 && '' === $h['text'] ) {
				$this->add( 'headings', 'error', __( 'A heading is empty. Screen readers announce it as a blank heading.', 'ai-seo-content-studio' ), array( $h['id'] ), '', array() );
			}
		}
		$this->walk( $elements, $this->page_bg );
		$this->findings = Findings::unique( $this->findings );

		$categories = array();
		foreach ( self::CATEGORIES as $category ) {
			$mine                    = array_filter( $this->findings, static fn ( array $f ): bool => $f['category'] === $category );
			$categories[ $category ] = array(
				'errors'          => count( array_filter( $mine, static fn ( array $f ): bool => 'error' === $f['severity'] ) ),
				'warnings'        => count( array_filter( $mine, static fn ( array $f ): bool => 'warning' === $f['severity'] ) ),
				'recommendations' => count( array_filter( $mine, static fn ( array $f ): bool => 'recommendation' === $f['severity'] ) ),
			);
		}
		return array(
			'categories' => $categories,
			'findings'   => $this->findings,
		);
	}

	/**
	 * Walk the tree with the background colour that applies at each level.
	 *
	 * @param array<int, mixed> $elements Elements.
	 * @param string            $bg       Background behind them ("" = unknown).
	 */
	private function walk( array $elements, string $bg ): void {
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$s    = (array) ( $el['settings'] ?? array() );
			$here = $bg;
			if ( 'widget' !== ( $el['elType'] ?? '' ) ) {
				$own = StyleValues::color( $s, 'background_color', $this->kit );
				if ( ! empty( $s['background_image']['url'] ) ) {
					$here = ''; // A photo: contrast cannot be computed.
				} elseif ( null !== $own && '' !== $own['hex'] && in_array( $s['background_background'] ?? 'classic', array( 'classic', '' ), true ) ) {
					$here = $own['hex'];
				}
				$this->walk( (array) ( $el['elements'] ?? array() ), $here );
				continue;
			}
			$this->widget( $el, $s, $bg );
		}
	}

	/**
	 * Checks of one widget.
	 *
	 * @param array<string, mixed> $el Widget.
	 * @param array<string, mixed> $s  Settings.
	 * @param string               $bg Background behind it.
	 */
	private function widget( array $el, array $s, string $bg ): void {
		$id = (string) $el['id'];
		switch ( (string) ( $el['widgetType'] ?? '' ) ) {
			case 'heading':
				$level = preg_match( '/^h([1-6])$/', (string) ( $s['header_size'] ?? 'h2' ), $m ) ? (int) $m[1] : 0;
				$size  = StyleValues::px( $s['typography_font_size'] ?? null );
				$this->contrast( $id, $s, 'title_color', $bg, ( $level > 0 && $level <= 3 ) || ( null !== $size && $size >= 24 ) );
				break;

			case 'text-editor':
				$size = StyleValues::px( $s['typography_font_size'] ?? null );
				$this->contrast( $id, $s, 'text_color', $bg, null !== $size && $size >= 24 );
				if ( null !== $size && $size < 14 ) {
					$this->add(
						'readability',
						'warning',
						/* translators: %d: font size. */
						sprintf( __( 'Body text is %dpx, which is hard to read.', 'ai-seo-content-studio' ), (int) $size ),
						array( $id ),
						__( 'Set the text size to 16px', 'ai-seo-content-studio' ),
						array( ConsistencyChecker::op( $id, array( 'typography_font_size' => ElementorMapper::slider( '16px' ) ) ) )
					);
				}
				$this->html( $id, (string) ( $s['editor'] ?? '' ) );
				break;

			case 'button':
				$text = trim( HtmlFragment::text( (string) ( $s['text'] ?? '' ) ) );
				$url  = trim( (string) ( $s['link']['url'] ?? '' ) );
				if ( '' === $text ) {
					$this->add( 'links', 'error', __( 'A button has no text, so screen readers cannot name it.', 'ai-seo-content-studio' ), array( $id ), __( 'Give the button a label', 'ai-seo-content-studio' ), array( ConsistencyChecker::op( $id, array( 'text' => '{value}' ) ) ), true );
				} elseif ( in_array( strtolower( rtrim( $text, '.!… ' ) ), self::GENERIC, true ) ) {
					/* translators: %s: button text. */
					$this->add( 'links', 'warning', sprintf( __( 'The button text "%s" does not say where it leads.', 'ai-seo-content-studio' ), $text ), array( $id ), __( 'Use a descriptive button label', 'ai-seo-content-studio' ), array( ConsistencyChecker::op( $id, array( 'text' => '{value}' ) ) ), true, $text );
				}
				if ( '' === $url || '#' === $url ) {
					/* translators: %s: button text. */
					$this->add( 'links', 'warning', sprintf( __( 'The button "%s" does not link anywhere yet.', 'ai-seo-content-studio' ), $text ), array( $id ), '', array() );
				}
				$button_bg = StyleValues::color( $s, 'background_color', $this->kit );
				$this->contrast( $id, $s, 'button_text_color', null !== $button_bg && '' !== $button_bg['hex'] ? $button_bg['hex'] : $bg, false );
				break;

			case 'image':
				$attachment = (int) ( $s['image']['id'] ?? 0 );
				$alt        = $attachment > 0 ? trim( (string) ( $this->alt )( $attachment ) ) : '';
				if ( $attachment > 0 && '' === $alt ) {
					$suggest = trim( HtmlFragment::text( (string) ( $s['caption'] ?? '' ) ) );
					$this->add(
						'images',
						'error',
						__( 'An image has no alternative text.', 'ai-seo-content-studio' ),
						array( $id ),
						__( 'Add alt text in the Media Library', 'ai-seo-content-studio' ),
						array(
							array(
								'type'          => 'attachment_alt',
								'attachment_id' => $attachment,
							),
						),
						true,
						$suggest
					);
				} elseif ( $attachment <= 0 && '' !== (string) ( $s['image']['url'] ?? '' ) ) {
					$this->add( 'images', 'warning', __( 'An image is loaded from a URL, so it has no Media Library alt text. Upload it to the Media Library and add alt text.', 'ai-seo-content-studio' ), array( $id ), '', array() );
				}
				break;

			case 'icon':
				if ( '' !== trim( (string) ( $s['link']['url'] ?? '' ) ) ) {
					$this->add( 'links', 'warning', __( 'A linked icon has no text, so its link has no accessible name. Use an Icon Box with a title, or a button.', 'ai-seo-content-studio' ), array( $id ), '', array() );
				}
				break;

			case 'icon-list':
				foreach ( (array) ( $s['icon_list'] ?? array() ) as $item ) {
					if ( is_array( $item ) && '' !== trim( (string) ( $item['link']['url'] ?? '' ) ) && '' === trim( HtmlFragment::text( (string) ( $item['text'] ?? '' ) ) ) ) {
						$this->add( 'links', 'error', __( 'A linked list item has no text.', 'ai-seo-content-studio' ), array( $id ), '', array() );
					}
				}
				break;

			case 'form':
				$fields  = array_values( (array) ( $s['form_fields'] ?? array() ) );
				$changed = $fields;
				$missing = 0;
				foreach ( $fields as $i => $field ) {
					if ( ! is_array( $field ) || in_array( $field['field_type'] ?? 'text', array( 'hidden', 'html', 'step', 'recaptcha', 'recaptcha_v3', 'honeypot' ), true ) ) {
						continue;
					}
					if ( '' === trim( (string) ( $field['field_label'] ?? '' ) ) ) {
						++$missing;
						$changed[ $i ]['field_label'] = (string) ( $field['placeholder'] ?? '' );
					}
				}
				if ( $missing > 0 ) {
					$all_placeholders = array_filter( $changed, static fn ( $f ): bool => ! is_array( $f ) || '' !== trim( (string) ( $f['field_label'] ?? '' ) ) || in_array( $f['field_type'] ?? 'text', array( 'hidden', 'html', 'step', 'recaptcha', 'recaptcha_v3', 'honeypot' ), true ) );
					$fixable          = count( $all_placeholders ) === count( $changed );
					$this->add(
						'forms',
						'error',
						/* translators: %d: number of fields. */
						sprintf( __( '%d form fields have no label (a placeholder is not a label).', 'ai-seo-content-studio' ), $missing ),
						array( $id ),
						$fixable ? __( 'Use each placeholder as the field label', 'ai-seo-content-studio' ) : '',
						$fixable ? array( ConsistencyChecker::op( $id, array( 'form_fields' => $changed ) ) ) : array()
					);
				}
				break;

			case 'html':
				if ( preg_match( '/\son(click|mouse\w+|key\w+)\s*=|tabindex\s*=\s*["\']?[1-9]/i', (string) ( $s['html'] ?? '' ) ) ) {
					$this->add( 'keyboard', 'warning', __( 'Custom HTML uses mouse handlers or a positive tabindex. Check that it works with the keyboard alone.', 'ai-seo-content-studio' ), array( $id ), '', array() );
				}
				$this->html( $id, (string) ( $s['html'] ?? '' ) );
				break;

			case 'video':
				$this->add( 'media', 'recommendation', __( 'Videos need captions (and a transcript for audio content). Check the video has them.', 'ai-seo-content-studio' ), array( $id ), '', array() );
				break;
		}
	}

	/**
	 * Link and image checks inside HTML content.
	 *
	 * @param string $id   Widget.
	 * @param string $html HTML.
	 */
	private function html( string $id, string $html ): void {
		if ( '' === $html ) {
			return;
		}
		if ( preg_match_all( '#<a\b([^>]*)>(.*?)</a>#is', $html, $links, PREG_SET_ORDER ) ) {
			foreach ( $links as $a ) {
				$text = trim( HtmlFragment::text( $a[2] ) );
				$img  = preg_match( '#<img\b[^>]*\balt\s*=\s*["\'][^"\']+#i', $a[2] );
				if ( '' === $text && ! $img && ! preg_match( '/aria-label\s*=\s*["\'][^"\']+/i', $a[1] ) ) {
					$this->add( 'links', 'error', __( 'A link has no text.', 'ai-seo-content-studio' ), array( $id ), '', array() );
				} elseif ( in_array( strtolower( rtrim( $text, '.!… ' ) ), self::GENERIC, true ) ) {
					/* translators: %s: link text. */
					$this->add( 'links', 'warning', sprintf( __( 'The link text "%s" does not describe its target. Edit the text in Elementor.', 'ai-seo-content-studio' ), $text ), array( $id ), '', array() );
				}
			}
		}
		if ( preg_match_all( '#<img\b[^>]*>#i', $html, $imgs ) ) {
			foreach ( $imgs[0] as $img ) {
				if ( ! preg_match( '/\balt\s*=/i', $img ) ) {
					$this->add( 'images', 'error', __( 'An image inside text has no alt attribute.', 'ai-seo-content-studio' ), array( $id ), '', array() );
				}
			}
		}
		$words = count( (array) preg_split( '/\s+/u', trim( HtmlFragment::text( $html ) ) ) );
		if ( $words > 150 && ! preg_match( '#</p>\s*<p|<br|<li#i', $html ) ) {
			$this->add( 'readability', 'recommendation', __( 'A long text block has no paragraph breaks. Split it into shorter paragraphs.', 'ai-seo-content-studio' ), array( $id ), '', array() );
		}
	}

	/**
	 * Contrast of an explicitly set text colour against the background.
	 *
	 * @param string               $id    Widget.
	 * @param array<string, mixed> $s     Settings.
	 * @param string               $key   Colour setting.
	 * @param string               $bg    Background ("" = unknown).
	 * @param bool                 $large Large text (3:1 is enough).
	 */
	private function contrast( string $id, array $s, string $key, string $bg, bool $large ): void {
		$color = StyleValues::color( $s, $key, $this->kit );
		if ( '' === $bg ) {
			return; // Photo or unknown background: not computed.
		}
		if ( null === $color || '' === $color['hex'] ) {
			if ( StyleValues::luminance( $bg ) < 0.2 ) {
				$this->add( 'contrast', 'warning', __( 'Text on a dark background uses the theme colour. Check that it is light enough.', 'ai-seo-content-studio' ), array( $id ), __( 'Set a contrasting text colour', 'ai-seo-content-studio' ), array( ConsistencyChecker::op( $id, array( $key => ElementorMapper::contrastText( $bg ) ) ) ) );
			}
			return;
		}
		$ratio = StyleValues::contrast( $color['hex'], $bg );
		$need  = $large ? 3.0 : 4.5;
		if ( $ratio + 0.001 < $need ) {
			$this->add(
				'contrast',
				'error',
				/* translators: 1: ratio, 2: text colour, 3: background, 4: required ratio. */
				sprintf( __( 'Contrast %1$s:1 between %2$s and %3$s is below the %4$s:1 minimum.', 'ai-seo-content-studio' ), number_format( $ratio, 2 ), $color['hex'], $bg, number_format( $need, 1 ) ),
				array( $id ),
				/* translators: %s: colour. */
				sprintf( __( 'Use %s for this text', 'ai-seo-content-studio' ), ElementorMapper::contrastText( $bg ) ),
				array( ConsistencyChecker::op( $id, array( $key => ElementorMapper::contrastText( $bg ), '__globals__.' . $key => null ) ) )
			);
		}
	}

	/**
	 * Record a finding.
	 *
	 * @param string                     $category    Category.
	 * @param string                     $severity    Severity.
	 * @param string                     $message     Message.
	 * @param list<string>               $elements    Element ids.
	 * @param string                     $fix_label   Fix label.
	 * @param list<array<string, mixed>> $ops         Operations.
	 * @param bool                       $needs_value Needs text from the user.
	 * @param string                     $suggestion  Suggested text.
	 */
	private function add( string $category, string $severity, string $message, array $elements, string $fix_label, array $ops, bool $needs_value = false, string $suggestion = '' ): void {
		$this->findings[] = Findings::make( 'a', $category, $severity, $message, $elements, $fix_label, $ops, $needs_value, $suggestion );
	}
}
