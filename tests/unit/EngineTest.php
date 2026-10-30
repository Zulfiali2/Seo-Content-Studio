<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\WebsiteStudio\Design\DesignTokens;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorCompat;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorMapper;
use AiSeoContentStudio\WebsiteStudio\Engine\HtmlFragment;
use AiSeoContentStudio\WebsiteStudio\Engine\IdGenerator;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;

/**
 * Native Elementor engine: schema, sanitiser, mapper and validator.
 */
class EngineTest extends TestCase {

	public static function landingPage(): array {
		return array(
			'schema_version' => 1,
			'page'           => array( 'title' => 'Accounting Services', 'slug' => 'accounting-services' ),
			'sections'       => array(
				array(
					'id'       => 'hero-001',
					'type'     => 'hero',
					'layout'   => array( 'kind' => 'stack', 'align' => 'center', 'min_height' => '520px' ),
					'style'    => array( 'background' => 'token:surface' ),
					'elements' => array(
						array( 'type' => 'heading', 'text' => 'Professional Accounting Services', 'level' => 'h1' ),
						array( 'type' => 'text', 'html' => '<p>Expert financial solutions for your business.</p>' ),
						array( 'type' => 'button', 'text' => 'Get started', 'url' => '/contact/' ),
						array( 'type' => 'button', 'text' => 'Our services', 'url' => '#services', 'variant' => 'secondary' ),
					),
				),
				array(
					'id'       => 'services',
					'type'     => 'cards',
					'layout'   => array( 'kind' => 'grid', 'columns' => array( 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ), 'gap' => '24px' ),
					'elements' => array(
						array( 'type' => 'heading', 'text' => 'What we do', 'level' => 'h2' ),
						array( 'type' => 'group', 'elements' => array( array( 'type' => 'icon', 'icon' => 'calculator' ), array( 'type' => 'heading', 'text' => 'Bookkeeping', 'level' => 'h3' ), array( 'type' => 'text', 'html' => 'Monthly books.' ) ) ),
						array( 'type' => 'group', 'elements' => array( array( 'type' => 'heading', 'text' => 'Payroll', 'level' => 'h3' ), array( 'type' => 'list', 'items' => array( 'Payslips', 'Pensions' ) ) ) ),
						array( 'type' => 'group', 'elements' => array( array( 'type' => 'heading', 'text' => 'Tax', 'level' => 'h3' ), array( 'type' => 'image', 'attachment_id' => 42, 'alt' => 'Tax forms on a desk' ) ) ),
					),
				),
				array(
					'id'       => 'faq',
					'type'     => 'faq',
					'elements' => array(
						array( 'type' => 'heading', 'text' => 'Questions', 'level' => 'h2' ),
						array( 'type' => 'accordion', 'items' => array( array( 'title' => 'How much does it cost?', 'html' => '<p>It depends on the work.</p>' ) ) ),
					),
				),
			),
		);
	}

	public static function tokens(): array {
		return DesignTokens::normalize(
			array(
				'colors'     => array( 'primary' => '#1F365C', 'accent' => '#8A8A2B', 'text' => '#3F3F3F', 'heading' => '#1F365C', 'surface' => '#F4EFE6' ),
				'typography' => array(
					'heading' => array( 'family' => 'Poppins', 'weight' => '600' ),
					'body'    => array( 'family' => 'Inter' ),
					'scale'   => array( 'h1' => array( 'desktop' => '52px', 'mobile' => '32px' ) ),
				),
				'components' => array(
					'button' => array( 'radius' => '8px', 'padding' => '14px 28px' ),
					'card'   => array( 'radius' => '12px', 'shadow' => '0 4px 16px 0 #0000001F', 'background' => '#FFFFFF' ),
				),
				'spacing'    => array( 'section_padding' => array( 'desktop' => '100px 0' ) ),
				'layout'     => array( 'container_width' => '1200px' ),
			)
		);
	}

	private static function compat( bool $containers = true, array $widgets = array() ): ElementorCompat {
		return new ElementorCompat( true, '3.25.0', false, $containers, $widgets );
	}

