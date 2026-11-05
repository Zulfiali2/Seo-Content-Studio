<?php
namespace AiSeoContentStudio\Tests;

use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorCompat;
use AiSeoContentStudio\WebsiteStudio\Engine\IdGenerator;
use AiSeoContentStudio\WebsiteStudio\Import\AssetRefs;
use AiSeoContentStudio\WebsiteStudio\Import\AssetResolver;
use AiSeoContentStudio\WebsiteStudio\Import\AssetSink;
use AiSeoContentStudio\WebsiteStudio\Import\CssScoper;
use AiSeoContentStudio\WebsiteStudio\Import\HybridBuilder;
use AiSeoContentStudio\WebsiteStudio\Import\ImportedPage;
use AiSeoContentStudio\WebsiteStudio\Import\ImportException;
use AiSeoContentStudio\WebsiteStudio\Import\ImportLimits;
use AiSeoContentStudio\WebsiteStudio\Import\ImportService;
use AiSeoContentStudio\WebsiteStudio\Import\ImportStore;
use AiSeoContentStudio\WebsiteStudio\Import\PreviewRenderer;
use AiSeoContentStudio\WebsiteStudio\Import\ProjectArchive;
use AiSeoContentStudio\WebsiteStudio\Import\ProjectParser;
use AiSeoContentStudio\WebsiteStudio\Import\ScriptAnalyzer;
use AiSeoContentStudio\WebsiteStudio\Import\SelectorIndex;
use AiSeoContentStudio\WebsiteStudio\Import\SvgSanitizer;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\Persistence\GeneratedPageRepository;
use AiSeoContentStudio\WebsiteStudio\Persistence\RevisionRepository;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

require_once dirname( __DIR__ ) . '/fakes.php';
require_once __DIR__ . '/DraftManagerTest.php';

// WordPress functions the import classes call (core behaviour for the inputs used here).
foreach ( array(
	'sanitize_file_name' => static fn ( $n ) => preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $n ),
	'size_format'        => static fn ( $b ) => round( $b / 1048576, 1 ) . ' MB',
	'esc_attr'           => static fn ( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ),
	'esc_html__'         => static fn ( $s ) => htmlspecialchars( (string) $s ),
	'wp_mkdir_p'         => static fn ( $d ) => is_dir( $d ) || mkdir( $d, 0777, true ),
	'wp_parse_url'       => static fn ( $u, $c = -1 ) => parse_url( $u, $c ),
) as $name => $fn ) {
	if ( ! function_exists( $name ) ) {
		$GLOBALS[ 'aiscs_test_fn_' . $name ] = $fn;
		eval( 'function ' . $name . '( ...$a ) { return $GLOBALS["aiscs_test_fn_' . $name . '"]( ...$a ); }' ); // phpcs:ignore -- test stubs.
	}
}
// Global-namespace stubs (this file is namespaced, so they are defined through eval).
foreach ( array(
	'_n'            => 'function _n( $one, $many, $n ) { return 1 === (int) $n ? $one : $many; }',
	'add_option'    => 'function add_option( $name, $value = "", $deprecated = "", $autoload = null ) { if ( array_key_exists( $name, $GLOBALS["fake_options"] ?? array() ) ) { return false; } $GLOBALS["fake_options"][ $name ] = $value; return true; }',
	'delete_option' => 'function delete_option( $name ) { unset( $GLOBALS["fake_options"][ $name ] ); return true; }',
	'wp_attachment_is_image' => 'function wp_attachment_is_image( $id ) { return isset( $GLOBALS["fake_attachments"][ (int) $id ] ); }',
) as $name => $code ) {
	if ( ! function_exists( $name ) ) {
		eval( $code ); // phpcs:ignore -- test stubs.
	}
}

/** In-memory asset storage. */
class FakeSink implements AssetSink {
	public array $images = array();
	public array $fonts  = array();
	public bool $fail    = false;
	public function image( string $bytes, string $ext, string $name, string $alt, string $key, bool $reuse ): array {
		if ( $this->fail ) {
			throw new ImportException( 'asset_rejected', 'refused by the test' );
		}
		$hash = hash( 'sha256', $bytes );
		if ( $reuse && isset( $this->images[ $hash ] ) ) {
			return $this->images[ $hash ] + array( 'reused' => true );
		}
		$id                    = 500 + count( $this->images );
		$GLOBALS['fake_attachments'][ $id ] = true;
		$this->images[ $hash ] = array( 'id' => $id, 'url' => "https://site.test/wp-content/uploads/$name", 'reused' => false );
		return $this->images[ $hash ];
	}
	public function font( string $bytes, string $ext, string $key ): string {
		$this->fonts[] = $ext;
		return "https://site.test/wp-content/uploads/aiscs-imports/$key/font.$ext";
	}
}

class ProjectImportTest extends TestCase {

	private string $tmp;
	private array $limits;

	public function __construct() {
		\FakeSite::reset();
		$GLOBALS['fake_options'] = array();
		$this->tmp               = sys_get_temp_dir() . '/aiscs-import-test-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->tmp, 0777, true );
		$this->limits = ImportLimits::all();
	}

