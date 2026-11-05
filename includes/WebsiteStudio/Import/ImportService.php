<?php
/**
 * Website project ZIP import: upload, analysis, preview and draft.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

use AiSeoContentStudio\Core\Capabilities;
use AiSeoContentStudio\Support\Lock;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Engine\IdGenerator;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

defined( 'ABSPATH' ) || exit;

/**
 * Workflow (each step is one REST request; nothing is saved to the site before the last one):
 *
 * 1. upload(): the ZIP is validated (ProjectArchive) and stored privately; the entry page is found.
 * 2. analyze(): files, entry page, CSS, scripts, assets and a conversion plan with the default
 *    settings. Cached in the token's state; nothing runs and nothing is fetched.
 * 3. preview(): the original page and the converted page as sandboxed documents.
 * 4. build(): repeated until done. "assets" imports the images and fonts the page uses (a few per
 *    request, within a time budget, reusing identical images); "page" converts, checks the
 *    Elementor document (MODE_IMPORTED) and creates a new draft or updates an eligible imported
 *    draft. A lock prevents two builds of one upload at the same time, and the state records the
 *    draft, so a repeated request never creates a second one.
 *
 * Scripts are never run in the admin. They are stored with the draft unapproved; a user who may
 * publish unfiltered HTML can approve them for the draft's front end (see ImportedPage).
 */
class ImportService {

	public const SETTINGS = array(
		'title'         => '',
		'entry'         => '',
		'mode'          => 'hybrid',
		'template'      => 'canvas',
		'header_footer' => 'include',
		'target'        => 0,
		'reuse_assets'  => true,
		'root_font'     => true,
		'scripts'       => 'request',
	);

	public const TEMPLATES = array(
		'canvas'     => 'elementor_canvas',
		'full_width' => 'elementor_header_footer',
		'default'    => '',
	);

	/** Seconds one build request may spend importing assets. */
	private const ASSET_BUDGET = 20;

	/** Assets imported per request at most. */
	private const ASSET_BATCH = 25;

	/** @var array<string, int> */
	private array $limits;

	/**
	 * Constructor.
	 *
	 * @param StudioServices $studio Studio services (drafts, posts, records).
	 * @param ImportStore    $store  Temporary storage.
	 * @param AssetSink      $sink   Asset storage.
	 * @param AuditLog       $audit  Audit log.
	 */
	public function __construct(
		private readonly StudioServices $studio,
		private readonly ImportStore $store = new ImportStore(),
		private readonly AssetSink $sink = new MediaAssetSink(),
		private readonly AuditLog $audit = new AuditLog(),
	) {
		$this->limits = ImportLimits::all();
	}

	/* ------------------------------------------------------------ 1. Upload */

	/**
	 * Validate and store an uploaded ZIP.
	 *
	 * @param string $file Temporary upload path.
	 * @param string $name Original file name.
	 * @param int    $user User.
	 * @return array<string, mixed> Analysis.
	 * @throws ImportException When the ZIP is unusable.
	 */
	public function upload( string $file, string $name, int $user ): array {
		$name = mb_substr( sanitize_file_name( $name ), 0, 120 );
		if ( 'zip' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			throw new ImportException( 'zip_required', __( 'Upload a .zip file that contains the whole website project.', 'ai-seo-content-studio' ) );
		}
		$archive = new ProjectArchive( $file, $this->limits );
		if ( array() === $archive->ofKind( 'html' ) ) {
			throw new ImportException( 'zip_no_html', __( 'The ZIP contains no HTML page. Add the project’s index.html and upload it again.', 'ai-seo-content-studio' ), array_map( static fn ( array $r ): string => $r['path'] . ': ' . $r['reason'], $archive->rejected() ) );
		}
		$token = $this->store->put(
			$file,
			array(
				'owner' => $user,
				'name'  => $name,
				'size'  => (int) filesize( $file ),
				'key'   => substr( bin2hex( random_bytes( 4 ) ), 0, 8 ),
			)
		);
		return $this->analyze( $token, $user );
	}

	/* ------------------------------------------------------------ 2. Analysis */

