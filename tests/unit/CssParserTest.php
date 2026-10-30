<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\WebsiteStudio\Analysis\CssStyleAnalyzer;
use AiSeoContentStudio\WebsiteStudio\Analysis\StyleSignals;
use AiSeoContentStudio\WebsiteStudio\Css\CssParser;

class CssParserTest extends TestCase {

	public function testParsesRulesMediaAndVariables(): void {
		$css    = <<<'CSS'
/* comment { not a rule } */
@import url("https://example.com/evil.css");
:root { --brand: #1F365C; --font: "Poppins", sans-serif; }
@font-face { font-family: X; src: url(x.woff2); }
h1, .title { color: var(--brand); font-family: var(--font); font-size: 48px; }
a[href="{"] { content: "}"; color: red }
@media (max-width: 1024px) { h1 { font-size: 36px; } }
@media screen and (max-width: 767px) { h1 { font-size: 28px !important; } @media (orientation: landscape) { p { margin: 0 } } }
@keyframes spin { from { transform: rotate(0) } to { transform: rotate(360deg) } }
@supports (display: grid) { .grid { display: grid; } }
CSS;
		$parsed = ( new CssParser() )->parse( $css );
		$rules  = $parsed['rules'];

		$this->assertSame( '#1F365C', $parsed['variables']['--brand'] );
		$this->assertSame( array( 'h1', '.title' ), $rules[1]['selectors'] );
		$this->assertSame( '#1F365C', $rules[1]['declarations']['color'], 'var() resolved' );
		$this->assertSame( '"Poppins", sans-serif', $rules[1]['declarations']['font-family'] );
		$this->assertSame( 'desktop', $rules[1]['device'] );
		$this->assertSame( array( 'a[href="{"]' ), $rules[2]['selectors'], 'braces inside quotes do not break parsing' );
		$this->assertSame( 'red', $rules[2]['declarations']['color'] );
		$this->assertSame( 'tablet', $rules[3]['device'] );
		$this->assertSame( '28px', $rules[4]['declarations']['font-size'], '!important stripped' );
		$this->assertSame( 'mobile', $rules[4]['device'] );
		$this->assertSame( 'mobile', $rules[5]['device'], 'nested media keeps the outer query' );
		$this->assertSame( array( '.grid' ), $rules[6]['selectors'], '@supports content is parsed' );
		$this->assertCount( 7, $rules, '@font-face, @keyframes and @import are skipped' );
		$this->assertSame( array( 767, 1024 ), $parsed['breakpoints'] );
		$this->assertFalse( $parsed['truncated'] );
	}

	public function testUnbalancedAndOversizedInputIsBounded(): void {
		$parsed = ( new CssParser() )->parse( 'p { color: red; } h2 { color: blue' );
		$this->assertCount( 1, $parsed['rules'] );
		$this->assertTrue( $parsed['truncated'] );

		$parsed = ( new CssParser() )->parse( str_repeat( 'p{color:red}', CssParser::MAX_RULES + 10 ) );
		$this->assertCount( CssParser::MAX_RULES, $parsed['rules'] );
		$this->assertTrue( $parsed['truncated'] );
	}

	public function testDeclarationsRejectBadPropertyNames(): void {
		$d = CssParser::declarations( 'color: red; <script>: x; --x: 1; background: url("a;b.png") no-repeat' );
		$this->assertSame( 'red', $d['color'] );
		$this->assertSame( '1', $d['--x'] );
		$this->assertSame( 'url("a;b.png") no-repeat', $d['background'], 'semicolons inside strings are kept' );
		$this->assertCount( 3, $d );
	}

	public function testSelectorRoles(): void {
		$this->assertSame( 'h2', CssStyleAnalyzer::role( '.entry-content h2' ) );
		$this->assertSame( 'h3', CssStyleAnalyzer::role( '.elementor-widget-heading .elementor-heading-title.elementor-size-h3' ) );
		$this->assertSame( 'button', CssStyleAnalyzer::role( '.wp-block-button__link' ) );
		$this->assertSame( 'button', CssStyleAnalyzer::role( '.elementor-kit-5 .elementor-button' ) );
		$this->assertSame( 'body', CssStyleAnalyzer::role( 'body' ) );
		$this->assertSame( 'link', CssStyleAnalyzer::role( '.entry a' ) );
		$this->assertSame( 'card', CssStyleAnalyzer::role( '.service-card' ) );
		$this->assertSame( '', CssStyleAnalyzer::role( 'a:hover' ), 'state styles are ignored' );
		$this->assertSame( '', CssStyleAnalyzer::role( 'h1::before' ) );
	}

	public function testStyleSignalsFromCss(): void {
		$signals = new StyleSignals();
		( new CssStyleAnalyzer() )->analyze(
			'body { color: #333; font-family: Inter, sans-serif; font-size: 16px; background-color: #fff }
			 h1 { font-family: "Poppins"; font-size: 48px; color: #1f365c }
			 @media (max-width: 767px) { h1 { font-size: 30px } }
			 .btn { background: #8a8a2b; color: #fff; border-radius: 8px; padding: 12px 24px }
			 .card { border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.1) }
			 .container { max-width: 1140px }',
			$signals
		);
		$this->assertSame( '#333', $signals->top( 'colors.text' ) );
		$this->assertSame( 'Inter', $signals->top( 'fonts.body' ) );
		$this->assertSame( 'Poppins', $signals->top( 'fonts.heading' ) );
		$this->assertSame( '48px', $signals->top( 'sizes.h1.desktop' ) );
		$this->assertSame( '30px', $signals->top( 'sizes.h1.mobile' ) );
		$this->assertSame( '#8a8a2b', $signals->top( 'colors.button_bg' ) );
		$this->assertSame( '8px', $signals->top( 'radii.button' ) );
		$this->assertSame( '12px', $signals->top( 'radii.card' ) );
		$this->assertSame( '1140px', $signals->top( 'layout.container_width' ) );
		$this->assertSame( '767', $signals->top( 'breakpoints.mobile' ) );
	}

	public function testStyleSignalsRankAndCap(): void {
		$s = new StyleSignals();
		$s->add( 'x', 'a', 1 );
		$s->add( 'x', 'b', 3 );
		$s->add( 'x', 'a', 1 );
		$s->add( 'x', '', 10 );
		$s->add( 'x', array( 'not scalar' ), 10 );
		$this->assertSame( array( 'b' => 3.0, 'a' => 2.0 ), $s->ranked( 'x' ) );
		for ( $i = 0; $i < 100; $i++ ) {
			$s->add( 'y', "v$i" );
		}
		$this->assertCount( 64, $s->ranked( 'y' ) );
		$this->assertSame( $s->toArray(), StyleSignals::fromArray( $s->toArray() )->toArray() );
	}
}
