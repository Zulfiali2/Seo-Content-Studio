<?php
/**
 * Website design analysis service.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Analysis;

use AiSeoContentStudio\WebsiteStudio\Design\BrandExtractor;
use AiSeoContentStudio\WebsiteStudio\Persistence\AnalysisRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Analyzes the site's own pages and turns one or more analyses into brand tokens.
 *
 * Per page: the Elementor document and kit (Elementor pages) or the blocks / classic HTML
 * (other pages), plus theme.json styles and the theme and Elementor stylesheets. Results
 * are cached by input fingerprint. No AI request and no HTTP request is made.
 */
class WebsiteAnalyzer {

	/** Bump when analysis output changes, so cached analyses are recomputed. */
	public const VERSION = 1;

	/** Most pages one brand analysis may combine. */
	public const MAX_PAGES = 10;

	/** Relative weight of stylesheet observations versus document settings. */
	private const CSS_WEIGHT = 0.5;

	public function __construct(
		private readonly SiteDesignSource $source,
		private readonly AnalysisRepository $analyses,
		private readonly BrandExtractor $extractor = new BrandExtractor(),
		private readonly ElementorDocumentAnalyzer $elementor = new ElementorDocumentAnalyzer(),
		private readonly BlockDocumentAnalyzer $blocks = new BlockDocumentAnalyzer(),
		private readonly CssStyleAnalyzer $css = new CssStyleAnalyzer(),
	) {}

	/**
	 * Analyze one page (or return the cached analysis of unchanged inputs).
	 * The caller has checked that the user may edit the page.
	 *
	 * @param \WP_Post $post    Page.
	 * @param int      $user_id User.
	 * @param bool     $force   Recompute even when cached.
	 * @return array<string, mixed> Stored analysis record.
	 * @throws \RuntimeException When the analysis cannot be stored.
	 */
	public function analyzePage( \WP_Post $post, int $user_id, bool $force = false ): array {
		$kit  = $this->source->kit();
		$hash = $this->source->fingerprint( $post, $kit['id'] . '@' . $kit['modified'] . '@' . md5( (string) wp_json_encode( $kit['settings'] ) ) );
		if ( ! $force ) {
			$cached = $this->analyses->findCached( $post->ID, $hash, self::VERSION );
			if ( null !== $cached ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		$analysis = $this->compute( $post, $kit );
		$id       = $this->analyses->insert( $user_id, $post->ID, $hash, self::VERSION, $analysis );
		if ( $id <= 0 ) {
			throw new \RuntimeException( 'analysis_not_stored' );
		}
		$record           = (array) $this->analyses->get( $id );
		$record['cached'] = false;
		return $record;
	}

	/**
	 * Compute an analysis.
	 *
	 * @param \WP_Post                                                      $post Page.
	 * @param array{id: int, modified: string, settings: array<string, mixed>} $kit  Active kit.
	 * @return array<string, mixed>
	 */
	public function compute( \WP_Post $post, array $kit ): array {
		$sets      = array();
		$notes     = array();
		$css_files = array();
		$elementor = $this->source->isElementorDocument( $post->ID );

		if ( $elementor ) {
			$result                    = $this->elementor->analyze( $this->source->elementorElements( $post->ID ), $kit['settings'], $this->source->widgetTypes() );
			$sets['elementor_kit']     = $result['kit_signals'];
			$sets['elementor_widgets'] = $result['signals'];
			$css_files                 = $this->source->elementorCss( $post->ID, $kit['id'] );
		} else {
			$settings = $this->source->globalSettings();
			$result   = $this->blocks->analyze( parse_blocks( $post->post_content ), $settings, $post->post_content );
			$sets['elementor_widgets'] = $result['signals'];
			if ( 'elementor' === $this->source->builder( $post ) ) {
				$notes[] = 'elementor_inactive';
			}
		}
		// Theme styles apply to every page (an Elementor page still inherits theme fonts and colours it does not override).
		$sets['theme_json'] = $this->blocks->analyzeTheme( $this->source->globalSettings(), $this->source->globalStyles() );

		$css_signals = new StyleSignals();
		$css_report  = array();
		foreach ( array_merge( $css_files, $this->source->themeCss() ) as $label => $css ) {
			$report               = $this->css->analyze( $css, $css_signals, self::CSS_WEIGHT );
			$css_report[ $label ] = array(
				'rules'     => $report['rules'],
				'truncated' => $report['truncated'],
			);
		}
		$sets['css'] = $css_signals;

		return array(
			'page'      => array(
				'id'       => $post->ID,
				'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'builder'  => $elementor ? 'elementor' : (string) $result['structure']['builder'],
				'modified' => mysql_to_rfc3339( $post->post_modified_gmt ),
			),
			'structure' => $result['structure'],
			'sections'  => $result['sections'],
			'tokens'    => $this->extractor->extract( $sets ),
			'signals'   => array_map( static fn ( StyleSignals $s ): array => $s->toArray(), $sets ),
			'css'       => $css_report,
			'notes'     => array_values( array_unique( array_merge( $result['notes'], $notes ) ) ),
		);
	}

	/**
	 * Brand tokens from several stored analyses (the site-wide brand profile).
	 *
	 * @param array<int, array<string, mixed>> $records Analysis records.
	 * @return array<string, mixed> Tokens.
	 */
	public function tokensFrom( array $records ): array {
		$sets = array();
		foreach ( $records as $record ) {
			foreach ( (array) ( $record['analysis']['signals'] ?? array() ) as $source => $data ) {
				if ( ! is_string( $source ) ) {
					continue;
				}
				$sets[ $source ] ??= new StyleSignals();
				// Kit and theme styles are site-wide: count them once, not once per page.
				$sets[ $source ]->merge( StyleSignals::fromArray( $data ), in_array( $source, array( 'elementor_kit', 'theme_json' ), true ) ? 1 / max( 1, count( $records ) ) : 1.0 );
			}
		}
		// Priority order for provenance.
		$ordered = array();
		foreach ( array( 'elementor_kit', 'theme_json', 'elementor_widgets', 'css' ) as $source ) {
			if ( isset( $sets[ $source ] ) ) {
				$ordered[ $source ] = $sets[ $source ];
			}
		}
		return $this->extractor->extract( $ordered );
	}
}
