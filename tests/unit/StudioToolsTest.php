<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\Media\MediaImporter;
use AiSeoContentStudio\REST\DesignLibraryController;
use AiSeoContentStudio\REST\PageToolsController;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Providers\Contracts\TextResponse;
use AiSeoContentStudio\Providers\Contracts\Usage;
use AiSeoContentStudio\Providers\TextGenerationService;
use AiSeoContentStudio\WebsiteStudio\Editor\AccessibilityChecker;
use AiSeoContentStudio\WebsiteStudio\Editor\ConsistencyChecker;
use AiSeoContentStudio\WebsiteStudio\Editor\ElementTree;
use AiSeoContentStudio\WebsiteStudio\Editor\Findings;
use AiSeoContentStudio\WebsiteStudio\Editor\Headings;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorCompat;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorMapper;
use AiSeoContentStudio\WebsiteStudio\Engine\IdGenerator;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;
use AiSeoContentStudio\WebsiteStudio\Generator\SiteGateway;
use AiSeoContentStudio\WebsiteStudio\History\RevisionDiff;
use AiSeoContentStudio\WebsiteStudio\History\RevisionService;
use AiSeoContentStudio\WebsiteStudio\Library\DesignLibrary;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\Persistence\BrandProfileRepository;
use AiSeoContentStudio\WebsiteStudio\Persistence\GeneratedPageRepository;
use AiSeoContentStudio\WebsiteStudio\Persistence\LibraryRepository;
use AiSeoContentStudio\WebsiteStudio\Persistence\RevisionRepository;
use AiSeoContentStudio\WebsiteStudio\Preview\PreviewManager;
use AiSeoContentStudio\WebsiteStudio\Sections\Proposals;
use AiSeoContentStudio\WebsiteStudio\Sections\RegenerateJob;
use AiSeoContentStudio\WebsiteStudio\Sections\SectionLocator;
use AiSeoContentStudio\WebsiteStudio\Sections\SectionTransforms;
use AiSeoContentStudio\WebsiteStudio\Sections\SectionWriter;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

require_once dirname( __DIR__ ) . '/fakes.php';
require_once __DIR__ . '/GeneratorTest.php';

class ToolPosts extends FakePosts {
	public function meta( int $id, string $key ): mixed { return $this->meta[ $id ][ $key ] ?? ''; }
	public function title( int $id ): string { return (string) ( $this->posts[ $id ]['title'] ?? '' ); }
}

class ToolProjects extends FakeProjects {
	private int $next = 700;
	public function create( array $data, int $user_id ): int { return $this->next++; }
	public function exists( int $id ): bool { return isset( $this->sections[ $id ] ); }
	public function canAccess( int $id, int $user_id ): bool { return true; }
}

class ToolMedia extends MediaImporter {
	public array $deleted = array();
	public function deleteUnused( int $id ): bool { $this->deleted[] = $id; return true; }
}

/** Returns a fixed section answer. */
class FakeSectionText extends TextGenerationService {
	public array $requests = array();
	public function __construct( private array $answer ) {}
	public function generate( TextRequest $request, ?string $provider_id = null ): TextResponse {
		$this->requests[] = $request;
		return new TextResponse( 'fake', 'section-model', '', $this->answer, TextResponse::FINISH_STOP, new Usage( 300, 400 ) );
	}
}

class StudioToolsTest extends TestCase {

	private FakeBridge $bridge;
	private ToolPosts $posts;
	private StudioServices $studio;
	private ToolProjects $projects;

	private function setUpSite(): void {
		\FakeSite::reset();
		\FakeSite::$users   = array( 2 => array( 'aiscs_generate_content', 'upload_files' ) );
		\FakeSite::$current = 2;
		$this->bridge       = new FakeBridge();
		$this->posts        = new ToolPosts();
		$this->posts->caps  = array( 2 => array( 'edit_pages', 'edit_post' ) );
		$this->studio       = new StudioServices( $this->bridge, new GeneratedPageRepository(), new AuditLog(), $this->posts );
		$this->projects     = new ToolProjects();
	}

	/** A studio page built from the engine's landing page. */
	private function page(): int {
		return (int) $this->studio->builder()->build( EngineTest::landingPage(), EngineTest::tokens(), array( 'user_id' => 2, 'generation_type' => 'generated' ) )['page_id'];
	}

	private function proposals(): Proposals {
		return new Proposals( $this->projects, $this->studio, new PreviewManager( $this->studio ), new FakeSiteGateway(), new ToolMedia(), new BrandProfileRepository() );
	}

