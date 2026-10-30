<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\WebsiteStudio\Cloner\CloneContentGenerator;
use AiSeoContentStudio\WebsiteStudio\Cloner\ContentSlots;
use AiSeoContentStudio\WebsiteStudio\Cloner\ElementTreeCopier;
use AiSeoContentStudio\WebsiteStudio\Cloner\PageCloner;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftManager;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorCompat;
use AiSeoContentStudio\WebsiteStudio\Engine\IdGenerator;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\Persistence\GeneratedPageRepository;

require_once dirname( __DIR__ ) . '/fakes.php';
require_once __DIR__ . '/DraftManagerTest.php';

/**
 * Page cloner: ID regeneration, slot detection and protection, content replacement,
 * AI answer validation, and the end-to-end clone into a new draft.
 */
class ClonerTest extends TestCase {

	private static function w( string $id, string $type, array $s ): array {
		return array( 'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $s, 'elements' => array() );
	}

	private static function c( string $id, array $children, array $s = array() ): array {
		return array( 'id' => $id, 'elType' => 'container', 'settings' => $s, 'elements' => $children, 'isInner' => false );
	}

	/** An existing Elementor page of the site. */
	public static function source(): array {
		return array(
			self::c( 'nav00001', array( self::w( 'logo0001', 'theme-site-logo', array() ), self::w( 'menu0001', 'nav-menu', array() ), self::w( 'navbtn01', 'button', array( 'text' => 'Contact' ) ) ) ),
			self::c(
				'hero0001',
				array(
					self::w( 'h1000001', 'heading', array( 'title' => 'Bookkeeping for small businesses', 'header_size' => 'h1', 'typography_font_size' => array( 'unit' => 'px', 'size' => 52 ) ) ),
					self::w( 'txt00001', 'text-editor', array( 'editor' => '<p>We keep your books tidy so you can focus on your work.</p>' ) ),
					self::w( 'btn00001', 'button', array( 'text' => 'Get a quote', 'link' => array( 'url' => '/quote/' ), 'background_color' => '#8A8A2B' ) ),
				),
				array( 'background_background' => 'classic', 'background_color' => '#F4EFE6', 'padding_mobile' => array( 'unit' => 'px', 'top' => '40', 'right' => '0', 'bottom' => '40', 'left' => '0' ), 'animation' => 'fadeInUp' )
			),
			self::c(
				'cards001',
				array(
					self::w( 'ibox0001', 'icon-box', array( 'title_text' => 'Monthly accounts', 'description_text' => 'Clear reports every month.' ) ),
					self::w( 'list0001', 'icon-list', array( 'icon_list' => array( array( '_id' => 'li00001', 'text' => 'Bank reconciliation' ), array( '_id' => 'li00002', 'text' => 'Call us on +44 20 7946 0000' ) ) ) ),
					self::w( 'cnt00001', 'counter', array( 'ending_number' => 250, 'title' => 'Happy clients' ) ),
					self::w( 'test0001', 'testimonial', array( 'testimonial_content' => 'Great service!', 'testimonial_name' => 'Jane' ) ),
				)
			),
			self::c( 'faq00001', array( self::w( 'acc00001', 'accordion', array( 'tabs' => array( array( '_id' => 'tab0001', 'tab_title' => 'What does it cost?', 'tab_content' => '<p>Prices start low.</p>' ) ) ) ) ) ),
			self::c( 'foot0001', array( self::w( 'menu0002', 'nav-menu', array() ), self::w( 'copy0001', 'text-editor', array( 'editor' => '<p>© 2026 Acme Books Ltd. All rights reserved.</p>' ) ) ) ),
			self::c( 'brand001', array( self::w( 'bh000001', 'heading', array( 'title' => 'Acme Books', 'header_size' => 'h2' ) ), self::w( 'dyn00001', 'heading', array( 'title' => 'x', '__dynamic__' => array( 'title' => '[elementor-tag name="post-title"]' ) ) ) ) ),
		);
	}

	private static function slotsByKey( array $slots ): array {
		$out = array();
		foreach ( $slots as $s ) {
			$out[ $s['key'] ] = $s;
		}
		return $out;
	}

	public function testCopierRegeneratesEveryIdAndKeepsSettings(): void {
		$src  = self::source();
		$copy = ( new ElementTreeCopier( new IdGenerator() ) )->copy( $src );
		$this->assertSame( $src, self::source(), 'source tree not modified' );
		$old = array();
		$new = array();
		array_walk_recursive( $src, function ( $v, $k ) use ( &$old ) { if ( 'id' === $k || '_id' === $k ) { $old[] = $v; } } );
		array_walk_recursive( $copy['elements'], function ( $v, $k ) use ( &$new ) { if ( 'id' === $k || '_id' === $k ) { $new[] = $v; } } );
		$this->assertCount( count( $old ), $new );
		$this->assertSame( array(), array_values( array_intersect( $old, $new ) ), 'no ID shared with the source' );
		$this->assertSame( count( $new ), count( array_unique( $new ) ) );
		$hero = $copy['elements'][1];
		$this->assertSame( 'fadeInUp', $hero['settings']['animation'] );
		$this->assertSame( '#F4EFE6', $hero['settings']['background_color'] );
		$this->assertSame( '40', $hero['settings']['padding_mobile']['top'] );
		$this->assertSame( 52, $hero['elements'][0]['settings']['typography_font_size']['size'] );
		$this->assertSame( $hero['id'], $copy['id_map']['hero0001'] );
	}

	public function testSlotsReplaceContentAndProtectFacts(): void {
		$slots = self::slotsByKey( ( new ContentSlots() )->extract( self::source(), 'Acme Books' ) );
		$replace = array_keys( array_filter( $slots, static fn ( $s ) => $s['replace'] ) );
		sort( $replace );
		$this->assertSame(
			array( 'acc00001|tabs|0|tab_content', 'acc00001|tabs|0|tab_title', 'btn00001|text', 'h1000001|title', 'ibox0001|description_text', 'ibox0001|title_text', 'list0001|icon_list|0|text', 'txt00001|editor' ),
			$replace
		);
		$this->assertSame( 'site_chrome', $slots['navbtn01|text']['reason'], 'header button kept' );
		$this->assertSame( 'site_chrome', $slots['copy0001|editor']['reason'], 'footer kept' );
		$this->assertSame( 'contact', $slots['list0001|icon_list|1|text']['reason'], 'phone number never rewritten' );
		$this->assertSame( 'statistic', $slots['cnt00001|*']['reason'] );
		$this->assertTrue( $slots['cnt00001|*']['locked'] );
		$this->assertSame( 'testimonial', $slots['test0001|*']['reason'] );
		$this->assertSame( 'brand', $slots['bh000001|title']['reason'] );
		$this->assertSame( 'dynamic', $slots['dyn00001|title']['reason'] );
		$this->assertTrue( $slots['dyn00001|title']['locked'] );
		$this->assertSame( 'h1', $slots['h1000001|title']['level'] );
		$this->assertSame( 'hero', $slots['h1000001|title']['section_type'] );
		$this->assertTrue( $slots['txt00001|editor']['html'] );
		$this->assertSame( 'legal', ContentSlots::protection( 'Read our privacy policy', 'content', '', false ) );
	}

	public function testApplyOnlyTouchesAllowedSlots(): void {
		$cs    = new ContentSlots();
		$slots = $cs->extract( self::source(), 'Acme Books' );
		$texts = array(
			'h1000001|title'               => 'Payroll services for <b>growing</b> teams',
			'txt00001|editor'              => '<p>We run payroll <script>x()</script>on time.</p>',
			'acc00001|tabs|0|tab_title'    => 'How is payroll priced?',
			'navbtn01|text'                => 'Hacked',      // Protected.
			'cnt00001|*'                   => '999',         // Locked.
			'list0001|icon_list|1|text'    => 'Call 000',    // Protected contact.
			'btn00001|text'                => '   ',         // Empty: ignored.
			'zzz|title'                    => 'Unknown key',
		);
		$r     = $cs->apply( self::source(), $texts, $slots );
		$els   = $r['elements'];
		$this->assertSame( 'Payroll services for growing teams', $els[1]['elements'][0]['settings']['title'], 'plain field, tags stripped' );
		$this->assertSame( '<p>We run payroll on time.</p>', $els[1]['elements'][1]['settings']['editor'], 'html sanitised' );
		$this->assertSame( 'Get a quote', $els[1]['elements'][2]['settings']['text'], 'empty answer keeps original' );
		$this->assertSame( '/quote/', $els[1]['elements'][2]['settings']['link']['url'], 'links kept' );
		$this->assertSame( 'How is payroll priced?', $els[3]['elements'][0]['settings']['tabs'][0]['tab_title'] );
		$this->assertSame( 'tab0001', $els[3]['elements'][0]['settings']['tabs'][0]['_id'] );
		$this->assertSame( 'Contact', $els[0]['elements'][2]['settings']['text'] );
		$this->assertSame( 'Call us on +44 20 7946 0000', $els[2]['elements'][1]['settings']['icon_list'][1]['text'] );
		$this->assertSame( 250, $els[2]['elements'][2]['settings']['ending_number'] );
		sort( $r['applied'] );
		$this->assertSame( array( 'acc00001|tabs|0|tab_title', 'h1000001|title', 'txt00001|editor' ), $r['applied'] );
	}

	public function testGeneratorRequestAndParse(): void {
		$slots = array_values( array_filter( ( new ContentSlots() )->extract( self::source(), 'Acme Books' ), static fn ( $s ) => $s['replace'] ) );
		$req   = CloneContentGenerator::request( array( 'topic' => 'Payroll', 'primary_keyword' => 'payroll services' ), $slots, array( 'business' => array( 'name' => 'Acme Books' ) ) );
		$this->assertSame( CloneContentGenerator::PURPOSE, $req->purpose );
		$this->assertSame( 'page_content', $req->schemaName );
		$this->assertFalse( $req->jsonSchema['additionalProperties'] );
		$payload = json_decode( $req->messages[0]->content, true );
		$this->assertCount( count( $slots ), $payload['slots'] );
		$this->assertSame( 'html', $payload['slots'][1]['format'] );
		$this->assertTrue( str_contains( $req->system, 'Never invent business facts' ) );

		$parsed = CloneContentGenerator::parse(
			array(
				'page_title'       => 'Payroll services',
				'meta_title'       => 'Payroll services | Acme',
				'meta_description' => '<b>Run</b> payroll on time.',
				'slots'            => array(
					array( 'key' => 'h1000001|title', 'text' => 'Payroll services for small teams' ),
					array( 'key' => 'h1000001|title', 'text' => 'Duplicate ignored' ),
					array( 'key' => 'btn00001|text', 'text' => 'Book a call with our friendly payroll specialists today please' ),
					array( 'key' => 'txt00001|editor', 'text' => '<p onclick="x">Clean</p>' ),
					array( 'key' => 'not-requested', 'text' => 'x' ),
				),
			),
			$slots
		);
		$this->assertSame( 'Payroll services for small teams', $parsed['texts']['h1000001|title'] );
		$this->assertSame( '<p>Clean</p>', $parsed['texts']['txt00001|editor'] );
		$this->assertFalse( isset( $parsed['texts']['not-requested'] ) );
		$this->assertSame( 'Run payroll on time.', $parsed['meta_description'] );
		$issues = array_column( $parsed['warnings'], 'issue', 'key' );
		$this->assertSame( 'longer_than_design', $issues['btn00001|text'] );
		$this->assertSame( 'not_written', $issues['acc00001|tabs|0|tab_title'] );
		$this->assertSame( array(), CloneContentGenerator::parse( 'garbage', array() )['texts'] );
	}

	public function testCloneCreatesNewDraftAndNeverTouchesTheSource(): void {
		\FakeSite::reset();
		$GLOBALS['fake_meta'] = array(
			7 => array( '_elementor_data' => 'SOURCE', '_elementor_page_settings' => array( 'hide_title' => 'yes' ), '_wp_page_template' => 'elementor_canvas', '_thumbnail_id' => 55 ),
		);
		\FakeSite::$posts[7] = new \WP_Post( array( 'ID' => 7, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Bookkeeping', 'post_modified_gmt' => '2026-10-01 10:00:00' ) );
		$bridge           = new FakeBridge();
		$bridge->docs[7]  = self::source();
		$posts            = new FakePosts();
		$posts->caps      = array( 2 => array( 'edit_pages' ) );
		$compat           = new ElementorCompat( true, '3.25.0', true, true );
		$drafts           = new DraftManager( $bridge, $compat, new DocumentValidator( $compat, static fn (): bool => true ), new GeneratedPageRepository(), new AuditLog(), $posts );
		$cloner           = new PageCloner( $bridge, $drafts );
		$source           = \FakeSite::$posts[7];

		$plan = $cloner->plan( $source, 'Acme Books' );
		$this->assertSame( 8, $plan['stats']['replace'] );
		$this->assertSame( array( 'header', 'hero', 'testimonials', 'faq', 'footer', 'content' ), array_column( $plan['sections'], 'type' ) );

		$result = $cloner->clone( $source, 2, array( 'texts' => array( 'h1000001|title' => 'Payroll services' ), 'slots' => $plan['slots'], 'meta_title' => 'Payroll | Acme', 'keyword' => 'payroll' ) );
		$new    = $result['page_id'];
		$this->assertTrue( 7 !== $new );
		$this->assertSame( self::source(), $bridge->docs[7], 'source document unchanged' );
		$this->assertSame( array( 7, $new ), array_keys( $bridge->docs ) );
		$this->assertSame( array( $new ), $bridge->saves, 'only the new page was saved' );
		$this->assertSame( 'SOURCE', $GLOBALS['fake_meta'][7]['_elementor_data'] );
		$this->assertTrue( $result['source_unchanged'] );
		$this->assertSame( 'draft', $posts->status( $new ) );
		$this->assertSame( 'Payroll services', $bridge->docs[ $new ][1]['elements'][0]['settings']['title'] );
		$this->assertTrue( 'hero0001' !== $bridge->docs[ $new ][1]['id'], 'new ids' );
		$this->assertSame( 'elementor_canvas', $posts->meta[ $new ]['_wp_page_template'] );
		$this->assertSame( 55, $posts->meta[ $new ]['_thumbnail_id'] );
		$this->assertSame( 'Payroll | Acme', $GLOBALS['fake_meta'][ $new ]['_aiscs_seo_title'] );
		$record = ( new GeneratedPageRepository() )->forPage( $new );
		$this->assertSame( 'clone_generated', $record['generation_type'] );
		$this->assertSame( 7, $record['source_page_id'] );
		$this->assertSame( array( 'h1000001|title' ), $result['applied'] );

		// The DraftManager refuses to write the source even when asked directly.
		$reason = '';
		try {
			$drafts->update( 7, self::source(), 2, DocumentValidator::MODE_CLONED );
		} catch ( DraftException $e ) {
			$reason = $e->reason;
		}
		$this->assertSame( 'not_studio_page', $reason );

		// Non-Elementor pages cannot be cloned as Elementor pages.
		\FakeSite::$posts[8] = new \WP_Post( array( 'ID' => 8, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Blocks' ) );
		$reason = '';
		try {
			$cloner->plan( \FakeSite::$posts[8] );
		} catch ( DraftException $e ) {
			$reason = $e->reason;
		}
		$this->assertSame( 'not_elementor', $reason );
	}
}