	/* ------------------------------------------------------------ Helpers */

	/** Build a ZIP from name => contents (or the fixture folder). */
	private function zip( array $files, string $name = 'project.zip', ?callable $tweak = null ): string {
		$path = $this->tmp . '/' . $name;
		$zip  = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		foreach ( $files as $entry => $contents ) {
			$zip->addFromString( $entry, $contents );
		}
		if ( $tweak ) {
			$tweak( $zip );
		}
		$zip->close();
		return $path;
	}

	/** The fixture project as name => contents, optionally under a folder. */
	private static function fixture( string $prefix = '' ): array {
		$base  = dirname( __DIR__ ) . '/fixtures/project-site/';
		$files = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$files[ $prefix . substr( $file->getPathname(), strlen( $base ) ) ] = file_get_contents( $file->getPathname() );
		}
		ksort( $files );
		return $files;
	}

	private function archive( array $files, array $limits = array() ): ProjectArchive {
		return new ProjectArchive( $this->zip( $files ), $limits + $this->limits );
	}

	private function service( ?FakeSink $sink = null, ?FakePosts $posts = null, ?FakeBridge $bridge = null ): array {
		$bridge ??= new FakeBridge();
		$posts  ??= new FakePosts();
		$posts->caps = array( 2 => array( 'edit_pages', 'edit_post' ), 3 => array( 'edit_pages', 'edit_post' ) );
		$studio = new StudioServices( $bridge, new GeneratedPageRepository(), new AuditLog(), $posts, new RevisionRepository() );
		$sink ??= new FakeSink();
		return array( new ImportService( $studio, new ImportStore( $this->tmp . '/store' ), $sink ), $sink, $posts, $bridge );
	}

	private function expectImportError( string $code, callable $fn ): ImportException {
		try {
			$fn();
		} catch ( ImportException $e ) {
			$this->assertSame( $code, $e->error_code, 'error code' );
			return $e;
		}
		throw new \RuntimeException( "Expected ImportException $code" );
	}

	/* ------------------------------------------------------------ ZIP handling */

	public function testValidProjectIsIndexedWithoutExtraction(): void {
		$a = $this->archive( self::fixture() );
		$this->assertSame( array( 'index.html' ), $a->ofKind( 'html' ) );
		$this->assertSame( array( 'css/base.css', 'css/responsive.css', 'css/style.css' ), $a->ofKind( 'css' ) );
		$this->assertSame( array( 'js/script.js' ), $a->ofKind( 'js' ) );
		$this->assertSame( array(), $a->rejected() );
		$this->assertTrue( str_contains( $a->read( 'index.html' ), 'pa-home' ) );
		$this->assertFalse( is_dir( $this->tmp . '/css' ), 'nothing is extracted to disk' );
	}

	public function testInvalidZipIsRefused(): void {
		file_put_contents( $this->tmp . '/fake.zip', 'this is not a zip' );
		$this->expectImportError( 'zip_invalid', fn () => new ProjectArchive( $this->tmp . '/fake.zip', $this->limits ) );
		file_put_contents( $this->tmp . '/truncated.zip', "PK\x03\x04garbage" );
		$this->expectImportError( 'zip_invalid', fn () => new ProjectArchive( $this->tmp . '/truncated.zip', $this->limits ) );
	}

	public function testNestedProjectFolderAndRootRelativeUrls(): void {
		$files = self::fixture( 'my-site/' );
		$files['my-site/index.html'] = str_replace( 'src="assets/images/logo.png"', 'src="/assets/images/logo.png"', $files['my-site/index.html'] );
		$a = $this->archive( $files );
		$this->assertSame( 'my-site', $a->topFolder() );
		$this->assertSame( array( 'my-site/index.html' ), ImportService::entries( $a ) );
		$refs = new AssetRefs( $a, 'my-site/index.html' );
		$this->assertSame( AssetRefs::token( 'my-site/assets/images/logo.png' ), $refs->map( 'my-site/index.html', '/assets/images/logo.png' ), 'a root-relative URL resolves inside the single project folder' );
		$this->assertSame( AssetRefs::token( 'my-site/assets/images/logo.png' ), $refs->map( 'my-site/css/style.css', '../assets/images/logo.png' ) );
		$this->assertNull( $refs->map( 'my-site/index.html', '../../etc/passwd' ), 'cannot leave the project' );
	}

	public function testMultipleHtmlFilesEntryDetection(): void {
		$a = $this->archive(
			array(
				'pages/about.html'  => '<p>About</p>',
				'docs/index.html'   => '<p>Docs</p>',
				'index.html'        => '<p>Home</p>',
				'landing-long.html' => str_repeat( '<p>x</p>', 100 ),
			)
		);
		$this->assertSame( 'index.html', ImportService::entries( $a )[0] );
		$this->assertSame( 'docs/index.html', ImportService::entries( $a )[1] );
		$b = $this->archive( array( 'a.html' => '<p>a</p>', 'b.html' => str_repeat( '<p>b</p>', 50 ) ) );
		$this->assertSame( 'b.html', ImportService::entries( $b )[0], 'without index.html the largest page is the entry' );
	}

	public function testMalformedAndMaliciousEntriesAreRefused(): void {
		$a = $this->archive(
			array(
				'index.html'          => '<p>ok</p>',
				'../escape.html'      => 'x',
				'/absolute.html'      => 'x',
				'C:/windows/x.html'   => 'x',
				'shell.php'           => '<?php system($_GET["c"]);',
				'img/photo.php.png'   => 'x',
				'.htaccess'           => 'AddType application/x-httpd-php .png',
				'tools/run.sh'        => 'rm -rf /',
				'__MACOSX/._index.html' => 'x',
				'notes.md'            => 'readme',
			),
			array(),
		);
		$paths = array_column( $a->rejected(), 'path' );
		foreach ( array( 'shell.php', 'img/photo.php.png', '.htaccess', 'tools/run.sh' ) as $p ) {
			$this->assertTrue( in_array( $p, $paths, true ), "$p refused" );
		}
		$this->assertSame( 3, count( array_filter( $a->rejected(), static fn ( $r ) => str_contains( $r['reason'], 'Unsafe path' ) ) ), 'traversal, absolute and drive paths refused' );
		$this->assertSame( array( 'index.html' ), array_keys( $a->files() ) );
		$this->assertSame( array( 'notes.md' ), $a->ignored() );
		$this->assertNull( ProjectArchive::normalise( "a\0b.html" ) );
		$this->assertSame( 'a/b.html', ProjectArchive::normalise( 'a\\.\\b.html' ) );
	}

	public function testSymlinksAreRefused(): void {
		$path = $this->zip(
			array( 'index.html' => '<p>x</p>', 'link.css' => '/etc/passwd' ),
			'link.zip',
			static fn ( \ZipArchive $z ) => $z->setExternalAttributesName( 'link.css', \ZipArchive::OPSYS_UNIX, ( 0120777 << 16 ) )
		);
		$a = new ProjectArchive( $path, $this->limits );
		$this->assertSame( 'Symbolic links are not allowed.', $a->rejected()[0]['reason'] );
		$this->assertFalse( isset( $a->files()['link.css'] ) );
	}

	public function testSizeCountAndBombLimits(): void {
		$a = $this->archive( array( 'index.html' => '<p>x</p>', 'big.png' => str_repeat( 'A', 70000 ) ), array( 'file_bytes' => 65536 ) );
		$this->assertTrue( str_contains( $a->rejected()[0]['reason'], 'Larger than' ) );
		$this->expectImportError( 'zip_too_many_files', fn () => $this->archive( array_fill_keys( array_map( static fn ( $i ) => "f$i.css", range( 1, 12 ) ), 'a{}' ), array( 'entries' => 10 ) ) );
		$this->expectImportError( 'zip_bomb', fn () => $this->archive( array( 'index.html' => '<p>x</p>', 'bomb.css' => str_repeat( "\0", 3 * 1048576 ) ), array( 'ratio' => 10 ) ) );
		$this->expectImportError( 'zip_too_large_extracted', fn () => $this->archive( array( 'index.html' => '<p>x</p>', 'a.css' => bin2hex( random_bytes( 600000 ) ) ), array( 'extracted_bytes' => 1048576 ) ) );
	}

	/* ------------------------------------------------------------ HTML */

	public function testParserRemovesScriptsHandlersAndUnsafeUrls(): void {
		$a    = $this->archive( self::fixture() );
		$refs = new AssetRefs( $a, 'index.html' );
		$page = new ProjectParser( $a, 'index.html', $refs, $this->limits );
		$html = HybridBuilder::innerHtml( $page->body );
		foreach ( array( '<script', 'onclick', 'onload', 'javascript:', 'evil.example/frame', '<base', 'http-equiv' ) as $bad ) {
			$this->assertFalse( stripos( $html, $bad ), "$bad removed" );
		}
		$this->assertTrue( str_contains( $html, 'https://www.youtube.com/embed/' ), 'YouTube embed kept' );
		$this->assertTrue( str_contains( $html, AssetRefs::token( 'assets/images/logo.png' ) ) );
		$this->assertTrue( str_contains( $html, 'href="#faq"' ) && str_contains( $html, 'href="#services"' ), 'in-page links kept, links to the entry page become fragments' );
		$this->assertSame( array( 'pa-home' ), $page->bodyClasses );
		$this->assertSame( array( 'no-js' ), $page->htmlClasses );
		$types = array_column( $page->scripts, 'type' );
		$this->assertSame( array( 'inline', 'file', 'external', 'missing', 'inline', 'data' ), $types, 'scripts in document order (the first is the one hidden in an SVG)' );
		$this->assertSame( array( 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Montserrat:wght@700;800&display=swap' ), $page->externalStylesheets(), 'only the allowed web-font stylesheet is kept' );
	}

	public function testSvgSanitizer(): void {
		$s     = new SvgSanitizer();
		$clean = $s->file( '<svg xmlns="http://www.w3.org/2000/svg" onload="x()"><script>alert(1)</script><foreignObject><div>x</div></foreignObject><a href="javascript:alert(2)"><circle r="2"/></a><use href="#a"/><image href="https://evil.example/x.png"/><style>.c{fill:red}</style></svg>' );
		foreach ( array( 'onload', '<script', 'foreignObject', 'javascript', 'evil.example', '<a ' ) as $bad ) {
			$this->assertFalse( stripos( $clean, $bad ), "$bad removed" );
		}
		$this->assertTrue( str_contains( $clean, '<use href="#a"' ) && str_contains( $clean, '.c{fill:red}' ), 'local references and safe styles stay in SVG files' );
		$this->assertFalse( str_contains( $s->file( '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg>&x;</svg>' ), 'root:' ), 'external entities never load' );
		$this->assertTrue( SvgSanitizer::unsafeStyle( 'background:url(javascript:alert(1))' ) && SvgSanitizer::unsafeStyle( 'width:expression(alert(1))' ) && ! SvgSanitizer::unsafeStyle( 'color:red' ) );
	}

	/* ------------------------------------------------------------ CSS */

	public function testCssScoperKeepsTheDesignSystem(): void {
		$s   = new CssScoper( '#r', array( 'dta-home' ), 'aiscs-k-' );
		$css = $s->scope(
			':root{--a:1px} html{font-size:62.5%} body.dta-home{color:var(--navy)} .dta-home{--navy:#0B2458} .dta-home .card>h3+p{margin:0} a:hover,.b::before{content:"}{"} '
			. '@media (max-width:768px){.grid{grid-template-columns:1fr}} @supports (display:grid){@container (min-width:1px){.x{display:grid}}} '
			. '@keyframes fade{from{opacity:0}to{opacity:1}} .y{animation:fade 1s;animation-name:fade} @font-face{font-family:F;src:url(../f.woff2)} '
			. '.n{color:red;& .m{color:blue}} @import url(x.css); .bg{background:url("img/a.png"),linear-gradient(var(--navy),#fff)}',
			static fn ( string $u, string $k ): ?string => 'import' === $k ? null : 'U(' . $u . ')'
		);
		$this->assertTrue( str_contains( $css, '#r{--a:1px}' ), ':root becomes the root' );
		$this->assertTrue( str_contains( $css, '#r{font-size:10px}' ), 'a relative html font size becomes pixels' );
		$this->assertSame( '62.5%', $s->htmlFontSize() );
		$this->assertTrue( str_contains( $css, '#r.dta-home{color:var(--navy)}' ), 'body with a class becomes the root with that class' );
		$this->assertTrue( str_contains( $css, '#r.dta-home,#r .dta-home{--navy:#0B2458}' ), 'a body class gets both forms' );
		$this->assertTrue( str_contains( $css, '#r.dta-home .card>h3+p,#r .dta-home .card>h3+p{margin:0}' ), 'combinators kept' );
		$this->assertTrue( str_contains( $css, '#r a:hover,#r .b::before{content:"}{"}' ), 'pseudo-classes, pseudo-elements and braces in strings' );
		$this->assertTrue( str_contains( $css, '@media (max-width:768px){#r .grid{grid-template-columns:1fr}}' ) );
		$this->assertTrue( str_contains( $css, '@supports (display:grid){@container (min-width:1px){#r .x{display:grid}}}' ), 'nested at-rules scoped' );
		$this->assertTrue( str_contains( $css, '@keyframes aiscs-k-fade{' ) && str_contains( $css, 'animation:aiscs-k-fade 1s;animation-name:aiscs-k-fade' ), 'keyframes renamed with their uses' );
		$this->assertTrue( str_contains( $css, '@font-face{font-family:F;src:url("U(../f.woff2)")}' ) );
		$this->assertTrue( str_contains( $css, '#r .n{color:red;& .m{color:blue}}' ), 'nested rules stay relative to the scoped parent' );
		$this->assertTrue( str_contains( $css, 'url("U(img/a.png)"),linear-gradient(var(--navy),#fff)' ), 'url() rewritten, gradients and variables untouched' );
		$this->assertFalse( str_contains( $css, '@import' ) );
		$stats = $s->stats();
		$this->assertSame( 1, $stats['media'] );
		$this->assertSame( 1, $stats['keyframes'] );
		$this->assertSame( 2, $stats['variables'], '--a and --navy' );
		$this->assertTrue( str_contains( PreviewRenderer::safeCss( 'a{content:"</style><script>"}' ), '<\\/style' ) );
	}

	public function testSelectorIndexContext(): void {
		$i = new SelectorIndex();
		$i->addCss( '.card h3 + p{margin:0} .cards > .card{padding:1px} .grid{display:grid} li:first-child{color:red} html body > main{x:y}' );
		$d = new \DOMDocument();
		@$d->loadHTML( '<html><body><main><div class="cards grid"><div class="card"><h3>T</h3><p>x</p></div></div><p>free</p><ul><li>a</li></ul></main></body></html>' );
		$x = new \DOMXPath( $d );
		$q = static fn ( string $p ) => $x->query( $p )->item( 0 );
		$this->assertTrue( $i->sensitive( $q( '//div[@class="card"]//p' ) ), 'p after h3 in a card' );
		$this->assertTrue( $i->sensitive( $q( '//h3' ) ), 'h3 before p' );
		$this->assertFalse( $i->sensitive( $q( '/html/body/main/p' ) ), 'a paragraph outside a card is free' );
		$this->assertTrue( $i->sensitive( $q( '//div[@class="card"]' ) ), 'child of .cards' );
		$this->assertTrue( $i->sensitive( $q( '//li' ) ) );
		$this->assertFalse( $i->sensitive( $q( '//main' ) ), 'html/body compounds are the root' );
		$this->assertTrue( $i->isLayout( $q( '//div[contains(@class,"grid")]' ) ) );
	}

	/* ------------------------------------------------------------ JavaScript */

	public function testScriptAnalyzer(): void {
		$a = ScriptAnalyzer::analyze( file_get_contents( dirname( __DIR__ ) . '/fixtures/project-site/js/script.js' ) );
		$this->assertSame( array( 'mobile_menu', 'scroll_reveal' ), $a['features'] );
		$this->assertSame( 'medium', $a['level'] );
		$this->assertSame( array( 'network', 'cookies_storage' ), array_column( $a['risks'], 'key' ) );
		$this->assertTrue( in_array( 'pa-burger', $a['hooks'], true ) && in_array( 'pa-menu', $a['hooks'], true ) );
		$b = ScriptAnalyzer::analyze( 'eval(atob("YWxlcnQoMSk=")); fetch("/wp-json/wp/v2/users",{headers:{"X-WP-Nonce":wpApiSettings.nonce}}); document.querySelectorAll(".tab").forEach(t=>t.addEventListener("click",()=>{}));' );
		$this->assertSame( 'high', $b['level'] );
		$this->assertTrue( array() === array_diff( array( 'dynamic_code', 'wordpress_access', 'network', 'obfuscated' ), array_column( $b['risks'], 'key' ) ) );
		$this->assertTrue( in_array( 'tabs', $b['features'], true ) );
	}

	/* ------------------------------------------------------------ Builder */

	private function build( string $mode, array $files = array() ): array {
		$a     = $this->archive( $files ?: self::fixture() );
		$entry = ImportService::entries( $a )[0];
		$page  = new ProjectParser( $a, $entry, new AssetRefs( $a, $entry ), $this->limits );
		$index = new SelectorIndex();
		foreach ( $a->ofKind( 'css' ) as $p ) {
			$index->addCss( $a->read( $p ) );
		}
		foreach ( $page->styles as $s ) {
			if ( 'inline' === $s['type'] ) {
				$index->addCss( $s['css'] );
			}
		}
		$hooks = array();
		foreach ( $a->ofKind( 'js' ) as $p ) {
			$hooks = array_merge( $hooks, ScriptAnalyzer::hooks( $a->read( $p ) ) );
		}
		return ( new HybridBuilder( $index, new IdGenerator(), '#aiscs-p-t', $hooks ) )->build( $page->body, array( 'mode' => $mode, 'root_id' => 'aiscs-p-t', 'root_classes' => $page->rootClasses ) );
	}

	public function testHybridModeKeepsStructureAndUsesNativeWidgetsSafely(): void {
		$r    = $this->build( 'hybrid' );
		$root = $r['elements'][0];
		$this->assertSame( 'container', $root['elType'] );
		$this->assertSame( 'aiscs-p-t', $root['settings']['_element_id'] );
		$this->assertTrue( str_contains( $root['settings']['css_classes'], 'pa-home' ), 'body classes move to the root' );
		$types = array_column( $r['sections'], 'type', 'label' );
		$this->assertSame( 'native', $types['Clear terms, no surprises.'] );
		$this->assertSame( 'native', $types['About these terms'] );
		$this->assertSame( 'hybrid', $types['Our services'], 'heading and intro are native; the card grid (.pa-cards > .pa-card) stays HTML' );
		$this->assertSame( 'html', $types['Questions about these terms?'], 'the iframe keeps its section as HTML' );
		$json = json_encode( $r['elements'] );
		$this->assertTrue( str_contains( $json, '"css_classes":"pa-section pa-services"' ) && str_contains( $json, '"_element_id":"services"' ), 'section tag, id and classes kept on containers' );
		$this->assertTrue( str_contains( $json, '"html":"<div class=\\"pa-cards\\">' ), 'the card grid keeps its HTML' );
		$this->assertTrue( str_contains( $json, '"widgetType":"heading"' ) && str_contains( $json, '"widgetType":"text-editor"' ) );
		$this->assertTrue( str_contains( $json, 'aiscs-s1' ) && str_contains( $r['css'], '#aiscs-p-t .aiscs-s1{background-image: url(' ), 'container inline style becomes a scoped rule' );
		// The validator accepts the result in imported mode.
		$compat = new ElementorCompat( true, '3.25.0', false, true );
		$final  = ImportService::finalize( HybridBuilder::strip( $r['elements'] ), static fn ( string $p ): string => 'https://site.test/wp-content/uploads/' . basename( $p ), array() );
		$this->assertFalse( str_contains( json_encode( $final ), AssetRefs::TOKEN ), 'finalize replaces every asset token' );
		$report = ( new DocumentValidator( $compat, static fn (): bool => true ) )->validate( $final, DocumentValidator::MODE_IMPORTED );
		$this->assertSame( array(), $report['errors'] );
	}

	public function testChildAndSiblingSelectorsKeepHtml(): void {
		$files = array(
			'index.html' => '<div class="row"><div class="card"><h3>T</h3><p>x</p></div><div class="plain"><h2>H</h2><p>y</p></div></div>',
			'style.css'  => '.card h3 + p{margin:0}',
		);
		$files['index.html'] = '<html><head><link rel="stylesheet" href="style.css"></head><body>' . $files['index.html'] . '</body></html>';
		$r = $this->build( 'hybrid', $files );
		$json = json_encode( $r['elements'] );
		$this->assertTrue( str_contains( $json, '"settings":{"html":"<div class=\"card\">' ), 'the card keeps its HTML (h3 + p)' );
		$this->assertTrue( str_contains( $json, '"title":"H"' ), 'the plain column is native' );
		$n = $this->build( 'native', $files );
		$this->assertTrue( str_contains( implode( ' ', $n['notes'] ), 'Native mode' ), 'native mode reports the risky element' );
		$f = $this->build( 'fidelity', $files );
		$this->assertSame( 0, $f['counts']['native'] );
		// In a flex row, mixed children would put an Elementor wrapper between the row and its
		// flex items, so the whole row stays HTML.
		$files['style.css'] = '.card h3 + p{margin:0} .row{display:flex}';
		$flex = json_encode( $this->build( 'hybrid', $files )['elements'] );
		$this->assertTrue( str_contains( $flex, '"html":"<div class=\"row\"><div class=\"card\">' ), 'flex row kept whole' );
	}

	public function testScriptHooksKeepHtml(): void {
		$r       = $this->build( 'hybrid' );
		$header  = array_values( array_filter( $r['sections'], static fn ( $s ) => 'header' === $s['tag'] ) )[0];
		$this->assertTrue( in_array( 'Used by the page’s JavaScript.', $header['reasons'], true ) || $header['html'] > 0 );
	}

	/* ------------------------------------------------------------ Validator */

	public function testImportedModeRefusesExecutableContent(): void {
		$v    = new DocumentValidator( new ElementorCompat( true, '3.25.0', false, true ), static fn (): bool => true );
		$wrap = static fn ( string $html ): array => array( array( 'id' => 'aaaaaaa', 'elType' => 'container', 'settings' => array(), 'elements' => array( array( 'id' => 'bbbbbbb', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => $html ), 'elements' => array() ) ) ) );
		$this->assertSame( array(), $v->validate( $wrap( '<svg viewBox="0 0 2 2"><circle r="1"/></svg><iframe src="https://www.youtube.com/embed/x"></iframe>' ), DocumentValidator::MODE_IMPORTED )['errors'] );
		foreach ( array( '<script>x</script>', '<img src=x onerror=alert(1)>', '<a href="javascript:alert(1)">x</a>', '<iframe src="https://evil.example/"></iframe>', '<object data=x></object>', '<style>body{}</style>', '<iframe srcdoc="x"></iframe>' ) as $bad ) {
			$this->assertSame( 1, count( $v->validate( $wrap( $bad ), DocumentValidator::MODE_IMPORTED )['errors'] ), "refused: $bad" );
		}
		$this->assertSame( 1, count( $v->validate( $wrap( '<svg></svg>' ), DocumentValidator::MODE_CONVERTED )['errors'] ), 'converted mode still refuses SVG' );
	}

	/* ------------------------------------------------------------ Service end to end */

	public function testUploadAnalyzePreviewBuildEndToEnd(): void {
		\FakeSite::$users   = array( 2 => array( 'aiscs_generate_content', 'upload_files', 'edit_pages', 'unfiltered_html' ), 3 => array( 'aiscs_generate_content', 'upload_files' ) );
		\FakeSite::$current = 2;
		[ $service, $sink, $posts, $bridge ] = $this->service();
		$zip = $this->zip( self::fixture(), 'site.zip' );

		$a = $service->upload( $zip, 'site.zip', 2 );
		$this->assertSame( 'index.html', $a['entry'] );
		$this->assertSame( 2, $a['stats']['css_files'], 'linked stylesheets (base.css arrives through @import) ' );
		$this->assertTrue( $a['stats']['css_rules'] > 60 && 2 === $a['stats']['media_queries'] && 1 === $a['stats']['keyframes'] );
		$this->assertSame( 1, $a['stats']['js_files'] );
		$this->assertTrue( $a['has_own_chrome'] );
		$this->assertSame( 'canvas', $a['defaults']['template'] );
		$this->assertSame( array( 'assets/images/hero.webp' ), $a['unused'] );
		$this->assertSame( array( 'about.html', 'privacy.html' ), array_column( $a['references']['missing'], 'ref' ), 'links to project pages that are not in the ZIP' );
		$this->assertFalse( isset( $a['scripts'][1]['code'] ), 'script code is not sent with the analysis' );
		$this->assertSame( $a, $service->analyze( $a['token'], 2 ), 'analysis is cached' );

		$o = $service->preview( $a['token'], 2, 'original', array() );
		$c = $service->preview( $a['token'], 2, 'converted', array( 'mode' => 'hybrid' ) );
		foreach ( array( $o['html'], $c['html'] ) as $doc ) {
			$this->assertTrue( str_contains( $doc, 'Content-Security-Policy' ) );
			$this->assertFalse( stripos( $doc, '<script' ) );
			$this->assertTrue( str_contains( $doc, 'data:image/png;base64,' ), 'assets inlined' );
			$this->assertFalse( str_contains( $doc, AssetRefs::TOKEN ), 'no token left' );
		}
		$this->assertTrue( str_contains( $c['html'], '#aiscs-p-' ) && str_contains( $c['html'], 'html{font-size:100%}' ) );

		// Build: assets first (in batches), then the page; a repeat returns the same draft.
		$this->expectImportError( 'forbidden', fn () => $service->build( $a['token'], 3, array() ) );
		$steps = 0;
		do {
			$r = $service->build( $a['token'], 2, array( 'title' => 'Imported terms' ) );
			++$steps;
		} while ( ! $r['done'] && $steps < 10 );
		$this->assertTrue( $r['done'] );
		$this->assertSame( 2, $steps, 'one asset step and one page step' );
		$page = $r['page_id'];
		$this->assertSame( 'Imported terms', $posts->posts[ $page ]['title'] );
		$this->assertSame( 'elementor_canvas', $posts->meta[ $page ]['_wp_page_template'] );
		$css = $posts->meta[ $page ][ ImportedPage::META_CSS ];
		$this->assertTrue( str_contains( $css, 'https://site.test/wp-content/uploads/team.jpg' ) && str_contains( $css, '/aiscs-imports/' ) && ! str_contains( $css, AssetRefs::TOKEN ), 'CSS points at imported assets' );
		$this->assertSame( 2, count( $sink->images ), 'logo used three times and the team photo: two images, imported once each' );
		$this->assertSame( array( 'woff2' ), $sink->fonts );
		$elements = json_encode( $bridge->docs[ $page ] );
		$this->assertTrue( str_contains( $elements, 'data:image\/svg+xml;base64,' ), 'SVG stays a sanitised data URI' );
		$scripts = $posts->meta[ $page ][ ImportedPage::META_SCRIPTS ];
		$this->assertSame( 3, count( $scripts ), 'two inline scripts and script.js (external and missing are never stored)' );
		$this->assertSame( array(), ImportedPage::approved( $scripts ), 'nothing is approved by the import' );
		$again = $service->build( $a['token'], 2, array( 'title' => 'Imported terms' ) );
		$this->assertSame( $page, $again['page_id'], 'repeating the last step does not create another draft' );
		$this->assertSame( 1, count( $bridge->saves ) );

		// Update the imported draft with other settings.
		$u = $service->build( $a['token'], 2, array( 'title' => 'Imported terms', 'mode' => 'fidelity', 'target' => $page, 'template' => 'full_width' ) );
		$this->assertTrue( $u['done'] && $u['updated'] );
		$this->assertSame( 'elementor_header_footer', $posts->meta[ $page ]['_wp_page_template'] );
		$this->assertSame( 2, count( $sink->images ), 'imported assets are reused across builds' );
	}

	public function testOwnershipAndExpiry(): void {
		\FakeSite::$users = array( 2 => array( 'aiscs_generate_content', 'upload_files' ), 3 => array( 'aiscs_generate_content', 'upload_files' ), 4 => array( 'aiscs_generate_content', 'aiscs_manage_settings', 'upload_files' ) );
		[ $service ] = $this->service();
		$a = $service->upload( $this->zip( self::fixture(), 'o.zip' ), 'o.zip', 2 );
		$this->expectImportError( 'forbidden', fn () => $service->analyze( $a['token'], 3 ) );
		$this->assertSame( $a['token'], $service->analyze( $a['token'], 4 )['token'], 'a settings manager may open it' );
		$this->expectImportError( 'upload_expired', fn () => $service->analyze( str_repeat( 'a', 32 ), 2 ) );
		$service->discard( $a['token'], 2 );
		$this->expectImportError( 'upload_expired', fn () => $service->analyze( $a['token'], 2 ) );
		$this->expectImportError( 'zip_required', fn () => $service->upload( $this->zip( self::fixture(), 'x.zip' ), 'site.tar', 2 ) );
		$this->expectImportError( 'zip_no_html', fn () => $service->upload( $this->zip( array( 'a.css' => 'a{}' ), 'nohtml.zip' ), 'nohtml.zip', 2 ) );
		// Daily clean-up removes uploads older than a day and keeps recent ones.
		$store = new ImportStore( $this->tmp . '/store' );
		$old   = $service->upload( $this->zip( self::fixture(), 'old.zip' ), 'old.zip', 2 )['token'];
		$new   = $service->upload( $this->zip( self::fixture(), 'new.zip' ), 'new.zip', 2 )['token'];
		touch( $this->tmp . '/store/' . $old, time() - 2 * DAY_IN_SECONDS );
		$this->assertSame( 1, $store->prune() );
		$this->expectImportError( 'upload_expired', fn () => $service->analyze( $old, 2 ) );
		$this->assertSame( $new, $service->analyze( $new, 2 )['token'] );
	}

	public function testMissingCssJsAndFailedAssetsArePartial(): void {
		\FakeSite::$users = array( 2 => array( 'aiscs_generate_content', 'upload_files', 'edit_pages' ) );
		$sink       = new FakeSink();
		$sink->fail = true;
		[ $service, , $posts ] = $this->service( $sink );
		$files = self::fixture();
		unset( $files['css/responsive.css'], $files['js/script.js'] );
		$a = $service->upload( $this->zip( $files, 'm.zip' ), 'm.zip', 2 );
		$this->assertTrue( in_array( 'css/responsive.css', array_column( $a['references']['missing'], 'ref' ), true ) );
		$this->assertSame( 'missing', $a['scripts'][1]['type'] );
		do {
			$r = $service->build( $a['token'], 2, array() );
		} while ( ! $r['done'] );
		$this->assertTrue( $r['partial'] );
		$this->assertTrue( str_contains( implode( ' ', $r['notes'] ), 'Not imported: assets/images/logo.png (refused by the test)' ) );
	}

	public function testMissingPageLinksAloneAreNotPartial(): void {
		\FakeSite::$users   = array( 2 => array( 'aiscs_generate_content', 'upload_files', 'edit_pages' ) );
		\FakeSite::$current = 2;
		[ $service ] = $this->service();
		$files               = self::fixture();
		$files['index.html'] = preg_replace( '#<script[^>]*missing\.js[^>]*></script>#', '', $files['index.html'] );
		$a = $service->upload( $this->zip( $files, 'p.zip' ), 'p.zip', 2 );
		$this->assertSame( array( 'page', 'page' ), array_column( $a['references']['missing'], 'kind' ), 'about.html and privacy.html are page links' );
		do {
			$r = $service->build( $a['token'], 2, array() );
		} while ( ! $r['done'] );
		$this->assertFalse( $r['partial'], 'links to pages outside the ZIP do not make the draft partial' );
		$b = $service->upload( $this->zip( self::fixture(), 'q.zip' ), 'q.zip', 2 );
		do {
			$q = $service->build( $b['token'], 2, array() );
		} while ( ! $q['done'] );
		$this->assertTrue( $q['partial'], 'a missing script file does' );
		$this->assertTrue( in_array( 'script', array_column( $q['report']['missing'], 'kind' ), true ) );
	}

	/* ------------------------------------------------------------ Front end */

	public function testScriptApprovalsAreBoundToTheCode(): void {
		$scripts = array(
			array( 'name' => 'a.js', 'code' => 'console.log(1)', 'hash' => hash( 'sha256', 'console.log(1)' ), 'approved' => false ),
			array( 'name' => 'b.js', 'code' => 'console.log(2)', 'hash' => hash( 'sha256', 'console.log(2)' ), 'approved' => false ),
		);
		$set = ImportedPage::setApprovals( $scripts, array( $scripts[0]['hash'] ), 7 );
		$this->assertSame( array( 'a.js' ), array_column( ImportedPage::approved( $set ), 'name' ) );
		$set[0]['code'] = 'fetch("https://evil.example")';
		$this->assertSame( array(), ImportedPage::approved( $set ), 'changed code is no longer approved' );
		$kept = ImportedPage::keepApprovals( ImportedPage::setApprovals( $scripts, array( $scripts[1]['hash'] ), 7 ), $scripts );
		$this->assertSame( array( false, true ), array_column( $kept, 'approved' ), 'an identical script keeps its approval after a rebuild' );
		$this->assertSame( 'a("<\\/script>")', ImportedPage::safeScript( 'a("</script>")' ) );
	}

	public function testAssetResolverChecksContent(): void {
		$a = $this->archive(
			array(
				'index.html' => '<p>x</p>',
				'fake.png'   => 'GIF89a' . str_repeat( "\0", 40 ),
				'f.woff2'    => 'notafont',
				'ok.png'     => file_get_contents( dirname( __DIR__ ) . '/fixtures/project-site/assets/images/logo.png' ),
				'i.svg'      => '<svg xmlns="http://www.w3.org/2000/svg"><script>x</script><rect width="1" height="1"/></svg>',
			)
		);
		$r = new AssetResolver( $a, $this->limits );
		$this->assertSame( '', $r->dataUri( 'fake.png' ), 'a GIF named .png is refused' );
		$this->assertSame( '', $r->dataUri( 'f.woff2' ) );
		$this->assertTrue( str_starts_with( $r->dataUri( 'ok.png' ), 'data:image/png;base64,' ) );
		$svg = base64_decode( substr( $r->dataUri( 'i.svg' ), strlen( 'data:image/svg+xml;base64,' ) ) );
		$this->assertFalse( str_contains( $svg, 'script' ) );
		$this->assertSame( 2, count( $r->problems() ) );
	}
}
