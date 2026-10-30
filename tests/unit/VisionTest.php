<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\Http\HttpClient;
use AiSeoContentStudio\Logging\Logger;
use AiSeoContentStudio\Media\MediaImporter;
use AiSeoContentStudio\Providers\Anthropic\AnthropicProvider;
use AiSeoContentStudio\Providers\Contracts\Capability;
use AiSeoContentStudio\Providers\Contracts\ImageInput;
use AiSeoContentStudio\Providers\Contracts\Message;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Providers\Contracts\TextResponse;
use AiSeoContentStudio\Providers\Contracts\Usage;
use AiSeoContentStudio\Providers\Custom\OpenAICompatibleProvider;
use AiSeoContentStudio\Providers\Gemini\GeminiProvider;
use AiSeoContentStudio\Providers\OpenAI\OpenAIProvider;
use AiSeoContentStudio\Providers\TextGenerationService;
use AiSeoContentStudio\Providers\XAI\GrokProvider;
use AiSeoContentStudio\Security\CredentialStore;
use AiSeoContentStudio\Security\Encryption;
use AiSeoContentStudio\Security\Redactor;
use AiSeoContentStudio\Security\UrlValidator;
use AiSeoContentStudio\WebsiteStudio\Cloner\CloneContentGenerator;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\Persistence\BrandProfileRepository;
use AiSeoContentStudio\WebsiteStudio\Persistence\GeneratedPageRepository;
use AiSeoContentStudio\WebsiteStudio\StudioServices;
use AiSeoContentStudio\WebsiteStudio\Vision\ScreenshotJob;
use AiSeoContentStudio\WebsiteStudio\Vision\ScreenshotPreparer;
use AiSeoContentStudio\WebsiteStudio\Vision\ScreenshotStore;
use AiSeoContentStudio\WebsiteStudio\Vision\VisionDesign;

require_once dirname( __DIR__ ) . '/fakes.php';
require_once __DIR__ . '/CloneJobTest.php';

/** Answers the vision request with a fixed layout, then content requests with "New <role>". */
class FakeVisionText extends TextGenerationService {
	public array $purposes = array();
	public array $images   = array();
	public function __construct( private array $layout ) {}
	public function generate( TextRequest $request, ?string $provider_id = null ): TextResponse {
		$this->purposes[] = $request->purpose;
		$this->images[]   = count( $request->messages[0]->images );
		if ( VisionDesign::PURPOSE === $request->purpose ) {
			return new TextResponse( 'fake', 'vision-model', '', $this->layout, TextResponse::FINISH_STOP, new Usage( 1000, 500 ) );
		}
		return ( new FakeText() )->generate( $request );
	}
}

class FakeMedia extends MediaImporter {
	public array $imported = array();
	public function import( string $bytes, array $file, array $details ): int {
		$this->imported[] = array( $file['mime'], $details['alt'] );
		return 500 + count( $this->imported );
	}
}

class VisionTest extends TestCase {

