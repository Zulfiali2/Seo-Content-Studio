<?php
/**
 * Standalone HTML previews of the original project and of the converted page.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Both previews are complete documents shown in a sandboxed iframe (`sandbox=""`: no scripts,
 * no same-origin access) with a Content Security Policy that blocks scripts and allows only
 * inline styles, data: assets and https fonts/images. Assets are inlined as data URIs, so the
 * preview needs no files on the server.
 *
 * - original(): the sanitised entry page with its CSS as written (only url()s rewritten). Scripts
 *   are never run in the admin; the preview shows the page as it looks without JavaScript.
 * - converted(): the Elementor elements rendered with the markup Elementor's front end uses for
 *   containers and widgets, under a stylesheet that imitates Elementor's own defaults (flex
 *   containers, widget spacing, kit typography), followed by the imported page's baseline and
 *   scoped CSS. It shows whether the imported styles hold against Elementor's; the real draft can
 *   still differ with the site's theme and plugins, so it is labelled as an approximation.
 */
final class PreviewRenderer {

	private const CSP = "default-src 'none'; style-src 'unsafe-inline' https:; img-src data: https:; font-src data: https:; media-src data: https:; frame-src https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com https://www.google.com https://maps.google.com";

	/** Imitation of Elementor's front-end defaults that would interfere with an imported design. */
	private const ELEMENTOR_DEFAULTS = 'body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:15px;line-height:1.5;color:#333;background:#fff}'
		. '.e-con{--display:flex;display:var(--display);flex-direction:column;gap:20px;padding:10px;position:relative;box-sizing:border-box;max-width:min(100%,1140px);margin-inline:auto;width:100%}'
		. '.e-con-full{max-width:100%}.elementor-widget{position:relative}.elementor-widget:not(:last-child){margin-block-end:20px}'
		. '.elementor-heading-title{padding:0;margin:0;line-height:1;color:#6EC1E4;font-family:Roboto,sans-serif;font-weight:600}'
		. '.elementor-widget-text-editor{color:#7A7A7A;font-family:Roboto,sans-serif}.elementor-widget-image{text-align:center}'
		. 'h1,h2,h3,h4,h5,h6{margin:0 0 .5em;font-weight:700;line-height:1.2;text-transform:capitalize}p{margin:0 0 1.6em}a{color:#0274be}img{max-width:100%;height:auto;vertical-align:middle}';

	/**
	 * Constructor.
	 *
	 * @param AssetResolver $assets Asset resolver.
	 */
	public function __construct( private readonly AssetResolver $assets ) {}

	/**
	 * Original page.
	 *
	 * @param ProjectParser $page        Parsed page.
	 * @param string        $css         CSS (url()s as tokens, selectors unscoped).
	 * @param string[]      $stylesheets Allowed external stylesheets.
	 */
	public function original( ProjectParser $page, string $css, array $stylesheets ): string {
		$html = HybridBuilder::innerHtml( $page->body );
		return $this->document(
			$this->tokens( $css ),
			$this->tokens( $html ),
			$stylesheets,
			$page->htmlClasses,
			$page->bodyClasses,
			$page->lang
		);
	}

	/**
	 * Converted page.
	 *
	 * @param array<int, array<string, mixed>> $elements    Elementor elements.
	 * @param string                           $css         Baseline + scoped CSS (tokens).
	 * @param string[]                         $stylesheets Allowed external stylesheets.
	 * @param string                           $template    Page template (canvas | full_width | default).
	 * @param string                           $lang        Language.
	 */
	public function converted( array $elements, string $css, array $stylesheets, string $template, string $lang = '' ): string {
		$body = '';
		foreach ( $elements as $el ) {
			$body .= $this->element( $el, true );
		}
		if ( 'canvas' !== $template ) {
			$note = '<div style="padding:14px 24px;background:#1d2327;color:#fff;font:14px/1.4 sans-serif">' . esc_html__( 'Your theme’s header (Full width / default template)', 'ai-seo-content-studio' ) . '</div>';
			$foot = '<div style="padding:14px 24px;background:#1d2327;color:#fff;font:14px/1.4 sans-serif">' . esc_html__( 'Your theme’s footer', 'ai-seo-content-studio' ) . '</div>';
			$body = $note . ( 'default' === $template ? '<div style="max-width:800px;margin:0 auto;padding:24px">' . $body . '</div>' : $body ) . $foot;
		}
		return $this->document( self::ELEMENTOR_DEFAULTS . "\n" . $this->tokens( $css ), $this->tokens( $body ), $stylesheets, array(), array( 'elementor-page' ), $lang );
	}