	private static function mapped( array $tokens = array() ): array {
		$compat = new ElementorCompat( true, '3.25.0', false, true );
		return ( new ElementorMapper( $compat, new IdGenerator(), $tokens ?: EngineTest::tokens() ) )->map( PageDocument::normalize( EngineTest::landingPage() ) )['elements'];
	}

	private static function widget( string $type, array $settings, string $id = '' ): array {
		static $n = 0;
		return array( 'id' => '' !== $id ? $id : sprintf( 'w%06d', ++$n ), 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => array() );
	}

	private static function box( string $id, array $children, array $settings = array(), bool $inner = false ): array {
		return array( 'id' => $id, 'elType' => 'container', 'isInner' => $inner, 'settings' => $settings, 'elements' => $children );
	}

	public function testRevisionStoreKeepsTheFirstAndTheNewest(): void {
		\FakeSite::reset();
		$repo = new RevisionRepository();
		for ( $i = 1; $i <= 30; $i++ ) {
			$this->assertSame( $i, $repo->add( array( 'page_id' => 5, 'action' => 1 === $i ? 'create' : 'update', 'summary' => "Change $i", 'created_by' => 2, 'elements' => array( array( 'id' => 'abcdefg', 'n' => $i ) ), 'document' => array( 'sections' => array() ), 'section_map' => array( 's' => 'abcdefg' ) ) ) );
		}
		$list = $repo->listFor( 5 );
		$this->assertCount( RevisionRepository::MAX_PER_PAGE, $list );
		$this->assertSame( 30, $list[0]['revision'] );
		$this->assertSame( 1, end( $list )['revision'], 'revision 1 is always kept' );
		$this->assertSame( 7, $list[ RevisionRepository::MAX_PER_PAGE - 2 ]['revision'] );
		$row = $repo->get( 5, 30 );
		$this->assertSame( 30, $row['snapshot']['elements'][0]['n'] );
		$this->assertSame( array( 's' => 'abcdefg' ), $row['snapshot']['section_map'] );
		$this->assertSame( RevisionRepository::hash( array( array( 'id' => 'abcdefg', 'n' => 30 ) ) ), $repo->latest( 5 )['hash'] );
		$this->assertNull( $repo->get( 5, 3 ), 'old revisions trimmed' );
		$this->assertSame( 'update', ( new RevisionRepository() )->get( 5, 30 )['action'] );
		$repo->deleteForPage( 5 );
		$this->assertSame( array(), $repo->listFor( 5 ) );
	}

	public function testDraftManagerRecordsRevisionsAndOutsideEdits(): void {
		$this->setUpSite();
		$page = $this->page();
		$revs = $this->studio->revisions();
		$this->assertSame( 'create', $revs->listFor( $page )[0]['action'] );
		$this->assertTrue( isset( $revs->get( $page, 1 )['snapshot']['document']['_tokens'] ), 'build tokens are stored with the document' );

		// Someone edits the page in Elementor, then the studio changes it.
		$edited = $this->bridge->docs[ $page ];
		$edited[0]['settings']['note'] = 'edited by hand';
		$this->bridge->docs[ $page ]   = $edited;
		$changed = $edited;
		array_pop( $changed );
		$result = $this->studio->drafts()->update( $page, $changed, 2, 'cloned', array( 'action' => 'fix', 'summary' => 'Test change' ) );
		$list   = $revs->listFor( $page );
		$this->assertSame( array( 'fix', 'external', 'create' ), array_column( $list, 'action' ) );
		$this->assertSame( 3, $result['revision'] );
		$this->assertSame( 'edited by hand', $revs->get( $page, 2 )['snapshot']['elements'][0]['settings']['note'], 'the hand edit is kept as its own revision' );

		// No outside edit: no extra revision.
		$this->studio->drafts()->update( $page, $changed, 2, 'cloned' );
		$this->assertCount( 4, $revs->listFor( $page ) );
	}