	/**
	 * Analyse (cached per entry page).
	 *
	 * @param string $token Token.
	 * @param int    $user  User.
	 * @param string $entry Entry page ("" = detected).
	 * @return array<string, mixed>
	 * @throws ImportException When the token is unknown or the page cannot be read.
	 */
	public function analyze( string $token, int $user, string $entry = '' ): array {
		$state   = $this->owned( $token, $user );
		$archive = $this->archive( $token );
		$entries = self::entries( $archive );
		$entry   = in_array( $entry, $entries, true ) ? $entry : $entries[0];
		if ( isset( $state['analysis'][ $entry ] ) ) {
			return $state['analysis'][ $entry ];
		}

		$refs   = new AssetRefs( $archive, $entry );
		$page   = new ProjectParser( $archive, $entry, $refs, $this->limits );
		$notes  = array();
		$scoper = new CssScoper( '#x', $page->rootClasses, 'x-' );
		$page->css( $scoper, $notes );
		$stats = $scoper->stats();

		$scripts = $this->scripts( $archive, $page );
		$plan    = $this->convert( $archive, $entry, self::settings( array( 'entry' => $entry ), $page ), (string) $state['key'] );

		$files = array();
		foreach ( $archive->files() as $path => $file ) {
			$files[] = array(
				'path' => $path,
				'kind' => $file['kind'],
				'size' => $file['size'],
			);
		}
		$dom      = $page->doc;
		$count    = static fn ( string $q ): int => ( new \DOMXPath( $dom ) )->query( $q )->length;
		$fonts    = self::fonts( $archive, $page );
		$has_own  = $count( '//body//header | //body//footer | //body//nav' ) > 0;
		$analysis = array(
			'token'       => $token,
			'name'        => (string) $state['name'],
			'size'        => (int) $state['size'],
			'total_bytes' => $archive->totalBytes(),
			'files'       => $files,
			'top_folder'  => $archive->topFolder(),
			'rejected'    => $archive->rejected(),
			'ignored'     => array_slice( $archive->ignored(), 0, 200 ),
			'entries'     => $entries,
			'entry'       => $entry,
			'title'       => $page->title,
			'stats'       => array(
				'html_files'     => count( $entries ),
				'sections'       => count( $plan['report']['sections'] ),
				'headings'       => $count( '//body//h1 | //body//h2 | //body//h3 | //body//h4 | //body//h5 | //body//h6' ),
				'text_elements'  => $count( '//body//p | //body//li | //body//blockquote | //body//td | //body//th | //body//figcaption | //body//dd | //body//dt' ),
				'images'         => $count( '//body//img | //body//picture | //body//*[local-name()="svg"]' ),
				'css_files'      => count( array_filter( $page->styles, static fn ( array $s ): bool => 'file' === $s['type'] ) ),
				'css_rules'      => $stats['rules'],
				'media_queries'  => $stats['media'],
				'keyframes'      => $stats['keyframes'],
				'font_faces'     => $stats['font_faces'],
				'css_variables'  => $stats['variables'],
				'js_files'       => count( array_filter( $scripts, static fn ( array $s ): bool => 'file' === $s['type'] ) ),
				'inline_scripts' => count( array_filter( $scripts, static fn ( array $s ): bool => 'inline' === $s['type'] ) ),
				'animations'     => $stats['keyframes'] + count( array_filter( $scripts, static fn ( array $s ): bool => array() !== array_intersect( $s['features'] ?? array(), array( 'scroll_reveal', 'scroll_effects', 'counter', 'marquee', 'slider' ) ) ) ),
				'interactive'    => $count( '//body//details | //body//form | //body//button | //body//*[@aria-expanded] | //body//*[@role="tab"] | //body//select | //body//iframe' ),
				'assets'         => count( $refs->used() ),
			),
			'fonts'       => $fonts,
			'css'         => array(
				'files'       => array_values( array_map( static fn ( array $s ): string => $s['path'], array_filter( $page->styles, static fn ( array $s ): bool => 'file' === $s['type'] ) ) ),
				'inline'      => count( array_filter( $page->styles, static fn ( array $s ): bool => 'inline' === $s['type'] ) ),
				'external'    => $page->externalStylesheets(),
				'notes'       => array_values( array_unique( array_merge( $notes, $scoper->warnings() ) ) ),
			),
			'scripts'     => array_map( static fn ( array $s ): array => array_diff_key( $s, array( 'code' => true ) ), $scripts ),
			'removed'     => $page->removed,
			'notes'       => $page->notes,
			'references'  => $refs->report(),
			'unused'      => array_values( array_diff( array_merge( $archive->ofKind( 'image' ), $archive->ofKind( 'svg' ), $archive->ofKind( 'font' ) ), array_keys( $refs->used() ) ) ),
			'has_own_chrome' => $has_own,
			'defaults'    => self::settings(
				array(
					'entry'    => $entry,
					'template' => 'canvas',
				),
				$page
			),
			'plan'        => $plan['report'],
			'can_approve_scripts' => user_can( $user, 'unfiltered_html' ),
		);
		$state['analysis']           = array( $entry => $analysis );
		$this->store->save( $token, $state );
		return $analysis;
	}

