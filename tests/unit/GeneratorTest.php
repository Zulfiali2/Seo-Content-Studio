<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\Providers\Contracts\ImageRequest;
use AiSeoContentStudio\Providers\Contracts\ImageResponse;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Providers\Contracts\TextResponse;
use AiSeoContentStudio\Providers\Contracts\Usage;
use AiSeoContentStudio\Providers\ImageGenerationService;
use AiSeoContentStudio\Providers\TextGenerationService;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;
use AiSeoContentStudio\WebsiteStudio\Generator\ContentLayoutMapper;
use AiSeoContentStudio\WebsiteStudio\Generator\GeneratedImages;
use AiSeoContentStudio\WebsiteStudio\Generator\GeneratorJob;
use AiSeoContentStudio\WebsiteStudio\Generator\InternalLinks;
use AiSeoContentStudio\WebsiteStudio\Generator\PageGenerator;
use AiSeoContentStudio\WebsiteStudio\Generator\SiteGateway;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\Persistence\BrandProfileRepository;
use AiSeoContentStudio\WebsiteStudio\Persistence\GeneratedPageRepository;
use AiSeoContentStudio\WebsiteStudio\StudioServices;
use AiSeoContentStudio\WebsiteStudio\Vision\VisionDesign;

require_once dirname( __DIR__ ) . '/fakes.php';
require_once __DIR__ . '/VisionTest.php';

/** Answers the generator request with a fixed page. */
class FakeGenText extends TextGenerationService {
	public array $requests = array();
	public function __construct( private array $answer ) {}
	public function generate( TextRequest $request, ?string $provider_id = null ): TextResponse {
		$this->requests[] = $request;
		return new TextResponse( 'fake', 'writer-model', '', $this->answer, TextResponse::FINISH_STOP, new Usage( 2000, 3000 ) );
	}
}

/** Returns a real PNG for every image request, or fails. */
class FakeImages extends ImageGenerationService {
	public array $prompts = array();
	public ?ProviderException $fail = null;
	public function __construct() {}
	public function generate( ImageRequest $request ): array {
		if ( null !== $this->fail ) {
			throw $this->fail;
		}
		$this->prompts[] = $request->prompt;
		$im = imagecreatetruecolor( 300, 200 );
		ob_start();
		imagepng( $im );
		$bytes = (string) ob_get_clean();
		return array(
			'response' => new ImageResponse( 'fake-img', 'img-model', $bytes, 'revised: ' . $request->prompt ),
			'file'     => array( 'mime' => 'image/png', 'ext' => 'png', 'width' => 300, 'height' => 200, 'bytes' => strlen( $bytes ) ),
		);
	}
}

class FakeGenMedia extends FakeMedia {
	public array $deleted = array();
	public function deleteUnused( int $id ): bool {
		$this->deleted[] = $id;
		return true;
	}
}

class FakeSiteGateway extends SiteGateway {
	public array $published = array(
		11 => 'https://site.test/payroll/',
		12 => 'https://site.test/bookkeeping/',
	);
	public array $attached = array();
	public array $deleted  = array();
	public array $seo      = array();
	public function linkCandidates( int $limit = 60 ): array {
		return array(
			array( 'id' => 11, 'title' => 'Payroll', 'url' => 'https://site.test/payroll/', 'type' => 'page' ),
			array( 'id' => 12, 'title' => 'Bookkeeping', 'url' => 'https://site.test/bookkeeping/', 'type' => 'page' ),
		);
	}
	public function permalink( int $id ): string { return $this->published[ $id ] ?? ''; }
	public function attach( int $attachment_id, int $page_id ): void { $this->attached[ $attachment_id ] = $page_id; }
	public function deleteImage( int $attachment_id ): bool { $this->deleted[] = $attachment_id; return true; }
	public function imageUrl( int $attachment_id ): string { return "https://site.test/wp-content/uploads/$attachment_id.png"; }
	public function saveSeo( int $page_id, array $seo ): void { $this->seo[ $page_id ] = $seo; }
}

class GeneratorTest extends TestCase {

	private static function leaf( string $type, string $text = '', array $more = array() ): array {
		return $more + array(
			'type'    => $type,
			'level'   => '',
			'text'    => $text,
			'items'   => array(),
			'answers' => array(),
			'variant' => '',
			'icon'    => '',
			'aspect'  => '',
			'prompt'  => '',
		);
	}

