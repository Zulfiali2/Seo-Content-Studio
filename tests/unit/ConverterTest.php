<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\WebsiteStudio\Converter\HtmlConverter;
use AiSeoContentStudio\WebsiteStudio\Converter\SelectorMatcher;
use AiSeoContentStudio\WebsiteStudio\Converter\StyleResolver;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftManager;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorCompat;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorMapper;
use AiSeoContentStudio\WebsiteStudio\Engine\IdGenerator;
use AiSeoContentStudio\WebsiteStudio\Engine\PageBuilder;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\Persistence\GeneratedPageRepository;

require_once dirname( __DIR__ ) . '/fakes.php';
require_once __DIR__ . '/DraftManagerTest.php';

/**
 * HTML/CSS → Elementor: selectors, cascade, structure detection, widget mapping, the
 * conversion report, security of untrusted input, and the converted save path.
 */
class ConverterTest extends TestCase {

	private static function fixture( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name );
	}

	private static function landing( array $options = array() ): array {
		return ( new HtmlConverter() )->convert( self::fixture( 'landing.html' ), self::fixture( 'landing.css' ), 'el.addEventListener("click", f); new Swiper(".s");', $options + array( 'fallback' => 'html' ) );
	}

	private static function dom( string $html ): \DOMDocument {
		$d = new \DOMDocument();
		libxml_use_internal_errors( true );
		$d->loadHTML( '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET );
		libxml_clear_errors();
		return $d;
	}

	public function testSelectorMatcher(): void {
		$m   = new SelectorMatcher();
		$doc = self::dom( '<div id="a" class="x y"><ul><li class="first" data-k="v1 v2">1</li><li>2</li></ul><p lang="en-GB">t</p></div>' );
		$li  = $doc->getElementsByTagName( 'li' )->item( 0 );
		$li2 = $doc->getElementsByTagName( 'li' )->item( 1 );
		$p   = $doc->getElementsByTagName( 'p' )->item( 0 );
		foreach ( array( 'li', '.first', '#a li', 'div.x.y > ul > li', 'ul li:first-child', '[data-k~=v2]', 'li[data-k^="v1"]', '*', 'html body div li' ) as $sel ) {
			$this->assertTrue( $m->matches( $li, $m->parse( $sel ) ), $sel );
		}
		$this->assertFalse( $m->matches( $li2, $m->parse( 'li:first-child' ) ) );
		$this->assertTrue( $m->matches( $li2, $m->parse( 'li:last-child' ) ) );
		$this->assertFalse( $m->matches( $li, $m->parse( 'div > li' ) ), 'child combinator' );
		$this->assertFalse( $m->matches( $li, $m->parse( '.z' ) ) );
		$this->assertTrue( $m->matches( $p, $m->parse( 'p[lang|=en]' ) ) );
		foreach ( array( 'a:hover', 'li::before', 'h1 + p', 'li ~ li', ':not(.x)', 'li:nth-child(2)' ) as $sel ) {
			$this->assertNull( $m->parse( $sel ), $sel . ' unsupported' );
		}
		$this->assertTrue( $m->parse( '#a .x li' )['specificity'] > $m->parse( '.x li' )['specificity'] );
	}

	public function testCascadeSpecificityMediaInlineAndInheritance(): void {
		$doc = self::dom( '<section class="s"><h2 class="t" style="color: #00ff00">Hi</h2><p>Body</p></section>' );
		$r   = new StyleResolver(
			'h2 { color: red; font-size: 30px } .s .t { color: blue } .t { color: green }
			 .s { color: #333; font-family: Inter; text-align: center }
			 @media (max-width: 1024px) { h2 { font-size: 24px } }
			 @media (max-width: 767px) { .s .t { font-size: 20px } }'
		);
		$h2 = $doc->getElementsByTagName( 'h2' )->item( 0 );
		$p  = $doc->getElementsByTagName( 'p' )->item( 0 );
		$this->assertSame( '#00ff00', $r->own( $h2 )['color'], 'inline style wins' );
		$this->assertSame( '30px', $r->own( $h2 )['font-size'] );
		$this->assertSame( '24px', $r->own( $h2, 'tablet' )['font-size'] );
		$this->assertSame( '20px', $r->own( $h2, 'mobile' )['font-size'], 'mobile inherits tablet rules and overrides them' );
		$this->assertSame( '#333', $r->computed( $p )['color'], 'inherited colour' );
		$this->assertSame( 'center', $r->computed( $p )['text-align'] );
		$this->assertFalse( isset( $r->own( $p )['color'] ), 'own() does not inherit' );
		$this->assertSame( 2, HtmlConverter::gridColumns( 'repeat(2, minmax(0, 1fr))' ) );
		$this->assertSame( 3, HtmlConverter::gridColumns( '1fr 2fr 1fr' ) );
		$this->assertSame( 4, HtmlConverter::gridColumns( 'repeat(auto-fit, minmax(280px, 1fr))' ) );
	}

	public function testLandingPageStructure(): void {
		$r = self::landing();
		$this->assertSame( array(), $r['errors'] );
		$sections = $r['document']['sections'];
		$this->assertSame( array( 'hero', 'services', 'services-more', 'section-4', 'section-5', 'section-6' ), array_column( $sections, 'id' ) );
		$this->assertSame( array( 'hero', 'cards', 'cards', 'about', 'faq', 'contact' ), array_column( $sections, 'type' ) );

		$hero = $sections[0];
		$this->assertSame( 'center', $hero['layout']['align'] );
		$this->assertSame( 'boxed', $hero['layout']['content_width'] );
		$this->assertSame( '#F4EFE6', $hero['style']['background'] );
		$this->assertSame( array( 'desktop' => '120px 0', 'tablet' => '', 'mobile' => '60px 16px' ), $hero['style']['padding'] );
		$h1 = $hero['elements'][0];
		$this->assertSame( 'Accounting that keeps you ahead', $h1['text'] );
		$this->assertSame( array( 'desktop' => '56px', 'tablet' => '', 'mobile' => '34px' ), $h1['style']['font_size'] );
		$this->assertSame( 'Poppins', $h1['style']['font_family'] );
		[ , , $primary, $secondary ] = $hero['elements'];
		$this->assertSame( array( 'button', '/contact', 'primary', '#8A8A2B', '#FFFFFF', '14px 28px', '8px' ), array( $primary['type'], $primary['url'], $primary['variant'], $primary['style']['background'], $primary['style']['color'], $primary['style']['padding'], $primary['style']['radius'] ) );
		$this->assertSame( array( 'secondary', '#1F365C', '2px' ), array( $secondary['variant'], $secondary['style']['border_color'], $secondary['style']['border_width'] ) );

		$services = $sections[1];
		$this->assertSame( 'grid', $services['layout']['kind'] );
		$this->assertSame( array( 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ), $services['layout']['columns'] );
		$this->assertSame( '24px', $services['layout']['gap'] );
		$this->assertSame( array( 'heading', 'text', 'group', 'group', 'group' ), array_column( $services['elements'], 'type' ) );
		$card = $services['elements'][2];
		$this->assertSame( array( 'background' => '#FFFFFF', 'radius' => '12px', 'shadow' => '0 4px 16px #0000001F', 'padding' => '32px' ), $card['style'] );
		$this->assertSame( array( 'icon', 'heading', 'text' ), array_column( $card['elements'], 'type' ) );
		$this->assertSame( 'calculator', $card['elements'][0]['icon'] );
		$this->assertSame( '<p>Monthly books, <strong>done right</strong>.</p>', $card['elements'][2]['html'] );
		$this->assertSame( '<ul><li>Payslips</li><li>Pensions</li></ul>', $services['elements'][3]['elements'][1]['html'] );
		$this->assertSame( 'https://cdn.example.com/tax.jpg', $services['elements'][4]['elements'][0]['url'] );
		$this->assertSame( 'All services', $sections[2]['elements'][0]['text'], 'element after the grid continues below it' );
		$this->assertSame( '0 0 80px', $sections[2]['style']['padding']['desktop'] );

		$about = $sections[3];
		$this->assertSame( 'split', $about['layout']['kind'] );
		$this->assertSame( array( 'desktop' => 2, 'tablet' => 2, 'mobile' => 1 ), $about['layout']['columns'] );
		$this->assertSame( '<p>We are a small team.</p><table><tr><th>Founded</th><td>Locally</td></tr></table>', $about['elements'][0]['elements'][1]['html'] );
		$this->assertSame( array( 'type' => 'video', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'provider' => 'youtube' ), $about['elements'][1]['elements'][0] );

		$faq = $sections[4]['elements'][1];
		$this->assertSame( 'accordion', $faq['type'] );
		$this->assertCount( 2, $faq['items'], 'consecutive <details> form one accordion' );
		$this->assertSame( 'Do you work remotely?', $faq['items'][1]['title'] );

		$contact = $sections[5];
		$this->assertSame( '#1F365C', $contact['style']['background'], 'gradient approximated' );
		$this->assertSame( '#FFFFFF', $contact['elements'][0]['style']['color'], 'unreadable heading colour fixed' );
		$this->assertSame( '<dl><dt>Phone</dt><dd>Call us</dd></dl>', $contact['elements'][1]['html'], 'fallback HTML widget' );
	}

	public function testReport(): void {
		$rep = self::landing()['report'];
		$this->assertSame( array( 'header', 'footer' ), array_column( $rep['skipped'], 'tag' ) );
		$this->assertSame( array( 'icon', 'form', 'svg', 'no_native_widget', 'image_url' ), array_column( $rep['unsupported'], 'category' ) );
		$this->assertSame( 1, $rep['fallbacks'] );
		$this->assertSame( 1, $rep['scripts_removed'] );
		$this->assertSame( 1, $rep['javascript']['inline_handlers_removed'] );
		$this->assertFalse( $rep['javascript']['executed'] );
		$this->assertSame( array( 'event_listeners', 'sliders' ), $rep['javascript']['features'] );
		$this->assertSame( array( 'https://cdn.example.com/theme.css' ), $rep['external_stylesheets'] );
		$this->assertSame( array( 'images/local.png' ), $rep['relative_urls'] );
		$this->assertSame( array( 'gradient_as_colour', 'contrast_fixed' ), $rep['approximations'] );
		$this->assertSame( 2, $rep['css']['unsupported_selectors'] );
		$this->assertSame( array( 'position' ), $rep['css']['unsupported_features'] );
		$this->assertSame( 81, $rep['coverage'] );

		$skip = self::landing( array( 'fallback' => 'skip', 'include_chrome' => true, 'base_url' => 'https://acme.test/site/' ) );
		$this->assertSame( 0, $skip['report']['fallbacks'] );
		$this->assertFalse( in_array( 'html', array_column( array_merge( ...array_column( $skip['document']['sections'], 'elements' ) ), 'type' ), true ), 'no HTML widget without the fallback option' );
		$this->assertSame( array(), $skip['report']['skipped'], 'header and footer included' );
		$urls = array();
		array_walk_recursive( $skip['document'], function ( $v, $k ) use ( &$urls ) { if ( 'url' === $k ) { $urls[] = $v; } } );
		$this->assertContains( 'https://acme.test/site/images/local.png', $urls, 'relative image resolved with the base URL' );
		$this->assertContains( 'https://acme.test/contact', $urls, 'root-relative link resolved' );
	}

	public function testTokensFromTheHtmlsOwnCss(): void {
		$t = self::landing()['tokens'];
		$this->assertSame( 'Poppins', $t['typography']['heading']['family'] );
		$this->assertSame( 'Inter', $t['typography']['body']['family'] );
		$this->assertSame( '#333333', $t['colors']['text'] );
		$this->assertSame( '#8A8A2B', $t['components']['button']['background'] );
		$this->assertSame( '1140px', $t['layout']['container_width'] );
		$this->assertSame( 'css', $t['sources']['colors'] );
	}

	public function testUntrustedInputIsNeutralised(): void {
		$html = '<section><h2 onmouseover="steal()">Title<script>bad()</script></h2>'
			. '<p>Hi <a href="javascript:alert(1)">x</a> <a href="https://ok.test/">ok</a><img src=x onerror=alert(1)></p>'
			. '<a class="btn" href="javascript:alert(2)">Go</a>'
			. '<img src="data:image/png;base64,AAAA" alt="d"><img src="https://ok.test/a.png" alt="&lt;b&gt;">'
			. '<iframe src="https://evil.test/embed"></iframe><object data="x.swf"></object>'
			. '<custom-widget><b>Text</b><script>x()</script></custom-widget>'
			. '<pre style="background: url(javascript:alert(1)); color: red">code</pre></section>';
		$r    = ( new HtmlConverter() )->convert( $html, '', '', array( 'fallback' => 'html' ) );
		$json = json_encode( $r['document'] );
		foreach ( array( 'script', 'onmouseover', 'onerror', 'javascript', 'data:image', 'evil.test', 'swf' ) as $bad ) {
			$this->assertFalse( str_contains( $json, $bad ), "removed: $bad" );
		}
		$els = $r['document']['sections'][0]['elements'];
		$this->assertSame( 'Title', $els[0]['text'] );
		$this->assertSame( '#', array_values( array_filter( $els, static fn ( $e ) => 'button' === $e['type'] ) )[0]['url'], 'unsafe button link replaced' );
		$this->assertContains( '<pre style="color: red">code</pre>', array_column( $els, 'html' ), 'fallback keeps only safe inline style' );
		$this->assertContains( 'iframe', array_column( $r['report']['unsupported'], 'category' ) );
		$this->assertContains( 'image_url', array_column( $r['report']['unsupported'], 'category' ), 'data: image not accepted' );
		$mapped = ( new ElementorMapper( new ElementorCompat( true, '3.25.0', false, true ), new IdGenerator() ) )->map( $r['document'] );
		$this->assertSame( array(), ( new DocumentValidator( new ElementorCompat( true, '3.25.0', false, true ), static fn (): bool => true ) )->validate( $mapped['elements'], DocumentValidator::MODE_CONVERTED )['errors'] );
	}

	public function testLimitsAndEmptyInput(): void {
		$c = new HtmlConverter();
		$this->assertSame( array( 'Paste or upload the HTML to convert.' ), $c->convert( '  ' )['errors'] );
		$this->assertSame( array( 'The HTML or CSS is larger than 1 MB.' ), $c->convert( str_repeat( 'a', HtmlConverter::MAX_HTML_BYTES + 1 ) )['errors'] );
		$this->assertSame( array( 'The HTML has too many elements (more than 8000).' ), $c->convert( str_repeat( '<b>x</b>', 8001 ) )['errors'] );
		$plain = $c->convert( 'Just a sentence.' );
		$this->assertSame( array(), $plain['errors'] );
		$this->assertSame( '<p>Just a sentence.</p>', $plain['document']['sections'][0]['elements'][0]['html'] );
		$many = $c->convert( str_repeat( '<section><h2>S</h2></section>', 45 ) );
		$this->assertCount( 40, $many['document']['sections'] );
		$this->assertTrue( $many['report']['truncated'] );
	}

	public function testConvertedPageIsSavedAsNativeDraft(): void {
		\FakeSite::reset();
		$compat        = new ElementorCompat( true, '3.25.0', false, true );
		$bridge        = new FakeBridge();
		$posts         = new FakePosts();
		$posts->caps   = array( 2 => array( 'edit_pages' ) );
		$drafts        = new DraftManager( $bridge, $compat, new DocumentValidator( $compat, static fn (): bool => true ), new GeneratedPageRepository(), new AuditLog(), $posts );
		$builder       = new PageBuilder( $bridge, $compat, $drafts );
		$r             = self::landing();
		$result        = $builder->build( $r['document'], $r['tokens'], array( 'user_id' => 2, 'generation_type' => 'converted' ), DocumentValidator::MODE_CONVERTED );
		$doc           = $bridge->docs[ $result['page_id'] ];
		$this->assertSame( 'container', $doc[0]['elType'] );
		$this->assertSame( 'Accounting that keeps you ahead', $doc[0]['elements'][0]['settings']['title'] );
		$this->assertSame( 34, $doc[0]['elements'][0]['settings']['typography_font_size_mobile']['size'] );
		$this->assertContains( 'html_fallback', $result['notes'] );
		$this->assertSame( 'converted', ( new GeneratedPageRepository() )->forPage( $result['page_id'] )['generation_type'] );

		// The same document is refused outside conversion mode (the HTML widget is a conversion fallback only).
		$reason = '';
		try {
			$builder->build( $r['document'], $r['tokens'], array( 'user_id' => 2 ) );
		} catch ( DraftException $e ) {
			$reason = $e->reason;
		}
		$this->assertSame( 'invalid_document', $reason );
	}
}