	public function testConsistencyCheckerReportsAndFixesOnlyWhatItNames(): void {
		$elements = self::mapped();
		$clean    = ( new ConsistencyChecker( EngineTest::tokens() ) )->check( $elements );
		$this->assertSame( array(), $clean['findings'], 'a page built from the tokens matches them' );
		$this->assertSame( 'not_checked', $clean['categories']['images']['status'], 'no image style in the profile' );
		$this->assertSame( 100, $clean['score'] );

		$tokens                                     = EngineTest::tokens();
		$tokens['components']['button']['radius'] = '2px';
		$tokens['typography']['heading']['family'] = 'Lora';
		$tokens['layout']['container_width']       = '960px';
		$report = ( new ConsistencyChecker( $tokens ) )->check( $elements );
		$cats   = array_column( $report['findings'], 'category' );
		foreach ( array( 'buttons', 'typography', 'widths' ) as $expected ) {
			$this->assertTrue( in_array( $expected, $cats, true ), $expected );
		}
		$this->assertSame( 'warning', $report['categories']['buttons']['status'] );
		$this->assertSame( 'pass', $report['categories']['spacing']['status'] );

		// Off-brand colour on one heading, fixed to the nearest brand colour.
		$hero_heading = $elements[0]['elements'][0]['id'];
		$tinted       = ElementTree::setSettings( $elements, $hero_heading, array( 'title_color' => '#22385F' ) );
		$report       = ( new ConsistencyChecker( EngineTest::tokens() ) )->check( $tinted );
		$this->assertCount( 1, $report['findings'] );
		$color = $report['findings'][0];
		$this->assertSame( 'colors', $color['category'] );
		$this->assertTrue( str_contains( $color['message'], 'close to the brand colour #1F365C' ) );
		$fixed = Findings::apply( $tinted, $report['findings'], array( $color['id'] ) );
		$this->assertSame( array( $color['id'] ), $fixed['applied'] );
		$this->assertSame( '#1F365C', ElementTree::find( $fixed['elements'], $hero_heading )['settings']['title_color'] );
		$this->assertSame( $elements[1], $fixed['elements'][1], 'unrelated sections unchanged' );
		$this->assertSame( array(), ( new ConsistencyChecker( EngineTest::tokens() ) )->check( $fixed['elements'] )['findings'] );

		// Kit colour: fixed as a global reference.
		$kit_report = ( new ConsistencyChecker( EngineTest::tokens(), array( 'primary' => '#1F365C' ) ) )->check( $tinted );
		$kit_fixed  = Findings::apply( $tinted, $kit_report['findings'], array( $kit_report['findings'][0]['id'] ) );
		$settings   = ElementTree::find( $kit_fixed['elements'], $hero_heading )['settings'];
		$this->assertFalse( isset( $settings['title_color'] ) );
		$this->assertSame( 'globals/colors?id=primary', $settings['__globals__']['title_color'] );

		$this->assertSame( array( 'nope' ), Findings::apply( $tinted, $report['findings'], array( 'nope' ) )['skipped'], 'unknown ids are skipped' );
	}

	public function testResponsiveAndHeadingChecks(): void {
		$heading = self::widget( 'heading', array( 'title' => 'Big', 'header_size' => 'h1', 'typography_font_size' => array( 'unit' => 'px', 'size' => 64 ) ), 'aaaa001' );
		$h4      = self::widget( 'heading', array( 'title' => 'Skipped', 'header_size' => 'h4' ), 'aaaa002' );
		$h1b     = self::widget( 'heading', array( 'title' => 'Second', 'header_size' => 'h1' ), 'aaaa003' );
		$row     = self::box(
			'aaaa010',
			array(
				self::box( 'aaaa011', array( $h4 ), array( 'width' => ElementorMapper::columnWidth( 3, 24 ), 'width_tablet' => ElementorMapper::columnWidth( 2, 24 ) ), true ),
				self::box( 'aaaa012', array( $h1b ), array( 'width' => ElementorMapper::columnWidth( 3, 24 ), 'width_mobile' => ElementorMapper::columnWidth( 1, 24 ) ), true ),
			),
			array( 'flex_direction' => 'row' ),
			true
		);
		$page    = array( self::box( 'aaaa020', array( $heading, $row ), array( 'padding' => ElementorMapper::dims( '120px 0' ) ) ) );
		$report  = ( new ConsistencyChecker( array() ) )->check( $page );
		$msgs    = array_column( $report['findings'], 'message', 'category' );
		$this->assertSame( 'fail', $report['categories']['headings']['status'] );
		$this->assertTrue( str_contains( implode( ' ', array_column( $report['findings'], 'message' ) ), 'has 2 H1 headings' ) );
		$this->assertTrue( str_contains( implode( ' ', array_column( $report['findings'], 'message' ) ), 'An H4 follows an H1' ) );
		$responsive = array_values( array_filter( $report['findings'], static fn ( array $f ): bool => 'responsive' === $f['category'] ) );
		$this->assertSame( array( array( 'aaaa001' ), array( 'aaaa011' ), array( 'aaaa020' ) ), array_column( $responsive, 'elements' ), 'big heading, a column that stays side by side (the other stacks), big padding' );
		$fixed = Findings::apply( $page, $report['findings'], array_column( $report['findings'], 'id' ) );
		$after = ( new ConsistencyChecker( array() ) )->check( $fixed['elements'] );
		$this->assertSame( array(), array_values( array_filter( $after['findings'], static fn ( array $f ): bool => 'responsive' === $f['category'] ) ) );
		$this->assertSame( array( 1, 2, 2 ), array_column( Headings::outline( $fixed['elements'] ), 'level' ) );
		$this->assertSame( array( 'unit' => 'px', 'size' => 40, 'sizes' => array() ), ElementTree::find( $fixed['elements'], 'aaaa001' )['settings']['typography_font_size_mobile'] );
	}