	/* ------------------------------------------------------------ 3. Preview */

	/**
	 * Preview document.
	 *
	 * @param string               $token    Token.
	 * @param int                  $user     User.
	 * @param string               $target   "original" or "converted".
	 * @param array<string, mixed> $settings Settings.
	 * @return array{html: string, report?: array<string, mixed>, problems: array<string, string>}
	 * @throws ImportException When the token is unknown.
	 */
	public function preview( string $token, int $user, string $target, array $settings ): array {
		$state    = $this->owned( $token, $user );
		$archive  = $this->archive( $token );
		$entries  = self::entries( $archive );
		$entry    = in_array( (string) ( $settings['entry'] ?? '' ), $entries, true ) ? (string) $settings['entry'] : $entries[0];
		$assets   = new AssetResolver( $archive, $this->limits );
		$renderer = new PreviewRenderer( $assets );
		if ( 'original' === $target ) {
			$refs  = new AssetRefs( $archive, $entry );
			$page  = new ProjectParser( $archive, $entry, $refs, $this->limits );
			$notes = array();
			$css   = $page->css( new CssScoper( '', array(), '' ), $notes );
			return array(
				'html'     => $renderer->original( $page, $css, $page->externalStylesheets() ),
				'problems' => $renderer->problems(),
			);
		}
		$page     = new ProjectParser( $archive, $entry, new AssetRefs( $archive, $entry ), $this->limits );
		$settings = self::settings( $settings + array( 'entry' => $entry ), $page );
		$result   = $this->convert( $archive, $entry, $settings, (string) $state['key'] );
		$css      = $result['css'] . ( $settings['root_font'] && '' !== $result['html_font'] ? "\nhtml{font-size:" . $result['html_font'] . '}' : '' );
		return array(
			'html'     => $renderer->converted( $result['elements'], $css, $result['stylesheets'], $settings['template'], $result['lang'] ),
			'report'   => $result['report'],
			'problems' => $renderer->problems(),
		);
	}

	/* ------------------------------------------------------------ 4. Build */

	/**
	 * One build step. Call again while "done" is false.
	 *
	 * @param string               $token    Token.
	 * @param int                  $user     User.
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed> Progress, and the draft once done.
	 * @throws ImportException|DraftException On failure.
	 */
	public function build( string $token, int $user, array $settings ): array {
		$state = $this->owned( $token, $user );
		if ( ! user_can( $user, 'upload_files' ) ) {
			throw new ImportException( 'forbidden', __( 'Importing a project adds its images to the Media Library, so you need permission to upload files.', 'ai-seo-content-studio' ) );
		}
		$lock = 'project-import:' . $token;
		if ( ! Lock::acquire( $lock, 300 ) ) {
			throw new ImportException( 'busy', __( 'This project is already being converted. Wait for the current step to finish.', 'ai-seo-content-studio' ) );
		}
		try {
			$archive  = $this->archive( $token );
			$entries  = self::entries( $archive );
			$entry    = in_array( (string) ( $settings['entry'] ?? '' ), $entries, true ) ? (string) $settings['entry'] : $entries[0];
			$page     = new ProjectParser( $archive, $entry, new AssetRefs( $archive, $entry ), $this->limits );
			$settings = self::settings( $settings + array( 'entry' => $entry ), $page );
			$hash     = md5( (string) wp_json_encode( $settings ) );
			$build    = (array) ( $state['build'] ?? array() );

			// A draft already made with these settings: report it again instead of making another.
			if ( isset( $build['result'] ) && ( $build['settings'] ?? '' ) === $hash ) {
				return $build['result'] + array( 'done' => true );
			}
			if ( ( $build['settings'] ?? '' ) !== $hash ) {
				$build = array(
					'settings' => $hash,
					'assets'   => (array) ( $build['assets'] ?? array() ), // Imported assets are reused across setting changes.
				);
			}

			$used    = $this->usedAssets( $archive, $entry );
			$pending = array_values( array_diff( array_keys( $used ), array_keys( $build['assets'] ) ) );
			if ( count( $used ) > $this->limits['assets'] ) {
				/* translators: %d: limit. */
				throw new ImportException( 'too_many_assets', sprintf( __( 'The page uses more than %d images and fonts.', 'ai-seo-content-studio' ), $this->limits['assets'] ) );
			}
			if ( array() !== $pending ) {
				$build['assets'] = $this->importAssets( $archive, $pending, $used, $build['assets'], (string) $state['key'], (bool) $settings['reuse_assets'] );
				$state['build']  = $build;
				$this->store->save( $token, $state );
				$done = count( array_intersect_key( $build['assets'], $used ) );
				return array(
					'done'     => false,
					'step'     => 'assets',
					'progress' => array(
						'done'  => $done,
						'total' => count( $used ),
					),
					/* translators: 1: done, 2: total. */
					'message'  => sprintf( __( 'Importing images and fonts: %1$d of %2$d', 'ai-seo-content-studio' ), $done, count( $used ) ),
				);
			}

			$result         = $this->createPage( $archive, $entry, $settings, $state, $build['assets'], $user );
			$build['result'] = $result;
			$state['build']  = $build;
			$this->store->save( $token, $state );
			return $result + array( 'done' => true );
		} finally {
			Lock::release( $lock );
		}
	}