	private static function ids(): IdGenerator {
		$n = 0;
		return new IdGenerator( static function () use ( &$n ): string {
			return sprintf( 'a%06x', ++$n );
		} );
	}

	private static function find( array $elements, callable $match ): array {
		$out = array();
		array_walk(
			$elements,
			function ( $el ) use ( &$out, $match ) {
				if ( $match( $el ) ) {
					$out[] = $el;
				}
				$out = array_merge( $out, self::find( $el['elements'] ?? array(), $match ) );
			}
		);
		return $out;
	}

	/* ---------------------------------------------------------------- Sanitiser */

	public function testHtmlFragmentStripsExecutableContent(): void {
		$this->assertSame( '<p>Hi <strong>there</strong></p>', HtmlFragment::clean( '<p onclick="x()">Hi <strong style="color:red">there</strong></p>' ) );
		$this->assertSame( '<p>alert</p>', HtmlFragment::clean( '<script>alert(1)</script><p>alert</p>' ) );
		$this->assertSame( '<p>click</p>', HtmlFragment::clean( '<p><a href="javascript:alert(1)">click</a></p>' ) );
		$this->assertSame( '<p>x</p>', HtmlFragment::clean( '<p><a href="jav&#x09;ascript:alert(1)">x</a></p>' ), 'entity-encoded control character in scheme' );
		$this->assertSame( '<p><a href="https://example.com/a?b=1&amp;c=2">ok</a></p>', HtmlFragment::clean( '<p><a href="https://example.com/a?b=1&c=2" target="_blank">ok</a></p>' ) );
		$this->assertSame( '<p>Text</p>', HtmlFragment::clean( 'Text' ) );
		$this->assertSame( '<p>A</p><p>B</p>', HtmlFragment::clean( '<div>A</div><section>B</section>' ) );
		$this->assertSame( '', HtmlFragment::clean( '<svg><script>alert(1)</script></svg><iframe src="x"></iframe>' ) );
		$this->assertSame( '<ul><li>One</li><li>Two &amp; three</li></ul>', HtmlFragment::clean( '<ul><li>One</li><li>Two &amp; three</li></ul>' ) );
		$this->assertSame( '', HtmlFragment::url( '//evil.example/x' ) );
		$this->assertSame( 'tel:+44 20 7946 0000', HtmlFragment::url( 'tel:+44 20 7946 0000' ) );
		$this->assertSame( '', HtmlFragment::url( 'data:text/html,<script>' ) );
	}

	/* ------------------------------------------------------------------ Schema */

	public function testPageDocumentNormalisesValidDocument(): void {
		$errors = array();
		$doc    = PageDocument::normalize( self::landingPage(), $errors );
		$this->assertSame( array(), $errors );
		$this->assertSame( array( 'hero-001', 'services', 'faq' ), PageDocument::sectionIds( $doc ) );
		$this->assertSame( array( 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ), $doc['sections'][1]['layout']['columns'] );
		$this->assertSame( '<p>Monthly books.</p>', $doc['sections'][1]['elements'][1]['elements'][2]['html'] );
		$this->assertSame( 'token:surface', $doc['sections'][0]['style']['background'] );
		$this->assertSame( 'primary', $doc['sections'][0]['elements'][2]['variant'] );
	}