	public function testAccessibilityChecker(): void {
		$alt  = static fn ( int $id ): string => 7 === $id ? 'Team at work' : '';
		$page = array(
			self::box(
				'bbbb001',
				array(
					self::widget( 'heading', array( 'title' => 'Hello', 'header_size' => 'h1', 'title_color' => '#777777' ), 'bbbb010' ),
					self::widget( 'text-editor', array( 'editor' => '<p>Read <a href="/x">click here</a> and <a href="/y"></a></p>', 'typography_font_size' => array( 'unit' => 'px', 'size' => 12 ) ), 'bbbb011' ),
					self::widget( 'button', array( 'text' => 'Learn more', 'link' => array( 'url' => '#' ) ), 'bbbb012' ),
					self::widget( 'image', array( 'image' => array( 'id' => 9, 'url' => '' ), 'caption' => 'Office' ), 'bbbb013' ),
					self::widget( 'image', array( 'image' => array( 'id' => 7, 'url' => '' ) ), 'bbbb014' ),
					self::widget( 'form', array( 'form_fields' => array( array( 'field_type' => 'email', 'field_label' => '', 'placeholder' => 'Your e-mail' ) ) ), 'bbbb015' ),
				),
				array( 'background_background' => 'classic', 'background_color' => '#888888' )
			),
			self::box( 'bbbb002', array( self::widget( 'heading', array( 'title' => 'Dark', 'header_size' => 'h2' ), 'bbbb020' ) ), array( 'background_background' => 'classic', 'background_color' => '#111111' ) ),
		);
		$report = ( new AccessibilityChecker( array(), $alt ) )->check( $page );
		$by     = array();
		foreach ( $report['findings'] as $f ) {
			$by[ $f['category'] ][] = $f;
		}
		$this->assertSame( 1, $report['categories']['images']['errors'], 'only the image without alt' );
		$this->assertSame( 'Office', $by['images'][0]['fix']['suggestion'] );
		$this->assertTrue( $by['images'][0]['fix']['needs_value'] );
		$this->assertSame( 2, $report['categories']['contrast']['errors'] + $report['categories']['contrast']['warnings'], 'grey on grey, and theme colour on dark' );
		$this->assertSame( 1, $report['categories']['links']['errors'], 'empty link' );
		$this->assertSame( 3, $report['categories']['links']['warnings'], 'generic link text, generic button, button without link' );
		$this->assertSame( 1, $report['categories']['forms']['errors'] );
		$this->assertSame( 1, $report['categories']['readability']['warnings'] );

		$ids    = array_column( $report['findings'], 'id' );
		$button = array_values( array_filter( $report['findings'], static fn ( array $f ): bool => 'links' === $f['category'] && null !== $f['fix'] ) )[0];
		$fixed  = Findings::apply( $page, $report['findings'], $ids, array( $by['images'][0]['id'] => 'Our office', $button['id'] => 'See our <services>' ) );
		$this->assertSame( array( array( 'attachment_id' => 9, 'alt' => 'Our office' ) ), $fixed['attachments'] );
		$this->assertSame( 'See our &lt;services&gt;', ElementTree::find( $fixed['elements'], 'bbbb012' )['settings']['text'], 'user text is escaped' );
		$this->assertSame( 'Your e-mail', ElementTree::find( $fixed['elements'], 'bbbb015' )['settings']['form_fields'][0]['field_label'] );
		$after = ( new AccessibilityChecker( array(), static fn ( int $id ): string => 'x' ) )->check( $fixed['elements'] );
		$this->assertSame( 0, $after['categories']['contrast']['errors'] + $after['categories']['contrast']['warnings'] );
		$this->assertSame( '#111111', ElementTree::find( $fixed['elements'], 'bbbb010' )['settings']['title_color'] );
		$this->assertSame( '#FFFFFF', ElementTree::find( $fixed['elements'], 'bbbb020' )['settings']['title_color'] );
	}