	/**
	 * Delete an upload.
	 *
	 * @param string $token Token.
	 * @param int    $user  User.
	 * @throws ImportException When the token is unknown.
	 */
	public function discard( string $token, int $user ): void {
		$this->owned( $token, $user );
		$this->store->delete( $token );
	}

	/* ------------------------------------------------------------ Conversion */

	/**
	 * Convert the entry page with the given settings (no side effects).
	 *
	 * @param ProjectArchive       $archive  Archive.
	 * @param string               $entry    Entry page.
	 * @param array<string, mixed> $settings Sanitised settings.
	 * @param string               $key      Project key (root id and keyframe prefix).
	 * @return array{elements: array<int, array<string, mixed>>, css: string, stylesheets: string[], html_font: string, lang: string, report: array<string, mixed>, scope: string}
	 */
	public function convert( ProjectArchive $archive, string $entry, array $settings, string $key ): array {
		$refs   = new AssetRefs( $archive, $entry );
		$page   = new ProjectParser( $archive, $entry, $refs, $this->limits );
		$root   = 'aiscs-p-' . $key;
		$scope  = '#' . $root;
		$notes  = array();
		$scoper = new CssScoper( $scope, $page->rootClasses, 'aiscs-' . $key . '-' );
		$css    = $page->css( $scoper, $notes );

		$index = new SelectorIndex();
		foreach ( $page->styles as $source ) {
			if ( 'inline' === $source['type'] ) {
				$index->addCss( (string) $source['css'] );
			}
		}
		foreach ( $archive->ofKind( 'css' ) as $path ) {
			try {
				$index->addCss( ProjectParser::toUtf8( $archive->read( $path, $this->limits['css_bytes'] ) ) );
			} catch ( ImportException ) {
				continue;
			}
		}
		$index->addCss( $page->svgCss );

		$hooks = array();
		foreach ( $this->scripts( $archive, $page ) as $script ) {
			$hooks = array_merge( $hooks, (array) ( $script['hooks'] ?? array() ) );
		}
		$builder = new HybridBuilder( $index, new IdGenerator(), $scope, array_values( array_unique( $hooks ) ) );
		$built   = $builder->build(
			$page->body,
			array(
				'mode'          => $settings['mode'],
				'header_footer' => $settings['header_footer'],
				'root_id'       => $root,
				'root_classes'  => array_merge( array( 'aiscs-proj-' . $key ), $page->rootClasses ),
			)
		);

		return array(
			'elements'    => $built['elements'],
			'css'         => CssScoper::baseline( $scope ) . "\n" . $css . $built['css'],
			'stylesheets' => $page->externalStylesheets(),
			'html_font'   => $scoper->htmlFontSize(),
			'lang'        => $page->lang,
			'scope'       => $scope,
			'report'      => array(
				'mode'     => $settings['mode'],
				'sections' => $built['sections'],
				'counts'   => $built['counts'],
				'notes'    => array_values( array_unique( array_merge( $built['notes'], $notes, $scoper->warnings(), $page->notes ) ) ),
				'css'      => $scoper->stats(),
				'keyframes' => count( $scoper->keyframes() ),
				'missing'  => $refs->report()['missing'],
				'pages'    => $refs->report()['pages'],
			),
		);
	}

