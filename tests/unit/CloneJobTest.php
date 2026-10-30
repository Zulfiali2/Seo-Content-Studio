<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Providers\Contracts\TextResponse;
use AiSeoContentStudio\Providers\Contracts\Usage;
use AiSeoContentStudio\Providers\TextGenerationService;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\WebsiteStudio\Cloner\CloneJob;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\Persistence\GeneratedPageRepository;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

require_once dirname( __DIR__ ) . '/fakes.php';
require_once __DIR__ . '/ClonerTest.php';

/** Answers every requested slot with "New <role>". */
class FakeText extends TextGenerationService {
	public int $calls = 0;
	public ?TextRequest $last = null;
	public function __construct() {}
	public function generate( TextRequest $request, ?string $provider_id = null ): TextResponse {
		++$this->calls;
		$this->last = $request;
		$slots      = json_decode( $request->messages[0]->content, true )['slots'];
		return new TextResponse(
			'fake',
			'fake-model',
			'',
			array(
				'page_title'       => 'Payroll services',
				'meta_title'       => 'Payroll services | Acme',
				'meta_description' => 'Payroll run on time for small businesses.',
				'slots'            => array_map( static fn ( array $s ): array => array( 'key' => $s['key'], 'text' => 'html' === $s['format'] ? '<p>New ' . $s['role'] . '</p>' : 'New ' . $s['role'] ), $slots ),
			),
			TextResponse::FINISH_STOP,
			new Usage( 100, 200 )
		);
	}
}

class FakeSettings extends SettingsRepository {
	public function __construct() {}
	public function all(): array {
		return array( 'business' => array( 'name' => 'Acme Books' ), 'ai' => array( 'max_output_tokens' => 4000 ) );
	}
}

class FakeProjects extends ProjectRepository {
	public array $sections = array();
	public function __construct() {}
	public function section( int $id, string $section ): ?array {
		return $this->sections[ $id ][ $section ] ?? null;
	}
	public function save( int $id, string $section, array $data ): void {
		$this->sections[ $id ][ $section ] = $data;
	}
	public function setStage( int $id, string $stage ): void {}
}

class CloneJobTest extends TestCase {

	private function setUpJob( array $studio ): array {
		\FakeSite::reset();
		\FakeSite::$users    = array( 2 => array( 'aiscs_generate_content' ) );
		\FakeSite::$editable = array( 2 => array( 7 ) );
		\FakeSite::$current  = 2;
		\FakeSite::$posts[7] = new \WP_Post( array( 'ID' => 7, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Bookkeeping', 'post_modified_gmt' => '2026-10-01' ) );
		$GLOBALS['fake_meta'] = array( 7 => array( '_elementor_data' => 'SOURCE' ) );
		$bridge          = new FakeBridge();
		$bridge->docs[7] = ClonerTest::source();
		$posts           = new FakePosts();
		$posts->caps     = array( 2 => array( 'edit_pages' ) );
		$projects        = new FakeProjects();
		$projects->save( 900, 'studio', $studio );
		$text            = new FakeText();
		$job             = new CloneJob( $projects, $text, new FakeSettings(), new StudioServices( $bridge, new GeneratedPageRepository(), new AuditLog(), $posts ) );
		return array( $job, $projects, $text, $bridge, $posts );
	}

	private function runAll( CloneJob $job ): array {
		$state = array();
		foreach ( $job->steps( 900, array() ) as $step ) {
			$this->assertTrue( $job->runStep( $step, 900, $state, array() ) );
		}
		return $state;
	}

	public function testFullRunWritesSelectedSlotsIntoANewDraft(): void {
		[ $job, $projects, $text, $bridge, $posts ] = $this->setUpJob(
			array(
				'source_page_id' => 7,
				'brief'          => array( 'topic' => 'Payroll', 'primary_keyword' => 'payroll services' ),
				'selected'       => array( 'h1000001|title', 'txt00001|editor', 'navbtn01|text', 'cnt00001|*' ),
			)
		);
		$state = $this->runAll( $job );
		$this->assertSame( array( 'plan', 'write', 'build' ), $job->steps( 900, array() ) );
		$this->assertSame( 1, $text->calls, 'one AI request' );
		$sent = array_column( json_decode( $text->last->messages[0]->content, true )['slots'], 'key' );
		$this->assertSame( array( 'navbtn01|text', 'h1000001|title', 'txt00001|editor' ), $sent, 'the user may opt in protected text, but locked slots are never sent' );

		$result = $projects->section( 900, 'studio' )['result'];
		$new    = $result['page_id'];
		$doc    = $bridge->docs[ $new ];
		$this->assertSame( 'New heading', $doc[1]['elements'][0]['settings']['title'] );
		$this->assertSame( '<p>New paragraph</p>', $doc[1]['elements'][1]['settings']['editor'] );
		$this->assertSame( 'Get a quote', $doc[1]['elements'][2]['settings']['text'], 'unselected slot kept' );
		$this->assertSame( 250, $doc[2]['elements'][2]['settings']['ending_number'], 'statistics never rewritten' );
		$this->assertSame( ClonerTest::source(), $bridge->docs[7], 'source unchanged' );
		$this->assertSame( 'Payroll services', $posts->posts[ $new ]['title'], 'title from the AI answer' );
		$this->assertSame( 'Payroll services | Acme', $GLOBALS['fake_meta'][ $new ]['_aiscs_seo_title'] );
		$this->assertSame( 'fake-model', $projects->section( 900, 'studio' )['written']['model'] );
	}

	public function testRetriedStepsNeverRepeatTheAiRequestOrTheDraft(): void {
		[ $job, $projects, $text, $bridge ] = $this->setUpJob( array( 'source_page_id' => 7, 'brief' => array( 'topic' => 'Payroll', 'primary_keyword' => 'payroll' ) ) );
		$state = $this->runAll( $job );
		$saves = count( $bridge->saves );
		// The runner may run a step again after an interruption.
		$job->runStep( 'write', 900, $state, array() );
		$job->runStep( 'build', 900, $state, array() );
		$this->assertSame( 1, $text->calls, 'no second billed request' );
		$this->assertSame( $saves, count( $bridge->saves ), 'no second draft' );
		$this->assertCount( 8, json_decode( $text->last->messages[0]->content, true )['slots'], 'default selection: every unprotected slot' );
	}

	public function testOwnerWhoLostAccessToTheSourceIsStopped(): void {
		[ $job ] = $this->setUpJob( array( 'source_page_id' => 7, 'brief' => array() ) );
		\FakeSite::$editable = array();
		$state  = array();
		$threw  = '';
		try {
			$job->runStep( 'plan', 900, $state, array() );
		} catch ( ProviderException $e ) {
			$threw = $e->category;
		}
		$this->assertSame( ProviderException::PERMISSION, $threw );
	}
}