	public function testPageDocumentRejectsInvalidInput(): void {
		$errors = array();
		PageDocument::normalize(
			array(
				'page'     => array( 'title' => '', 'slug' => 'Bad Slug!' ),
				'sections' => array(
					array( 'id' => 'a', 'type' => 'hero', 'elements' => array( array( 'type' => 'heading', 'text' => '<b></b>' ), array( 'type' => 'html', 'html' => '<script>' ), array( 'type' => 'button', 'text' => 'Go', 'url' => 'javascript:alert(1)' ) ) ),
					array( 'id' => 'a', 'type' => 'nope', 'layout' => array( 'kind' => 'split', 'columns' => array( 'desktop' => 9 ) ), 'elements' => array( array( 'type' => 'group', 'elements' => array( array( 'type' => 'group', 'elements' => array() ) ) ) ) ),
					array( 'id' => 'g', 'type' => 'cards', 'layout' => array( 'kind' => 'grid' ), 'style' => array( 'background' => 'token:evil', 'padding' => array( 'desktop' => '10px;color:red' ) ), 'elements' => array( array( 'type' => 'group', 'elements' => array( array( 'type' => 'icon', 'icon' => 'skull' ) ) ), array( 'type' => 'heading', 'text' => 'Late header' ) ) ),
				),
			),
			$errors
		);
		foreach ( array(
			'page.title: required',
			'page.slug: use lowercase letters, digits and hyphens',
			'sections[0].elements[0].text: required',
			'sections[0].elements[1].type: HTML elements are only allowed as a conversion fallback',
			'sections[0].elements[2].url: not an allowed link',
			'sections[1].id: duplicate id "a"',
			'sections[1].type: unknown section type',
			'sections[1].layout.columns.desktop: 1 to 6',
			'sections[1].elements[0].elements[0]: groups cannot be nested',
			'sections[1].layout.kind: a split section needs exactly two groups and nothing else',
			'sections[2].style.background: unknown colour token',
			'sections[2].style.padding.desktop: invalid value',
			'sections[2].elements[0].elements[0].icon: not an available icon',
			'sections[2].elements: in a grid, elements other than groups must come before the groups',
		) as $expected ) {
			$this->assertContains( $expected, $errors );
		}
	}

	/* ------------------------------------------------------------------ Mapper */

