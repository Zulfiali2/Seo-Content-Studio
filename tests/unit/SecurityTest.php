<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\WebsiteStudio\Editor\ElementTree;
use AiSeoContentStudio\WebsiteStudio\Editor\Findings;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorCompat;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorMapper;
use AiSeoContentStudio\WebsiteStudio\Engine\HtmlFragment;
use AiSeoContentStudio\WebsiteStudio\Engine\IdGenerator;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;
use AiSeoContentStudio\WebsiteStudio\Generator\InternalLinks;
use AiSeoContentStudio\WebsiteStudio\Generator\PageGenerator;
use AiSeoContentStudio\WebsiteStudio\Persistence\LibraryRepository;
use AiSeoContentStudio\WebsiteStudio\Persistence\RevisionRepository;
use AiSeoContentStudio\WebsiteStudio\Preview\PreviewManager;
use AiSeoContentStudio\WebsiteStudio\Sections\SectionWriter;
use AiSeoContentStudio\WebsiteStudio\StudioServices;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\Persistence\GeneratedPageRepository;
use AiSeoContentStudio\WebsiteStudio\StudioTables;

require_once dirname( __DIR__ ) . '/fakes.php';
require_once __DIR__ . '/StudioToolsTest.php';

/**
 * Hostile input at every boundary where model output, user input or stored data enters the
 * studio: nothing executable may reach an Elementor document, and nothing may reach SQL
 * unescaped.
 */
class SecurityTest extends TestCase {

	/** Classic XSS payloads, including filter-bypass variants. */
	public const PAYLOADS = array(
		'<script>alert(1)</script>',
		'<img src=x onerror=alert(1)>',
		'<svg/onload=alert(1)>',
		'<a href="javascript:alert(1)">x</a>',
		'<a href="jav&#x09;ascript:alert(1)">x</a>',
		'<a href=" JaVaScRiPt:alert(1)">x</a>',
		'<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
		'<iframe src="https://evil.test"></iframe>',
		'<p style="background:url(javascript:alert(1))">x</p>',
		'<math><mi xlink:href="javascript:alert(1)">x</mi></math>',
		'<p onclick="alert(1)">x</p>',
		'"><script>alert(1)</script>',
		'<template><script>alert(1)</script></template>',
		'<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>',
		'<a href="vbscript:msgbox(1)">x</a>',
		'<object data="x.swf"></object><embed src="x.swf">',
	);

	private static function executable( string $html ): bool {
		$decoded = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return (bool) preg_match( '/<\s*(script|iframe|object|embed|svg|math|style|link|meta|template|noscript)\b|\son[a-z]+\s*=|javascript\s*:|vbscript\s*:|data\s*:\s*text\/html/i', $decoded );
	}

	public function testHtmlFragmentNeutralisesPayloads(): void {
		foreach ( self::PAYLOADS as $payload ) {
			$clean = HtmlFragment::clean( '<p>Hi</p>' . $payload );
			$this->assertFalse( self::executable( $clean ), 'clean(): ' . $payload . ' → ' . $clean );
			$this->assertFalse( self::executable( HtmlFragment::text( $payload ) ), 'text(): ' . $payload );
		}
		foreach ( array( 'javascript:alert(1)', ' javascript:x', "java\tscript:x", 'JAVASCRIPT:x', '//evil.test', 'data:text/html,x', 'vbscript:x', "http://x\x00y" ) as $url ) {
			$this->assertSame( '', HtmlFragment::url( $url ), $url );
		}
	}

	public function testEveryPayloadIsInertInAMappedAndValidatedPage(): void {
		$compat   = new ElementorCompat( true, '3.25.0', false, true );
		$sections = array();
		foreach ( self::PAYLOADS as $i => $payload ) {
			$sections[] = array(
				'id'       => 's' . $i,
				'type'     => 'content',
				'elements' => array(
					array( 'type' => 'heading', 'text' => 'Safe ' . $payload, 'level' => 'h2' ),
					array( 'type' => 'text', 'html' => '<p>Safe</p>' . $payload ),
					array( 'type' => 'button', 'text' => 'Safe ' . $payload, 'url' => 0 === $i ? 'javascript:alert(1)' : '/ok/' ),
					array( 'type' => 'list', 'items' => array( 'Safe ' . $payload ) ),
					array( 'type' => 'accordion', 'items' => array( array( 'title' => 'Safe ' . $payload, 'html' => '<p>Safe</p>' . $payload ) ) ),
				),
			);
		}
		$errors = array();
		$doc    = PageDocument::normalize( array( 'schema_version' => 1, 'page' => array( 'title' => '<script>x</script>' ), 'sections' => $sections ), $errors );
		$this->assertTrue( array() !== $errors, 'the javascript: button URL is reported' );
		$elements = ( new ElementorMapper( $compat, new IdGenerator() ) )->map( $doc )['elements'];
		$json     = (string) json_encode( $elements );
		$this->assertFalse( self::executable( json_decode( $json, true ) ? implode( ' ', ElementTree::texts( $elements ) ) . ' ' . $json : '' ), 'mapped page carries no executable markup' );
		$report = ( new DocumentValidator( $compat, static fn (): bool => true ) )->validate( $elements, DocumentValidator::MODE_GENERATED );
		$this->assertSame( array(), $report['errors'] );

		// The validator is the last line: a hand-made unsafe tree is refused in every mode.
		foreach ( array( DocumentValidator::MODE_GENERATED, DocumentValidator::MODE_CLONED, DocumentValidator::MODE_CONVERTED ) as $mode ) {
			foreach ( array(
				array( 'widget', 'text-editor', array( 'editor' => '<img src=x onerror=alert(1)>' ) ),
				array( 'widget', 'button', array( 'text' => 'Go', 'link' => array( 'url' => 'javascript:alert(1)' ) ) ),
				array( 'widget', 'html', array( 'html' => '<script>alert(1)</script>' ) ),
			) as [ $type, $widget, $settings ] ) {
				$tree   = array( array( 'id' => 'abcdef1', 'elType' => 'container', 'settings' => array(), 'elements' => array( array( 'id' => 'abcdef2', 'elType' => $type, 'widgetType' => $widget, 'settings' => $settings, 'elements' => array() ) ) ) );
				$result = ( new DocumentValidator( $compat, static fn (): bool => true, static fn (): bool => false ) )->validate( $tree, $mode );
				$this->assertTrue( array() !== $result['errors'], "$mode refuses unsafe $widget for users without unfiltered_html" );
			}
		}
	}