	/**
	 * Make or update the draft.
	 *
	 * @param ProjectArchive                  $archive  Archive.
	 * @param string                          $entry    Entry.
	 * @param array<string, mixed>            $settings Settings.
	 * @param array<string, mixed>            $state    Token state.
	 * @param array<string, array<string, mixed>> $assets Imported assets (path => {url, id, error}).
	 * @param int                             $user     User.
	 * @return array<string, mixed>
	 * @throws ImportException|DraftException On failure.
	 */
	private function createPage( ProjectArchive $archive, string $entry, array $settings, array $state, array $assets, int $user ): array {
		$key    = (string) $state['key'];
		$target = (int) $settings['target'];
		if ( $target > 0 ) {
			$this->assertTarget( $target, $user );
			$key = (string) ( $this->studio->posts()->meta( $target, ImportedPage::META_KEY ) ?: $key );
		}
		$result = $this->convert( $archive, $entry, $settings, $key );

		$map = static function ( string $path ) use ( $assets ): string {
			return (string) ( $assets[ $path ]['url'] ?? '' );
		};
		$elements = self::finalize( HybridBuilder::strip( $result['elements'] ), $map, $assets );
		$css      = AssetRefs::replaceTokens( $result['css'], $map );

		$failed = array_filter( $assets, static fn ( array $a ): bool => '' !== (string) ( $a['error'] ?? '' ) );
		$notes  = $result['report']['notes'];
		foreach ( $failed as $path => $asset ) {
			$notes[] = sprintf( __( 'Not imported: %1$s (%2$s)', 'ai-seo-content-studio' ), $path, $asset['error'] );
		}

		$document = array(
			'source'   => array(
				'name'  => (string) $state['name'],
				'entry' => $entry,
				'size'  => (int) $state['size'],
				'files' => count( $archive->files() ),
			),
			'settings' => $settings,
			'report'   => $result['report'],
			'assets'   => array_map( static fn ( array $a ): array => array_intersect_key( $a, array_flip( array( 'id', 'url', 'kind', 'reused', 'error' ) ) ), $assets ),
			'key'      => $key,
			'imported' => time(),
		);
		$scripts = 'off' === $settings['scripts'] ? array() : $this->storedScripts( $archive, $entry );

		$drafts = $this->studio->drafts();
		if ( $target > 0 ) {
			$draft = $drafts->update(
				$target,
				$elements,
				$user,
				DocumentValidator::MODE_IMPORTED,
				array(
					'action'      => 'import_update',
					'summary'     => sprintf( __( 'Updated from %s', 'ai-seo-content-studio' ), (string) $state['name'] ),
					'document'    => $document,
					'section_map' => array(),
				)
			);
			$page_id = $target;
		} else {
			$draft   = $drafts->create(
				$elements,
				array(),
				array(
					'user_id'         => $user,
					'title'           => $settings['title'],
					'post_type'       => 'page',
					'mode'            => DocumentValidator::MODE_IMPORTED,
					'generation_type' => 'imported',
					'template'        => self::TEMPLATES[ $settings['template'] ],
					'document'        => $document,
					'notes'           => array_slice( $notes, 0, 50 ),
				)
			);
			$page_id = (int) $draft['page_id'];
		}

		$posts = $this->studio->posts();
		if ( $target > 0 ) {
			$posts->setMeta( $page_id, '_wp_page_template', self::TEMPLATES[ $settings['template'] ] );
		}
		$posts->setMeta( $page_id, ImportedPage::META_KEY, $key );
		$posts->setMeta( $page_id, ImportedPage::META_CSS, $css );
		$posts->setMeta( $page_id, ImportedPage::META_STYLESHEETS, $result['stylesheets'] );
		$posts->setMeta( $page_id, ImportedPage::META_ROOT_FONT, $settings['root_font'] && '' !== $result['html_font'] ? $result['html_font'] : '' );
		$posts->setMeta( $page_id, ImportedPage::META_SCRIPTS, ImportedPage::keepApprovals( (array) $posts->meta( $page_id, ImportedPage::META_SCRIPTS ), $scripts ) );

		$this->audit->record(
			'design.project_import',
			'page',
			$page_id,
			array(
				'name'    => (string) $state['name'],
				'mode'    => (string) $settings['mode'],
				'updated' => $target > 0 ? 1 : 0,
				'assets'  => count( $assets ),
				'scripts' => count( $scripts ),
			),
			$user
		);

		$partial = array() !== $failed || array() !== $result['report']['missing'];
		return $draft + array(
			'report'   => $result['report'],
			'partial'  => $partial,
			'notes'    => array_values( array_unique( $notes ) ),
			'scripts'  => array_map( static fn ( array $s ): array => array_diff_key( $s, array( 'code' => true ) ), $scripts ),
			'updated'  => $target > 0,
			'can_approve_scripts' => user_can( $user, 'unfiltered_html' ),
		);
	}

