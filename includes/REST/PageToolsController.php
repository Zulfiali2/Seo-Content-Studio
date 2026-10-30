<?php
/**
 * AI Website Studio page tools: checks, section regeneration, preview, revisions.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\Jobs\JobRunner;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\ImageGenerationService;
use AiSeoContentStudio\Support\Sanitize;
use AiSeoContentStudio\WebsiteStudio\Analysis\PageCatalog;
use AiSeoContentStudio\WebsiteStudio\Analysis\WebsiteAnalyzer;
use AiSeoContentStudio\WebsiteStudio\Editor\ElementTree;
use AiSeoContentStudio\WebsiteStudio\Editor\Findings;
use AiSeoContentStudio\WebsiteStudio\Editor\PageChecks;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\History\RevisionService;
use AiSeoContentStudio\WebsiteStudio\Persistence\BrandProfileRepository;
use AiSeoContentStudio\WebsiteStudio\Sections\Proposals;
use AiSeoContentStudio\WebsiteStudio\Sections\RegenerateJob;
use AiSeoContentStudio\WebsiteStudio\Sections\SectionLocator;
use AiSeoContentStudio\WebsiteStudio\Sections\SectionTransforms;
use AiSeoContentStudio\WebsiteStudio\Sections\SectionWriter;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

defined( 'ABSPATH' ) || exit;

/**
 * Every route requires aiscs_generate_content and edit permission on the page. Reading
 * (checks, preview links, history) works on any Elementor page the user may edit; every
 * write goes through DraftManager, which only changes studio-made drafts.
 */