	private static function section( array $more ): array {
		return $more + array(
			'type'       => 'content',
			'layout'     => 'stack',
			'columns'    => 1,
			'background' => '',
			'align'      => 'left',
			'header'     => array(),
			'groups'     => array(),
		);
	}

	/** What a model returns for "payroll services". */
	public static function answer(): array {
		$card = static fn ( string $title, string $text ): array => array(
			'card'     => true,
			'elements' => array( self::leaf( 'icon', '', array( 'icon' => 'fa-check' ) ), self::leaf( 'heading', $title, array( 'level' => 'h3' ) ), self::leaf( 'text', $text ) ),
		);
		return array(
			'page_title'       => 'Payroll services for small businesses',
			'meta_title'       => 'Payroll Services for Small Businesses | Acme',
			'meta_description' => 'Payroll services that pay your team on time.',
			'slug'             => 'Payroll Services!',
			'sections'         => array(
				self::section(
					array(
						'type'       => 'hero',
						'layout'     => 'split',
						'columns'    => 2,
						'background' => 'dark',
						'groups'     => array(
							array(
								'card'     => false,
								'elements' => array(
									self::leaf( 'heading', 'Payroll services that pay your team on time', array( 'level' => 'h1' ) ),
									self::leaf( 'text', "First paragraph about payroll.\n\nSecond paragraph." ),
									self::leaf( 'button', 'Book a call', array( 'variant' => 'primary' ) ),
								),
							),
							array(
								'card'     => false,
								'elements' => array( self::leaf( 'image', 'Accountant reviewing payslips at a desk', array( 'aspect' => '3:2', 'prompt' => 'A bright modern office, an accountant reviewing payslips, natural light' ) ) ),
							),
						),
					)
				),
				self::section(
					array(
						'type'       => 'features',
						'layout'     => 'grid',
						'columns'    => 2, // Wrong for four short cards.
						'background' => 'surface',
						'header'     => array( self::leaf( 'heading', 'Why choose our payroll services', array( 'level' => 'h1' ) ) ),
						'groups'     => array(
							$card( 'On-time pay', 'Every run is checked before it goes out, with clear bookkeeping records you can share.' ),
							$card( 'Compliance', 'Filings handled. We keep 98% of clients for years.' ),
							$card( 'Self-service', 'Staff see their own payslips.' ),
							$card( 'Support', 'Talk to a real person.' ),
						),
					)
				),
				self::section(
					array(
						'type'   => 'faq',
						'layout' => 'grid',
						'header' => array( self::leaf( 'heading', 'Payroll questions', array( 'level' => 'h2' ) ) ),
						'groups' => array(
							array( 'card' => false, 'elements' => array( self::leaf( 'accordion', '', array( 'items' => array( 'How long does setup take?', 'Can you call me?' ), 'answers' => array( 'Usually a week.', 'Yes, call [Add your phone number].' ) ) ) ) ),
						),
					)
				),
				self::section(
					array(
						'type'       => 'cta',
						'background' => 'primary',
						'align'      => 'center',
						'header'     => array(
							self::leaf( 'heading', 'Ready to hand over payroll?', array( 'level' => 'h2' ) ),
							self::leaf( 'image', 'Team celebrating payday', array( 'aspect' => '1:1' ) ),
							self::leaf( 'button', 'Get started' ),
							self::leaf( 'button', 'Call us', array( 'variant' => 'secondary' ) ),
							self::leaf( 'button', 'Email us', array( 'variant' => 'secondary' ) ),
						),
					)
				),
				self::section( array( 'header' => array( self::leaf( 'text', '' ) ) ) ), // Empty: dropped.
			),
			'link_suggestions' => array(
				array( 'target_id' => 12, 'anchor' => 'bookkeeping records', 'section' => 1, 'reason' => 'Related service' ),
				array( 'target_id' => 12, 'anchor' => 'payroll', 'section' => 0, 'reason' => 'Duplicate target' ),
				array( 'target_id' => 99, 'anchor' => 'payroll', 'section' => 0, 'reason' => 'Unknown page' ),
				array( 'target_id' => 11, 'anchor' => 'pay your team', 'section' => 0, 'reason' => 'Only in a heading' ),
				array( 'target_id' => 11, 'anchor' => 'paragraph about payroll', 'section' => 0, 'reason' => 'In the hero text' ),
			),
			'assumptions'      => array( 'Phone number needed' ),
		);
	}