	public function testSectionTransforms(): void {
		$doc   = PageDocument::normalize( EngineTest::landingPage() );
		$cards = $doc['sections'][1];
		$dark  = SectionTransforms::apply( 'colors', $cards, array( 'background' => 'dark' ), EngineTest::tokens() )['section'];
		$this->assertSame( 'token:heading', $dark['style']['background'] );
		$this->assertSame( 'token:background', $dark['style']['text_color'] );
		$this->assertSame( $cards['elements'], $dark['elements'], 'content untouched' );

		$grid = SectionTransforms::apply( 'layout', $cards, array( 'kind' => 'grid', 'columns' => 2 ), array() )['section'];
		$this->assertSame( array( 'desktop' => 2, 'tablet' => 2, 'mobile' => 1 ), $grid['layout']['columns'] );
		$threw = '';
		try {
			SectionTransforms::apply( 'layout', $cards, array( 'kind' => 'split' ), array() );
		} catch ( \InvalidArgumentException $e ) {
			$threw = $e->getMessage();
		}
		$this->assertTrue( str_contains( $threw, 'exactly two parts' ) );

		$spaced = SectionTransforms::apply( 'spacing', $cards, array( 'spacing' => 'compact' ), array() )['section'];
		$this->assertSame( array( 'desktop' => '40px 0', 'tablet' => '32px 0', 'mobile' => '24px 0' ), $spaced['style']['padding'] );

		$bigger = SectionTransforms::apply( 'typography', $doc['sections'][0], array( 'scale' => 'larger', 'font' => 'Lora' ), EngineTest::tokens() )['section'];
		$this->assertSame( array( 'desktop' => '62px', 'tablet' => '53px', 'mobile' => '45px' ), $bigger['elements'][0]['style']['font_size'], 'h1 from the 52px brand size' );
		$this->assertSame( 'Lora', explode( ',', $bigger['elements'][0]['style']['font_family'] )[0] );

		$responsive = SectionTransforms::apply( 'responsive', $spaced, array(), array() )['section'];
		$this->assertSame( '20px 0', $responsive['style']['padding']['mobile'] );
		$this->assertSame( 1, $responsive['layout']['columns']['mobile'] );
		$errors = array();
		$grid['id'] = 'grid-copy';
		$dark['id'] = 'dark-copy';
		PageDocument::normalize( array( 'schema_version' => 1, 'page' => array( 'title' => 'x' ), 'sections' => array( $bigger, $responsive, $dark, $grid ) ), $errors );
		$this->assertSame( array(), $errors );
	}

	/** A model answer that rewrites the cards section (one image, wrong H1). */
	private static function sectionAnswer( bool $redesign = false ): array {
		$leaf = static fn ( string $type, string $text = '', array $more = array() ): array => $more + array( 'type' => $type, 'level' => '', 'text' => $text, 'items' => array(), 'answers' => array(), 'variant' => '', 'icon' => '', 'aspect' => '', 'prompt' => '' );
		$card = static fn ( string $title, array $extra = array() ): array => array( 'card' => true, 'elements' => array_merge( array( $leaf( 'heading', $title, array( 'level' => 'h3' ) ), $leaf( 'text', 'New text for ' . $title ) ), $extra ) );
		return array(
			'section' => array(
				'type'       => 'cards',
				'layout'     => $redesign ? 'grid' : 'stack',
				'columns'    => 4,
				'background' => 'primary',
				'align'      => 'left',
				'header'     => array( $leaf( 'heading', 'Services we offer', array( 'level' => 'h1' ) ) ),
				'groups'     => array( $card( 'Books' ), $card( 'Payroll' ), $card( 'Tax', array( $leaf( 'image', 'Tax papers', array( 'aspect' => '3:2' ) ), $leaf( 'image', 'Extra' ) ) ), $card( 'Advice' ) ),
			),
			'summary' => 'Rewrote the service cards.',
		);
	}