class PageToolsController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param StudioServices         $studio    Studio services.
	 * @param ProjectRepository      $projects  Projects.
	 * @param JobRunner              $jobs      Jobs.
	 * @param BrandProfileRepository $profiles  Brand profiles.
	 * @param WebsiteAnalyzer        $analyzer  Page analysis (reference pages).
	 * @param PageChecks             $checks    Checks.
	 * @param Proposals              $proposals Regeneration proposals.
	 * @param RevisionService        $history   Revisions.
	 * @param ImageGenerationService $images    Image provider check.
	 */
	public function __construct(
		private readonly StudioServices $studio,
		private readonly ProjectRepository $projects,
		private readonly JobRunner $jobs,
		private readonly BrandProfileRepository $profiles,
		private readonly WebsiteAnalyzer $analyzer,
		private readonly PageChecks $checks,
		private readonly Proposals $proposals,
		private readonly RevisionService $history,
		private readonly ImageGenerationService $images,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$page = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);
		$ids  = static fn ( int $max ): array => array(
			'type'     => 'array',
			'items'    => array(
				'type'    => 'string',
				'pattern' => '^[a-z][a-f0-9]{12}$',
			),
			'maxItems' => $max,
			'required' => true,
		);
		$reference = array(
			'profile_id'         => array(
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			),
			'reference_page_ids' => array(
				'type'     => 'array',
				'items'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'maxItems' => 5,
				'default'  => array(),
			),
		);
		$routes = array(
			array( '/design/pages/(?P<id>\d+)/overview', \WP_REST_Server::READABLE, 'overview', $page ),
			array( '/design/pages/(?P<id>\d+)/preview', \WP_REST_Server::READABLE . ', ' . \WP_REST_Server::CREATABLE, 'preview', $page ),
			array( '/design/pages/(?P<id>\d+)/consistency-check', \WP_REST_Server::CREATABLE, 'consistency', $page + $reference ),
			array( '/design/pages/(?P<id>\d+)/consistency/apply', \WP_REST_Server::CREATABLE, 'applyConsistency', $page + $reference + array( 'fix_ids' => $ids( 100 ) ) ),
			array( '/design/pages/(?P<id>\d+)/accessibility-check', \WP_REST_Server::CREATABLE, 'accessibility', $page ),
			array(
				'/design/pages/(?P<id>\d+)/accessibility/apply',
				\WP_REST_Server::CREATABLE,
				'applyAccessibility',
				$page + array(
					'fix_ids' => $ids( 100 ),
					'values'  => array(
						'type'                 => 'object',
						'default'              => array(),
						'additionalProperties' => array(
							'type'      => 'string',
							'maxLength' => 250,
						),
					),
				),
			),
			array(
				'/design/pages/(?P<id>\d+)/sections/(?P<section_id>[a-z0-9][a-z0-9-]{0,40})/regenerate',
				\WP_REST_Server::CREATABLE,
				'regenerate',
				$page + array(
					'action'          => array(
						'type'     => 'string',
						'enum'     => array_merge( SectionWriter::ACTIONS, SectionTransforms::ACTIONS, array( 'image' ) ),
						'required' => true,
					),
					'background'      => array(
						'type'    => 'string',
						'enum'    => array( '', 'surface', 'primary', 'secondary', 'dark' ),
						'default' => '',
					),
					'scale'           => array(
						'type'    => 'string',
						'enum'    => array( 'smaller', 'larger', 'default' ),
						'default' => 'default',
					),
					'font'            => array(
						'type'      => 'string',
						'maxLength' => 100,
						'default'   => '',
					),
					'kind'            => array(
						'type'    => 'string',
						'enum'    => array( '', 'stack', 'split', 'grid' ),
						'default' => '',
					),
					'columns'         => array(
						'type'    => 'integer',
						'minimum' => 0,
						'maximum' => 6,
						'default' => 0,
					),
					'spacing'         => array(
						'type'    => 'string',
						'enum'    => array_keys( SectionTransforms::SPACING ),
						'default' => 'normal',
					),
					'instruction'     => array(
						'type'      => 'string',
						'maxLength' => 1000,
						'default'   => '',
					),
					'primary_keyword' => array(
						'type'      => 'string',
						'maxLength' => 120,
						'default'   => '',
					),
					'prompt'          => array(
						'type'      => 'string',
						'maxLength' => 1500,
						'default'   => '',
					),
					'image_index'     => array(
						'type'    => 'integer',
						'minimum' => -1,
						'maximum' => 50,
						'default' => -1,
					),
				),
			),
			array( '/design/pages/(?P<id>\d+)/revisions', \WP_REST_Server::READABLE, 'revisions', $page ),
			array(
				'/design/pages/(?P<id>\d+)/revisions/compare',
				\WP_REST_Server::READABLE,
				'compare',
				$page + array(
					'from' => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'to'   => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			),
			array( '/design/pages/(?P<id>\d+)/revisions/(?P<revision>\d+)/restore', \WP_REST_Server::CREATABLE, 'restore', $page ),
			array( '/design/pages/(?P<id>\d+)/revisions/(?P<revision>\d+)/sections/(?P<element_id>[a-z0-9]{7,8})/restore', \WP_REST_Server::CREATABLE, 'restoreSection', $page ),
		);
		foreach ( $routes as [ $path, $methods, $callback, $args ] ) {
			register_rest_route(
				self::NAMESPACE,
				$path,
				array(
					'methods'             => $methods,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'canUsePage' ),
					'args'                => $args,
				)
			);
		}
		$proposal = array(
			array( '/design/proposals/(?P<id>\d+)', \WP_REST_Server::READABLE, 'proposal' ),
			array( '/design/proposals/(?P<id>\d+)/preview', \WP_REST_Server::CREATABLE, 'previewProposal' ),
			array( '/design/proposals/(?P<id>\d+)/approve', \WP_REST_Server::CREATABLE, 'approve' ),
			array( '/design/proposals/(?P<id>\d+)/discard', \WP_REST_Server::CREATABLE, 'discard' ),
		);
		foreach ( $proposal as [ $path, $methods, $callback ] ) {
			register_rest_route(
				self::NAMESPACE,
				$path,
				array(
					'methods'             => $methods,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'canUseProposal' ),
					'args'                => $page,
				)
			);
		}
	}

	/**
	 * Permission: capability, an existing page or post, and edit permission on it.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function canUsePage( \WP_REST_Request $request ): bool|\WP_Error {
		$can = $this->canGenerate();
		if ( true !== $can ) {
			return $can;
		}
		$post = get_post( (int) $request->get_param( 'id' ) );
		if ( ! $post || ! in_array( $post->post_type, PageCatalog::POST_TYPES, true ) || 'trash' === $post->post_status ) {
			return new \WP_Error( 'aiscs_page_not_found', __( 'Page not found.', 'ai-seo-content-studio' ), array( 'status' => 404 ) );
		}
		return current_user_can( 'edit_post', $post->ID ) ? true : $this->forbidden();
	}

	/**
	 * Permission: a regeneration project the user owns.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function canUseProposal( \WP_REST_Request $request ): bool|\WP_Error {
		$can = $this->canGenerate();
		if ( true !== $can ) {
			return $can;
		}
		$id = (int) $request->get_param( 'id' );
		if ( ! $this->projects->exists( $id ) || Proposals::KIND !== get_post_meta( $id, ProjectRepository::KIND_META, true ) || 'regenerate' !== ( $this->projects->section( $id, 'studio' )['source'] ?? '' ) ) {
			return new \WP_Error( 'aiscs_not_found', __( 'Proposal not found.', 'ai-seo-content-studio' ), array( 'status' => 404 ) );
		}
		return $this->projects->canAccess( $id, get_current_user_id() ) ? true : $this->forbidden();
	}

	/**
	 * GET /design/pages/{id}/overview: what the tools can do with this page.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function overview( \WP_REST_Request $request ): \WP_REST_Response {
		$id       = (int) $request->get_param( 'id' );
		$elements = $this->studio->bridge()->elements( $id );
		$record   = $this->studio->pages()->forPage( $id );
		$writable = '';
		try {
			$this->studio->drafts()->assertWritable( $id, get_current_user_id() );
		} catch ( DraftException $e ) {
			$writable = $e->getMessage();
		}
		return new \WP_REST_Response(
			array(
				'page'        => array(
					'id'              => $id,
					'title'           => $this->studio->posts()->title( $id ),
					'status'          => $this->studio->posts()->status( $id ),
					'studio'          => null !== $record,
					'generation_type' => (string) ( $record['generation_type'] ?? '' ),
					'writable'        => '' === $writable,
					'not_writable'    => $writable,
					'elementor'       => $this->studio->bridge()->isBuiltWithElementor( $id ),
					'elementor_url'   => $this->studio->bridge()->editUrl( $id ),
					'preview_url'     => $this->studio->posts()->previewUrl( $id ),
				),
				'regenerable'  => null !== $record && ! empty( $record['document']['sections'] ),
				'sections'     => null !== $record ? SectionLocator::sections( $record, $elements, $this->studio->compat()->containers ) : array(),
				'outline'      => ElementTree::outline( $elements ),
				'image_ready'  => null !== $this->images->provider() && current_user_can( 'upload_files' ),
			)
		);
	}

	/**
	 * GET|POST /design/pages/{id}/preview: protected preview links (WordPress shows drafts
	 * only to users who may edit them).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function preview( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request->get_param( 'id' );
		return new \WP_REST_Response(
			array(
				'preview_url'   => $this->studio->posts()->previewUrl( $id ),
				'elementor_url' => $this->studio->bridge()->editUrl( $id ),
				'devices'       => array(
					'desktop' => 1280,
					'tablet'  => 768,
					'mobile'  => 390,
				),
			)
		);
	}

	/**
	 * Reference tokens for the consistency check: a brand profile, reference pages, or the
	 * page's own build tokens / Elementor kit.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{tokens: array<string, mixed>, source: string}|\WP_Error
	 */
	private function reference( \WP_REST_Request $request ): array|\WP_Error {
		$user    = get_current_user_id();
		$profile = (int) $request->get_param( 'profile_id' );
		if ( $profile > 0 ) {
			if ( ! $this->profiles->canAccess( $profile, $user ) ) {
				return $this->forbidden();
			}
			return array(
				'tokens' => (array) ( $this->profiles->get( $profile )['tokens'] ?? array() ),
				'source' => 'profile',
			);
		}
		$pages = array_values( array_unique( array_map( 'intval', (array) $request->get_param( 'reference_page_ids' ) ) ) );
		if ( array() !== $pages ) {
			$records = array();
			foreach ( $pages as $page ) {
				$post = get_post( $page );
				if ( ! $post || ! in_array( $post->post_type, PageCatalog::POST_TYPES, true ) || ! current_user_can( 'edit_post', $page ) ) {
					return $this->forbidden();
				}
				try {
					$records[] = $this->analyzer->analyzePage( $post, $user );
				} catch ( \RuntimeException ) {
					return new \WP_Error( 'aiscs_analysis_failed', __( 'A reference page could not be analysed.', 'ai-seo-content-studio' ), array( 'status' => 500 ) );
				}
			}
			return array(
				'tokens' => $this->analyzer->tokensFrom( $records ),
				'source' => 'reference_pages',
			);
		}
		$record = $this->studio->pages()->forPage( (int) $request->get_param( 'id' ) );
		return array(
			'tokens' => $this->proposals->tokens( (array) $record, $user ),
			'source' => null !== $record && isset( $record['document']['_tokens'] ) ? 'page' : 'site',
		);
	}

	/**
	 * POST /design/pages/{id}/consistency-check
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function consistency( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$reference = $this->reference( $request );
		if ( is_wp_error( $reference ) ) {
			return $reference;
		}
		$report = $this->checks->consistency( (int) $request->get_param( 'id' ), $reference['tokens'] );
		return new \WP_REST_Response(
			array(
				'reference'  => $reference['source'],
				'score'      => $report['score'],
				'categories' => $report['categories'],
				'findings'   => Findings::view( $report['findings'] ),
			)
		);
	}

	/**
	 * POST /design/pages/{id}/consistency/apply
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function applyConsistency( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$reference = $this->reference( $request );
		if ( is_wp_error( $reference ) ) {
			return $reference;
		}
		$id = (int) $request->get_param( 'id' );
		try {
			$findings = $this->checks->consistency( $id, $reference['tokens'] )['findings'];
			return new \WP_REST_Response( $this->checks->apply( $id, $findings, Sanitize::strings( $request->get_param( 'fix_ids' ), 100, 20 ), array(), get_current_user_id(), __( 'Design consistency', 'ai-seo-content-studio' ) ) );
		} catch ( DraftException $e ) {
			return self::draftError( $e );
		}
	}

	/**
	 * POST /design/pages/{id}/accessibility-check
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function accessibility( \WP_REST_Request $request ): \WP_REST_Response {
		$report = $this->checks->accessibility( (int) $request->get_param( 'id' ) );
		return new \WP_REST_Response(
			array(
				'target'     => 'WCAG 2.2 AA',
				'notice'     => __( 'Automated checks find only some accessibility problems. They do not show that the page meets WCAG; test it with a keyboard and a screen reader too.', 'ai-seo-content-studio' ),
				'categories' => $report['categories'],
				'findings'   => Findings::view( $report['findings'] ),
			)
		);
	}

	/**
	 * POST /design/pages/{id}/accessibility/apply
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function applyAccessibility( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$values = array();
		foreach ( (array) $request->get_param( 'values' ) as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/^a[a-f0-9]{12}$/', $key ) ) {
				$values[ $key ] = Sanitize::text( $value, 250 );
			}
		}
		try {
			$findings = $this->checks->accessibility( $id )['findings'];
			return new \WP_REST_Response( $this->checks->apply( $id, $findings, Sanitize::strings( $request->get_param( 'fix_ids' ), 100, 20 ), $values, get_current_user_id(), __( 'Accessibility', 'ai-seo-content-studio' ) ) );
		} catch ( DraftException $e ) {
			return self::draftError( $e );
		}
	}

	/**
	 * POST /design/pages/{id}/sections/{section_id}/regenerate: a proposal (immediately for
	 * style actions, through a background job for AI actions).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function regenerate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$page    = (int) $request->get_param( 'id' );
		$section = (string) $request->get_param( 'section_id' );
		$action  = (string) $request->get_param( 'action' );
		$user    = get_current_user_id();
		$options = array(
			'background'      => (string) $request->get_param( 'background' ),
			'scale'           => (string) $request->get_param( 'scale' ),
			'font'            => Sanitize::text( $request->get_param( 'font' ), 100 ),
			'kind'            => (string) $request->get_param( 'kind' ),
			'columns'         => (int) $request->get_param( 'columns' ),
			'spacing'         => (string) $request->get_param( 'spacing' ),
			'instruction'     => Sanitize::textarea( $request->get_param( 'instruction' ), 1000 ),
			'primary_keyword' => Sanitize::text( $request->get_param( 'primary_keyword' ), 120 ),
			'prompt'          => Sanitize::textarea( $request->get_param( 'prompt' ), 1500 ),
			'image_index'     => (int) $request->get_param( 'image_index' ),
		);
		try {
			$this->studio->drafts()->assertWritable( $page, $user );
			$context = $this->proposals->context( $page, $section );
			if ( in_array( $action, SectionTransforms::ACTIONS, true ) ) {
				try {
					$changed = SectionTransforms::apply( $action, $context['located']['section'], $options, $this->proposals->tokens( $context['record'], $user ) );
				} catch ( \InvalidArgumentException $e ) {
					return new \WP_Error( 'aiscs_invalid_option', $e->getMessage(), array( 'status' => 400 ) );
				}
				$proposal = $this->proposals->build(
					$context,
					$changed['section'],
					$changed['summary'],
					array(
						'action'  => $action,
						'options' => $options,
					),
					$user
				);
				$project  = $this->proposals->createProject( $page, $section, $action, $options, $user );
				if ( $project <= 0 ) {
					return new \WP_Error( 'aiscs_project_not_created', __( 'The proposal could not be stored.', 'ai-seo-content-studio' ), array( 'status' => 500 ) );
				}
				$this->proposals->store( $project, $proposal );
				return new \WP_REST_Response(
					array(
						'project_id' => $project,
						'proposal'   => Proposals::view( (array) $this->projects->section( $project, 'studio' ) ),
					),
					201
				);
			}
		} catch ( DraftException $e ) {
			return self::draftError( $e );
		}
		if ( 'image' === $action ) {
			if ( ! current_user_can( 'upload_files' ) ) {
				return $this->forbidden();
			}
			if ( null === $this->images->provider() ) {
				return new \WP_Error( 'aiscs_no_image_provider', __( 'Choose a default image provider under AI Providers.', 'ai-seo-content-studio' ), array( 'status' => 424 ) );
			}
			if ( array() === SectionWriter::images( (array) $context['located']['section']['elements'] ) ) {
				return new \WP_Error( 'aiscs_no_images', __( 'This section has no images to regenerate.', 'ai-seo-content-studio' ), array( 'status' => 409 ) );
			}
		}
		$project = $this->proposals->createProject( $page, $section, $action, $options, $user );
		if ( $project <= 0 ) {
			return new \WP_Error( 'aiscs_project_not_created', __( 'The job could not be created.', 'ai-seo-content-studio' ), array( 'status' => 500 ) );
		}
		try {
			$job = $this->jobs->start( $project, RegenerateJob::TYPE, $user );
		} catch ( ProviderException $e ) {
			return new \WP_Error( 'aiscs_job_not_started', $e->getMessage(), array( 'status' => 409 ) );
		}
		return new \WP_REST_Response(
			array(
				'project_id' => $project,
				'job'        => $this->jobs->publicView( $job ),
			),
			202
		);
	}

	/**
	 * GET /design/proposals/{id}: job status and the proposal.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function proposal( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request->get_param( 'id' );
		$this->jobs->tick( $id );
		$studio = (array) $this->projects->section( $id, 'studio' );
		return new \WP_REST_Response(
			array(
				'project_id' => $id,
				'page_id'    => (int) ( $studio['page_id'] ?? 0 ),
				'section_id' => (string) ( $studio['section_id'] ?? '' ),
				'action'     => (string) ( $studio['action'] ?? '' ),
				'status'     => (string) ( $studio['status'] ?? 'pending' ),
				'job'        => $this->jobs->publicView( $this->projects->section( $id, 'job' ) ),
				'proposal'   => Proposals::view( $studio ),
			)
		);
	}

	/**
	 * POST /design/proposals/{id}/preview
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function previewProposal( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $this->proposals->preview( (int) $request->get_param( 'id' ), get_current_user_id() ) );
		} catch ( DraftException $e ) {
			return self::draftError( $e );
		}
	}

	/**
	 * POST /design/proposals/{id}/approve
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function approve( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $this->proposals->approve( (int) $request->get_param( 'id' ), get_current_user_id() ) );
		} catch ( DraftException $e ) {
			return self::draftError( $e );
		}
	}

	/**
	 * POST /design/proposals/{id}/discard
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function discard( \WP_REST_Request $request ): \WP_REST_Response {
		$this->proposals->discard( (int) $request->get_param( 'id' ) );
		return new \WP_REST_Response( array( 'discarded' => true ) );
	}

	/**
	 * GET /design/pages/{id}/revisions
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function revisions( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( $this->history->history( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * GET /design/pages/{id}/revisions/compare?from=&to=
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function compare( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $this->history->compare( (int) $request->get_param( 'id' ), (int) $request->get_param( 'from' ), (int) $request->get_param( 'to' ) ) );
		} catch ( DraftException $e ) {
			return self::draftError( $e );
		}
	}

	/**
	 * POST /design/pages/{id}/revisions/{revision}/restore
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function restore( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $this->history->restore( (int) $request->get_param( 'id' ), (int) $request->get_param( 'revision' ), get_current_user_id() ) );
		} catch ( DraftException $e ) {
			return self::draftError( $e );
		}
	}

	/**
	 * POST /design/pages/{id}/revisions/{revision}/sections/{element_id}/restore
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function restoreSection( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $this->history->restoreSection( (int) $request->get_param( 'id' ), (int) $request->get_param( 'revision' ), (string) $request->get_param( 'element_id' ), get_current_user_id() ) );
		} catch ( DraftException $e ) {
			return self::draftError( $e );
		}
	}

	/**
	 * DraftException → WP_Error.
	 *
	 * @param DraftException $e Exception.
	 */
	public static function draftError( DraftException $e ): \WP_Error {
		return new \WP_Error(
			'aiscs_' . $e->reason,
			$e->getMessage(),
			array(
				'status'   => $e->status(),
				'errors'   => array_slice( $e->errors, 0, 30 ),
				'warnings' => array_slice( $e->warnings, 0, 30 ),
			)
		);
	}
}