	public function testAnswerBecomesAValidDocument(): void {
		$gen = PageGenerator::toDocument( self::answer(), array( 'cta_url' => '/contact/', 'images' => 2, 'primary_keyword' => 'payroll services' ) );
		$doc = $gen['document'];
		$this->assertCount( 4, $doc['sections'], 'empty section dropped' );
		$this->assertSame( 'payroll-services', $doc['page']['slug'] );
		$this->assertSame( 'payroll services', $gen['seo']['keyword'] );
		$hero = $doc['sections'][0];
		$this->assertSame( 'token:heading', $hero['style']['background'] );
		$this->assertSame( 'token:background', $hero['style']['text_color'] );
		$this->assertSame( '<p>First paragraph about payroll.</p><p>Second paragraph.</p>', $hero['elements'][0]['elements'][1]['html'] );
		$this->assertSame( '/contact/', $hero['elements'][0]['elements'][2]['url'] );
		$image = $hero['elements'][1]['elements'][0];
		$this->assertSame( '_image', $image['type'] );
		$this->assertTrue( str_contains( $image['prompt'], 'accountant reviewing payslips' ) );
		$this->assertSame( 'h2', $doc['sections'][1]['elements'][0]['level'], 'second H1 demoted' );
		$this->assertSame( 'check', $doc['sections'][1]['elements'][1]['elements'][0]['icon'] );
		$this->assertSame( 'token:background', $doc['sections'][1]['elements'][1]['style']['background'], 'cards on a surface section use the page background' );
		$slots = PageGenerator::imageSlots( $doc );
		$this->assertCount( 2, $slots );
		$this->assertSame( 'Team celebrating payday', $slots[1]['prompt'], 'a missing prompt falls back to the alt text' );
		$this->assertSame( 'img-2', $slots[1]['id'] );
		$this->assertSame( 3, $slots[1]['section'] );

		$capped = PageGenerator::toDocument( self::answer(), array( 'images' => 1 ) );
		$this->assertCount( 1, PageGenerator::imageSlots( $capped['document'] ) );
		$this->assertSame( 1, $capped['report']['images_removed'] );
		$this->assertSame( '#', $capped['document']['sections'][0]['elements'][0]['elements'][2]['url'], 'no CTA URL: buttons link to #' );

		$errors = array();
		$probe  = array( 'placeholders' => 0 );
		PageDocument::normalize( VisionDesign::resolveImages( ContentLayoutMapper::adapt( $doc )['document'], static fn (): int => 7, $probe ), $errors );
		$this->assertSame( array(), $errors );
	}

	public function testCtaUrlAndRequest(): void {
		$this->assertSame( '', PageGenerator::ctaUrl( 'javascript:alert(1)' ) );
		$this->assertSame( '', PageGenerator::ctaUrl( '//evil.test/x' ) );
		$this->assertSame( '#contact', PageGenerator::ctaUrl( '#contact' ) );
		$this->assertSame( 'https://site.test/contact/', PageGenerator::ctaUrl( 'https://site.test/contact/' ) );

		$request = PageGenerator::request( array( 'topic' => 'Payroll', 'primary_keyword' => 'payroll services', 'length' => 'long', 'images' => 2, 'sections' => array( 'hero', 'faq' ) ), array( 'business' => array( 'name' => 'Acme' ) ), ( new FakeSiteGateway() )->linkCandidates() );
		$this->assertSame( PageGenerator::PURPOSE, $request->purpose );
		$payload = json_decode( $request->messages[0]->content, true );
		$this->assertSame( array( 'hero', 'faq' ), $payload['brief']['requested_sections'] );
		$this->assertSame( 'Acme', $payload['business']['name'] );
		$this->assertSame( array( 'id' => 11, 'title' => 'Payroll', 'type' => 'page' ), $payload['internal_pages'][0], 'the model sees ids and titles, never URLs to copy' );
		$this->assertTrue( str_contains( $request->system, 'About 8 sections' ) );
		$this->assertTrue( str_contains( $request->system, 'Never invent business facts' ) );
		$this->assertTrue( str_contains( $request->system, 'at most 2 image elements' ) );
		$this->assertFalse( (bool) preg_match( '/"additionalProperties":true/', (string) json_encode( PageGenerator::schema() ) ) );
	}

