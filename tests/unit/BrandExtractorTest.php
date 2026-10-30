<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\WebsiteStudio\Analysis\BlockDocumentAnalyzer;
use AiSeoContentStudio\WebsiteStudio\Analysis\CssStyleAnalyzer;
use AiSeoContentStudio\WebsiteStudio\Analysis\ElementorDocumentAnalyzer;
use AiSeoContentStudio\WebsiteStudio\Analysis\StyleSignals;
use AiSeoContentStudio\WebsiteStudio\Design\BrandExtractor;

require_once __DIR__ . '/ElementorDocumentAnalyzerTest.php';

class BrandExtractorTest extends TestCase {

	public function testTokensFromElementorPage(): void {
		$r = ( new ElementorDocumentAnalyzer() )->analyze( ElementorDocumentAnalyzerTest::document(), ElementorDocumentAnalyzerTest::kit() );
		$t = ( new BrandExtractor() )->extract(
			array(
				'elementor_kit'     => $r['kit_signals'],
				'elementor_widgets' => $r['signals'],
			)
		);
		$this->assertSame( '#1F365C', $t['colors']['primary'] );
		$this->assertSame( '#54595F', $t['colors']['secondary'] );
		$this->assertSame( '#8A8A2B', $t['colors']['accent'] );
		$this->assertSame( '#3F3F3F', $t['colors']['text'] );
		$this->assertSame( '#1F365C', $t['colors']['heading'] );
		$this->assertSame( '#F4EFE6', $t['colors']['surface'] );
		$this->assertSame( 'Poppins', $t['typography']['heading']['family'] );
		$this->assertSame( '600', $t['typography']['heading']['weight'] );
		$this->assertSame( 'Inter', $t['typography']['body']['family'] );
		$this->assertSame( '52px', $t['typography']['scale']['h1']['desktop'] );
		$this->assertSame( '32px', $t['typography']['scale']['h1']['mobile'] );
		$this->assertSame( '8px', $t['components']['button']['radius'] );
		$this->assertSame( '#8A8A2B', $t['components']['button']['background'] );
		$this->assertSame( '12px', $t['components']['card']['radius'] );
		$this->assertSame( '0 4px 16px 0 #0000001F', $t['components']['card']['shadow'] );
		$this->assertSame( '100px 0', $t['spacing']['section_padding']['desktop'] );
		$this->assertSame( '1200px', $t['layout']['container_width'] );
		$this->assertSame( 1024, $t['layout']['breakpoints']['tablet'] );
		$this->assertSame( 767, $t['layout']['breakpoints']['mobile'] );
		$this->assertSame( 'elementor_kit', $t['sources']['colors'] );
		$this->assertSame( 'elementor_kit', $t['sources']['typography'] );
		$this->assertSame( '#1F365C', $t['palette'][0]['hex'], 'most used colour first' );
		$this->assertContains( 'primary', $t['palette'][0]['roles'] );
	}

	public function testNothingIsInventedWithoutSignals(): void {
		$t = ( new BrandExtractor() )->extract( array( 'css' => new StyleSignals() ) );
		foreach ( $t['colors'] as $role => $value ) {
			$this->assertSame( '', $value, "colors.$role" );
		}
		$this->assertSame( '', $t['typography']['body']['family'] );
		$this->assertSame( array(), $t['palette'] );
		$this->assertSame( 'none', $t['sources']['colors'] );
	}

	public function testPrimaryFallsBackToBrandHueNotNeutral(): void {
		$css = new StyleSignals();
		( new CssStyleAnalyzer() )->analyze( 'body{color:#222;background:#fff} .section{background:#f7f7f7} a{color:#0a7d55} .card{background:#e63946}', $css );
		$t = ( new BrandExtractor() )->extract( array( 'css' => $css ) );
		$this->assertTrue( in_array( $t['colors']['primary'], array( '#0A7D55', '#E63946' ), true ), 'primary is a saturated colour, got ' . $t['colors']['primary'] );
		$this->assertSame( '#222222', $t['colors']['text'] );
		$this->assertSame( '#FFFFFF', $t['colors']['background'] );
		$this->assertTrue( BrandExtractor::isNeutral( '#F7F7F7' ) );
		$this->assertFalse( BrandExtractor::isNeutral( '#1F365C' ) );
		$this->assertSame( 'css', $t['sources']['colors'] );
	}