	/**
	 * Replace asset tokens in the element tree; image widgets get the attachment id.
	 *
	 * @param array<int, array<string, mixed>>   $elements Elements.
	 * @param callable(string): string           $map      Path → URL.
	 * @param array<string, array<string, mixed>> $assets  Imported assets.
	 * @return array<int, array<string, mixed>>
	 */
	public static function finalize( array $elements, callable $map, array $assets ): array {
		foreach ( $elements as &$el ) {
			foreach ( (array) ( $el['settings'] ?? array() ) as $k => $value ) {
				if ( is_string( $value ) ) {
					$el['settings'][ $k ] = AssetRefs::replaceTokens( $value, $map );
				}
			}
			if ( 'image' === ( $el['widgetType'] ?? '' ) ) {
				$url  = (string) ( $el['settings']['image']['url'] ?? '' );
				$path = str_starts_with( $url, AssetRefs::TOKEN ) ? rawurldecode( substr( $url, strlen( AssetRefs::TOKEN ) ) ) : '';
				$id   = (int) ( $assets[ $path ]['id'] ?? 0 );
				if ( $id > 0 ) {
					$el['settings']['image'] = array(
						'id'     => $id,
						'url'    => (string) $assets[ $path ]['url'],
						'source' => 'library',
					);
				} else {
					// Not in the Media Library (an SVG data URI made by the importer, or an image that
					// failed to import): keep it as markup, without a source when there is none.
					$alt              = (string) ( $el['settings']['image']['alt'] ?? '' );
					$src              = $map( $path );
					$el['widgetType'] = 'text-editor';
					$el['settings']   = array( 'editor' => '<img' . ( '' !== $src ? ' src="' . htmlspecialchars( $src, ENT_QUOTES ) . '"' : '' ) . ' alt="' . htmlspecialchars( $alt, ENT_QUOTES ) . '">' );
				}
			}
			if ( ! empty( $el['elements'] ) ) {
				$el['elements'] = self::finalize( $el['elements'], $map, $assets );
			}
		}
		return $elements;
	}

	/* ------------------------------------------------------------ Assets */

	/**
	 * Assets the entry page uses (path => kind).
	 *
	 * @param ProjectArchive $archive Archive.
	 * @param string         $entry   Entry.
	 * @return array<string, string>
	 */
	private function usedAssets( ProjectArchive $archive, string $entry ): array {
		$refs  = new AssetRefs( $archive, $entry );
		$page  = new ProjectParser( $archive, $entry, $refs, $this->limits );
		$notes = array();
		$page->css( new CssScoper( '#x', array(), 'x-' ), $notes );
		return $refs->used();
	}