	public function testMapperBuildsNativeContainerLayout(): void {
		$doc    = PageDocument::normalize( self::landingPage() );
		$mapper = new ElementorMapper( self::compat(), self::ids(), self::tokens(), array( 'primary' => '#1F365C', 'accent' => '#8A8A2B' ) );
		$result = $mapper->map( $doc );
		$els    = $result['elements'];

		$this->assertCount( 3, $els );
		$this->assertSame( array( 'hero-001' => $els[0]['id'], 'services' => $els[1]['id'], 'faq' => $els[2]['id'] ), $result['section_map'] );
		$this->assertSame( array(), $result['notes'] );

		// Hero: boxed container from the profile, responsive padding, surface background, centred.
		$hero = $els[0];
		$this->assertSame( 'container', $hero['elType'] );
		$this->assertSame( 'boxed', $hero['settings']['content_width'] );
		$this->assertSame( 1200, $hero['settings']['boxed_width']['size'] );
		$this->assertSame( '100', $hero['settings']['padding']['top'] );
		$this->assertSame( '75', $hero['settings']['padding_tablet']['top'], 'tablet padding derived' );
		$this->assertSame( '50', $hero['settings']['padding_mobile']['top'], 'mobile padding derived' );
		$this->assertSame( '#F4EFE6', $hero['settings']['background_color'] );
		$this->assertSame( 'center', $hero['settings']['flex_align_items'] );
		$this->assertSame( 520, $hero['settings']['min_height']['size'] );

		// H1 with global colour, profile font and per-device sizes.
		$h1 = $hero['elements'][0];
		$this->assertSame( 'heading', $h1['widgetType'] );
		$this->assertSame( 'h1', $h1['settings']['header_size'] );
		$this->assertSame( 'globals/colors?id=primary', $h1['settings']['__globals__']['title_color'] );
		$this->assertFalse( isset( $h1['settings']['title_color'] ), 'global colour replaces the literal' );
		$this->assertSame( 'Poppins', $h1['settings']['typography_font_family'] );
		$this->assertSame( 52, $h1['settings']['typography_font_size']['size'] );
		$this->assertSame( 44, $h1['settings']['typography_font_size_tablet']['size'], 'derived 52 × 0.85' );
		$this->assertSame( 32, $h1['settings']['typography_font_size_mobile']['size'], 'profile mobile size' );

		// Buttons: primary filled with contrasting text; secondary outlined; full width on mobile.
		$primary   = $hero['elements'][2]['settings'];
		$secondary = $hero['elements'][3]['settings'];
		$this->assertSame( 'globals/colors?id=primary', $primary['__globals__']['background_color'] );
		$this->assertSame( '#FFFFFF', $primary['button_text_color'] );
		$this->assertSame( '8', $primary['border_radius']['top'] );
		$this->assertSame( 'justify', $primary['align_mobile'] );
		$this->assertSame( '/contact/', $primary['link']['url'] );
		$this->assertSame( 'solid', $secondary['border_border'] );
		$this->assertSame( 'globals/colors?id=primary', $secondary['__globals__']['button_text_color'] );

		// Cards: header widget, then a wrapping row of cards with per-device widths.
		$services = $els[1];
		$this->assertSame( 'heading', $services['elements'][0]['widgetType'] );
		$row = $services['elements'][1];
		$this->assertSame( 'row', $row['settings']['flex_direction'] );
		$this->assertSame( 'wrap', $row['settings']['flex_wrap'] );
		$this->assertSame( 24, $row['settings']['flex_gap']['size'] );
		$this->assertCount( 3, $row['elements'] );
		$card = $row['elements'][0]['settings'];
		$this->assertSame( 'calc((100% - 48px) / 3)', $card['width']['size'] );
		$this->assertSame( 'calc((100% - 24px) / 2)', $card['width_tablet']['size'] );
		$this->assertSame( 100, $card['width_mobile']['size'] );
		$this->assertSame( '#FFFFFF', $card['background_color'] );
		$this->assertSame( '12', $card['border_radius']['top'] );
		$this->assertSame( 'yes', $card['box_shadow_box_shadow_type'] );
		$this->assertSame( 'rgba(0,0,0,0.12)', $card['box_shadow_box_shadow']['color'] );

		$icon = $row['elements'][0]['elements'][0];
		$this->assertSame( 'fas fa-calculator', $icon['settings']['selected_icon']['value'] );
		$list = $row['elements'][1]['elements'][1];
		$this->assertSame( 'icon-list', $list['widgetType'] );
		$this->assertCount( 2, $list['settings']['icon_list'] );
		$this->assertTrue( IdGenerator::isValid( $list['settings']['icon_list'][0]['_id'] ) );
		$image = $row['elements'][2]['elements'][1];
		$this->assertSame( 42, $image['settings']['image']['id'] );

		// FAQ: native accordion with FAQ schema.
		$acc = $els[2]['elements'][1];
		$this->assertSame( 'accordion', $acc['widgetType'] );
		$this->assertSame( 'yes', $acc['settings']['faq_schema'] );
		$this->assertSame( 'How much does it cost?', $acc['settings']['tabs'][0]['tab_title'] );

		// Every widget is a native Free widget; nothing is an HTML widget.
		$widgets = array_unique( array_column( self::find( $els, static fn ( $e ) => 'widget' === ( $e['elType'] ?? '' ) ), 'widgetType' ) );
		sort( $widgets );
		$this->assertSame( array( 'accordion', 'button', 'heading', 'icon', 'icon-list', 'image', 'text-editor' ), $widgets );
	}

	public function testMappedDocumentPassesValidator(): void {
		$mapped = ( new ElementorMapper( self::compat(), self::ids(), self::tokens() ) )->map( PageDocument::normalize( self::landingPage() ) );
		$report = ( new DocumentValidator( self::compat(), static fn ( int $id ): bool => 42 === $id ) )->validate( $mapped['elements'] );
		$this->assertSame( array(), $report['errors'] );
		$this->assertSame( 14, $report['stats']['widgets'] );
	}

	public function testMapperWithoutTokensUsesDefaultsNotInventedColours(): void {
		$mapped = ( new ElementorMapper( self::compat(), self::ids() ) )->map( PageDocument::normalize( self::landingPage() ) );
		$h1     = $mapped['elements'][0]['elements'][0]['settings'];
		$this->assertFalse( isset( $h1['title_color'] ), 'no colour without a profile' );
		$this->assertSame( 48, $h1['typography_font_size']['size'], 'default scale' );
		$this->assertSame( 35, $h1['typography_font_size_mobile']['size'], 'derived 48 × 0.72' );
		$this->assertSame( '80', $mapped['elements'][0]['settings']['padding']['top'] );
		$this->assertFalse( isset( $mapped['elements'][0]['settings']['background_background'] ) );
	}