	public function testSectionWriterRequestAndParse(): void {
		$doc     = PageDocument::normalize( EngineTest::landingPage() );
		$cards   = $doc['sections'][1];
		$request = SectionWriter::request( 'seo', $cards, array( 'title' => 'Accounting', 'types' => array( 'hero', 'cards', 'faq' ), 'index' => 1 ), array( 'primary_keyword' => 'accounting services' ), 'Mention small businesses', array() );
		$this->assertSame( SectionWriter::PURPOSE, $request->purpose );
		$payload = json_decode( $request->messages[0]->content, true );
		$this->assertSame( 'Mention small businesses', $payload['instruction'] );
		$this->assertSame( array( 'hero', 'cards', 'faq' ), $payload['page']['section_types'] );
		$this->assertSame( 3, count( $payload['current_section']['groups'] ) );
		$this->assertTrue( str_contains( $request->system, 'Never invent business facts' ) );

		$parsed = SectionWriter::parse( self::sectionAnswer(), 'rewrite', $cards );
		$new    = $parsed['section'];
		$this->assertSame( 'services', $new['id'] );
		$this->assertSame( $cards['layout'], $new['layout'], 'rewrite keeps the layout' );
		$this->assertSame( $cards['style'], $new['style'] );
		$this->assertSame( 'h2', $new['elements'][0]['level'], 'no second H1' );
		$tax = $new['elements'][3]['elements'];
		$this->assertSame( 42, $tax[2]['attachment_id'], 'the existing image is reused' );
		$this->assertSame( 'Tax papers', $tax[2]['alt'] );
		$this->assertCount( 3, $tax, 'the extra image is dropped, never invented' );
		$this->assertSame( 1, $parsed['report']['images_dropped'] );
		$this->assertSame( 'Rewrote the service cards.', $parsed['summary'] );

		$redesigned = SectionWriter::parse( self::sectionAnswer( true ), 'redesign', $cards )['section'];
		$this->assertSame( 4, $redesigned['layout']['columns']['desktop'] );
		$this->assertSame( 'token:primary', $redesigned['style']['background'] );

		$hero = SectionWriter::parse( self::sectionAnswer(), 'rewrite', $doc['sections'][0] )['section'];
		$this->assertSame( 'h1', $hero['elements'][0]['level'], 'the hero keeps the page H1' );
	}

	public function testRegenerationProposalPreviewAndApproval(): void {
		$this->setUpSite();
		$page      = $this->page();
		$before    = $this->bridge->docs[ $page ];
		$proposals = $this->proposals();
		$sections  = SectionLocator::sections( $this->studio->pages()->forPage( $page ), $before, true );
		$this->assertSame( array( 'hero-001', 'services', 'faq' ), array_column( $sections, 'section_id' ) );
		$this->assertTrue( $sections[1]['present'] );

		// Style action: immediate proposal.
		$context  = $proposals->context( $page, 'services' );
		$changed  = SectionTransforms::apply( 'colors', $context['located']['section'], array( 'background' => 'dark' ), $proposals->tokens( $context['record'], 2 ) );
		$proposal = $proposals->build( $context, $changed['section'], $changed['summary'], array( 'action' => 'colors' ), 2 );
		$project  = $proposals->createProject( $page, 'services', 'colors', array(), 2 );
		$proposals->store( $project, $proposal );
		$this->assertSame( $before, $this->bridge->docs[ $page ], 'nothing written before approval' );

		$preview = $proposals->preview( $project, 2 );
		$this->assertTrue( str_contains( $preview['preview_url'], 'preview=true' ) );
		$preview_id = array_key_last( $this->posts->posts );
		$this->assertTrue( str_starts_with( $this->posts->posts[ $preview_id ]['title'], 'AI Studio preview (temporary)' ) );
		$this->assertTrue( '' !== $this->posts->meta[ $preview_id ][ PreviewManager::META ] );
		$this->assertSame( '#1F365C', $this->bridge->docs[ $preview_id ][1]['settings']['background_color'], 'the preview shows the new section' );
		$this->assertSame( $before[0], $this->bridge->docs[ $preview_id ][0] );

		$result = $proposals->approve( $project, 2 );
		$after  = $this->bridge->docs[ $page ];
		$this->assertSame( $before[0], $after[0], 'hero unchanged' );
		$this->assertSame( $before[2], $after[2], 'FAQ unchanged' );
		$this->assertSame( '#1F365C', $after[1]['settings']['background_color'] );
		$this->assertFalse( isset( $this->posts->posts[ $preview_id ] ), 'preview page deleted' );
		$record = $this->studio->pages()->forPage( $page );
		$this->assertSame( 'token:heading', $record['document']['sections'][1]['style']['background'], 'design document updated' );
		$this->assertSame( $after[1]['id'], $record['section_map']['services'] );
		$this->assertSame( 'regenerate', $this->studio->revisions()->listFor( $page )[0]['action'] );
		$this->assertSame( 'services', $this->studio->revisions()->listFor( $page )[0]['section_id'] );
		$this->assertTrue( $result['revision'] > 1 );

		$threw = '';
		try {
			$proposals->approve( $project, 2 );
		} catch ( DraftException $e ) {
			$threw = $e->reason;
		}
		$this->assertSame( 'proposal_closed', $threw );

		// AI rewrite through the job.
		$text = new FakeSectionText( self::sectionAnswer() );
		$job  = new RegenerateJob( $this->projects, $text, new FakeImages(), new ToolMedia(), new FakeSettings(), $proposals );
		$p2   = $proposals->createProject( $page, 'services', 'rewrite', array( 'instruction' => 'Shorter' ), 2 );
		$state = array();
		$job->runStep( 'generate', $p2, $state, array() );
		$job->runStep( 'generate', $p2, $state, array() );
		$this->assertCount( 1, $text->requests, 'retry does not repeat the request' );
		$view = Proposals::view( $this->projects->section( $p2, 'studio' ) );
		$this->assertSame( 'section-model', $view['model'] );
		$this->assertTrue( in_array( 'New text for Books', $view['texts'], true ) );
		$proposals->discard( $p2 );
		$this->assertSame( 'discarded', $this->projects->section( $p2, 'studio' )['status'] );
		$this->assertSame( $after, $this->bridge->docs[ $page ], 'a discarded proposal never touches the page' );

		// A section removed in Elementor can no longer be regenerated.
		$this->bridge->docs[ $page ] = array( $after[0], $after[2] );
		$threw = '';
		try {
			$proposals->context( $page, 'services' );
		} catch ( DraftException $e ) {
			$threw = $e->reason;
		}
		$this->assertSame( 'section_missing', $threw );
	}