	/**
	 * Import a batch of assets within the time budget.
	 *
	 * @param ProjectArchive                      $archive Archive.
	 * @param string[]                            $pending Paths still to import.
	 * @param array<string, string>               $used    All used paths (kinds).
	 * @param array<string, array<string, mixed>> $done    Already imported.
	 * @param string                              $key     Project key.
	 * @param bool                                $reuse   Reuse identical images.
	 * @return array<string, array<string, mixed>>
	 */
	private function importAssets( ProjectArchive $archive, array $pending, array $used, array $done, string $key, bool $reuse ): array {
		$resolver = new AssetResolver( $archive, array( 'preview_bytes' => PHP_INT_MAX ) + $this->limits );
		$started  = time();
		$count    = 0;
		foreach ( $pending as $path ) {
			if ( $count >= self::ASSET_BATCH || time() - $started > self::ASSET_BUDGET ) {
				break;
			}
			++$count;
			try {
				$asset = $resolver->load( $path );
				if ( 'svg' === $asset['kind'] ) {
					// SVG stays a data URI: never a file in the site's uploads.
					$done[ $path ] = array(
						'kind' => 'svg',
						'url'  => 'data:image/svg+xml;base64,' . base64_encode( $asset['bytes'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI.
						'id'   => 0,
					);
				} elseif ( 'font' === $asset['kind'] ) {
					$done[ $path ] = array(
						'kind' => 'font',
						'url'  => $this->sink->font( $asset['bytes'], $asset['ext'], $key ),
						'id'   => 0,
					);
				} else {
					$stored        = $this->sink->image( $asset['bytes'], $asset['ext'], basename( $path ), '', $key, $reuse );
					$done[ $path ] = array(
						'kind'   => 'image',
						'url'    => $stored['url'],
						'id'     => $stored['id'],
						'reused' => $stored['reused'],
					);
				}
			} catch ( ImportException $e ) {
				$done[ $path ] = array(
					'kind'  => $used[ $path ] ?? '',
					'url'   => '',
					'id'    => 0,
					'error' => $e->getMessage(),
				);
			}
		}
		return $done;
	}

	/* ------------------------------------------------------------ Scripts */

	/**
	 * Scripts of the page with their analysis.
	 *
	 * @param ProjectArchive $archive Archive.
	 * @param ProjectParser  $page    Page.
	 * @return array<int, array<string, mixed>>
	 */
	private function scripts( ProjectArchive $archive, ProjectParser $page ): array {
		$out    = array();
		$budget = $this->limits['js_bytes'];
		foreach ( $page->scripts as $i => $script ) {
			$row = array(
				'type'   => (string) $script['type'],
				'name'   => (string) ( $script['path'] ?? $script['url'] ?? $script['ref'] ?? '' ),
				'module' => ! empty( $script['module'] ),
			);
			$code = null;
			if ( 'file' === $script['type'] ) {
				try {
					$code = ProjectParser::toUtf8( $archive->read( (string) $script['path'], max( 1, $budget ) ) );
				} catch ( ImportException $e ) {
					$row['error'] = $e->getMessage();
				}
			} elseif ( 'inline' === $script['type'] ) {
				$code        = (string) $script['code'];
				$row['name'] = sprintf( __( 'Inline script %1$d in %2$s', 'ai-seo-content-studio' ), $i + 1, $script['path'] );
			}
			if ( null !== $code ) {
				$budget -= strlen( $code );
				if ( $budget < 0 ) {
					$row['error'] = __( 'Over the JavaScript size limit; not analysed.', 'ai-seo-content-studio' );
					$out[]        = $row;
					continue;
				}
				$row          += ScriptAnalyzer::analyze( $code );
				$row['hash']   = hash( 'sha256', $code );
				$row['code']   = $code;
				$row['defer']  = ! empty( $script['defer'] );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Scripts stored with the draft (unapproved; only file and inline scripts with code).
	 *
	 * @param ProjectArchive $archive Archive.
	 * @param string         $entry   Entry.
	 * @return array<int, array<string, mixed>>
	 */
	private function storedScripts( ProjectArchive $archive, string $entry ): array {
		$page = new ProjectParser( $archive, $entry, new AssetRefs( $archive, $entry ), $this->limits );
		$out  = array();
		foreach ( $this->scripts( $archive, $page ) as $script ) {
			if ( isset( $script['code'] ) && ! $script['module'] ) {
				$out[] = array(
					'name'     => $script['name'],
					'hash'     => $script['hash'],
					'code'     => $script['code'],
					'features' => $script['features'],
					'risks'    => $script['risks'],
					'level'    => $script['level'],
					'approved' => false,
				);
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------ Helpers */

	/**
	 * Sanitised settings.
	 *
	 * @param array<string, mixed> $in   Input.
	 * @param ProjectParser|null   $page Entry page (title default).
	 * @return array<string, mixed>
	 */
	public static function settings( array $in, ?ProjectParser $page = null ): array {
		$s          = array_merge( self::SETTINGS, array_intersect_key( $in, self::SETTINGS ) );
		$title      = trim( sanitize_text_field( (string) $s['title'] ) );
		$s['title'] = mb_substr( '' !== $title ? $title : ( null !== $page && '' !== $page->title ? $page->title : __( 'Imported page', 'ai-seo-content-studio' ) ), 0, 200 );
		$s['entry'] = (string) $s['entry'];
		foreach ( array(
			'mode'          => HybridBuilder::MODES,
			'template'      => array_keys( self::TEMPLATES ),
			'header_footer' => array( 'include', 'skip' ),
			'scripts'       => array( 'request', 'off' ),
		) as $key => $allowed ) {
			$s[ $key ] = in_array( $s[ $key ], $allowed, true ) ? $s[ $key ] : self::SETTINGS[ $key ];
		}
		$s['target']       = max( 0, (int) $s['target'] );
		$s['reuse_assets'] = filter_var( $s['reuse_assets'], FILTER_VALIDATE_BOOLEAN );
		$s['root_font']    = filter_var( $s['root_font'], FILTER_VALIDATE_BOOLEAN );
		return $s;
	}

	/**
	 * HTML pages in the archive, the most likely entry page first.
	 *
	 * @param ProjectArchive $archive Archive.
	 * @return string[]
	 */
	public static function entries( ProjectArchive $archive ): array {
		$pages = $archive->ofKind( 'html' );
		$files = $archive->files();
		usort(
			$pages,
			static function ( string $a, string $b ) use ( $files ): int {
				$score = static fn ( string $p ): array => array(
					preg_match( '#(^|/)index\.html?$#i', $p ) ? 0 : 1,
					substr_count( $p, '/' ),
					-$files[ $p ]['size'],
					$p,
				);
				return $score( $a ) <=> $score( $b );
			}
		);
		return $pages;
	}

	/**
	 * Fonts the project uses: families named in CSS, local font files and web font links.
	 *
	 * @param ProjectArchive $archive Archive.
	 * @param ProjectParser  $page    Page.
	 * @return array{families: string[], files: string[], web: string[]}
	 */
	private static function fonts( ProjectArchive $archive, ProjectParser $page ): array {
		$families = array();
		$css      = '';
		foreach ( $page->styles as $s ) {
			if ( 'inline' === $s['type'] ) {
				$css .= (string) $s['css'];
			}
		}
		foreach ( $archive->ofKind( 'css' ) as $path ) {
			try {
				$css .= "\n" . $archive->read( $path );
			} catch ( ImportException ) {
				continue;
			}
		}
		if ( preg_match_all( '/font-family\s*:\s*([^;}]+)/i', $css, $m ) ) {
			foreach ( $m[1] as $list ) {
				$first = trim( (string) strtok( $list, ',' ), " \t\n\r\"'" );
				if ( '' !== $first && ! str_starts_with( $first, 'var(' ) && ! in_array( strtolower( $first ), array( 'inherit', 'initial', 'unset', 'sans-serif', 'serif', 'monospace', 'system-ui' ), true ) ) {
					$families[ $first ] = true;
				}
			}
		}
		$web = array();
		foreach ( $page->externalStylesheets() as $url ) {
			if ( preg_match_all( '/family=([^&:]+)/', $url, $fm ) ) {
				foreach ( $fm[1] as $f ) {
					$web[] = urldecode( str_replace( '+', ' ', $f ) );
				}
			}
		}
		return array(
			'families' => array_slice( array_keys( $families ), 0, 30 ),
			'files'    => $archive->ofKind( 'font' ),
			'web'      => array_values( array_unique( $web ) ),
		);
	}

	/**
	 * Token state owned by the user (or any upload for settings managers).
	 *
	 * @param string $token Token.
	 * @param int    $user  User.
	 * @return array<string, mixed>
	 * @throws ImportException When unknown, expired or someone else's.
	 */
	private function owned( string $token, int $user ): array {
		$state = $this->store->state( $token );
		if ( null === $state || '' === $this->store->archive( $token ) ) {
			throw new ImportException( 'upload_expired', __( 'This upload is no longer available (uploads are kept for one day). Upload the ZIP again.', 'ai-seo-content-studio' ) );
		}
		if ( (int) ( $state['owner'] ?? 0 ) !== $user && ! user_can( $user, Capabilities::MANAGE ) ) {
			throw new ImportException( 'forbidden', __( 'This upload belongs to another user.', 'ai-seo-content-studio' ) );
		}
		return $state;
	}

	/**
	 * Open a token's archive.
	 *
	 * @param string $token Token.
	 */
	private function archive( string $token ): ProjectArchive {
		return new ProjectArchive( $this->store->archive( $token ), $this->limits );
	}

	/**
	 * An existing draft may be updated only if it was made by a project import and is writable.
	 *
	 * @param int $page_id Page.
	 * @param int $user    User.
	 * @throws ImportException|DraftException When it may not.
	 */
	private function assertTarget( int $page_id, int $user ): void {
		$record = $this->studio->pages()->forPage( $page_id );
		if ( null === $record || 'imported' !== ( $record['generation_type'] ?? '' ) ) {
			throw new ImportException( 'target_not_imported', __( 'Only drafts created by a project import can be updated. Create a new draft instead.', 'ai-seo-content-studio' ) );
		}
		$this->studio->drafts()->assertWritable( $page_id, $user );
	}
}