	private static function png( int $w, int $h ): string {
		$im = imagecreatetruecolor( $w, $h );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 30, 54, 92 ) );
		ob_start();
		imagepng( $im );
		imagedestroy( $im );
		return (string) ob_get_clean();
	}

	/** What a vision model returns for a three-section landing page. */
	public static function layout(): array {
		$leaf = static fn ( string $type, string $text = '', array $more = array() ): array => $more + array(
			'type'         => $type,
			'level'        => '',
			'text'         => $text,
			'items'        => array(),
			'answers'      => array(),
			'variant'      => '',
			'icon'         => '',
			'aspect'       => '',
			'approx_words' => 0,
		);
		$section = static fn ( array $more ): array => $more + array(
			'type'                => 'content',
			'layout'              => 'stack',
			'columns'             => 1,
			'background'          => '',
			'dark_background'     => false,
			'background_is_photo' => false,
			'align'               => 'left',
			'tall'                => false,
			'header'              => array(),
			'groups'              => array(),
		);
		return array(
			'page_title'   => 'Acme Accounting',
			'palette'      => array( 'primary' => '#1f365c', 'secondary' => '', 'accent' => '#8a8a2b', 'background' => '#ffffff', 'surface' => '#f4efe6', 'text' => '#333333', 'heading' => 'navy' ),
			'heading_font' => 'Poppins',
			'body_font'    => 'sans-serif',
			'font_style'   => array( 'heading' => 'sans-serif', 'body' => 'sans-serif' ),
			'button_shape' => 'pill',
			'card_shape'   => 'rounded',
			'sections'     => array(
				$section(
					array(
						'type'                => 'hero',
						'background'          => '#1F365C',
						'dark_background'     => true,
						'background_is_photo' => true,
						'align'               => 'center',
						'tall'                => true,
						'header'              => array(
							$leaf( 'heading', 'Accounting that keeps you ahead', array( 'level' => 'h1', 'approx_words' => 5 ) ),
							$leaf( 'text', 'Bookkeeping, payroll and tax.', array( 'approx_words' => 5 ) ),
							$leaf( 'button', 'Book a call', array( 'variant' => 'primary' ) ),
							$leaf( 'heading', 'Second H1 by mistake', array( 'level' => 'h1' ) ),
						),
					)
				),
				$section(
					array(
						'type'       => 'cards',
						'layout'     => 'split', // Wrong: three cards and a header; becomes a grid.
						'columns'    => 3,
						'background' => '#F4EFE6',
						'header'     => array( $leaf( 'heading', 'What we do', array( 'level' => 'h2' ) ) ),
						'groups'     => array(
							array( 'background' => '#ffffff', 'shadow' => true, 'rounded' => true, 'elements' => array( $leaf( 'icon', '', array( 'icon' => 'fa-calculator' ) ), $leaf( 'heading', 'Bookkeeping', array( 'level' => 'h3' ) ) ) ),
							array( 'background' => '', 'shadow' => false, 'rounded' => false, 'elements' => array( $leaf( 'icon', '', array( 'icon' => 'unicorn' ) ), $leaf( 'image', 'Payroll team at work', array( 'aspect' => '1:1' ) ) ) ),
							array( 'background' => '', 'shadow' => false, 'rounded' => false, 'elements' => array( $leaf( 'list', '', array( 'items' => array( 'Returns', '<b>VAT</b>' ) ) ) ) ),
						),
					)
				),
				$section(
					array(
						'type'   => 'faq',
						'header' => array( $leaf( 'accordion', '', array( 'items' => array( 'How much?', 'Remote?' ), 'answers' => array( 'It depends.' ) ) ), $leaf( 'video' ) ),
					)
				),
				$section( array( 'header' => array( $leaf( 'heading', '' ) ) ) ), // Empty: dropped.
			),
			'observations' => array( 'Navigation bar skipped.' ),
		);
	}

	public function testImageInputAndMessage(): void {
		$png   = self::png( 100, 80 );
		$image = ImageInput::fromBytes( $png );
		$this->assertSame( 'image/png', $image->mime );
		$this->assertSame( 'data:image/png;base64,' . base64_encode( $png ), $image->dataUrl() );
		foreach ( array( 'not an image at all, just text', str_repeat( 'x', ImageInput::MAX_BYTES + 1 ) ) as $bad ) {
			$threw = false;
			try {
				ImageInput::fromBytes( $bad );
			} catch ( \InvalidArgumentException ) {
				$threw = true;
			}
			$this->assertTrue( $threw );
		}
		$threw = false;
		try {
			new Message( 'assistant', 'x', array( $image ) );
		} catch ( \InvalidArgumentException ) {
			$threw = true;
		}
		$this->assertTrue( $threw, 'only user messages carry images' );
		$a = new TextRequest( array( new Message( 'user', 'x', array( $image ) ) ) );
		$b = new TextRequest( array( new Message( 'user', 'x', array( ImageInput::fromBytes( self::png( 101, 80 ) ) ) ) ) );
		$this->assertTrue( $a->hasImages() );
		$this->assertFalse( ( new TextRequest( array( Message::user( 'x' ) ) ) )->hasImages() );
		$this->assertFalse( $a->hash() === $b->hash(), 'different images, different request hash' );
	}

	public function testProviderBodiesCarryImagesInTheirOwnFormat(): void {
		$settings = new FakeSettings();
		$creds    = new CredentialStore( new Encryption( str_repeat( 'k', 32 ) ) );
		$http     = new HttpClient( new Logger( new Redactor() ), new UrlValidator() );
		$image    = ImageInput::fromBytes( self::png( 100, 80 ) );
		$request  = VisionDesign::request( array( $image, $image ), 'test-model' );

		$openai = ( new OpenAIProvider( $settings, $creds, $http ) )->buildBody( $request );
		$parts  = $openai['input'][0]['content'];
		$this->assertSame( array( 'input_text', 'input_image', 'input_image' ), array_column( $parts, 'type' ) );
		$this->assertSame( $image->dataUrl(), $parts[1]['image_url'] );
		$this->assertSame( 'json_schema', $openai['text']['format']['type'] );

		$grok = ( new GrokProvider( $settings, $creds, $http ) )->buildBody( $request );
		$this->assertSame( 'input_image', $grok['input'][0]['content'][1]['type'], 'xAI uses the same Responses format' );

		$claude = ( new AnthropicProvider( $settings, $creds, $http ) )->buildBody( $request );
		$blocks = $claude['messages'][0]['content'];
		$this->assertSame( array( 'image', 'image', 'text' ), array_column( $blocks, 'type' ), 'images before the text' );
		$this->assertSame( array( 'type' => 'base64', 'media_type' => 'image/png', 'data' => $image->base64 ), $blocks[0]['source'] );

		$gemini = ( new GeminiProvider( $settings, $creds, $http ) )->buildBody( $request );
		$this->assertSame( array( 'mimeType' => 'image/png', 'data' => $image->base64 ), $gemini['contents'][0]['parts'][0]['inlineData'] );
		$this->assertTrue( isset( $gemini['contents'][0]['parts'][2]['text'] ) );

		foreach ( array( OpenAIProvider::class, GrokProvider::class, AnthropicProvider::class, GeminiProvider::class ) as $class ) {
			$this->assertTrue( ( new $class( $settings, $creds, $http ) )->supports( Capability::VISION ), $class );
		}
		$this->assertFalse( ( new OpenAICompatibleProvider( $settings, $creds, $http ) )->supports( Capability::VISION ), 'custom endpoints: not claimed' );

		// Text-only requests are unchanged.
		$plain = ( new AnthropicProvider( $settings, $creds, $http ) )->buildBody( new TextRequest( array( Message::user( 'hi' ) ), '', 100, 'm' ) );
		$this->assertSame( 'hi', $plain['messages'][0]['content'] );
	}

	public function testScreenshotPreparation(): void {
		$tall = ScreenshotPreparer::tiles( self::png( 1440, 3000 ) );
		$this->assertSame( 1280, $tall['width'] );
		$this->assertSame( 2667, $tall['height'] );
		$this->assertCount( 2, $tall['tiles'] );
		$this->assertSame( 'image/jpeg', $tall['tiles'][0]->mime, 're-encoded (metadata dropped)' );
		// Upload validation reads only the header (cheap); a truncated body fails when decoded in the job.
		$got = '';
		try {
			ScreenshotPreparer::tiles( substr( self::png( 300, 300 ), 0, 60 ) );
		} catch ( \InvalidArgumentException $e ) {
			$got = $e->getMessage();
		}
		$this->assertSame( 'The image could not be decoded.', $got );
		$this->assertFalse( $tall['truncated'] );
		$this->assertCount( 1, ScreenshotPreparer::tiles( self::png( 800, 600 ) )['tiles'], 'small images are not upscaled or split' );
		$longest = ScreenshotPreparer::tiles( self::png( 1000, 8192 ) );
		$this->assertCount( 6, $longest['tiles'], 'the tallest accepted image fits in the tile limit' );
		$this->assertFalse( $longest['truncated'] );

		foreach ( array( 'GIF89a' . str_repeat( "\0", 40 ) => 'Upload a PNG', self::png( 40, 40 ) => '64 and 8192', "\x89PNG\r\n\x1a\n" . str_repeat( 'z', 60 ) => 'could not be read' ) as $bytes => $message ) {
			$got = '';
			try {
				ScreenshotPreparer::validate( (string) $bytes );
			} catch ( \InvalidArgumentException $e ) {
				$got = $e->getMessage();
			}
			$this->assertTrue( '' !== $got, "rejected: $message" );
			if ( 'could not be read' !== $message ) {
				$this->assertTrue( str_contains( $got, $message ), "$message / $got" );
			}
		}
	}

	public function testScreenshotStore(): void {
		$dir   = sys_get_temp_dir() . '/aiscs-store-' . bin2hex( random_bytes( 4 ) );
		$store = new ScreenshotStore( $dir );
		$token = $store->put( 'bytes' );
		$this->assertTrue( ScreenshotStore::valid( $token ) );
		$this->assertSame( 'bytes', $store->get( $token ) );
		$this->assertTrue( is_file( $dir . '/.htaccess' ) && is_file( $dir . '/index.php' ) );
		$this->assertSame( '', $store->get( '../../wp-config' ), 'tokens cannot traverse' );
		$this->assertSame( '', $store->get( str_repeat( 'a', 32 ) ) );
		touch( $dir . '/' . $token . '.bin', time() - 90000 );
		$this->assertSame( 1, $store->prune( 86400 ) );
		$this->assertSame( '', $store->get( $token ) );
		$t2 = $store->put( 'x' );
		$store->delete( $t2 );
		$this->assertFalse( is_file( $dir . '/' . $t2 . '.bin' ) );
	}

	public function testVisionAnswerBecomesAValidDocument(): void {
		$this->assertSame( 'page_layout', VisionDesign::request( array( ImageInput::fromBytes( self::png( 100, 80 ) ) ) )->schemaName );
		$schema = VisionDesign::schema();
		$check  = function ( array $node ) use ( &$check ): void {
			if ( 'object' === ( $node['type'] ?? '' ) ) {
				$this->assertFalse( $node['additionalProperties'] );
				$this->assertSame( array_keys( $node['properties'] ), $node['required'], 'strict mode: every property required' );
				array_map( $check, $node['properties'] );
			}
			if ( isset( $node['items'] ) ) {
				$check( $node['items'] );
			}
		};
		$check( $schema );

		$r   = VisionDesign::toDocument( self::layout() );
		$doc = $r['document'];
		$this->assertSame( 'Acme Accounting', $doc['page']['title'] );
		$this->assertCount( 3, $doc['sections'], 'empty section dropped' );
		$hero = $doc['sections'][0];
		$this->assertSame( array( 'h1', 'h2' ), array_column( array_filter( $hero['elements'], static fn ( $e ) => 'heading' === $e['type'] ), 'level' ), 'only one h1' );
		$this->assertSame( 'token:primary', $hero['style']['background'], 'palette colour kept as a token reference' );
		$this->assertSame( '#FFFFFF', $hero['style']['text_color'] );
		$this->assertSame( '560px', $hero['layout']['min_height'] );
		$cards = $doc['sections'][1];
		$this->assertSame( 'grid', $cards['layout']['kind'] );
		$this->assertSame( array( 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ), $cards['layout']['columns'] );
		$this->assertSame( array( 'background' => 'token:background', 'radius' => '12px', 'shadow' => '0 4px 16px 0 #0000001A', 'padding' => '32px' ), $cards['elements'][1]['style'], 'palette colour as a token' );
		$this->assertSame( 'calculator', $cards['elements'][1]['elements'][0]['icon'] );
		$this->assertSame( 'check', $cards['elements'][2]['elements'][0]['icon'], 'unknown icon replaced' );
		$this->assertSame( array( 'Returns', 'VAT' ), $cards['elements'][3]['elements'][0]['items'] );
		$faq = $doc['sections'][2]['elements'][0];
		$this->assertSame( '<p>[Answer to this question]</p>', $faq['items'][1]['html'], 'missing answer is a visible placeholder, not invented' );

		$t = $r['tokens'];
		$this->assertSame( array( '#1F365C', '#8A8A2B', '#F4EFE6', '#333333' ), array( $t['colors']['primary'], $t['colors']['accent'], $t['colors']['surface'], $t['colors']['heading'] ), 'invalid heading colour falls back to the text colour' );
		$this->assertSame( 'Poppins', $t['typography']['heading']['family'] );
		$this->assertSame( '', $t['typography']['body']['family'], 'generic family names are not fonts' );
		$this->assertSame( '999px', $t['components']['button']['radius'] );
		$this->assertSame( array( 'visual_approximation', 'fonts_guessed', 'photo_backgrounds' ), $r['report']['approximations'] );
		$this->assertSame( 1, $r['report']['icons_replaced'] );
		$this->assertSame( 1, $r['report']['dropped'], 'unknown element type dropped' );

		$report = $r['report'];
		$final  = VisionDesign::resolveImages( $doc, static fn (): int => 77, $report );
		$this->assertSame( 77, $final['sections'][1]['elements'][2]['elements'][1]['attachment_id'] );
		$this->assertSame( 'Payroll team at work', $final['sections'][1]['elements'][2]['elements'][1]['alt'] );
		$errors = array();
		PageDocument::normalize( $final, $errors );
		$this->assertSame( array(), $errors );
		$none = VisionDesign::resolveImages( $doc, static fn (): int => 0, $report );
		$this->assertSame( array( 'icon' ), array_column( $none['sections'][1]['elements'][2]['elements'], 'type' ), 'images dropped without placeholders' );
	}

	public function testSlotsAndNewContent(): void {
		$doc   = VisionDesign::toDocument( self::layout() )['document'];
		$slots = VisionDesign::slots( $doc );
		$keys  = array_column( $slots, 'key' );
		$this->assertSame( array( '0.0|text', '0.1|html', '0.2|text', '0.3|text', '1.0|text', '1.1.1|text', '1.3.0|items|0', '1.3.0|items|1', '2.0|items|0|title', '2.0|items|0|html', '2.0|items|1|title', '2.0|items|1|html' ), $keys );
		$this->assertSame( 'card_title', $slots[5]['role'] );
		$this->assertSame( 5, $slots[0]['words'], 'length from the screenshot' );
		$answer = array( 'page_title' => 'Payroll', 'meta_title' => '', 'meta_description' => '', 'slots' => array_map( static fn ( $s ) => array( 'key' => $s['key'], 'text' => $s['html'] ? '<p>New ' . $s['role'] . '</p>' : 'New ' . $s['role'] ), $slots ) );
		$texts  = CloneContentGenerator::parse( $answer, $slots )['texts'];
		$new    = VisionDesign::applyTexts( $doc, $texts + array( '9.9|text' => 'nowhere', '0.99|text' => 'x', '1.3.0|items|7' => 'x' ) );
		$this->assertSame( 'New heading', $new['sections'][0]['elements'][0]['text'] );
		$this->assertSame( '<p>New paragraph</p>', $new['sections'][0]['elements'][1]['html'] );
		$this->assertSame( 'New card_title', $new['sections'][1]['elements'][1]['elements'][1]['text'] );
		$this->assertSame( 'New list_item', $new['sections'][1]['elements'][3]['elements'][0]['items'][1] );
		$this->assertSame( '<p>New faq_answer</p>', $new['sections'][2]['elements'][0]['items'][1]['html'] );
		$this->assertCount( count( $doc['sections'] ), $new['sections'], 'unknown keys never create elements' );
		$this->assertCount( 4, $new['sections'][0]['elements'] );
		$this->assertCount( 2, $new['sections'][1]['elements'][3]['elements'][0]['items'] );
	}

	private function job( array $studio, string $type, FakeVisionText $text, FakeMedia $media, ScreenshotStore $store, FakeBridge $bridge, FakeProjects $projects ): ScreenshotJob {
		$projects->save( 900, 'studio', $studio );
		$posts       = new FakePosts();
		$posts->caps = array( 2 => array( 'edit_pages' ) );
		return new ScreenshotJob( $type, $projects, $text, new FakeSettings(), new StudioServices( $bridge, new GeneratedPageRepository(), new AuditLog(), $posts ), $media, $store, new BrandProfileRepository() );
	}

	public function testScreenshotJobsEndToEnd(): void {
		\FakeSite::reset();
		\FakeSite::$users   = array( 2 => array( 'aiscs_generate_content', 'upload_files' ) );
		\FakeSite::$current = 2;
		$store    = new ScreenshotStore( sys_get_temp_dir() . '/aiscs-job-' . bin2hex( random_bytes( 4 ) ) );
		$token    = $store->put( self::png( 1440, 2000 ) );
		$text     = new FakeVisionText( self::layout() );
		$media    = new FakeMedia();
		$bridge   = new FakeBridge();
		$projects = new FakeProjects();

		$analyze = $this->job( array( 'source' => 'screenshot', 'token' => $token ), ScreenshotJob::TYPE_ANALYZE, $text, $media, $store, $bridge, $projects );
		$state   = array();
		$this->assertTrue( $analyze->runStep( 'analyze', 900, $state, array() ) );
		$studio  = $projects->section( 900, 'studio' );
		$this->assertSame( 'vision-model', $studio['design']['model'] );
		$this->assertSame( 2, $studio['design']['report']['tiles'] );
		$this->assertSame( array( 2 ), $text->images, 'both tiles sent in one request' );
		$this->assertSame( '', $store->get( $token ), 'screenshot deleted after analysis' );
		$this->assertFalse( isset( $studio['token'] ) );
		$analyze->runStep( 'analyze', 900, $state, array() );
		$this->assertCount( 1, $text->purposes, 'retry does not repeat the vision request' );

		$studio['brief']   = array( 'topic' => 'Payroll', 'primary_keyword' => 'payroll services' );
		$studio['options'] = array( 'keep_text' => false, 'placeholders' => true, 'profile_id' => 0, 'title' => '' );
		$build = $this->job( $studio, ScreenshotJob::TYPE_BUILD, $text, $media, $store, $bridge, $projects );
		foreach ( $build->steps( 900, array() ) as $step ) {
			$build->runStep( $step, 900, $state, array() );
		}
		foreach ( $build->steps( 900, array() ) as $step ) {
			$build->runStep( $step, 900, $state, array() ); // Retried: nothing repeats.
		}
		$this->assertSame( array( VisionDesign::PURPOSE, CloneContentGenerator::PURPOSE ), $text->purposes );
		$this->assertSame( array( array( 'image/png', 'Payroll team at work' ) ), $media->imported, 'one placeholder, imported once' );
		$result = $projects->section( 900, 'studio' )['result'];
		$this->assertCount( 1, $bridge->saves, 'one draft' );
		$doc = $bridge->docs[ $result['page_id'] ];
		$this->assertSame( 'New heading', $doc[0]['elements'][0]['settings']['title'] );
		$this->assertSame( 'h1', $doc[0]['elements'][0]['settings']['header_size'] );
		$this->assertSame( 501, $doc[1]['elements'][1]['elements'][1]['elements'][1]['settings']['image']['id'], 'section > row > second card > image' );
		$this->assertSame( '#1F365C', $doc[0]['settings']['background_color'], 'token reference resolved with the screenshot palette' );
		$this->assertSame( 1, $result['report']['placeholders'] );
		$this->assertSame( 'screenshot', ( new GeneratedPageRepository() )->forPage( $result['page_id'] )['generation_type'] );
	}

	public function testKeepTextAndMissingScreenshot(): void {
		\FakeSite::reset();
		\FakeSite::$users   = array( 2 => array( 'aiscs_generate_content' ) );
		\FakeSite::$current = 2;
		$store    = new ScreenshotStore( sys_get_temp_dir() . '/aiscs-job-' . bin2hex( random_bytes( 4 ) ) );
		$text     = new FakeVisionText( self::layout() );
		$projects = new FakeProjects();
		$bridge   = new FakeBridge();
		$studio   = array( 'source' => 'screenshot', 'design' => VisionDesign::toDocument( self::layout() ) + array( 'provider' => 'f', 'model' => 'm' ), 'options' => array( 'keep_text' => true, 'placeholders' => true, 'profile_id' => 0, 'title' => 'Kept' ) );
		$build    = $this->job( $studio, ScreenshotJob::TYPE_BUILD, $text, new FakeMedia(), $store, $bridge, $projects );
		$state    = array();
		foreach ( $build->steps( 900, array() ) as $step ) {
			$build->runStep( $step, 900, $state, array() );
		}
		$this->assertSame( array(), $text->purposes, 'kept text: no AI request at all' );
		$this->assertSame( array(), $projects->section( 900, 'studio' )['image_ids'], 'no upload permission: no placeholders' );
		$doc = end( $bridge->docs );
		$this->assertSame( 'Accounting that keeps you ahead', $doc[0]['elements'][0]['settings']['title'] );

		$analyze = $this->job( array( 'source' => 'screenshot', 'token' => str_repeat( 'b', 32 ) ), ScreenshotJob::TYPE_ANALYZE, $text, new FakeMedia(), $store, $bridge, new FakeProjects() );
		$threw   = '';
		try {
			$analyze->runStep( 'analyze', 900, $state, array() );
		} catch ( ProviderException $e ) {
			$threw = $e->getMessage();
		}
		$this->assertTrue( str_contains( $threw, 'no longer available' ) );
	}
}
