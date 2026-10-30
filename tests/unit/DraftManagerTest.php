<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\Elementor\ElementorBridgeInterface;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftManager;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorCompat;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorMapper;
use AiSeoContentStudio\WebsiteStudio\Engine\IdGenerator;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;
use AiSeoContentStudio\WebsiteStudio\Engine\PostGateway;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\Persistence\GeneratedPageRepository;

require_once dirname( __DIR__ ) . '/fakes.php';
require_once __DIR__ . '/EngineTest.php';

/** Elementor stand-in: stores documents in memory. */
class FakeBridge implements ElementorBridgeInterface {
	public array $docs   = array();
	public bool $accept  = true;
	public array $saves  = array();
	public function isActive(): bool { return true; }
	public function containersActive(): bool { return true; }
	public function supportsPostType( string $post_type ): bool { return true; }
	public function canHideThemeTitle(): bool { return true; }
	public function widgetTypes(): array { return array(); }
	public function save( int $post_id, array $elements, array $settings ): bool {
		$this->saves[] = $post_id;
		if ( $this->accept ) {
			$this->docs[ $post_id ] = $elements;
		}
		return $this->accept;
	}
	public function elements( int $post_id ): array { return $this->docs[ $post_id ] ?? array(); }
	public function editUrl( int $post_id ): string { return "https://site.test/wp-admin/post.php?post=$post_id&action=elementor"; }
	public function isBuiltWithElementor( int $post_id ): bool { return isset( $this->docs[ $post_id ] ); }
	public function activeKit(): array { return array( 'id' => 0, 'modified' => '', 'settings' => array() ); }
}

/** Posts stand-in. */
class FakePosts extends PostGateway {
	public array $posts = array();
	public array $meta  = array();
	public array $caps  = array();
	private int $next   = 100;
	public function insertDraft( string $title, string $slug, int $author, string $post_type = 'page' ): int {
		$id                 = $this->next++;
		$this->posts[ $id ] = array( 'title' => $title, 'status' => 'draft', 'author' => $author, 'type' => $post_type );
		return $id;
	}
	public function delete( int $id ): void { unset( $this->posts[ $id ], $this->meta[ $id ] ); }
	public function status( int $id ): string { return $this->posts[ $id ]['status'] ?? ''; }
	public function type( int $id ): string { return $this->posts[ $id ]['type'] ?? ''; }
	public function setMeta( int $id, string $key, mixed $value ): void { $this->meta[ $id ][ $key ] = $value; }
	public function can( int $user_id, string $cap, int $id = 0 ): bool { return in_array( $cap, $this->caps[ $user_id ] ?? array(), true ); }
	public function previewUrl( int $id ): string { return "https://site.test/?page_id=$id&preview=true"; }
	public function editUrl( int $id ): string { return "https://site.test/wp-admin/post.php?post=$id&action=edit"; }
}

class DraftManagerTest extends TestCase {

	private FakeBridge $bridge;
	private FakePosts $posts;
	private DraftManager $drafts;
	private GeneratedPageRepository $records;
	private array $elements;

	public function __construct() {
		\FakeSite::reset();
		$compat         = new ElementorCompat( true, '3.25.0', false, true );
		$this->bridge   = new FakeBridge();
		$this->posts    = new FakePosts();
		$this->posts->caps = array( 2 => array( 'edit_pages', 'edit_post' ), 3 => array() );
		$this->records  = new GeneratedPageRepository();
		$this->drafts   = new DraftManager( $this->bridge, $compat, new DocumentValidator( $compat, static fn (): bool => true ), $this->records, new AuditLog(), $this->posts );
		$this->elements = ( new ElementorMapper( $compat, new IdGenerator(), EngineTest::tokens() ) )->map( PageDocument::normalize( EngineTest::landingPage() ) )['elements'];
	}