	public function testRevisionCompareRestoreAndUndoSection(): void {
		$this->setUpSite();
		$page    = $this->page();
		$history = new RevisionService( $this->studio );
		$v1      = $this->bridge->docs[ $page ];
		$v2      = ElementTree::setSettings( $v1, $v1[0]['elements'][0]['id'], array( 'title' => 'Changed title' ) );
		array_pop( $v2 );
		$this->studio->drafts()->update( $page, $v2, 2, 'cloned', array( 'summary' => 'Edit' ) );

		$diff = $history->compare( $page, 1, 0 )['diff'];
		$this->assertSame( array( 'Questions' ), array_column( $diff['removed'], 'label' ) );
		$this->assertSame( array( 'Changed title' ), $diff['changed'][0]['text_added'] );
		$this->assertSame( array( 'Professional Accounting Services' ), $diff['changed'][0]['text_removed'] );
		$this->assertSame( 1, $diff['unchanged'] );

		$history->restoreSection( $page, 1, $v1[2]['id'], 2 );
		$this->assertSame( json_decode( (string) json_encode( $v1[2] ), true ), $this->bridge->docs[ $page ][2], 'removed FAQ put back in its place' );
		$this->assertSame( 'Changed title', $this->bridge->docs[ $page ][0]['elements'][0]['settings']['title'], 'other sections keep the newer version' );

		$history->restore( $page, 1, 2 );
		$this->assertSame( json_decode( (string) json_encode( $v1 ), true ), $this->bridge->docs[ $page ], 'as stored (Elementor stores JSON too)' );
		$items = $history->history( $page )['items'];
		$this->assertSame( array( 'restore', 'restore', 'update', 'create' ), array_column( $items, 'action' ) );
		$this->assertFalse( $history->history( $page )['edited_since'] );
		$this->bridge->docs[ $page ][0]['settings']['x'] = 1;
		$this->assertTrue( $history->history( $page )['edited_since'] );
		$this->assertSame( array(), RevisionDiff::compare( $v1, $v1 )['changed'] );
	}