	public function testThemeJsonAndBlocks(): void {
		$settings = array(
			'color'      => array( 'palette' => array( 'theme' => array( array( 'slug' => 'primary', 'color' => '#004E89' ), array( 'slug' => 'base', 'color' => '#FFFFFF' ), array( 'slug' => 'contrast', 'color' => '#111111' ) ) ) ),
			'typography' => array(
				'fontFamilies' => array( 'theme' => array( array( 'slug' => 'heading', 'fontFamily' => '"DM Serif Display", serif' ), array( 'slug' => 'body', 'fontFamily' => 'Lato, sans-serif' ) ) ),
				'fontSizes'    => array( 'theme' => array( array( 'slug' => 'x-large', 'size' => '42px' ) ) ),
			),
			'layout'     => array( 'contentSize' => '720px', 'wideSize' => '1200px' ),
		);
		$styles   = array(
			'color'      => array( 'background' => 'var:preset|color|base', 'text' => 'var(--wp--preset--color--contrast)' ),
			'typography' => array( 'fontFamily' => 'var:preset|font-family|body', 'fontSize' => 'clamp(1rem, 0.9rem + 0.5vw, 1.125rem)' ),
			'elements'   => array(
				'heading' => array( 'typography' => array( 'fontFamily' => 'var:preset|font-family|heading', 'fontWeight' => '400' ) ),
				'h1'      => array( 'typography' => array( 'fontSize' => 'var:preset|font-size|x-large' ) ),
				'button'  => array( 'color' => array( 'background' => 'var:preset|color|primary', 'text' => 'var:preset|color|base' ), 'border' => array( 'radius' => '6px' ) ),
			),
		);
		$analyzer = new BlockDocumentAnalyzer();
		$theme    = $analyzer->analyzeTheme( $settings, $styles );
		$blocks   = array(
			array( 'blockName' => 'core/cover', 'attrs' => array(), 'innerBlocks' => array( array( 'blockName' => 'core/heading', 'attrs' => array( 'level' => 1 ), 'innerBlocks' => array(), 'innerHTML' => '<h1>Welcome</h1>' ) ), 'innerHTML' => '' ),
			array( 'blockName' => 'core/columns', 'attrs' => array(), 'innerBlocks' => array_fill( 0, 3, array( 'blockName' => 'core/column', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '' ) ), 'innerHTML' => '' ),
			array( 'blockName' => 'core/details', 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => '<details><summary>Why?</summary></details>' ),
		);
		$page = $analyzer->analyze( $blocks, $settings );
		$this->assertSame( array( 'hero', 'cards', 'faq' ), array_column( $page['sections'], 'type' ) );
		$this->assertSame( 'blocks', $page['structure']['builder'] );

		$t = ( new BrandExtractor() )->extract( array( 'theme_json' => $theme, 'elementor_widgets' => $page['signals'] ) );
		$this->assertSame( '#004E89', $t['colors']['primary'] );
		$this->assertSame( '#111111', $t['colors']['text'] );
		$this->assertSame( '#FFFFFF', $t['colors']['background'] );
		$this->assertSame( 'DM Serif Display', $t['typography']['heading']['family'] );
		$this->assertSame( 'Lato', $t['typography']['body']['family'] );
		$this->assertSame( '42px', $t['typography']['scale']['h1']['desktop'] );
		$this->assertSame( '1.125rem', $t['typography']['scale']['body']['desktop'], 'clamp() keeps its maximum' );
		$this->assertSame( '6px', $t['components']['button']['radius'] );
		$this->assertSame( '1200px', $t['layout']['container_width'] );
		$this->assertSame( 'theme_json', $t['sources']['colors'] );
	}

	public function testClassicPageSections(): void {
		$page = ( new BlockDocumentAnalyzer() )->analyze( array( array( 'blockName' => null, 'attrs' => array(), 'innerHTML' => 'x' ) ), array(), '<p>Intro</p><h2>Our services</h2><p>Text</p><h2>How much does it cost?</h2><p>Answer</p>' );
		$this->assertSame( 'classic', $page['structure']['builder'] );
		$this->assertSame( array( 'hero', 'content', 'faq' ), array_column( $page['sections'], 'type' ) );
		$this->assertContains( 'classic_content', $page['notes'] );
	}
}