	private function create( int $user = 2, array $elements = null ): array {
		return $this->drafts->create( $elements ?? $this->elements, array( 'hide_title' => 'yes' ), array( 'title' => 'New page', 'user_id' => $user, 'template' => 'elementor_header_footer', 'section_map' => array( 'hero-001' => 'x' ) ) );
	}

	private function reason( callable $fn ): string {
		try {
			$fn();
		} catch ( DraftException $e ) {
			return $e->reason;
		}
		return 'no exception';
	}

	public function testCreatesNewDraftWithOwnershipRecord(): void {
		$r = $this->create();
		$this->assertSame( 100, $r['page_id'] );
		$this->assertSame( 'draft', $this->posts->status( 100 ), 'never published' );
		$this->assertSame( $this->elements, $this->bridge->docs[100] );
		$this->assertSame( 'elementor_header_footer', $this->posts->meta[100]['_wp_page_template'] );
		$this->assertSame( (string) $this->records->forPage( 100 )['id'], $this->posts->meta[100][ DraftManager::META_MARKER ] );
		$this->assertSame( array( 'hero-001' => 'x' ), $this->records->forPage( 100 )['section_map'] );
		$this->assertTrue( str_contains( $r['elementor_url'], 'action=elementor' ) );
		$this->assertSame( 'design.draft_create', $GLOBALS['wpdb']->get_var( 'SELECT action FROM wp_aiscs_audit_log' ) );
	}

	public function testInvalidDocumentIsNeverSaved(): void {
		$bad    = $this->elements;
		$bad[0]['elements'][0]['settings']['title'] = '<script>alert(1)</script>';
		$this->assertSame( 'invalid_document', $this->reason( fn () => $this->create( 2, $bad ) ) );
		$this->assertSame( array(), $this->posts->posts, 'no draft created' );
		$this->assertSame( array(), $this->bridge->saves );
	}

	public function testPermissionAndRollback(): void {
		$this->assertSame( 'forbidden', $this->reason( fn () => $this->create( 3 ) ) );
		$this->bridge->accept = false;
		$this->assertSame( 'elementor_rejected', $this->reason( fn () => $this->create() ) );
		$this->assertSame( array(), $this->posts->posts, 'rejected draft removed' );
		$this->assertNull( $this->records->forPage( 100 ) );
	}

	public function testOriginalAndPublishedPagesAreNeverOverwritten(): void {
		// A page the studio did not create.
		$this->posts->posts[50] = array( 'title' => 'Home', 'status' => 'draft', 'author' => 2, 'type' => 'page' );
		$this->assertSame( 'not_studio_page', $this->reason( fn () => $this->drafts->update( 50, $this->elements, 2 ) ) );
		$this->assertFalse( isset( $this->bridge->docs[50] ) );

		$page = $this->create()['page_id'];
		$this->assertSame( 'forbidden', $this->reason( fn () => $this->drafts->update( $page, $this->elements, 3 ) ) );
		$this->posts->posts[ $page ]['status'] = 'publish';
		$this->assertSame( 'published', $this->reason( fn () => $this->drafts->update( $page, $this->elements, 2 ) ) );
		$this->posts->posts[ $page ]['status'] = 'draft';
		$changed = $this->elements;
		array_pop( $changed );
		$this->assertSame( $page, $this->drafts->update( $page, $changed, 2 )['page_id'] );
		$this->assertCount( 2, $this->bridge->docs[ $page ] );
	}

	public function testElementorMissingBlocksCreation(): void {
		$compat = new ElementorCompat( false, '', false, false );
		$drafts = new DraftManager( $this->bridge, $compat, new DocumentValidator( $compat ), $this->records, new AuditLog(), $this->posts );
		$this->assertSame( 'elementor_inactive', $this->reason( fn () => $drafts->create( $this->elements, array(), array( 'user_id' => 2 ) ) ) );
		$old = new ElementorCompat( true, '3.5.0', false, true );
		$this->assertSame( 'elementor_too_old', $old->blocker() );
	}
}