	public function testLegacyFallbackAndWidgetFallbacks(): void {
		$compat = self::compat( false, array( 'heading', 'text-editor', 'button', 'image', 'spacer' ) );
		$mapped = ( new ElementorMapper( $compat, self::ids(), self::tokens() ) )->map( PageDocument::normalize( self::landingPage() ) );
		$types  = array_column( $mapped['elements'], 'elType' );
		$this->assertSame( array( 'section', 'section', 'section', 'section' ), $types, 'grid with a header becomes two sections' );
		$this->assertSame( $mapped['elements'][1]['id'], $mapped['section_map']['services'] );
		$grid = $mapped['elements'][2];
		$this->assertCount( 3, $grid['elements'] );
		$this->assertSame( 33, $grid['elements'][0]['settings']['_column_size'] );
		$this->assertSame( 50.0, $grid['elements'][0]['settings']['_inline_size_tablet'] );
		$this->assertSame( '0', $mapped['elements'][1]['settings']['padding']['bottom'], 'header keeps the top padding only' );
		$this->assertSame( '0', $grid['settings']['padding']['top'], 'grid keeps the bottom padding only' );
		foreach ( array( 'legacy_sections', 'legacy_two_sections', 'icon_skipped', 'list_as_text', 'accordion_as_headings' ) as $note ) {
			$this->assertContains( $note, $mapped['notes'] );
		}
		$report = ( new DocumentValidator( $compat, static fn (): bool => true ) )->validate( $mapped['elements'] );
		$this->assertSame( array(), $report['errors'] );
	}

	public function testHelpers(): void {
		$this->assertSame( array( 'unit' => 'px', 'top' => '100', 'right' => '0', 'bottom' => '100', 'left' => '0', 'isLinked' => false ), ElementorMapper::dims( '100px 0' ) );
		$this->assertSame( array( 'unit' => 'rem', 'top' => '1', 'right' => '2', 'bottom' => '3', 'left' => '2', 'isLinked' => false ), ElementorMapper::dims( '1rem 2rem 3rem' ) );
		$this->assertSame( '15px', ElementorMapper::scaleLength( '18px', 0.72, 15 ), 'readable minimum' );
		$this->assertSame( '2.16rem', ElementorMapper::scaleLength( '3rem', 0.72, 15 ) );
		$this->assertSame( '#FFFFFF', ElementorMapper::contrastText( '#1F365C' ) );
		$this->assertSame( '#111111', ElementorMapper::contrastText( '#F4EFE6' ) );
		$this->assertSame( '#111111', ElementorMapper::contrastText( '#FFD60A' ), 'yellow needs dark text' );
	}

	/* --------------------------------------------------------------- Validator */

