<?php
/**
 * Performance benchmark of the studio's CPU-bound steps (no network, no WordPress).
 *
 * Usage: php tests/benchmark.php
 * Prints time and peak memory per step for the largest documents the studio accepts, and
 * exits non-zero when a step exceeds its budget (budgets are generous for slow hosts).
 *
 * @package AiSeoContentStudio
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/TestCase.php';
require_once __DIR__ . '/unit/StudioToolsTest.php';

use AiSeoContentStudio\Tests\EngineTest;
use AiSeoContentStudio\WebsiteStudio\Converter\HtmlConverter;
use AiSeoContentStudio\WebsiteStudio\Editor\AccessibilityChecker;
use AiSeoContentStudio\WebsiteStudio\Editor\ConsistencyChecker;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorCompat;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorMapper;
use AiSeoContentStudio\WebsiteStudio\Engine\IdGenerator;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;
use AiSeoContentStudio\WebsiteStudio\History\RevisionDiff;
use AiSeoContentStudio\WebsiteStudio\Persistence\RevisionRepository;

$results = array();
$failed  = false;
$measure = static function ( string $name, float $budget, callable $fn ) use ( &$results, &$failed ) {
	gc_collect_cycles();
	$base  = memory_get_usage();
	$start = hrtime( true );
	$out   = $fn();
	$ms    = ( hrtime( true ) - $start ) / 1e6;
	$mb    = ( memory_get_peak_usage() - $base ) / 1048576;
	$ok    = $ms <= $budget;
	$failed = $failed || ! $ok;
	printf( "%-58s %9.1f ms %7.1f MB  %s (budget %d ms)\n", $name, $ms, max( 0, $mb ), $ok ? 'ok' : 'SLOW', $budget );
	return $out;
};

// The largest generated page: 40 sections × (heading, text, 6 cards of 4 widgets).
$sections = array();
for ( $i = 0; $i < 40; $i++ ) {
	$cards = array();
	for ( $c = 0; $c < 6; $c++ ) {
		$cards[] = array(
			'type'     => 'group',
			'elements' => array(
				array( 'type' => 'icon', 'icon' => 'check' ),
				array( 'type' => 'heading', 'text' => "Card $c of section $i", 'level' => 'h3' ),
				array( 'type' => 'text', 'html' => '<p>' . str_repeat( 'Readable card text with a few words. ', 6 ) . '</p>' ),
				array( 'type' => 'button', 'text' => 'Learn about card ' . $c, 'url' => '/card-' . $c . '/' ),
			),
		);
	}
	$sections[] = array(
		'id'       => 'section-' . $i,
		'type'     => 0 === $i ? 'hero' : 'cards',
		'layout'   => array(
			'kind'    => 'grid',
			'columns' => array(
				'desktop' => 3,
				'tablet'  => 2,
				'mobile'  => 1,
			),
		),
		'elements' => array_merge(
			array(
				array( 'type' => 'heading', 'text' => 0 === $i ? 'Main heading' : "Section $i", 'level' => 0 === $i ? 'h1' : 'h2' ),
				array( 'type' => 'text', 'html' => '<p>' . str_repeat( 'Introduction text for the section. ', 10 ) . '</p>' ),
			),
			$cards
		),
	);
}
$input  = array(
	'schema_version' => 1,
	'page'           => array( 'title' => 'Benchmark' ),
	'sections'       => $sections,
);
$compat = new ElementorCompat( true, '3.25.0', false, true );

$doc      = $measure( 'PageDocument::normalize (40 sections, 1,040 elements)', 500, static fn () => PageDocument::normalize( $input ) );
$elements = $measure( 'ElementorMapper::map', 500, static fn () => ( new ElementorMapper( $compat, new IdGenerator(), EngineTest::tokens() ) )->map( $doc )['elements'] );
$count    = 0;
array_walk_recursive( $elements, static function ( $v, $k ) use ( &$count ) { $count += 'elType' === $k ? 1 : 0; } );
printf( "%-58s %d Elementor elements, %.0f KB JSON\n", '  page size', $count, strlen( (string) json_encode( $elements ) ) / 1024 );
$measure( 'DocumentValidator::validate (generated)', 500, static fn () => ( new DocumentValidator( $compat, static fn (): bool => true ) )->validate( $elements ) );
$measure( 'ConsistencyChecker::check', 1000, static fn () => ( new ConsistencyChecker( EngineTest::tokens() ) )->check( $elements ) );
$measure( 'AccessibilityChecker::check', 1000, static fn () => ( new AccessibilityChecker( array(), static fn (): string => 'alt' ) )->check( $elements ) );
$measure( 'RevisionDiff::compare (page vs. itself)', 500, static fn () => RevisionDiff::compare( $elements, $elements ) );

\FakeSite::reset();
$repo = new RevisionRepository();
$measure( 'RevisionRepository::add × 30 (snapshot compress + trim)', 3000, static function () use ( $repo, $elements, $doc ) {
	for ( $i = 0; $i < 30; $i++ ) {
		$repo->add( array( 'page_id' => 1, 'elements' => $elements, 'document' => $doc ) );
	}
} );
$row = $measure( 'RevisionRepository::get (decompress)', 300, static fn () => $repo->get( 1, 30 ) );
global $wpdb;
printf( "%-58s %.0f KB raw → %.0f KB stored\n", '  snapshot', $row['bytes'] / 1024, strlen( (string) $wpdb->get_var( 'SELECT snapshot FROM wp_aiscs_page_revisions WHERE revision = 30' ) ) / 1024 );

// HTML conversion of a large page.
$html = '<main>';
$css  = '';
for ( $i = 0; $i < 300; $i++ ) {
	$html .= "<section class=\"s$i\"><h2>Section $i</h2><div class=\"grid\">" . str_repeat( '<div class="card"><h3>Card</h3><p>Text <strong>bold</strong></p><a class="btn" href="/x">Go</a></div>', 4 ) . '</div></section>';
	$css  .= ".s$i{padding:80px 0;background:#f4efe6}.s$i h2{font-size:36px;color:#1f365c}";
}
$html .= '</main>';
$css  .= '.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:24px}.card{background:#fff;border-radius:12px;padding:32px}.btn{background:#1f365c;color:#fff;border-radius:8px}@media (max-width:767px){.grid{grid-template-columns:1fr}}';
$measure( 'HtmlConverter::convert (300 sections, 7,500 nodes, 600 rules)', 5000, static fn () => ( new HtmlConverter() )->convert( $html, $css, '', array() ) );

echo $failed ? "\nSome steps exceeded their budget.\n" : "\nAll steps within budget.\n";
exit( $failed ? 1 : 0 );
