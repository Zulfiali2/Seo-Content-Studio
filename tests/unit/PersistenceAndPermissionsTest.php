<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\Elementor\ElementorBridge;
use AiSeoContentStudio\REST\WebsiteStudioController;
use AiSeoContentStudio\WebsiteStudio\Analysis\PageCatalog;
use AiSeoContentStudio\WebsiteStudio\Analysis\SiteDesignSource;
use AiSeoContentStudio\WebsiteStudio\Analysis\WebsiteAnalyzer;
use AiSeoContentStudio\WebsiteStudio\Persistence\AnalysisRepository;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\Persistence\BrandProfileRepository;

require_once dirname( __DIR__ ) . '/fakes.php';

/**
 * Repositories against an in-memory database, and the REST permission callbacks
 * (authentication, capability, ownership / IDOR, page edit permission).
 */
class PersistenceAndPermissionsTest extends TestCase {

	private const ADMIN  = 1;
	private const EDITOR = 2;
	private const OTHER  = 3;
	private const AUTHOR = 4; // No plugin capability.

	private BrandProfileRepository $profiles;
	private AnalysisRepository $analyses;
	private WebsiteStudioController $controller;

	public function __construct() {
		\FakeSite::reset();
		\FakeSite::$users = array(
			self::ADMIN  => array( 'aiscs_manage_settings', 'aiscs_generate_content' ),
			self::EDITOR => array( 'aiscs_generate_content' ),
			self::OTHER  => array( 'aiscs_generate_content' ),
			self::AUTHOR => array(),
		);
		\FakeSite::$posts    = array(
			10 => (object) array( 'ID' => 10, 'post_type' => 'page', 'post_status' => 'publish' ),
			11 => (object) array( 'ID' => 11, 'post_type' => 'page', 'post_status' => 'trash' ),
			12 => (object) array( 'ID' => 12, 'post_type' => 'attachment', 'post_status' => 'inherit' ),
		);
		\FakeSite::$editable = array(
			self::ADMIN  => array( 10, 11, 12 ),
			self::EDITOR => array( 10 ),
		);
		$this->profiles   = new BrandProfileRepository();
		$this->analyses   = new AnalysisRepository();
		$source           = new SiteDesignSource( new ElementorBridge() );
		$this->controller = new WebsiteStudioController( new PageCatalog( $source ), new WebsiteAnalyzer( $source, $this->analyses ), $this->analyses, $this->profiles, new AuditLog() );
	}

	private function as( int $user ): void {
		\FakeSite::$current = $user;
	}

	private static function status( mixed $result ): int {
		if ( true === $result ) {
			return 200;
		}
		if ( $result instanceof \WP_Error ) {
			return (int) ( $result->data['status'] ?? 0 );
		}
		return $result instanceof \WP_REST_Response ? $result->status : -1;
	}

	public function testProfileCrudAndOwnership(): void {
		$id = $this->profiles->create( self::EDITOR, 'Main brand', array( 'colors' => array( 'primary' => '#abc', 'text' => 'bad' ) ), array( 10, 10, -1, 'x' ) );
		$this->assertTrue( $id > 0 );
		$p = $this->profiles->get( $id );
		$this->assertSame( '#AABBCC', $p['tokens']['colors']['primary'], 'tokens normalised on write' );
		$this->assertSame( '', $p['tokens']['colors']['text'] );
		$this->assertSame( array( 10 ), $p['source_pages'] );

		$this->assertTrue( $this->profiles->canAccess( $id, self::EDITOR ), 'owner' );
		$this->assertTrue( $this->profiles->canAccess( $id, self::ADMIN ), 'manager' );
		$this->assertFalse( $this->profiles->canAccess( $id, self::OTHER ), 'another generator cannot access' );
		$this->assertFalse( $this->profiles->canAccess( $id, self::AUTHOR ), 'no capability' );
		$this->assertFalse( $this->profiles->canAccess( $id, 0 ), 'logged out' );
		$this->assertFalse( $this->profiles->canAccess( 999, self::ADMIN ), 'missing profile' );

		$this->profiles->create( self::OTHER, 'Other', array() );
		$this->assertSame( 1, $this->profiles->listFor( self::EDITOR )['total'] );
		$this->assertSame( 2, $this->profiles->listFor( self::ADMIN )['total'] );

		$this->assertTrue( $this->profiles->update( $id, 'Renamed', null ) );
		$this->assertSame( 'Renamed', $this->profiles->get( $id )['name'] );
		$this->assertSame( '#AABBCC', $this->profiles->get( $id )['tokens']['colors']['primary'], 'name-only update keeps tokens' );
		$this->assertTrue( $this->profiles->delete( $id ) );
		$this->assertNull( $this->profiles->get( $id ) );
	}