	public function testLayoutMapperFitsTheLayoutToTheContent(): void {
		$doc     = PageGenerator::toDocument( self::answer(), array() )['document'];
		$adapted = ContentLayoutMapper::adapt( $doc );
		$out     = $adapted['document'];
		$this->assertSame( 'split', $out['sections'][0]['layout']['kind'] );
		$this->assertSame( 4, $out['sections'][1]['layout']['columns']['desktop'], 'four short cards: four columns' );
		$this->assertSame( 2, $out['sections'][1]['layout']['columns']['tablet'] );
		$this->assertSame( 'stack', $out['sections'][2]['layout']['kind'], 'FAQ in one column' );
		$this->assertSame( 'accordion', $out['sections'][2]['elements'][1]['type'] );
		$buttons = array_filter( $out['sections'][3]['elements'], static fn ( array $e ): bool => 'button' === $e['type'] );
		$this->assertCount( 2, $buttons, 'third button removed' );
		$this->assertSame( '44px', $out['sections'][0]['elements'][0]['elements'][0]['style']['font_size']['desktop'] ?? 'none', 'long H1 inside the split column' ) ;
		$codes = array_column( $adapted['changes'], 'change' );
		$this->assertTrue( in_array( 'columns_4', $codes, true ) );
		$this->assertTrue( in_array( 'faq_single_column', $codes, true ) );

		$this->assertSame( 2, ContentLayoutMapper::gridColumns( 4, true ) );
		$this->assertSame( 3, ContentLayoutMapper::gridColumns( 5, false ) );
		$this->assertSame( 3, ContentLayoutMapper::gridColumns( 6, false ) );
		$this->assertSame( 4, ContentLayoutMapper::gridColumns( 8, false ) );
		$this->assertSame( 3, ContentLayoutMapper::gridColumns( 12, false ) );

		// A page without an H1 gets one; three long cards fall back to fewer columns.
		$long = str_repeat( 'word ', 70 );
		$page = array(
			'sections' => array(
				array( 'id' => 's1', 'type' => 'hero', 'layout' => array( 'kind' => 'stack', 'columns' => array( 'desktop' => 1 ) ), 'style' => array(), 'elements' => array( array( 'type' => 'heading', 'text' => 'Hi', 'level' => 'h2' ) ) ),
				array(
					'id'       => 's2',
					'type'     => 'cards',
					'layout'   => array( 'kind' => 'split', 'columns' => array( 'desktop' => 3 ) ),
					'style'    => array(),
					'elements' => array_fill( 0, 3, array( 'type' => 'group', 'style' => array(), 'elements' => array( array( 'type' => 'text', 'html' => "<p>$long</p>" ) ) ) ),
				),
			),
		);
		$fixed = ContentLayoutMapper::adapt( $page );
		$this->assertSame( 'h1', $fixed['document']['sections'][0]['elements'][0]['level'] );
		$this->assertSame( 'grid', $fixed['document']['sections'][1]['layout']['kind'] );
		$this->assertSame( 2, $fixed['document']['sections'][1]['layout']['columns']['desktop'] );
		$this->assertTrue( in_array( 'long_cards_fewer_columns', array_column( $fixed['changes'], 'change' ), true ) );
	}

	public function testInternalLinkSuggestionsAndInsertion(): void {
		$doc   = ContentLayoutMapper::adapt( PageGenerator::toDocument( self::answer(), array() )['document'] )['document'];
		$links = InternalLinks::suggestions( self::answer()['link_suggestions'], ( new FakeSiteGateway() )->linkCandidates(), $doc );
		$this->assertSame( array( 'bookkeeping records', 'paragraph about payroll' ), array_column( $links, 'anchor' ), 'duplicate, unknown and heading-only suggestions dropped' );
		$this->assertSame( 'https://site.test/bookkeeping/', $links[0]['url'] );

		$site   = new FakeSiteGateway();
		unset( $site->published[11] ); // Unpublished since the suggestion was made.
		$result = InternalLinks::apply( $doc, $links, array( $site, 'permalink' ) );
		$this->assertSame( array( 'link-1' ), $result['applied'] );
		$this->assertSame( array( 'link-2' ), $result['skipped'] );
		$card = $result['document']['sections'][1]['elements'][1]['elements'][2]['html'];
		$this->assertSame( '<p>Every run is checked before it goes out, with clear <a href="https://site.test/bookkeeping/">bookkeeping records</a> you can share.</p>', $card );

		$this->assertSame( '<p><a href="/x">Payroll</a> and <a href="/y">payroll</a></p>', InternalLinks::wrap( '<p><a href="/x">Payroll</a> and payroll</p>', 'payroll', '/y' ), 'existing links are skipped' );
		$this->assertSame( '<p>payrolls</p>', InternalLinks::wrap( '<p>payrolls</p>', 'payroll', '/y' ), 'whole words only' );
		$this->assertSame( '<p>a <a href="/q?a=1&amp;b=2">B&amp;Q</a></p>', InternalLinks::wrap( '<p>a B&amp;Q</p>', 'B&Q', '/q?a=1&b=2' ) );
	}