	public function testValidatorRejectsUnsafeOrBrokenGeneratedDocuments(): void {
		$v   = new DocumentValidator( self::compat( true, array( 'heading', 'text-editor', 'button', 'image', 'html', 'form' ) ), static fn (): bool => false );
		$w   = static fn ( string $id, string $type, array $s ): array => array( 'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $s, 'elements' => array() );
		$doc = array(
			array(
				'id'       => 'aaaaaaa',
				'elType'   => 'container',
				'settings' => array( 'padding' => array( 'unit' => 'px', 'top' => '10;x' ), 'width' => array( 'unit' => 'custom', 'size' => 'expression(alert(1))' ) ),
				'elements' => array(
					$w( 'bbbbbbb', 'heading', array( 'title' => 'Hi <img src=x onerror=alert(1)>' ) ),
					$w( 'bbbbbbb', 'html', array( 'html' => '<p>x</p>' ) ),
					$w( 'ccccccc', 'button', array( 'text' => 'Go', 'link' => array( 'url' => 'javascript:alert(1)' ) ) ),
					$w( 'ddddddd', 'image', array( 'image' => array( 'id' => 99, 'url' => 'https://example.com/a.jpg' ) ) ),
					$w( 'eeeeeee', 'form', array() ),
					$w( 'BAD', 'heading', array( 'title' => 'x' ) ),
					array( 'id' => 'fffffff', 'elType' => 'column', 'settings' => array(), 'elements' => array() ),
					$w( 'ggggggg', 'text-editor', array() ),
				),
			),
			array( 'id' => 'hhhhhhh', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'top' ), 'elements' => array( array() ) ),
		);
		$errors = $v->validate( $doc )['errors'];
		foreach ( array(
			'[0].settings.padding is not a valid spacing value.',
			'[0].width is not a valid size.',
			'[0].elements[0] contains script or event-handler content.',
			'[0].elements[1] reuses the id bbbbbbb.',
			'[0].elements[1] uses the "html" widget, which cannot be generated on this site.',
			'[0].elements[2] has a link or image address that is not allowed.',
			'[0].elements[3] refers to image #99, which is not in the Media Library.',
			'[0].elements[4] uses the "form" widget, which cannot be generated on this site.',
			'[0].elements[5] has an invalid id.',
			'[0].elements[6]: a column cannot be placed inside a container.',
			'[0].elements[7] (text-editor) is missing its editor.',
			'[1]: a widget cannot be placed inside a root.',
			'[1]: widgets cannot contain elements.',
		) as $expected ) {
			$this->assertContains( str_replace( '[0].settings.padding', '[0].padding', $expected ), $errors );
		}
		$this->assertContains( 'The page has no content.', $v->validate( array() )['errors'] );
	}

	public function testClonedModeAllowsSiteWidgetsButStillChecksStructure(): void {
		$v      = new DocumentValidator( self::compat( true, array( 'heading', 'text-editor', 'button', 'image', 'html' ) ), static fn (): bool => false, static fn (): bool => true ); // An administrator (unfiltered_html).
		$report = $v->validate(
			array(
				array(
					'id'       => 'aaaaaaa',
					'elType'   => 'container',
					'settings' => array( 'custom_css' => 'selector { color: red; }' ),
					'elements' => array(
						array( 'id' => 'bbbbbbb', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => '<script>trackVisit()</script>' ), 'elements' => array() ),
						array( 'id' => 'ccccccc', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => array(), 'elements' => array() ),
						array( 'id' => 'ddddddd', 'elType' => 'widget', 'widgetType' => 'image', 'settings' => array( 'image' => array( 'id' => 7, 'url' => 'https://x.test/a.png' ) ), 'elements' => array() ),
						array( 'id' => 'eeeeeee', 'elType' => 'e-flexbox', 'settings' => array(), 'elements' => array() ),
						array( 'id' => 'fffffff', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'link' => array( 'url' => 'javascript:void(0)' ) ), 'elements' => array() ),
					),
				),
			),
			DocumentValidator::MODE_CLONED
		);
		$this->assertSame( array( '[0].elements[4] has a link or image address that is not allowed.' ), $report['errors'], 'the author\'s own HTML widget is kept, unsafe links are not' );
		$this->assertTrue( (bool) array_filter( $report['warnings'], static fn ( $w ) => str_contains( $w, '"form" widget is not available' ) ) );
		$this->assertTrue( (bool) array_filter( $report['warnings'], static fn ( $w ) => str_contains( $w, 'image #7' ) ) );
	}

	public function testIdGenerator(): void {
		$ids = new IdGenerator( static fn (): string => 'abcdef1' );
		$this->assertSame( 'abcdef1', $ids->next() );
		$threw = false;
		try {
			$ids->next();
		} catch ( \RuntimeException ) {
			$threw = true;
		}
		$this->assertTrue( $threw, 'never reissues an id' );
		$random = new IdGenerator();
		$random->reserveTree( array( array( 'id' => 'x', 'settings' => array( 'tabs' => array( array( '_id' => 'y' ) ) ) ) ) );
		$seen = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$id = $random->next();
			$this->assertTrue( IdGenerator::isValid( $id ) );
			$this->assertFalse( isset( $seen[ $id ] ) );
			$seen[ $id ] = true;
		}
	}
}
