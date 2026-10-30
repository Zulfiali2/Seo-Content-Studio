<?php
/**
 * Live smoke test on a real WordPress + Elementor site (no AI requests, no network).
 *
 * Run on a staging site, as an administrator, from a checkout of this repository:
 *
 *   wp eval-file tests/integration/smoke.php --user=admin
 *
 * It builds a draft through the real engine and Elementor, then runs the phase 3–7 workflows
 * against it (validation, Elementor save/read-back, revisions, consistency and accessibility
 * checks and fixes, section regeneration proposal → preview → approve, restore, design library
 * save/insert, preview pages), checks the rendered HTML, and deletes everything it created.
 * Exit code 0 = all checks passed.
 *
 * @package AiSeoContentStudio
 */

use AiSeoContentStudio\Media\MediaImporter;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\WebsiteStudio\Editor\ElementTree;
use AiSeoContentStudio\WebsiteStudio\Editor\Findings;
use AiSeoContentStudio\WebsiteStudio\Editor\PageChecks;
use AiSeoContentStudio\WebsiteStudio\Generator\SiteGateway;
use AiSeoContentStudio\WebsiteStudio\History\RevisionService;
use AiSeoContentStudio\WebsiteStudio\Library\DesignLibrary;
use AiSeoContentStudio\WebsiteStudio\Persistence\BrandProfileRepository;
use AiSeoContentStudio\WebsiteStudio\Persistence\LibraryRepository;
use AiSeoContentStudio\WebsiteStudio\Preview\PreviewManager;
use AiSeoContentStudio\WebsiteStudio\Sections\Proposals;
use AiSeoContentStudio\WebsiteStudio\Sections\SectionTransforms;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

if ( ! defined( 'ABSPATH' ) || ! class_exists( StudioServices::class ) ) {
	fwrite( STDERR, "Run with: wp eval-file tests/integration/smoke.php --user=admin (plugin active)\n" );
	exit( 2 );
}

$passed  = 0;
$failed  = 0;
$created = array(
	'pages'    => array(),
	'projects' => array(),
	'library'  => array(),
);
$check   = static function ( string $name, bool $ok, string $detail = '' ) use ( &$passed, &$failed ): void {
	$ok ? ++$passed : ++$failed;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $ok || '' === $detail ? '' : ' — ' . $detail );
};
$render  = static function ( int $page_id ): string {
	return (string) \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $page_id );
};

$user     = get_current_user_id();
$studio   = new StudioServices();
$compat   = $studio->compat();
printf( "WordPress %s, Elementor %s%s, containers %s, PHP %s\n\n", get_bloginfo( 'version' ), $compat->version, $compat->pro ? ' + Pro' : '', $compat->containers ? 'on' : 'off', PHP_VERSION );
$check( 'user can create pages', current_user_can( 'edit_pages' ) && $user > 0 );
$check( 'Elementor is usable', '' === $compat->blocker(), $compat->blocker() );