	public function testFactCheck(): void {
		$facts = PageGenerator::factCheck( PageGenerator::toDocument( self::answer(), array() )['document'] );
		$this->assertSame( array( 'We keep 98% of clients for years.' ), array_column( $facts['numbers'], 'text' ) );
		$this->assertSame( array( '[Add your phone number]' ), array_column( $facts['placeholders'], 'text' ) );
	}

	public function testKitTokens(): void {
		$tokens = GeneratorJob::kitTokens( array( 'system_colors' => array( array( '_id' => 'primary', 'color' => '#1F365C' ), array( '_id' => 'text', 'color' => '#333333' ), array( '_id' => 'evil', 'color' => 'red;}' ) ) ) );
		$this->assertSame( '#1F365C', $tokens['colors']['primary'] );
		$this->assertSame( '#333333', $tokens['colors']['heading'] );
		$this->assertSame( 'elementor_kit', $tokens['sources']['colors'] );
		$this->assertSame( '#EFF1F4', $tokens['colors']['surface'] );
		$this->assertSame( 'none', GeneratorJob::kitTokens( array() )['sources']['colors'] );
		$this->assertSame( '#2563EB', GeneratorJob::kitTokens( array() )['colors']['primary'] );
	}

	private function job( string $type, FakeGenText $text, FakeImages $images, FakeGenMedia $media, FakeSiteGateway $site, FakeBridge $bridge, FakeProjects $projects, FakePosts $posts ): GeneratorJob {
		return new GeneratorJob( $type, $projects, $text, $images, new FakeSettings(), new StudioServices( $bridge, new GeneratedPageRepository(), new AuditLog(), $posts ), $media, $site, new BrandProfileRepository() );
	}

	private static function run( GeneratorJob $job, array $options = array() ): void {
		$state = array();
		foreach ( $job->steps( 900, $options ) as $step ) {
			$job->runStep( $step, 900, $state, $options );
		}
	}