	/**
	 * Problems with assets while rendering.
	 *
	 * @return array<string, string>
	 */
	public function problems(): array {
		return $this->assets->problems();
	}

	/**
	 * One Elementor element as front-end markup.
	 *
	 * @param array<string, mixed> $el  Element.
	 * @param bool                 $top Top level.
	 */
	private function element( array $el, bool $top = false ): string {
		$s  = (array) ( $el['settings'] ?? array() );
		$id = (string) ( $el['id'] ?? '' );
		if ( 'container' === ( $el['elType'] ?? '' ) ) {
			$tag      = in_array( $s['html_tag'] ?? 'div', array( 'div', 'section', 'header', 'footer', 'main', 'article', 'aside', 'nav' ), true ) ? $s['html_tag'] : 'div';
			$classes  = 'elementor-element elementor-element-' . $id . ' e-con-full e-flex e-con ' . ( $top ? 'e-parent' : 'e-child' ) . ' ' . (string) ( $s['css_classes'] ?? '' );
			$id_attr  = '' !== (string) ( $s['_element_id'] ?? '' ) ? ' id="' . esc_attr( (string) $s['_element_id'] ) . '"' : '';
			$children = '';
			foreach ( (array) ( $el['elements'] ?? array() ) as $child ) {
				$children .= $this->element( $child );
			}
			return '<' . $tag . ' class="' . esc_attr( trim( $classes ) ) . '"' . $id_attr . ' data-id="' . esc_attr( $id ) . '" data-element_type="container">' . $children . '</' . $tag . '>';
		}
		$type    = (string) ( $el['widgetType'] ?? '' );
		$content = match ( $type ) {
			'heading' => '<' . ( $s['header_size'] ?? 'h2' ) . ' class="elementor-heading-title elementor-size-default">' . (string) ( $s['title'] ?? '' ) . '</' . ( $s['header_size'] ?? 'h2' ) . '>',
			'image' => '<img src="' . esc_attr( (string) ( $s['image']['url'] ?? '' ) ) . '" alt="' . esc_attr( (string) ( $s['image']['alt'] ?? '' ) ) . '" class="attachment-full size-full">',
			'text-editor' => (string) ( $s['editor'] ?? '' ),
			default => (string) ( $s['html'] ?? '' ),
		};
		$classes = 'elementor-element elementor-element-' . $id . ' elementor-widget elementor-widget-' . $type . ' ' . (string) ( $s['_css_classes'] ?? '' );
		return '<div class="' . esc_attr( trim( $classes ) ) . '" data-id="' . esc_attr( $id ) . '" data-element_type="widget" data-widget_type="' . esc_attr( $type ) . '.default"><div class="elementor-widget-container">' . $content . '</div></div>';
	}

	/**
	 * Complete preview document.
	 *
	 * @param string   $css          CSS.
	 * @param string   $body         Body markup.
	 * @param string[] $stylesheets  External stylesheets.
	 * @param string[] $html_classes <html> classes.
	 * @param string[] $body_classes <body> classes.
	 * @param string   $lang         Language.
	 */
	private function document( string $css, string $body, array $stylesheets, array $html_classes, array $body_classes, string $lang ): string {
		$links = '';
		foreach ( $stylesheets as $url ) {
			$links .= '<link rel="stylesheet" href="' . esc_attr( $url ) . '">';
		}
		return '<!DOCTYPE html><html' . ( '' !== $lang ? ' lang="' . esc_attr( $lang ) . '"' : '' ) . ( array() !== $html_classes ? ' class="' . esc_attr( implode( ' ', $html_classes ) ) . '"' : '' ) . '><head><meta charset="utf-8">'
			. '<meta http-equiv="Content-Security-Policy" content="' . esc_attr( self::CSP ) . '">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">' . $links
			. '<style>' . self::safeCss( $css ) . '</style></head><body' . ( array() !== $body_classes ? ' class="' . esc_attr( implode( ' ', $body_classes ) ) . '"' : '' ) . '>' . $body . '</body></html>';
	}

	/**
	 * Replace asset tokens with data URIs.
	 *
	 * @param string $text Text.
	 */
	private function tokens( string $text ): string {
		return AssetRefs::replaceTokens( $text, fn ( string $path ): string => $this->assets->dataUri( $path ) );
	}

	/**
	 * CSS that cannot close the <style> element.
	 *
	 * @param string $css CSS.
	 */
	public static function safeCss( string $css ): string {
		return (string) preg_replace( '#</(style|script)#i', '<\\\\/$1', $css );
	}
}
