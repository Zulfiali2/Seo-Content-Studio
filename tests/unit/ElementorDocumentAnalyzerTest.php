<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\WebsiteStudio\Analysis\ElementorDocumentAnalyzer;

class ElementorDocumentAnalyzerTest extends TestCase {

	/** Elementor kit settings as stored in _elementor_page_settings. */
	public static function kit(): array {
		return array(
			'system_colors'          => array(
				array( '_id' => 'primary', 'title' => 'Primary', 'color' => '#1F365C' ),
				array( '_id' => 'secondary', 'title' => 'Secondary', 'color' => '#54595F' ),
				array( '_id' => 'text', 'title' => 'Text', 'color' => '#3F3F3F' ),
				array( '_id' => 'accent', 'title' => 'Accent', 'color' => '#8A8A2B' ),
			),
			'custom_colors'          => array( array( '_id' => 'a1b2c3d', 'title' => 'Sand', 'color' => '#F4EFE6' ) ),
			'system_typography'      => array(
				array( '_id' => 'primary', 'typography_typography' => 'custom', 'typography_font_family' => 'Poppins', 'typography_font_weight' => '600' ),
				array( '_id' => 'text', 'typography_typography' => 'custom', 'typography_font_family' => 'Inter', 'typography_font_weight' => '400' ),
				array( '_id' => 'accent', 'typography_typography' => 'custom', 'typography_font_family' => 'Inter', 'typography_font_weight' => '500' ),
			),
			'container_width'        => array( 'unit' => 'px', 'size' => 1200 ),
			'button_border_radius'   => array( 'unit' => 'px', 'top' => '8', 'right' => '8', 'bottom' => '8', 'left' => '8', 'isLinked' => true ),
			'h1_typography_font_size' => array( 'unit' => 'px', 'size' => 52 ),
			'h1_typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 32 ),
			'viewport_tablet'        => 1024,
			'viewport_mobile'        => 767,
		);
	}