	public function testJobsEndToEnd(): void {
		\FakeSite::reset();
		\FakeSite::$users   = array( 2 => array( 'aiscs_generate_content', 'upload_files' ) );
		\FakeSite::$current = 2;
		$text        = new FakeGenText( self::answer() );
		$images      = new FakeImages();
		$media       = new FakeGenMedia();
		$site        = new FakeSiteGateway();
		$bridge      = new FakeBridge();
		$projects    = new FakeProjects();
		$posts       = new FakePosts();
		$posts->caps = array( 2 => array( 'edit_pages', 'edit_post' ) );
		$projects->save(
			900,
			'studio',
			array(
				'source'     => 'generator',
				'brief'      => array( 'topic' => 'Payroll', 'primary_keyword' => 'payroll services', 'images' => 2, 'cta_url' => '/contact/' ),
				'options'    => array( 'profile_id' => 0, 'title' => '' ),
				'candidates' => $site->linkCandidates(),
			)
		);

		$write = $this->job( GeneratorJob::TYPE_WRITE, $text, $images, $media, $site, $bridge, $projects, $posts );
		self::run( $write );
		self::run( $write );
		$this->assertCount( 1, $text->requests, 'retry does not repeat the request' );
		$design = $projects->section( 900, 'studio' )['design'];
		$this->assertCount( 2, $design['links'] );
		$this->assertCount( 2, $design['images'] );
		$this->assertSame( 'writer-model', $design['model'] );
		$this->assertSame( array( 'Phone number needed' ), $design['report']['assumptions'] );
		$this->assertSame( array(), $bridge->saves, 'nothing is built before review' );

		$studio                   = $projects->section( 900, 'studio' );
		$studio['approved_links'] = array( 'link-1' );
		$projects->save( 900, 'studio', $studio );
		$build = $this->job( GeneratorJob::TYPE_BUILD, $text, $images, $media, $site, $bridge, $projects, $posts );
		self::run( $build );
		self::run( $build );
		$this->assertCount( 1, $bridge->saves, 'one draft' );
		$this->assertCount( 2, $media->imported, 'two placeholders, imported once' );
		$studio = $projects->section( 900, 'studio' );
		$page   = $studio['result']['page_id'];
		$this->assertSame( array( 501 => $page, 502 => $page ), $site->attached, 'placeholders attached to the draft' );
		$this->assertSame( 'payroll services', $site->seo[ $page ]['keyword'] );
		$this->assertSame( array( 'link-1' ), $studio['result']['report']['links_applied'] );
		$this->assertSame( 'generated', ( new GeneratedPageRepository() )->forPage( $page )['generation_type'] );
		$json = (string) json_encode( $bridge->docs[ $page ] );
		$this->assertTrue( str_contains( $json, 'bookkeeping\/\">bookkeeping records<\/a>' ), 'approved link in the draft' );
		$this->assertFalse( str_contains( $json, 'payroll\/' ), 'unapproved link not inserted' );
		$this->assertSame( array( 'img-1', 'img-2' ), array_column( $studio['images'], 'id' ) );
		$this->assertSame( 'placeholder', $studio['images'][0]['status'] );

		// AI images: candidates only, until approved.
		$job     = $this->job( GeneratorJob::TYPE_IMAGES, $text, $images, $media, $site, $bridge, $projects, $posts );
		$options = array( 'slots' => array( 'img-1' ), 'run' => 'run1' );
		$this->assertSame( array( 'image:img-1' ), $job->steps( 900, $options ) );
		self::run( $job, $options );
		self::run( $job, $options );
		$this->assertCount( 1, $images->prompts, 'same run: no second image' );
		$slot = $projects->section( 900, 'studio' )['images'][0];
		$this->assertSame( 'candidate', $slot['status'] );
		$this->assertSame( 503, $slot['candidate']['attachment_id'] );
		$this->assertTrue( str_contains( json_encode( $bridge->docs[ $page ] ), '"id":501' ), 'page unchanged before approval' );

		self::run( $job, array( 'slots' => array( 'img-1' ), 'run' => 'run2' ) );
		$this->assertSame( array( 503 ), $media->deleted, 'the replaced, never-approved candidate is deleted' );

		$review = new GeneratedImages( $projects, new StudioServices( $bridge, new GeneratedPageRepository(), new AuditLog(), $posts ), $site, $media );
		$out    = $review->apply( 900, array( 'img-1', 'img-2' ), 2 );
		$this->assertSame( array( 'img-1' ), $out['applied'], 'img-2 has no candidate' );
		$json = (string) json_encode( $bridge->docs[ $page ] );
		$this->assertTrue( str_contains( $json, '"id":504' ) && ! str_contains( $json, '"id":501' ) );
		$this->assertSame( array( 501 ), $site->deleted, 'the placeholder is deleted once replaced' );
		$this->assertSame( $page, $site->attached[504] );
		$this->assertSame( 'applied', $projects->section( 900, 'studio' )['images'][0]['status'] );

		// Failing provider: the error is stored on the slot, nothing else changes.
		$images->fail = new ProviderException( 'Content policy', ProviderException::INVALID_REQUEST );
		self::run( $job, array( 'slots' => array( 'img-2' ), 'run' => 'run3' ) );
		$this->assertSame( 'Content policy', $projects->section( 900, 'studio' )['images'][1]['error'] );

		// Discard.
		$images->fail = null;
		self::run( $job, array( 'slots' => array( 'img-2' ), 'run' => 'run4' ) );
		$slots = $review->discard( 900, 'img-2' );
		$this->assertNull( $slots[1]['candidate'] );
		$this->assertSame( 'placeholder', $slots[1]['status'] );
		$this->assertSame( 505, end( $media->deleted ) );
	}
}