	public function testAnalysisCacheAndRetention(): void {
		$first = $this->analyses->insert( self::EDITOR, 10, str_repeat( 'a', 64 ), 1, array( 'page' => array( 'id' => 10 ) ) );
		$this->assertSame( $first, $this->analyses->findCached( 10, str_repeat( 'a', 64 ), 1 )['id'] );
		$this->assertNull( $this->analyses->findCached( 10, str_repeat( 'b', 64 ), 1 ), 'changed inputs miss the cache' );
		$this->assertNull( $this->analyses->findCached( 10, str_repeat( 'a', 64 ), 2 ), 'new analyzer version misses the cache' );
		for ( $i = 0; $i < 7; $i++ ) {
			$this->analyses->insert( self::EDITOR, 10, str_repeat( (string) $i, 64 ), 1, array() );
		}
		$this->assertSame( AnalysisRepository::KEEP_PER_PAGE, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM wp_aiscs_design_analyses WHERE source_page_id = 10' ) );
		$this->assertNull( $this->analyses->get( $first ), 'oldest analysis removed' );
	}

	public function testAnalysisAccessNeedsOwnershipAndPageEditPermission(): void {
		$id = $this->analyses->insert( self::EDITOR, 10, str_repeat( 'a', 64 ), 1, array() );
		$this->assertTrue( $this->analyses->canAccess( $id, self::EDITOR ) );
		$this->assertFalse( $this->analyses->canAccess( $id, self::OTHER ), 'IDOR: not the owner' );
		\FakeSite::$editable[ self::EDITOR ] = array();
		$this->assertFalse( $this->analyses->canAccess( $id, self::EDITOR ), 'owner who lost edit permission on the page' );

		$this->as( self::OTHER );
		$this->assertSame( 403, self::status( $this->controller->canAccessAnalysis( new \WP_REST_Request( array( 'id' => $id ) ) ) ) );
		$this->assertSame( 404, self::status( $this->controller->canAccessAnalysis( new \WP_REST_Request( array( 'id' => 999 ) ) ) ) );
	}

	public function testRestPermissionCallbacks(): void {
		$id = $this->profiles->create( self::EDITOR, 'Brand', array() );
		$req = new \WP_REST_Request( array( 'id' => $id ) );

		$this->as( 0 );
		$this->assertSame( 401, self::status( $this->controller->canAccessProfile( $req ) ), 'unauthenticated' );
		$this->assertSame( 401, self::status( $this->controller->canGenerate() ) );
		$this->as( self::AUTHOR );
		$this->assertSame( 403, self::status( $this->controller->canAccessProfile( $req ) ), 'role without the capability' );
		$this->as( self::OTHER );
		$this->assertSame( 403, self::status( $this->controller->canAccessProfile( $req ) ), 'IDOR on profiles' );
		$this->assertSame( 404, self::status( $this->controller->canAccessProfile( new \WP_REST_Request( array( 'id' => 999 ) ) ) ) );
		$this->as( self::EDITOR );
		$this->assertSame( 200, self::status( $this->controller->canAccessProfile( $req ) ) );
		$this->as( self::ADMIN );
		$this->assertSame( 200, self::status( $this->controller->canAccessProfile( $req ) ) );

		// Page analysis: every page must exist, be a page/post, not trashed, and be editable.
		$this->as( self::EDITOR );
		$this->assertSame( 200, self::status( $this->controller->canAnalyzePages( new \WP_REST_Request( array( 'page_ids' => array( 10 ) ) ) ) ) );
		$this->as( self::OTHER );
		$this->assertSame( 403, self::status( $this->controller->canAnalyzePages( new \WP_REST_Request( array( 'page_ids' => array( 10 ) ) ) ) ), 'cannot edit the page' );
		$this->as( self::ADMIN );
		$this->assertSame( 404, self::status( $this->controller->canAnalyzePages( new \WP_REST_Request( array( 'page_ids' => array( 10, 999 ) ) ) ) ), 'one missing page fails the request' );
		$this->assertSame( 404, self::status( $this->controller->canAnalyzePages( new \WP_REST_Request( array( 'page_ids' => array( 11 ) ) ) ) ), 'trashed' );
		$this->assertSame( 404, self::status( $this->controller->canAnalyzePages( new \WP_REST_Request( array( 'page_ids' => array( 12 ) ) ) ) ), 'not a page or post' );
	}

	public function testCreateProfileValidatesTokensAndAnalysisOwnership(): void {
		$this->as( self::EDITOR );
		$bad = $this->controller->createProfile( new \WP_REST_Request( array( 'name' => 'X', 'tokens' => array( 'colors' => array( 'primary' => 'javascript:alert(1)' ) ) ) ) );
		$this->assertSame( 400, self::status( $bad ) );
		$this->assertContains( 'colors.primary: invalid value', $bad->data['errors'] );

		$this->assertSame( 400, self::status( $this->controller->createProfile( new \WP_REST_Request( array( 'name' => 'Empty' ) ) ) ) );

		$theirs = $this->analyses->insert( self::OTHER, 10, str_repeat( 'c', 64 ), 1, array() );
		$this->assertSame( 403, self::status( $this->controller->createProfile( new \WP_REST_Request( array( 'name' => 'Steal', 'analysis_ids' => array( $theirs ) ) ) ) ), 'IDOR via analysis_ids' );

		$ok = $this->controller->createProfile( new \WP_REST_Request( array( 'name' => '<b>Brand</b>', 'tokens' => array( 'colors' => array( 'primary' => '#1F365C' ) ) ) ) );
		$this->assertSame( 201, self::status( $ok ) );
		$this->assertSame( 'Brand', $ok->data['name'], 'name sanitised' );
		$this->assertSame( 'user', $ok->data['tokens']['sources']['colors'] );
		$this->assertSame( 'brand_profile.create', $GLOBALS['wpdb']->get_var( 'SELECT action FROM wp_aiscs_audit_log ORDER BY id DESC LIMIT 1' ), 'audit trail' );

		$mine    = $this->analyses->insert( self::EDITOR, 10, str_repeat( 'd', 64 ), 1, array( 'signals' => array( 'elementor_kit' => array( 'colors.primary' => array( '#004E89' => 5 ) ) ) ) );
		$derived = $this->controller->createProfile( new \WP_REST_Request( array( 'name' => 'From analysis', 'analysis_ids' => array( $mine ) ) ) );
		$this->assertSame( 201, self::status( $derived ) );
		$this->assertSame( '#004E89', $derived->data['tokens']['colors']['primary'] );
		$this->assertSame( array( 10 ), $derived->data['source_pages'] );
	}

	public function testUpdateMarksEditedGroupsAsUser(): void {
		$this->as( self::EDITOR );
		$id      = $this->profiles->create( self::EDITOR, 'B', array( 'colors' => array( 'primary' => '#111111' ), 'typography' => array( 'body' => array( 'family' => 'Inter' ) ), 'sources' => array( 'colors' => 'elementor_kit', 'typography' => 'css' ) ) );
		$tokens  = $this->profiles->get( $id )['tokens'];
		$tokens['colors']['primary'] = '#222222';
		$updated = $this->controller->updateProfile( new \WP_REST_Request( array( 'id' => $id, 'tokens' => $tokens ) ) );
		$this->assertSame( 200, self::status( $updated ) );
		$this->assertSame( 'user', $updated->data['tokens']['sources']['colors'] );
		$this->assertSame( 'css', $updated->data['tokens']['sources']['typography'], 'unchanged group keeps its source' );
		$this->assertSame( 400, self::status( $this->controller->updateProfile( new \WP_REST_Request( array( 'id' => $id, 'name' => '   ' ) ) ) ), 'empty name rejected' );
	}
}
