<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\WebsiteStudio\Design\DesignTokens;

class DesignTokensTest extends TestCase {

	public function testColorNormalisation(): void {
		$this->assertSame( '#AABBCC', DesignTokens::color( '#abc' ) );
		$this->assertSame( '#1F365C', DesignTokens::color( ' #1f365c ' ) );
		$this->assertSame( '#1F365C', DesignTokens::color( '#1f365cff' ) );
		$this->assertSame( '#1F365C80', DesignTokens::color( '#1f365c80' ) );
		$this->assertSame( '#FF8000', DesignTokens::color( 'rgb(255, 128, 0)' ) );
		$this->assertSame( '#FF800080', DesignTokens::color( 'rgba(255,128,0,0.5)' ) );
		$this->assertSame( '#FF8000', DesignTokens::color( 'rgb(255 128 0 / 100%)' ) );
		$this->assertNull( DesignTokens::color( 'rgba(0,0,0,0)' ), 'transparent is not a brand colour' );
		$this->assertNull( DesignTokens::color( 'rgb(300,0,0)' ) );
		$this->assertNull( DesignTokens::color( 'red' ) );
		$this->assertNull( DesignTokens::color( '#12345' ) );
		$this->assertNull( DesignTokens::color( '#fff;background:url(x)' ) );
		$this->assertSame( '', DesignTokens::color( '' ) );
	}

	public function testLengthsAndBoxes(): void {
		$this->assertSame( '16px', DesignTokens::length( '16PX' ) );
		$this->assertSame( '1.5rem', DesignTokens::length( '1.5rem' ) );
		$this->assertSame( '0', DesignTokens::length( '0' ) );
		$this->assertNull( DesignTokens::length( '16' ) );
		$this->assertNull( DesignTokens::length( 'calc(100% - 2px)' ) );
		$this->assertNull( DesignTokens::length( '10px;color:red' ) );
		$this->assertSame( '100px 0', DesignTokens::box( '100px   0' ) );
		$this->assertSame( '1px 2px 3px 4px', DesignTokens::box( '1px 2px 3px 4px' ) );
		$this->assertNull( DesignTokens::box( '1px 2px 3px 4px 5px' ) );
		$this->assertNull( DesignTokens::box( '1px auto' ) );
	}

	public function testShadow(): void {
		$this->assertSame( '0 4px 12px 0 #00000026', DesignTokens::shadow( '0 4px 12px 0 rgba(0,0,0,0.15)' ) );
		$this->assertSame( 'inset 0 1px #FFFFFF', DesignTokens::shadow( 'inset 0 1px #fff' ) );
		$this->assertSame( 'none', DesignTokens::shadow( 'none' ) );
		$this->assertNull( DesignTokens::shadow( '0 4px red' ) );
		$this->assertNull( DesignTokens::shadow( '0 4px 12px #000, 0 0 1px #000' ), 'multiple shadows are not a single token' );
	}

	public function testFontFamilyRejectsCssInjection(): void {
		$this->assertSame( 'Open Sans, sans-serif', DesignTokens::fontFamily( '"Open Sans", sans-serif' ) );
		$this->assertSame( 'Poppins', DesignTokens::fontFamily( 'Poppins' ) );
		$this->assertNull( DesignTokens::fontFamily( 'Arial; } body { display:none' ) );
		$this->assertNull( DesignTokens::fontFamily( '<script>' ) );
		$this->assertNull( DesignTokens::fontFamily( 'var(--x)' ) );
		$this->assertSame( '700', DesignTokens::fontWeight( '700' ) );
		$this->assertSame( 'bold', DesignTokens::fontWeight( 'Bold' ) );
		$this->assertNull( DesignTokens::fontWeight( '750' ) );
		$this->assertSame( '1.6', DesignTokens::lineHeight( '1.6' ) );
		$this->assertSame( '24px', DesignTokens::lineHeight( '24px' ) );
	}

	public function testNormalizeKeepsValidAndReportsInvalid(): void {
		$errors = array();
		$tokens = DesignTokens::normalize(
			array(
				'schema_version' => 1,
				'colors'         => array(
					'primary' => '#1f365c',
					'text'    => 'not-a-colour',
					'unknown' => '#000000',
				),
				'palette'        => array(
					array(
						'hex'   => '#1f365c',
						'count' => 3,
						'roles' => array( 'primary', 'evil' ),
					),
					array( 'hex' => '#1F365C' ),
					array( 'hex' => 'nope' ),
				),
				'typography'     => array(
					'heading' => array( 'family' => 'Poppins', 'weight' => '600' ),
					'scale'   => array( 'h1' => array( 'desktop' => '48px', 'mobile' => '90000px' ) ),
				),
				'layout'         => array( 'breakpoints' => array( 'tablet' => 1024, 'mobile' => 50 ) ),
				'sources'        => array( 'colors' => 'elementor_kit', 'bad' => 'somewhere' ),
			),
			$errors
		);
		$this->assertSame( '#1F365C', $tokens['colors']['primary'] );
		$this->assertSame( '', $tokens['colors']['text'] );
		$this->assertFalse( isset( $tokens['colors']['unknown'] ), 'unknown roles are dropped' );
		$this->assertCount( 1, $tokens['palette'], 'duplicates and invalid palette entries are removed' );
		$this->assertSame( array( 'primary' ), $tokens['palette'][0]['roles'] );
		$this->assertSame( 'Poppins', $tokens['typography']['heading']['family'] );
		$this->assertSame( '48px', $tokens['typography']['scale']['h1']['desktop'] );
		$this->assertSame( '', $tokens['typography']['scale']['h1']['mobile'] );
		$this->assertSame( 1024, $tokens['layout']['breakpoints']['tablet'] );
		$this->assertSame( 0, $tokens['layout']['breakpoints']['mobile'] );
		$this->assertSame( array( 'colors' => 'elementor_kit' ), $tokens['sources'] );
		$this->assertContains( 'colors.text: invalid value', $errors );
		$this->assertContains( 'typography.scale.h1.mobile: invalid value', $errors );
		$this->assertContains( 'layout.breakpoints.mobile: out of range', $errors );
	}

	public function testNormalizeIsIdempotentAndRejectsWrongVersion(): void {
		$errors = array();
		$once   = DesignTokens::normalize( array( 'colors' => array( 'primary' => '#abc' ) ) );
		$this->assertSame( $once, DesignTokens::normalize( $once, $errors ) );
		$this->assertSame( array(), $errors );
		DesignTokens::normalize( array( 'schema_version' => 99 ), $errors );
		$this->assertContains( 'schema_version: unsupported version', $errors );
		$errors = array();
		DesignTokens::normalize( 'string', $errors );
		$this->assertContains( 'tokens: must be an object', $errors );
	}
}