try {
	// 1. Build a draft through the engine.
	$doc    = array(
		'schema_version' => 1,
		'page'           => array( 'title' => 'AISCS smoke test ' . gmdate( 'H:i:s' ) ),
		'sections'       => array(
			array(
				'id'       => 'hero',
				'type'     => 'hero',
				'layout'   => array( 'kind' => 'stack', 'align' => 'center' ),
				'style'    => array( 'background' => 'token:surface' ),
				'elements' => array(
					array( 'type' => 'heading', 'text' => 'Smoke test heading', 'level' => 'h1' ),
					array( 'type' => 'text', 'html' => '<p>Paragraph with <strong>bold</strong> text.</p>' ),
					array( 'type' => 'button', 'text' => 'Contact us', 'url' => '/contact/' ),
				),
			),
			array(
				'id'       => 'cards',
				'type'     => 'cards',
				'layout'   => array( 'kind' => 'grid', 'columns' => array( 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ) ),
				'elements' => array(
					array( 'type' => 'heading', 'text' => 'Three cards', 'level' => 'h2' ),
					array( 'type' => 'group', 'elements' => array( array( 'type' => 'icon', 'icon' => 'check' ), array( 'type' => 'heading', 'text' => 'One', 'level' => 'h3' ), array( 'type' => 'text', 'html' => '<p>First card.</p>' ) ) ),
					array( 'type' => 'group', 'elements' => array( array( 'type' => 'heading', 'text' => 'Two', 'level' => 'h3' ), array( 'type' => 'list', 'items' => array( 'A', 'B' ) ) ) ),
					array( 'type' => 'group', 'elements' => array( array( 'type' => 'heading', 'text' => 'Three', 'level' => 'h3' ), array( 'type' => 'text', 'html' => '<p>Third card.</p>' ) ) ),
				),
			),
			array(
				'id'       => 'faq',
				'type'     => 'faq',
				'elements' => array(
					array( 'type' => 'heading', 'text' => 'Questions', 'level' => 'h2' ),
					array( 'type' => 'accordion', 'items' => array( array( 'title' => 'Is this a test?', 'html' => '<p>Yes.</p>' ) ) ),
				),
			),
		),
	);
	$tokens = array( 'colors' => array( 'primary' => '#1F365C', 'surface' => '#F4EFE6', 'text' => '#333333', 'heading' => '#1F365C' ), 'components' => array( 'button' => array( 'radius' => '8px' ) ) );
	$built  = $studio->builder()->build( $doc, $tokens, array( 'user_id' => $user, 'generation_type' => 'generated' ) );
	$page   = (int) $built['page_id'];
	$created['pages'][] = $page;
	$check( 'draft created', $page > 0 && 'draft' === get_post_status( $page ) );
	$stored = $studio->bridge()->elements( $page );
	$check( 'Elementor stored the sections', count( $stored ) === ( $compat->containers ? 3 : 4 ), (string) count( $stored ) );
	$check( 'built with Elementor', $studio->bridge()->isBuiltWithElementor( $page ) );
	$html = $render( $page );
	foreach ( array( 'Smoke test heading', 'Three cards', 'Is this a test?', 'elementor-widget-heading', 'elementor-widget-button', 'elementor-widget-accordion' ) as $needle ) {
		$check( "rendered HTML contains $needle", str_contains( $html, $needle ) );
	}
	$check( 'rendered HTML has one h1', 1 === preg_match_all( '/<h1\b/i', $html ) );
	$check( 'revision 1 stored', 'create' === ( $studio->revisions()->listFor( $page )[0]['action'] ?? '' ) );

	// 2. Checks and a fix.
	$checks      = new PageChecks( $studio );
	$tokens_diff = $tokens;
	$tokens_diff['components']['button']['radius'] = '2px';
	$report      = $checks->consistency( $page, $tokens_diff );
	$button      = array_values( array_filter( $report['findings'], static fn ( array $f ): bool => 'buttons' === $f['category'] ) );
	$check( 'consistency: button radius difference found', 1 === count( $button ) );
	if ( $button ) {
		$applied = $checks->apply( $page, $report['findings'], array( $button[0]['id'] ), array(), $user, 'Smoke' );
		$check( 'consistency fix applied with a revision', array( $button[0]['id'] ) === $applied['applied'] && $applied['revision'] > 1 );
		$check( 'consistency clean after the fix', array() === array_filter( $checks->consistency( $page, $tokens_diff )['findings'], static fn ( array $f ): bool => 'buttons' === $f['category'] ) );
	}
	$a11y = $checks->accessibility( $page );
	$check( 'accessibility check runs', isset( $a11y['categories']['contrast'] ) );

	// 3. Section regeneration (style action): proposal → preview → approve.
	$projects  = new ProjectRepository();
	$proposals = new Proposals( $projects, $studio, new PreviewManager( $studio ), new SiteGateway(), new MediaImporter(), new BrandProfileRepository() );
	$context   = $proposals->context( $page, 'cards' );
	$changed   = SectionTransforms::apply( 'colors', $context['located']['section'], array( 'background' => 'dark' ), $proposals->tokens( $context['record'], $user ) );
	$proposal  = $proposals->build( $context, $changed['section'], $changed['summary'], array( 'action' => 'colors' ), $user );
	$project   = $proposals->createProject( $page, 'cards', 'colors', array(), $user );
	$created['projects'][] = $project;
	$proposals->store( $project, $proposal );
	$before  = $studio->bridge()->elements( $page );
	$preview = $proposals->preview( $project, $user );
	$preview_id = (int) ( $projects->section( $project, 'studio' )['preview_id'] ?? 0 );
	$created['pages'][] = $preview_id;
	$check( 'preview page created as a hidden draft', $preview_id > 0 && 'draft' === get_post_status( $preview_id ) && '' !== get_post_meta( $preview_id, PreviewManager::META, true ) );
	$check( 'preview URL is a nonce-protected draft preview', str_contains( $preview['preview_url'], 'preview' ) );
	$check( 'preview renders the proposal', str_contains( $render( $preview_id ), 'Three cards' ) );
	$check( 'page unchanged before approval', $before === $studio->bridge()->elements( $page ) );
	$proposals->approve( $project, $user );
	$after = $studio->bridge()->elements( $page );
	$check( 'approve changed only that section', $before[0] === $after[0] && $before[ count( $before ) - 1 ] === $after[ count( $after ) - 1 ] && $before[1] !== $after[1] );
	$check( 'preview page deleted after approval', null === get_post( $preview_id ) );

	// 4. Revisions: outside edit, compare, restore.
	$edited = ElementTree::setSettings( $after, (string) $after[0]['id'], array( '_css_classes' => 'smoke-edit' ) );
	$studio->bridge()->save( $page, $edited, array() );
	$history = new RevisionService( $studio );
	$check( 'edit made outside the studio detected', $history->history( $page )['edited_since'] );
	$history->restore( $page, 1, $user );
	$actions = array_column( $studio->revisions()->listFor( $page ), 'action' );
	$check( 'restore stored the outside edit first', array_slice( $actions, 0, 2 ) === array( 'restore', 'external' ) );
	$check( 'restore brought back revision 1', $studio->revisions()->get( $page, 1 )['snapshot']['elements'] === $studio->bridge()->elements( $page ) );

	// 5. Design library.
	$library = new DesignLibrary( new LibraryRepository(), $studio, new PreviewManager( $studio ) );
	$current = $studio->bridge()->elements( $page );
	$item    = $library->save( $user, $page, (string) $current[1]['id'], 'Smoke cards', 'services' );
	$created['library'][] = (int) $item['id'];
	$inserted = $library->insert( (int) $item['id'], $page, -1, $user );
	$final    = $studio->bridge()->elements( $page );
	$ids      = array_column( array_column( ElementTree::flatten( $final ), 'element' ), 'id' );
	$check( 'library item inserted with new unique ids', count( $final ) === count( $current ) + 1 && count( $ids ) === count( array_unique( $ids ) ) && '' !== $inserted['element_id'] );
	$check( 'rendered page shows the inserted copy', 2 === substr_count( $render( $page ), 'Three cards' ) );
} catch ( \Throwable $e ) {
	$check( 'no exception', false, get_class( $e ) . ': ' . $e->getMessage() . ( $e instanceof \AiSeoContentStudio\WebsiteStudio\Engine\DraftException ? ' ' . implode( ' ', $e->errors ) : '' ) );
}

// Clean up everything this test created.
foreach ( $created['library'] as $id ) {
	( new LibraryRepository() )->delete( $id );
}
foreach ( $created['projects'] as $id ) {
	wp_delete_post( $id, true );
}
foreach ( $created['pages'] as $id ) {
	if ( $id > 0 && get_post( $id ) ) {
		wp_delete_post( $id, true );
	}
	$studio->pages()->deleteForPage( $id );
	$studio->revisions()->deleteForPage( $id );
}

printf( "\n%d passed, %d failed\n", $passed, $failed );
exit( $failed > 0 ? 1 : 0 );
