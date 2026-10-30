<?php
/**
 * AI Website Studio endpoints: page catalog, design analysis and brand profiles.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\Support\Sanitize;
use AiSeoContentStudio\WebsiteStudio\Analysis\PageCatalog;
use AiSeoContentStudio\WebsiteStudio\Analysis\WebsiteAnalyzer;
use AiSeoContentStudio\WebsiteStudio\Design\DesignTokens;
use AiSeoContentStudio\WebsiteStudio\Persistence\AnalysisRepository;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\Persistence\BrandProfileRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Every route requires aiscs_generate_content. Page routes additionally require edit
 * permission on each page; analysis and profile routes require ownership (or the manage
 * capability). Responses never include credentials.
 */
class WebsiteStudioController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param PageCatalog            $catalog   Page catalog.
	 * @param WebsiteAnalyzer        $analyzer  Analyzer.
	 * @param AnalysisRepository     $analyses  Analyses.
	 * @param BrandProfileRepository $profiles  Brand profiles.
	 * @param AuditLog               $audit     Audit log.
	 */
	public function __construct(
		private readonly PageCatalog $catalog,
		private readonly WebsiteAnalyzer $analyzer,
		private readonly AnalysisRepository $analyses,
		private readonly BrandProfileRepository $profiles,
		private readonly AuditLog $audit,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$id = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/pages/available',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'pages' ),
				'permission_callback' => array( $this, 'canGenerate' ),
				'args'                => array(
					'page'      => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 50,
					),
					'search'    => array(
						'type'      => 'string',
						'default'   => '',
						'maxLength' => 100,
					),
					'post_type' => array(
						'type'    => 'string',
						'enum'    => PageCatalog::POST_TYPES,
						'default' => 'page',
					),
					'builder'   => array(
						'type'    => 'string',
						'enum'    => array( '', 'elementor' ),
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/website/analyze',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'analyze' ),
				'permission_callback' => array( $this, 'canAnalyzePages' ),
				'args'                => array(
					'page_ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'minItems' => 1,
						'maxItems' => WebsiteAnalyzer::MAX_PAGES,
					),
					'force'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/website/analysis/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'analysis' ),
				'permission_callback' => array( $this, 'canAccessAnalysis' ),
				'args'                => $id,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/website/brand-profiles',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'listProfiles' ),
					'permission_callback' => array( $this, 'canGenerate' ),
					'args'                => array(
						'page' => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'createProfile' ),
					'permission_callback' => array( $this, 'canGenerate' ),
					'args'                => array(
						'name'         => array(
							'type'      => 'string',
							'required'  => true,
							'minLength' => 1,
							'maxLength' => 191,
						),
						'tokens'       => array( 'type' => 'object' ),
						'analysis_ids' => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'maxItems' => WebsiteAnalyzer::MAX_PAGES,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/website/brand-profiles/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getProfile' ),
					'permission_callback' => array( $this, 'canAccessProfile' ),
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'updateProfile' ),
					'permission_callback' => array( $this, 'canAccessProfile' ),
					'args'                => array_merge(
						$id,
						array(
							'name'   => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 191,
							),
							'tokens' => array( 'type' => 'object' ),
						)
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'deleteProfile' ),
					'permission_callback' => array( $this, 'canAccessProfile' ),
					'args'                => $id,
				),
			)
		);
	}

	/**
	 * Permission: capability + edit permission on every requested page.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function canAnalyzePages( \WP_REST_Request $request ): bool|\WP_Error {
		$can = $this->canGenerate();
		if ( true !== $can ) {
			return $can;
		}
		foreach ( (array) $request->get_param( 'page_ids' ) as $page_id ) {
			$post = get_post( (int) $page_id );
			if ( ! $post || ! in_array( $post->post_type, PageCatalog::POST_TYPES, true ) || 'trash' === $post->post_status ) {
				return new \WP_Error( 'aiscs_page_not_found', __( 'Page not found.', 'ai-seo-content-studio' ), array( 'status' => 404 ) );
			}
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return $this->forbidden();
			}
		}
		return true;
	}

	/**
	 * Permission: analysis ownership.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function canAccessAnalysis( \WP_REST_Request $request ): bool|\WP_Error {
		$can = $this->canGenerate();
		if ( true !== $can ) {
			return $can;
		}
		$id = (int) $request->get_param( 'id' );
		if ( null === $this->analyses->get( $id ) ) {
			return new \WP_Error( 'aiscs_not_found', __( 'Analysis not found.', 'ai-seo-content-studio' ), array( 'status' => 404 ) );
		}
		return $this->analyses->canAccess( $id, get_current_user_id() ) ? true : $this->forbidden();
	}

	/**
	 * Permission: profile ownership.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function canAccessProfile( \WP_REST_Request $request ): bool|\WP_Error {
		$can = $this->canGenerate();
		if ( true !== $can ) {
			return $can;
		}
		$id = (int) $request->get_param( 'id' );
		if ( null === $this->profiles->get( $id ) ) {
			return new \WP_Error( 'aiscs_not_found', __( 'Brand profile not found.', 'ai-seo-content-studio' ), array( 'status' => 404 ) );
		}
		return $this->profiles->canAccess( $id, get_current_user_id() ) ? true : $this->forbidden();
	}

	/**
	 * GET /pages/available
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function pages( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			$this->catalog->list(
				array(
					'page'      => (int) $request->get_param( 'page' ),
					'per_page'  => (int) $request->get_param( 'per_page' ),
					'search'    => Sanitize::text( $request->get_param( 'search' ), 100 ),
					'post_type' => (string) $request->get_param( 'post_type' ),
					'builder'   => (string) $request->get_param( 'builder' ),
				)
			)
		);
	}

	/**
	 * POST /website/analyze
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function analyze( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user    = get_current_user_id();
		$ids     = array_values( array_unique( array_map( 'intval', (array) $request->get_param( 'page_ids' ) ) ) );
		$records = array();
		foreach ( $ids as $page_id ) {
			$post = get_post( $page_id );
			if ( ! $post ) {
				continue;
			}
			try {
				$records[] = $this->analyzer->analyzePage( $post, $user, (bool) $request->get_param( 'force' ) );
			} catch ( \RuntimeException ) {
				return new \WP_Error( 'aiscs_analysis_failed', __( 'The analysis could not be saved. Check that the plugin database tables exist (deactivate and reactivate the plugin).', 'ai-seo-content-studio' ), array( 'status' => 500 ) );
			}
		}
		$fresh = array_values( array_filter( $records, static fn ( array $r ): bool => empty( $r['cached'] ) ) );
		if ( array() !== $fresh ) {
			$this->audit->record( 'design.analyze', 'page', $ids[0], array( 'page_ids' => $ids ) );
		}
		return new \WP_REST_Response(
			array(
				'analyses' => array_map( array( $this, 'analysisView' ), $records ),
				'tokens'   => $this->analyzer->tokensFrom( $records ),
			)
		);
	}

	/**
	 * GET /website/analysis/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function analysis( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( $this->analysisView( (array) $this->analyses->get( (int) $request->get_param( 'id' ) ) ) );
	}

	/**
	 * GET /website/brand-profiles
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function listProfiles( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( $this->profiles->listFor( get_current_user_id(), (int) $request->get_param( 'page' ) ) );
	}

	/**
	 * POST /website/brand-profiles: from explicit tokens, or built from the user's analyses.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function createProfile( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user = get_current_user_id();
		if ( $this->profiles->countOwned( $user ) >= BrandProfileRepository::MAX_PER_USER ) {
			return new \WP_Error( 'aiscs_profile_limit', __( 'You have reached the maximum number of brand profiles. Delete one you no longer need.', 'ai-seo-content-studio' ), array( 'status' => 409 ) );
		}
		$name   = Sanitize::text( $request->get_param( 'name' ), 191 );
		$pages  = array();
		$tokens = $request->get_param( 'tokens' );

		$analysis_ids = array_values( array_unique( array_map( 'intval', (array) $request->get_param( 'analysis_ids' ) ) ) );
		if ( null === $tokens && array() === $analysis_ids ) {
			return new \WP_Error( 'aiscs_profile_empty', __( 'Analyze at least one page, or provide design tokens.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		if ( null === $tokens ) {
			$records = array();
			foreach ( $analysis_ids as $analysis_id ) {
				if ( ! $this->analyses->canAccess( $analysis_id, $user ) ) {
					return $this->forbidden();
				}
				$record    = (array) $this->analyses->get( $analysis_id );
				$records[] = $record;
				$pages[]   = (int) $record['source_page_id'];
			}
			$tokens = $this->analyzer->tokensFrom( $records );
		} else {
			$errors = array();
			$tokens = DesignTokens::normalize( $tokens, $errors );
			if ( array() !== $errors ) {
				return $this->invalidTokens( $errors );
			}
			$tokens['sources'] = array_fill_keys( array( 'colors', 'typography', 'components', 'layout' ), 'user' );
		}

		$id = $this->profiles->create( $user, $name, $tokens, $pages );
		if ( $id <= 0 ) {
			return new \WP_Error( 'aiscs_profile_not_saved', __( 'The brand profile could not be saved.', 'ai-seo-content-studio' ), array( 'status' => 500 ) );
		}
		$this->audit->record( 'brand_profile.create', 'brand_profile', $id, array( 'source_pages' => $pages ) );
		return new \WP_REST_Response( $this->profiles->get( $id ), 201 );
	}

	/**
	 * GET /website/brand-profiles/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function getProfile( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( $this->profiles->get( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * PUT /website/brand-profiles/{id}: rename and/or replace tokens (user edits).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function updateProfile( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$name   = null === $request->get_param( 'name' ) ? null : Sanitize::text( $request->get_param( 'name' ), 191 );
		$tokens = $request->get_param( 'tokens' );
		if ( null !== $tokens ) {
			$errors = array();
			$clean  = DesignTokens::normalize( $tokens, $errors );
			if ( array() !== $errors ) {
				return $this->invalidTokens( $errors );
			}
			// Groups the user changed are marked as user-defined; unchanged groups keep their source.
			$current = (array) $this->profiles->get( $id );
			$sources = (array) ( $current['tokens']['sources'] ?? array() );
			foreach ( array( 'colors', 'typography', 'components', 'layout' ) as $group ) {
				$before = 'layout' === $group ? array( $current['tokens']['layout'] ?? null, $current['tokens']['spacing'] ?? null ) : ( $current['tokens'][ $group ] ?? null );
				$after  = 'layout' === $group ? array( $clean['layout'], $clean['spacing'] ) : $clean[ $group ];
				if ( $before !== $after ) {
					$sources[ $group ] = 'user';
				}
			}
			$clean['sources'] = $sources;
			$tokens           = $clean;
		}
		if ( '' === $name ) {
			return new \WP_Error( 'aiscs_profile_name', __( 'The profile needs a name.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		if ( ! $this->profiles->update( $id, $name, $tokens ) ) {
			return new \WP_Error( 'aiscs_profile_not_saved', __( 'The brand profile could not be saved.', 'ai-seo-content-studio' ), array( 'status' => 500 ) );
		}
		$this->audit->record( 'brand_profile.update', 'brand_profile', $id, array( 'tokens_changed' => null !== $tokens ) );
		return new \WP_REST_Response( $this->profiles->get( $id ) );
	}

	/**
	 * DELETE /website/brand-profiles/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function deleteProfile( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request->get_param( 'id' );
		if ( ! $this->profiles->delete( $id ) ) {
			return new \WP_Error( 'aiscs_profile_not_deleted', __( 'The brand profile could not be deleted.', 'ai-seo-content-studio' ), array( 'status' => 500 ) );
		}
		$this->audit->record( 'brand_profile.delete', 'brand_profile', $id );
		return new \WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Public view of an analysis record (raw signals are internal and omitted).
	 *
	 * @param array<string, mixed> $record Record.
	 * @return array<string, mixed>
	 */
	public function analysisView( array $record ): array {
		$analysis = (array) ( $record['analysis'] ?? array() );
		unset( $analysis['signals'] );
		return array(
			'id'             => (int) ( $record['id'] ?? 0 ),
			'source_page_id' => (int) ( $record['source_page_id'] ?? 0 ),
			'created_at'     => (string) ( $record['created_at'] ?? '' ),
			'cached'         => ! empty( $record['cached'] ),
			'analysis'       => $analysis,
		);
	}

	/**
	 * 400 for invalid tokens.
	 *
	 * @param string[] $errors Errors.
	 */
	private function invalidTokens( array $errors ): \WP_Error {
		return new \WP_Error(
			'aiscs_invalid_tokens',
			__( 'Some design values are not valid. Use hex colours (#1F365C), CSS lengths (16px, 1.5rem) and plain font names.', 'ai-seo-content-studio' ),
			array(
				'status' => 400,
				'errors' => array_slice( $errors, 0, 20 ),
			)
		);
	}
}