	public function testCopiedScriptsNeedUnfilteredHtml(): void {
		$compat = new ElementorCompat( true, '3.25.0', false, true );
		$tree   = array( array( 'id' => 'abcdef1', 'elType' => 'container', 'settings' => array(), 'elements' => array( array( 'id' => 'abcdef2', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => '<script>trackVisit()</script>' ), 'elements' => array() ) ) ) );
		$admin  = ( new DocumentValidator( $compat, static fn (): bool => true, static fn (): bool => true ) )->validate( $tree, DocumentValidator::MODE_CLONED );
		$this->assertSame( array(), $admin['errors'], 'an administrator\'s own page scripts can be kept in a copy' );
		$this->assertTrue( array() !== $admin['warnings'] );
		$author = ( new DocumentValidator( $compat, static fn (): bool => true, static fn (): bool => false ) )->validate( $tree, DocumentValidator::MODE_CLONED );
		$this->assertTrue( str_contains( implode( ' ', $author['errors'] ), 'not allowed to publish unfiltered HTML' ) );
		$link = $tree;
		$link[0]['elements'][0] = array( 'id' => 'abcdef2', 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array( 'link' => array( 'url' => 'javascript:alert(1)' ) ), 'elements' => array() );
		$this->assertTrue( array() !== ( new DocumentValidator( $compat, static fn (): bool => true, static fn (): bool => true ) )->validate( $link, DocumentValidator::MODE_CLONED )['errors'], 'script URLs are refused even for administrators' );
	}

	public function testModelOutputCannotInjectLinksOrMarkup(): void {
		$leaf = static fn ( string $type, string $text, array $more = array() ): array => $more + array( 'type' => $type, 'level' => '', 'text' => $text, 'items' => array(), 'answers' => array(), 'variant' => '', 'icon' => '', 'aspect' => '', 'prompt' => '' );
		$evil = array(
			'page_title' => '<script>x</script>Title',
			'sections'   => array(
				array(
					'type'       => 'hero',
					'layout'     => 'stack',
					'columns'    => 1,
					'background' => 'url(javascript:alert(1))',
					'align'      => 'left',
					'header'     => array(
						$leaf( 'heading', '<img src=x onerror=alert(1)>', array( 'level' => 'h1' ) ),
						$leaf( 'text', '<a href="javascript:alert(1)">click</a>' ),
						$leaf( 'button', 'Go', array( 'variant' => 'primary' ) ),
						$leaf( 'icon', '', array( 'icon' => 'fa-x" onload="alert(1)' ) ),
					),
					'groups'     => array(),
				),
			),
		);
		$gen  = PageGenerator::toDocument( $evil, array( 'cta_url' => 'javascript:alert(1)' ) );
		$json = (string) json_encode( $gen['document'] );
		$this->assertFalse( self::executable( implode( ' ', array_map( 'strval', array_merge( ...array_map( static fn ( array $e ): array => array( $e['text'] ?? '', $e['html'] ?? '' ), $gen['document']['sections'][0]['elements'] ) ) ) ) ) );
		$by = array_column( $gen['document']['sections'][0]['elements'], null, 'type' );
		$this->assertSame( '#', $by['button']['url'], 'only the user\'s safe CTA URL is ever used' );
		$this->assertSame( 'check', $by['icon']['icon'] );
		$this->assertSame( '', $gen['document']['sections'][0]['style']['background'], 'colours cannot be given by the model' );
		$this->assertFalse( str_contains( $json, 'onerror=' ) && ! str_contains( $json, '&lt;img' ), 'tags arrive as escaped text' );

		$wrapped = InternalLinks::wrap( '<p>See our <b>payroll</b> page</p>', 'payroll', '" onmouseover="alert(1)' );
		$this->assertFalse( (bool) preg_match( '/\sonmouseover\s*=/', html_entity_decode( $wrapped ) ) && ! str_contains( $wrapped, '&quot; onmouseover' ), 'link target is attribute-escaped' );

		$section = PageDocument::normalize( \AiSeoContentStudio\Tests\EngineTest::landingPage() )['sections'][1];
		$answer  = array(
			'section' => array( 'type' => 'cards', 'layout' => 'grid', 'columns' => 3, 'background' => '', 'align' => 'left', 'header' => array( $leaf( 'heading', '<script>alert(1)</script>Safe', array( 'level' => 'h2' ) ) ), 'groups' => array() ),
			'summary' => '<b>done</b>',
		);
		$parsed = SectionWriter::parse( $answer, 'rewrite', $section );
		$this->assertFalse( self::executable( $parsed['section']['elements'][0]['text'] ) );
		$this->assertSame( 'done', $parsed['summary'] );
	}

	public function testSqlInputsAreBoundAndStoredDataIsValidated(): void {
		\FakeSite::reset();
		\FakeSite::$users = array( 2 => array( 'aiscs_generate_content' ) );
		$items            = new LibraryRepository();
		$id               = $items->insert( array( 'owner_id' => 2, 'name' => "x'); DROP TABLE wp_aiscs_design_library; --", 'kind' => "section' OR 1=1 --", 'category' => "faq' --", 'elements' => array() ) );
		$this->assertTrue( $id > 0 );
		$item = $items->get( $id );
		$this->assertSame( 'section', $item['kind'], 'unknown kind replaced' );
		$this->assertSame( 'other', $item['category'], 'unknown category replaced' );
		$this->assertSame( array(), $items->listFor( 2, '', "x' OR '1'='1" ), 'category filter is bound, not concatenated' );
		$this->assertCount( 1, $items->listFor( 2 ), 'table intact' );

		$revisions = new RevisionRepository();
		$revisions->add( array( 'page_id' => 9, 'action' => 'drop table', 'section_id' => "x'; --<b>", 'summary' => '<script>s</script>Sum', 'elements' => array() ) );
		$row = $revisions->get( 9, 1 );
		$this->assertSame( 'update', $row['action'] );
		$this->assertSame( 'x--b', $row['section_id'], 'only id characters are kept' );
		$this->assertFalse( str_contains( $row['summary'], '<script' ) );

		// A tampered snapshot is ignored, never decoded into elements.
		global $wpdb;
		$wpdb->update( StudioTables::revisions(), array( 'snapshot' => 'z:not-base64!!' ), array( 'page_id' => 9 ) );
		$this->assertNull( $revisions->get( 9, 1 )['snapshot'] );
		$wpdb->update( StudioTables::revisions(), array( 'snapshot' => 'O:8:"stdClass":0:{}' ), array( 'page_id' => 9 ) );
		$this->assertNull( $revisions->get( 9, 1 )['snapshot'], 'no PHP unserialize path' );
	}

	public function testFixesAndPreviewsCannotBeAbused(): void {
		$tree     = array( array( 'id' => 'abcdef1', 'elType' => 'container', 'settings' => array(), 'elements' => array() ) );
		$findings = array( Findings::make( 'a', 'links', 'warning', 'm', array( 'abcdef1' ), 'Label', array( array( 'type' => 'settings', 'element_id' => 'abcdef1', 'set' => array( 'text' => '{value}' ) ) ), true ) );
		$applied  = Findings::apply( $tree, $findings, array( $findings[0]['id'], 'a000000000000' ), array( $findings[0]['id'] => '<img src=x onerror=alert(1)>' ) );
		$this->assertSame( '&lt;img src=x onerror=alert(1)&gt;', $applied['elements'][0]['settings']['text'] );
		$this->assertSame( array( 'a000000000000' ), $applied['skipped'], 'ids the server did not produce do nothing' );

		\FakeSite::reset();
		$posts       = new ToolPosts();
		$posts->caps = array( 2 => array( 'edit_pages' ), 3 => array() );
		$studio      = new StudioServices( new FakeBridge(), new GeneratedPageRepository(), new AuditLog(), $posts );
		$previews    = new PreviewManager( $studio );
		$real        = $posts->insertDraft( 'Real page', '', 2 );
		$previews->delete( $real );
		$this->assertTrue( isset( $posts->posts[ $real ] ), 'only preview pages can be deleted through the preview manager' );
		$threw = '';
		try {
			$previews->create( array(), array(), 3, 'x' );
		} catch ( \AiSeoContentStudio\WebsiteStudio\Engine\DraftException $e ) {
			$threw = $e->reason;
		}
		$this->assertSame( 'forbidden', $threw, 'previews need permission to create pages' );
		$threw = '';
		try {
			$previews->create( array( array( 'id' => 'abcdef1', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => '<script>x</script>' ), 'elements' => array() ) ), array(), 2, 'x' );
		} catch ( \AiSeoContentStudio\WebsiteStudio\Engine\DraftException $e ) {
			$threw = $e->reason;
		}
		$this->assertSame( 'invalid_document', $threw, 'previews are validated too' );
	}
}