	private static function w( string $id, string $type, array $settings ): array {
		return array( 'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => array() );
	}

	private static function c( string $id, array $settings, array $children ): array {
		return array( 'id' => $id, 'elType' => 'container', 'settings' => $settings, 'elements' => $children );
	}

	/** A five-section landing page. */
	public static function document(): array {
		$card = static fn ( string $id, string $title ): array => self::c(
			$id,
			array(
				'background_background'      => 'classic',
				'background_color'           => '#FFFFFF',
				'border_radius'              => array( 'unit' => 'px', 'top' => '12', 'right' => '12', 'bottom' => '12', 'left' => '12' ),
				'box_shadow_box_shadow_type' => 'yes',
				'box_shadow_box_shadow'      => array( 'horizontal' => 0, 'vertical' => 4, 'blur' => 16, 'spread' => 0, 'color' => 'rgba(0,0,0,0.12)' ),
				'padding'                    => array( 'unit' => 'px', 'top' => '32', 'right' => '24', 'bottom' => '32', 'left' => '24' ),
			),
			array(
				self::w( $id . 'h', 'heading', array( 'title' => $title, 'header_size' => 'h3' ) ),
				self::w( $id . 't', 'text-editor', array( 'editor' => '<p>Short description.</p>' ) ),
			)
		);
		return array(
			self::c(
				'hero0001',
				array(
					'background_background' => 'classic',
					'background_color'      => '#F4EFE6',
					'padding'               => array( 'unit' => 'px', 'top' => '100', 'right' => '0', 'bottom' => '100', 'left' => '0' ),
					'padding_mobile'        => array( 'unit' => 'px', 'top' => '50', 'right' => '0', 'bottom' => '50', 'left' => '0' ),
					'boxed_width'           => array( 'unit' => 'px', 'size' => 1200 ),
				),
				array(
					self::w(
						'hh000001',
						'heading',
						array(
							'title'                 => 'Professional Accounting Services',
							'header_size'           => 'h1',
							'__globals__'           => array( 'title_color' => 'globals/colors?id=primary', 'typography_typography' => 'globals/typography?id=primary' ),
						)
					),
					self::w( 'ht000001', 'text-editor', array( 'editor' => '<p>Expert financial solutions for your business.</p>', '__globals__' => array( 'text_color' => 'globals/colors?id=text' ) ) ),
					self::w( 'hb000001', 'button', array( 'text' => 'Get started', '__globals__' => array( 'background_color' => 'globals/colors?id=accent' ), 'border_radius' => array( 'unit' => 'px', 'top' => '8', 'right' => '8', 'bottom' => '8', 'left' => '8' ), 'text_padding' => array( 'unit' => 'px', 'top' => '14', 'right' => '28', 'bottom' => '14', 'left' => '28' ) ) ),
					self::w( 'hi000001', 'image', array( 'image' => array( 'id' => 5, 'url' => 'https://example.com/a.jpg' ) ) ),
				)
			),
			self::c( 'cards001', array( 'flex_direction' => 'row', 'flex_direction_mobile' => 'column' ), array( $card( 'card1aa', 'Bookkeeping' ), $card( 'card2aa', 'Payroll' ), $card( 'card3aa', 'Tax returns' ) ) ),
			self::c( 'faq00001', array(), array( self::w( 'fh000001', 'heading', array( 'title' => 'Questions' ) ), self::w( 'fa000001', 'accordion', array() ) ) ),
			self::c( 'contact1', array(), array( self::w( 'cf000001', 'form', array() ) ) ),
			self::c( 'cta00001', array( 'background_background' => 'classic', '__globals__' => array( 'background_color' => 'globals/colors?id=primary' ) ), array( self::w( 'ch000001', 'heading', array( 'title' => 'Ready to talk?', 'title_color' => '#FFFFFF' ) ), self::w( 'cb000001', 'button', array( 'text' => 'Book a call' ) ) ) ),
		);
	}

	public function testStructureAndSections(): void {
		$r = ( new ElementorDocumentAnalyzer() )->analyze( self::document(), self::kit(), array( 'heading', 'text-editor', 'button', 'image', 'accordion' ) );
		$s = $r['structure'];
		$this->assertSame( 'elementor', $s['builder'] );
		$this->assertTrue( $s['uses_containers'] );
		$this->assertFalse( $s['uses_legacy_sections'] );
		$this->assertSame( 3, $s['max_depth'] );
		$this->assertSame( 15, $s['widget_count'] );
		$this->assertSame( 2, $s['responsive_settings'] );
		$this->assertSame( 4, $s['global_colors'] );
		$this->assertSame( 1, $s['global_typography'] );
		$this->assertSame( array( 'form' ), $s['unavailable_widgets'] );
		$this->assertContains( 'unavailable_widgets', $r['notes'] );

		$types = array_column( $r['sections'], 'type' );
		$this->assertSame( array( 'hero', 'cards', 'faq', 'contact', 'cta' ), $types );
		$this->assertSame( 'Professional Accounting Services', $r['sections'][0]['heading'] );
		$this->assertSame( 3, $r['sections'][1]['columns'] );
	}

	public function testKitAndWidgetSignals(): void {
		$r   = ( new ElementorDocumentAnalyzer() )->analyze( self::document(), self::kit() );
		$kit = $r['kit_signals'];
		$w   = $r['signals'];
		$this->assertSame( '#1F365C', $kit->top( 'colors.primary' ) );
		$this->assertSame( '#8A8A2B', $kit->top( 'colors.accent' ) );
		$this->assertSame( 'Poppins', $kit->top( 'fonts.heading' ) );
		$this->assertSame( 'Inter', $kit->top( 'fonts.body' ) );
		$this->assertSame( '52px', $kit->top( 'sizes.h1.desktop' ) );
		$this->assertSame( '32px', $kit->top( 'sizes.h1.mobile' ) );
		$this->assertSame( '1200px', $kit->top( 'layout.container_width' ) );
		$this->assertSame( '8px', $kit->top( 'radii.button' ) );
		$this->assertSame( '1024', $kit->top( 'breakpoints.tablet' ) );

		// Globals resolve to kit values.
		$this->assertSame( '#1F365C', $w->top( 'colors.heading' ) );
		$this->assertSame( '#3F3F3F', $w->top( 'colors.text' ) );
		$this->assertSame( '#8A8A2B', $w->top( 'colors.button_bg' ) );
		$this->assertSame( 'Poppins', $w->top( 'fonts.heading' ) );
		// Local settings.
		$this->assertSame( '12px', $w->top( 'radii.card' ) );
		$this->assertSame( '0 4px 16px 0 rgba(0,0,0,0.12)', $w->top( 'shadows.card' ) );
		$this->assertSame( '32px 24px', $w->top( 'paddings.card' ) );
		$this->assertSame( '14px 28px', $w->top( 'paddings.button' ) );
		$this->assertSame( '100px 0', $w->top( 'paddings.section.desktop' ) );
		$this->assertSame( '50px 0', $w->top( 'paddings.section.mobile' ) );
		$this->assertSame( '#F4EFE6', $w->top( 'colors.section_bg' ) );
		$this->assertSame( '#FFFFFF', $w->top( 'colors.card_bg' ) );
	}

	public function testControlValueHelpers(): void {
		$this->assertSame( '48px', ElementorDocumentAnalyzer::slider( array( 'unit' => 'px', 'size' => 48 ) ) );
		$this->assertSame( '1.5', ElementorDocumentAnalyzer::slider( array( 'unit' => 'em', 'size' => '1.5' ), true ) );
		$this->assertSame( '', ElementorDocumentAnalyzer::slider( array( 'unit' => 'px', 'size' => '' ) ) );
		$this->assertSame( '', ElementorDocumentAnalyzer::slider( array( 'unit' => 'evil', 'size' => 3 ) ) );
		$this->assertSame( '10px', ElementorDocumentAnalyzer::dimensions( array( 'unit' => 'px', 'top' => '10', 'right' => '10', 'bottom' => '10', 'left' => '10' ) ) );
		$this->assertSame( '1em 2em 3em 4em', ElementorDocumentAnalyzer::dimensions( array( 'unit' => 'em', 'top' => 1, 'right' => 2, 'bottom' => 3, 'left' => 4 ) ) );
		$this->assertSame( '', ElementorDocumentAnalyzer::dimensions( array( 'unit' => 'px', 'top' => '10', 'right' => '', 'bottom' => '10', 'left' => '10' ) ), 'partly set dimensions are ignored' );
	}

	public function testHostileAndMalformedTreesAreSafe(): void {
		$deep = array( 'id' => 'x', 'elType' => 'container', 'settings' => array(), 'elements' => array() );
		for ( $i = 0; $i < 100; $i++ ) {
			$deep = array( 'id' => 'x' . $i, 'elType' => 'container', 'settings' => array(), 'elements' => array( $deep ) );
		}
		$r = ( new ElementorDocumentAnalyzer() )->analyze( array( $deep, 'not an element', array( 'elType' => 'widget', 'widgetType' => '<script>' ) ) );
		$this->assertSame( 40, $r['structure']['max_depth'], 'depth is capped' );
		$this->assertSame( array(), $r['structure']['widgets'], 'invalid widget names are not recorded' );
		$this->assertContains( 'no_kit', $r['notes'] );
	}

	public function testLegacySectionsAndAtomicElements(): void {
		$doc = array(
			array(
				'id'       => 'sec00001',
				'elType'   => 'section',
				'settings' => array( 'content_width' => array( 'unit' => 'px', 'size' => 1140 ) ),
				'elements' => array(
					array( 'id' => 'col00001', 'elType' => 'column', 'settings' => array(), 'elements' => array( self::w( 'w0000001', 'heading', array( 'title' => 'Hello', 'header_size' => 'h1' ) ), self::w( 'w0000002', 'button', array() ) ) ),
				),
			),
			array( 'id' => 'atom0001', 'elType' => 'e-flexbox', 'settings' => array(), 'elements' => array( array( 'id' => 'atom0002', 'elType' => 'widget', 'widgetType' => 'e-heading', 'settings' => array( 'title' => array( '$$type' => 'string', 'value' => 'Atomic' ) ), 'elements' => array() ) ) ),
		);
		$r = ( new ElementorDocumentAnalyzer() )->analyze( $doc );
		$this->assertTrue( $r['structure']['uses_legacy_sections'] );
		$this->assertSame( 'hero', $r['sections'][0]['type'] );
		$this->assertSame( '1140px', $r['signals']->top( 'layout.container_width' ) );
		$this->assertSame( 2, $r['structure']['atomic_elements'] );
		$this->assertContains( 'atomic_elements', $r['notes'] );
	}
}