	public function testDesignLibrary(): void {
		$this->setUpSite();
		\FakeSite::$users[3] = array( 'aiscs_generate_content' );
		$page    = $this->page();
		$library = new DesignLibrary( new LibraryRepository(), $this->studio, new PreviewManager( $this->studio ) );
		$doc     = $this->bridge->docs[ $page ];
		$section = $library->save( 2, $page, $doc[1]['id'], 'Service cards', 'services' );
		$this->assertSame( 'section', $section['kind'] );
		$this->assertSame( 'services', $section['category'] );
		$this->assertSame( 'What we do', $section['summary']['label'] );
		$card      = $doc[1]['elements'][1]['elements'][0];
		$component = $library->save( 2, $page, $card['id'], '', 'card' );
		$this->assertSame( 'component', $component['kind'] );
		$this->assertSame( 'Bookkeeping', $component['name'], 'name defaults to the label' );
		$this->assertSame( 'other', ( new LibraryRepository() )->get( $library->save( 2, $page, $card['id'], 'x', 'hero' )['id'] )['category'], 'section categories are not component categories' );

		$this->assertFalse( ( new LibraryRepository() )->canAccess( $section['id'], 3 ), 'other users cannot use it' );
		$this->assertCount( 3, ( new LibraryRepository() )->listFor( 2 ) );
		$this->assertCount( 0, ( new LibraryRepository() )->listFor( 3 ) );

		$result = $library->insert( $component['id'], $page, 1, 2 );
		$after  = $this->bridge->docs[ $page ];
		$this->assertCount( 4, $after );
		$this->assertSame( $result['element_id'], $after[1]['id'] );
		$this->assertSame( 'container', $after[1]['elType'], 'a component is wrapped in a section' );
		$this->assertSame( 'Bookkeeping', ElementTree::label( $after[1] ) );
		$this->assertTrue( $card['id'] !== $after[1]['elements'][0]['id'], 'new element ids' );
		$all = array_column( array_column( ElementTree::flatten( $after ), 'element' ), 'id' );
		$this->assertSame( count( $all ), count( array_unique( $all ) ), 'ids unique on the page' );
		$this->assertSame( 'insert', $this->studio->revisions()->listFor( $page )[0]['action'] );

		$copy = $library->duplicate( $section['id'], 2 );
		$this->assertSame( 'Service cards (copy)', $copy['name'] );
		$preview = $library->preview( $section['id'], 2 );
		$this->assertSame( $section['summary']['label'], ElementTree::label( $this->bridge->docs[ $preview['preview_id'] ][0] ) );

		$this->assertTrue( ( new LibraryRepository() )->update( $copy['id'], array( 'name' => 'Renamed', 'category' => 'faq' ) ) );
		$this->assertSame( 'faq', ( new LibraryRepository() )->get( $copy['id'] )['category'] );
		$this->assertTrue( ( new LibraryRepository() )->delete( $copy['id'] ) );

		$threw = '';
		try {
			$library->save( 2, $page, 'zzzzzzz', 'x', 'other' );
		} catch ( DraftException $e ) {
			$threw = $e->reason;
		}
		$this->assertSame( 'not_found', $threw );
	}

	private static function status( mixed $r ): int {
		return $r instanceof \WP_Error ? (int) $r->data['status'] : 200;
	}

	public function testRestPermissions(): void {
		$this->setUpSite();
		\FakeSite::$users[3]    = array( 'aiscs_generate_content' );
		\FakeSite::$users[4]    = array();
		\FakeSite::$editable    = array( 2 => array( 10 ) );
		\FakeSite::$posts[10]   = new \WP_Post( array( 'ID' => 10, 'post_type' => 'page', 'post_status' => 'draft' ) );
		\FakeSite::$posts[11]   = new \WP_Post( array( 'ID' => 11, 'post_type' => 'page', 'post_status' => 'trash' ) );
		\FakeSite::$posts[12]   = new \WP_Post( array( 'ID' => 12, 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
		$tools = ( new \ReflectionClass( PageToolsController::class ) )->newInstanceWithoutConstructor();
		$this->assertSame( 200, self::status( $tools->canUsePage( new \WP_REST_Request( array( 'id' => 10 ) ) ) ) );
		$this->assertSame( 404, self::status( $tools->canUsePage( new \WP_REST_Request( array( 'id' => 11 ) ) ) ), 'trashed' );
		$this->assertSame( 404, self::status( $tools->canUsePage( new \WP_REST_Request( array( 'id' => 12 ) ) ) ), 'not a page' );
		\FakeSite::$current = 3;
		$this->assertSame( 403, self::status( $tools->canUsePage( new \WP_REST_Request( array( 'id' => 10 ) ) ) ), 'cannot edit the page' );
		\FakeSite::$current = 4;
		$this->assertSame( 403, self::status( $tools->canUsePage( new \WP_REST_Request( array( 'id' => 10 ) ) ) ), 'no studio capability' );

		\FakeSite::$current = 2;
		$items   = new LibraryRepository();
		$id      = $items->insert( array( 'owner_id' => 2, 'name' => 'Mine', 'elements' => array( array( 'id' => 'abcdefg' ) ) ) );
		$library = new DesignLibraryController( $items, new DesignLibrary( $items, $this->studio, new PreviewManager( $this->studio ) ), new BrandProfileRepository() );
		$this->assertSame( 200, self::status( $library->canUseItem( new \WP_REST_Request( array( 'id' => $id ) ) ) ) );
		$this->assertSame( 404, self::status( $library->canUseItem( new \WP_REST_Request( array( 'id' => 999 ) ) ) ) );
		\FakeSite::$current = 3;
		$this->assertSame( 403, self::status( $library->canUseItem( new \WP_REST_Request( array( 'id' => $id ) ) ) ), 'not the owner' );
		$this->assertSame( 403, self::status( $library->create( new \WP_REST_Request( array( 'page_id' => 10, 'element_id' => 'abcdefg' ) ) ) ), 'saving needs edit permission on the page' );
	}
}
