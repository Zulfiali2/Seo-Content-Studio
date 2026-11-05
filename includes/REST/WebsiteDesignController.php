<?php
/**
 * AI Website Studio endpoints: page cloning and native Elementor page building.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\Content\WritingProfile;
use AiSeoContentStudio\Jobs\JobRunner;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\ImageGenerationService;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\Support\Sanitize;
use AiSeoContentStudio\WebsiteStudio\Analysis\PageCatalog;
use AiSeoContentStudio\WebsiteStudio\Cloner\CloneJob;
use AiSeoContentStudio\WebsiteStudio\Converter\HtmlConverter;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftManager;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;
use AiSeoContentStudio\WebsiteStudio\Generator\GeneratedImages;
use AiSeoContentStudio\WebsiteStudio\Generator\GeneratorJob;
use AiSeoContentStudio\WebsiteStudio\Generator\PageGenerator;
use AiSeoContentStudio\WebsiteStudio\Generator\SiteGateway;
use AiSeoContentStudio\WebsiteStudio\Persistence\BrandProfileRepository;
use AiSeoContentStudio\WebsiteStudio\StudioServices;
use AiSeoContentStudio\WebsiteStudio\Vision\ScreenshotJob;
use AiSeoContentStudio\WebsiteStudio\Vision\ScreenshotPreparer;
use AiSeoContentStudio\WebsiteStudio\Vision\ScreenshotStore;

defined( 'ABSPATH' ) || exit;

/**
 * Every route requires aiscs_generate_content. Source pages additionally require edit
 * permission; brand profiles and studio projects require ownership (or the manage
 * capability). Nothing here modifies an existing page: all writes create new drafts.
 */
class WebsiteDesignController extends RestController {

	public const KIND = 'website_studio';

	/**
	 * Constructor.
	 *
	 * Studio services are resolved inside the callbacks, so registering these routes does not
	 * make Elementor load its widgets on every REST request.
	 *
	 * @param StudioServices         $studio   Studio services.
	 * @param ProjectRepository      $projects Projects.
	 * @param JobRunner              $jobs     Jobs.
	 * @param BrandProfileRepository $profiles Brand profiles.
	 * @param SettingsRepository     $settings Settings.
	 * @param ImageGenerationService $images   Image generation (provider check only; jobs generate).
	 * @param GeneratedImages        $reviewed Image review of generated pages.
	 * @param SiteGateway            $site     Link candidates.
	 */
	public function __construct(
		private readonly StudioServices $studio,
		private readonly ProjectRepository $projects,
		private readonly JobRunner $jobs,
		private readonly BrandProfileRepository $profiles,
		private readonly SettingsRepository $settings,
		private readonly ImageGenerationService $images,
		private readonly GeneratedImages $reviewed,
		private readonly SiteGateway $site,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$id   = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);
		$text = static fn ( int $max, bool $required = false ): array => array(
			'type'      => 'string',
			'maxLength' => $max,
			'required'  => $required,
		);

		register_rest_route(
			self::NAMESPACE,
			'/design/capabilities',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn (): \WP_REST_Response => new \WP_REST_Response( $this->studio->compat()->toArray() + array( 'icons' => PageDocument::ICONS ) ),
				'permission_callback' => array( $this, 'canGenerate' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)/clone/plan',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'plan' ),
				'permission_callback' => array( $this, 'canUseSource' ),
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)/clone',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'duplicate' ),
				'permission_callback' => array( $this, 'canUseSource' ),
				'args'                => $id + array( 'title' => $text( 200 ) ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/pages/(?P<id>\d+)/clone-and-generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cloneAndGenerate' ),
				'permission_callback' => array( $this, 'canUseSource' ),
				'args'                => $id + array(
					'topic'              => $text( 300, true ),
					'primary_keyword'    => $text( 120, true ),
					'secondary_keywords' => array(
						'type'     => 'array',
						'items'    => array(
							'type'      => 'string',
							'maxLength' => 120,
						),
						'maxItems' => 15,
						'default'  => array(),
					),
					'audience'           => $text( 300 ),
					'intent'             => array(
						'type'    => 'string',
						'enum'    => array( '', 'informational', 'commercial', 'transactional', 'local' ),
						'default' => '',
					),
					'location'           => $text( 120 ),
					'tone'               => $text( 120 ),
					'notes'              => $text( 3000 ),
					'title'              => $text( 200 ),
					'profile_id'         => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
					'selected'           => array(
						'type'     => 'array',
						'items'    => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'maxItems' => 400,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/jobs/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'job' ),
				'permission_callback' => array( $this, 'canAccessStudioProject' ),
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/jobs/(?P<id>\d+)/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => array( $this, 'canAccessStudioProject' ),
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/pages/build',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'build' ),
				'permission_callback' => array( $this, 'canGenerate' ),
				'args'                => array(
					'document'   => array(
						'type'     => 'object',
						'required' => true,
					),
					'profile_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			)
		);
		$html_args = array(
			'html'           => array(
				'type'      => 'string',
				'required'  => true,
				'maxLength' => HtmlConverter::MAX_HTML_BYTES,
			),
			'css'            => array(
				'type'      => 'string',
				'default'   => '',
				'maxLength' => HtmlConverter::MAX_CSS_BYTES,
			),
			'js'             => array(
				'type'      => 'string',
				'default'   => '',
				'maxLength' => 524288,
			),
			'base_url'       => array(
				'type'      => 'string',
				'default'   => '',
				'maxLength' => 500,
			),
			'fallback'       => array(
				'type'    => 'string',
				'enum'    => array( 'skip', 'html' ),
				'default' => 'skip',
			),
			'include_chrome' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'title'          => $text( 200 ),
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/html/parse',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'parseHtml' ),
				'permission_callback' => array( $this, 'canGenerate' ),
				'args'                => $html_args,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/html/convert',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'convertHtml' ),
				'permission_callback' => array( $this, 'canGenerate' ),
				'args'                => $html_args + array(
					'profile_id' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/image/analyze',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'analyzeImage' ),
				'permission_callback' => array( $this, 'canGenerate' ),
				'args'                => array(
					'model' => array(
						'type'    => 'string',
						'default' => '',
						'pattern' => '^[A-Za-z0-9._:/-]{0,100}$',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/image/(?P<id>\d+)/generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generateFromImage' ),
				'permission_callback' => array( $this, 'canAccessStudioProject' ),
				'args'                => $id + array(
					'topic'              => $text( 300 ),
					'primary_keyword'    => $text( 120 ),
					'secondary_keywords' => array(
						'type'     => 'array',
						'items'    => array(
							'type'      => 'string',
							'maxLength' => 120,
						),
						'maxItems' => 15,
						'default'  => array(),
					),
					'audience'           => $text( 300 ),
					'intent'             => array(
						'type'    => 'string',
						'enum'    => array( '', 'informational', 'commercial', 'transactional', 'local' ),
						'default' => '',
					),
					'location'           => $text( 120 ),
					'tone'               => $text( 120 ),
					'notes'              => $text( 3000 ),
					'title'              => $text( 200 ),
					'keep_text'          => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'placeholders'       => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'profile_id'         => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			)
		);
		$brief_args = array(
			'topic'              => $text( 300, true ),
			'primary_keyword'    => $text( 120, true ),
			'secondary_keywords' => array(
				'type'     => 'array',
				'items'    => array(
					'type'      => 'string',
					'maxLength' => 120,
				),
				'maxItems' => 15,
				'default'  => array(),
			),
			'audience'           => $text( 300 ),
			'intent'             => array(
				'type'    => 'string',
				'enum'    => array( '', 'informational', 'commercial', 'transactional', 'local' ),
				'default' => '',
			),
			'location'           => $text( 120 ),
			'tone'               => $text( 120 ),
			'notes'              => $text( 3000 ),
			'length'             => array(
				'type'    => 'string',
				'enum'    => array_keys( PageGenerator::LENGTHS ),
				'default' => 'medium',
			),
			'sections'           => array(
				'type'     => 'array',
				'items'    => array(
					'type' => 'string',
					'enum' => PageDocument::SECTION_TYPES,
				),
				'maxItems' => 12,
				'default'  => array(),
			),
			'images'             => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => PageGenerator::MAX_IMAGES,
				'default' => 3,
			),
			'cta_url'            => $text( 500 ),
			'links'              => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'title'              => $text( 200 ),
			'profile_id'         => array(
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			),
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generatePage' ),
				'permission_callback' => array( $this, 'canGenerate' ),
				'args'                => $brief_args,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/generate/(?P<id>\d+)/build',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'buildGenerated' ),
				'permission_callback' => array( $this, 'canAccessStudioProject' ),
				'args'                => $id + array(
					'approved_links' => array(
						'type'     => 'array',
						'items'    => array(
							'type'    => 'string',
							'pattern' => '^link-\d{1,2}$',
						),
						'maxItems' => 20,
						'default'  => array(),
					),
					'title'          => $text( 200 ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/generate/(?P<id>\d+)/images',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'generateImages' ),
				'permission_callback' => array( $this, 'canAccessStudioProject' ),
				'args'                => $id + array(
					'slots'   => array(
						'type'     => 'array',
						'items'    => array(
							'type'    => 'string',
							'pattern' => '^img-\d{1,2}$',
						),
						'minItems' => 1,
						'maxItems' => PageGenerator::MAX_IMAGES,
						'required' => true,
					),
					'prompts' => array(
						'type'                 => 'object',
						'default'              => array(),
						'additionalProperties' => array(
							'type'      => 'string',
							'maxLength' => 1500,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/generate/(?P<id>\d+)/images/apply',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'applyImages' ),
				'permission_callback' => array( $this, 'canAccessStudioProject' ),
				'args'                => $id + array(
					'slots' => array(
						'type'     => 'array',
						'items'    => array(
							'type'    => 'string',
							'pattern' => '^img-\d{1,2}$',
						),
						'minItems' => 1,
						'maxItems' => PageGenerator::MAX_IMAGES,
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/generate/(?P<id>\d+)/images/(?P<slot>img-\d{1,2})/discard',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'discardImage' ),
				'permission_callback' => array( $this, 'canAccessStudioProject' ),
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/pages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listPages' ),
				'permission_callback' => array( $this, 'canGenerate' ),
				'args'                => array(
					'page' => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);
	}

	/**
	 * Permission: capability + edit permission on the source page.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function canUseSource( \WP_REST_Request $request ): bool|\WP_Error {
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
	 * Permission: a studio project the user owns.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function canAccessStudioProject( \WP_REST_Request $request ): bool|\WP_Error {
		$can = $this->canGenerate();
		if ( true !== $can ) {
			return $can;
		}
		$id = (int) $request->get_param( 'id' );
		if ( ! $this->projects->exists( $id ) || self::KIND !== get_post_meta( $id, ProjectRepository::KIND_META, true ) ) {
			return new \WP_Error( 'aiscs_not_found', __( 'Job not found.', 'ai-seo-content-studio' ), array( 'status' => 404 ) );
		}
		return $this->projects->canAccess( $id, get_current_user_id() ) ? true : $this->forbidden();
	}

	/**
	 * GET /pages/{id}/clone/plan
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function plan( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $this->studio->cloner()->plan( get_post( (int) $request->get_param( 'id' ) ), WritingProfile::fromSettings( $this->settings )->brand() ) );
		} catch ( DraftException $e ) {
			return self::draftError( $e );
		}
	}

	/**
	 * POST /pages/{id}/clone: exact duplicate as a new draft (no AI).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function duplicate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$result = $this->studio->cloner()->clone( get_post( (int) $request->get_param( 'id' ) ), get_current_user_id(), array( 'title' => Sanitize::text( $request->get_param( 'title' ), 200 ) ) );
			return new \WP_REST_Response( $result, 201 );
		} catch ( DraftException $e ) {
			return self::draftError( $e );
		}
	}

	/**
	 * POST /pages/{id}/clone-and-generate: start the background job.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function cloneAndGenerate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user    = get_current_user_id();
		$source  = (int) $request->get_param( 'id' );
		$profile = (int) $request->get_param( 'profile_id' );
		if ( $profile > 0 && ! $this->profiles->canAccess( $profile, $user ) ) {
			return $this->forbidden();
		}
		$blocker = $this->studio->compat()->blocker();
		if ( '' !== $blocker ) {
			return new \WP_Error( 'aiscs_' . $blocker, DraftManager::blockerMessage( $blocker ), array( 'status' => 424 ) );
		}
		$brief = array(
			'topic'              => Sanitize::text( $request->get_param( 'topic' ), 300 ),
			'primary_keyword'    => Sanitize::text( $request->get_param( 'primary_keyword' ), 120 ),
			'secondary_keywords' => Sanitize::strings( $request->get_param( 'secondary_keywords' ), 15, 120 ),
			'audience'           => Sanitize::text( $request->get_param( 'audience' ), 300 ),
			'intent'             => Sanitize::text( $request->get_param( 'intent' ), 20 ),
			'location'           => Sanitize::text( $request->get_param( 'location' ), 120 ),
			'tone'               => Sanitize::text( $request->get_param( 'tone' ), 120 ),
			'notes'              => Sanitize::textarea( $request->get_param( 'notes' ), 3000 ),
		);
		if ( '' === $brief['topic'] || '' === $brief['primary_keyword'] ) {
			return new \WP_Error( 'aiscs_brief_incomplete', __( 'Enter the page topic and the primary keyword.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}

		$project = $this->projects->create(
			array(
				'keyword'      => $brief['primary_keyword'],
				'content_type' => 'studio_clone',
			),
			$user
		);
		if ( $project <= 0 ) {
			return new \WP_Error( 'aiscs_project_not_created', __( 'The job could not be created.', 'ai-seo-content-studio' ), array( 'status' => 500 ) );
		}
		update_post_meta( $project, ProjectRepository::KIND_META, self::KIND );
		$selected = $request->get_param( 'selected' );
		$this->projects->save(
			$project,
			'studio',
			array(
				'source_page_id' => $source,
				'brief'          => $brief,
				'title'          => Sanitize::text( $request->get_param( 'title' ), 200 ),
				'profile_id'     => $profile,
				'selected'       => is_array( $selected ) ? Sanitize::strings( $selected, 400, 200 ) : null,
			)
		);
		try {
			$job = $this->jobs->start( $project, CloneJob::TYPE, $user );
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
	 * GET /design/jobs/{id}: real job status (advanced when WP-Cron is slow) and the result.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function job( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request->get_param( 'id' );
		$this->jobs->tick( $id );
		$studio = (array) $this->projects->section( $id, 'studio' );
		return new \WP_REST_Response(
			array(
				'project_id' => $id,
				'source'     => (string) ( $studio['source'] ?? 'clone' ),
				'design'     => isset( $studio['design'] ) ? array(
					'document' => self::designView( (array) $studio['design']['document'] ),
					'tokens'   => $studio['design']['tokens'] ?? null,
					'report'   => $studio['design']['report'],
					'provider' => $studio['design']['provider'] ?? '',
					'model'    => $studio['design']['model'] ?? '',
				) : null,
				'generator'  => 'generator' === ( $studio['source'] ?? '' ) && isset( $studio['design'] ) ? array(
					'seo'    => $studio['design']['seo'],
					'links'  => $studio['design']['links'],
					'facts'  => $studio['design']['facts'],
					'images' => $studio['design']['images'],
					'report' => $studio['design']['report'],
				) : null,
				'images'     => 'generator' === ( $studio['source'] ?? '' ) ? array(
					'slots'        => array_values( (array) ( $studio['images'] ?? array() ) ),
					'can_generate' => null !== $this->images->provider() && current_user_can( 'upload_files' ),
				) : null,
				'job'        => $this->jobs->publicView( $this->projects->section( $id, 'job' ) ),
				'result'     => $studio['result'] ?? null,
				'written'    => isset( $studio['written'] ) ? array(
					'page_title'       => $studio['written']['page_title'] ?? '',
					'meta_title'       => $studio['written']['meta_title'] ?? '',
					'meta_description' => $studio['written']['meta_description'] ?? '',
					'provider'         => $studio['written']['provider'] ?? '',
					'model'            => $studio['written']['model'] ?? '',
				) : null,
			)
		);
	}

	/**
	 * POST /design/jobs/{id}/cancel
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function cancel( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request->get_param( 'id' );
		$this->jobs->cancel( $id );
		return new \WP_REST_Response( array( 'job' => $this->jobs->publicView( $this->projects->section( $id, 'job' ) ) ) );
	}

	/**
	 * POST /design/pages/build: PageDocument → new Elementor draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function build( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user    = get_current_user_id();
		$profile = (int) $request->get_param( 'profile_id' );
		$tokens  = array();
		if ( $profile > 0 ) {
			if ( ! $this->profiles->canAccess( $profile, $user ) ) {
				return $this->forbidden();
			}
			$tokens = (array) ( $this->profiles->get( $profile )['tokens'] ?? array() );
		}
		try {
			$result = $this->studio->builder()->build(
				$request->get_param( 'document' ),
				$tokens,
				array(
					'user_id'         => $user,
					'profile_id'      => $profile,
					'generation_type' => 'generated',
				)
			);
			return new \WP_REST_Response( $result, 201 );
		} catch ( DraftException $e ) {
			return self::draftError( $e );
		}
	}

	/**
	 * Run the converter on a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{document: array<string, mixed>, errors: string[], tokens: array<string, mixed>, report: array<string, mixed>}
	 */
	private function runConverter( \WP_REST_Request $request ): array {
		return ( new HtmlConverter() )->convert(
			(string) $request->get_param( 'html' ),
			(string) $request->get_param( 'css' ),
			(string) $request->get_param( 'js' ),
			array(
				'fallback'       => (string) $request->get_param( 'fallback' ),
				'base_url'       => esc_url_raw( (string) $request->get_param( 'base_url' ), array( 'http', 'https' ) ),
				'title'          => Sanitize::text( $request->get_param( 'title' ), 200 ),
				'include_chrome' => (bool) $request->get_param( 'include_chrome' ),
			)
		);
	}

	/**
	 * POST /design/html/parse: analyse only (nothing is saved).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function parseHtml( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->runConverter( $request );
		return new \WP_REST_Response(
			array(
				'document' => $result['document'],
				'errors'   => array_slice( $result['errors'], 0, 30 ),
				'report'   => $result['report'],
				'tokens'   => $result['tokens'],
			)
		);
	}

	/**
	 * POST /design/html/convert: convert and save as a new Elementor draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function convertHtml( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user    = get_current_user_id();
		$profile = (int) $request->get_param( 'profile_id' );
		if ( $profile > 0 && ! $this->profiles->canAccess( $profile, $user ) ) {
			return $this->forbidden();
		}
		$result = $this->runConverter( $request );
		if ( array() !== $result['errors'] ) {
			return new \WP_Error(
				'aiscs_conversion_failed',
				__( 'The HTML could not be converted.', 'ai-seo-content-studio' ),
				array(
					'status' => 422,
					'errors' => array_slice( $result['errors'], 0, 30 ),
				)
			);
		}
		// The chosen brand profile styles the page; otherwise the page's own CSS does.
		$tokens = $profile > 0 ? (array) ( $this->profiles->get( $profile )['tokens'] ?? array() ) : $result['tokens'];
		try {
			$built = $this->studio->builder()->build(
				$result['document'],
				$tokens,
				array(
					'user_id'         => $user,
					'profile_id'      => $profile,
					'generation_type' => 'converted',
				),
				DocumentValidator::MODE_CONVERTED
			);
			return new \WP_REST_Response( $built + array( 'report' => $result['report'] ), 201 );
		} catch ( DraftException $e ) {
			return self::draftError( $e );
		}
	}

	/**
	 * POST /design/image/analyze: upload a screenshot (multipart field "screenshot") and start the analysis.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function analyzeImage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$files = $request->get_file_params();
		$file  = is_array( $files['screenshot'] ?? null ) ? $files['screenshot'] : null;
		if ( null === $file || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new \WP_Error( 'aiscs_no_screenshot', __( 'Upload a screenshot (PNG, JPEG or WebP).', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > ScreenshotPreparer::MAX_UPLOAD_BYTES ) {
			return new \WP_Error( 'aiscs_screenshot_too_large', __( 'The screenshot is larger than 10 MB.', 'ai-seo-content-studio' ), array( 'status' => 413 ) );
		}
		$bytes = (string) file_get_contents( $file['tmp_name'], false, null, 0, ScreenshotPreparer::MAX_UPLOAD_BYTES + 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- PHP upload temp file.
		try {
			$info = ScreenshotPreparer::validate( $bytes );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'aiscs_invalid_screenshot', $e->getMessage(), array( 'status' => 400 ) );
		}
		$blocker = $this->studio->compat()->blocker();
		if ( '' !== $blocker ) {
			return new \WP_Error( 'aiscs_' . $blocker, DraftManager::blockerMessage( $blocker ), array( 'status' => 424 ) );
		}
		$user    = get_current_user_id();
		$project = $this->projects->create(
			array(
				'keyword'      => __( 'Page from screenshot', 'ai-seo-content-studio' ),
				'content_type' => 'studio_screenshot',
			),
			$user
		);
		if ( $project <= 0 ) {
			return new \WP_Error( 'aiscs_project_not_created', __( 'The job could not be created.', 'ai-seo-content-studio' ), array( 'status' => 500 ) );
		}
		update_post_meta( $project, ProjectRepository::KIND_META, self::KIND );
		try {
			$token = ( new ScreenshotStore() )->put( $bytes );
		} catch ( \RuntimeException ) {
			$this->projects->trash( $project );
			return new \WP_Error( 'aiscs_screenshot_not_stored', __( 'The screenshot could not be stored temporarily. Check that the uploads folder is writable.', 'ai-seo-content-studio' ), array( 'status' => 500 ) );
		}
		$this->projects->save(
			$project,
			'studio',
			array(
				'source' => 'screenshot',
				'token'  => $token,
				'upload' => $info,
				'model'  => (string) $request->get_param( 'model' ),
			)
		);
		try {
			$job = $this->jobs->start( $project, ScreenshotJob::TYPE_ANALYZE, $user );
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
	 * POST /design/image/{project}/generate: write content (optional), add placeholders and build the draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function generateFromImage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id      = (int) $request->get_param( 'id' );
		$user    = get_current_user_id();
		$studio  = (array) $this->projects->section( $id, 'studio' );
		$profile = (int) $request->get_param( 'profile_id' );
		if ( 'screenshot' !== ( $studio['source'] ?? '' ) || empty( $studio['design']['document']['sections'] ) ) {
			return new \WP_Error( 'aiscs_not_analyzed', __( 'Analyse a screenshot first.', 'ai-seo-content-studio' ), array( 'status' => 409 ) );
		}
		if ( isset( $studio['result']['page_id'] ) ) {
			return new \WP_Error( 'aiscs_already_built', __( 'A draft was already created from this analysis. Upload the screenshot again to make another one.', 'ai-seo-content-studio' ), array( 'status' => 409 ) );
		}
		if ( $profile > 0 && ! $this->profiles->canAccess( $profile, $user ) ) {
			return $this->forbidden();
		}
		$studio['brief']   = array(
			'topic'              => Sanitize::text( $request->get_param( 'topic' ), 300 ),
			'primary_keyword'    => Sanitize::text( $request->get_param( 'primary_keyword' ), 120 ),
			'secondary_keywords' => Sanitize::strings( $request->get_param( 'secondary_keywords' ), 15, 120 ),
			'audience'           => Sanitize::text( $request->get_param( 'audience' ), 300 ),
			'intent'             => Sanitize::text( $request->get_param( 'intent' ), 20 ),
			'location'           => Sanitize::text( $request->get_param( 'location' ), 120 ),
			'tone'               => Sanitize::text( $request->get_param( 'tone' ), 120 ),
			'notes'              => Sanitize::textarea( $request->get_param( 'notes' ), 3000 ),
		);
		$studio['options'] = array(
			'keep_text'    => (bool) $request->get_param( 'keep_text' ),
			'placeholders' => (bool) $request->get_param( 'placeholders' ),
			'profile_id'   => $profile,
			'title'        => Sanitize::text( $request->get_param( 'title' ), 200 ),
		);
		if ( ! $studio['options']['keep_text'] && ( '' === $studio['brief']['topic'] || '' === $studio['brief']['primary_keyword'] ) ) {
			return new \WP_Error( 'aiscs_brief_incomplete', __( 'Enter the page topic and primary keyword, or choose to keep the text from the screenshot.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		unset( $studio['written'], $studio['image_ids'] );
		$this->projects->save( $id, 'studio', $studio );
		try {
			$job = $this->jobs->start( $id, ScreenshotJob::TYPE_BUILD, $user );
		} catch ( ProviderException $e ) {
			return new \WP_Error( 'aiscs_job_not_started', $e->getMessage(), array( 'status' => 409 ) );
		}
		return new \WP_REST_Response(
			array(
				'project_id' => $id,
				'job'        => $this->jobs->publicView( $job ),
			),
			202
		);
	}

	/**
	 * Brief fields of a request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private static function brief( \WP_REST_Request $request ): array {
		return array(
			'topic'              => Sanitize::text( $request->get_param( 'topic' ), 300 ),
			'primary_keyword'    => Sanitize::text( $request->get_param( 'primary_keyword' ), 120 ),
			'secondary_keywords' => Sanitize::strings( $request->get_param( 'secondary_keywords' ), 15, 120 ),
			'audience'           => Sanitize::text( $request->get_param( 'audience' ), 300 ),
			'intent'             => Sanitize::text( $request->get_param( 'intent' ), 20 ),
			'location'           => Sanitize::text( $request->get_param( 'location' ), 120 ),
			'tone'               => Sanitize::text( $request->get_param( 'tone' ), 120 ),
			'notes'              => Sanitize::textarea( $request->get_param( 'notes' ), 3000 ),
		);
	}

	/**
	 * POST /design/generate: write a new page from a brief (design for review; nothing is saved to pages).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function generatePage( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user    = get_current_user_id();
		$profile = (int) $request->get_param( 'profile_id' );
		if ( $profile > 0 && ! $this->profiles->canAccess( $profile, $user ) ) {
			return $this->forbidden();
		}
		$blocker = $this->studio->compat()->blocker();
		if ( '' !== $blocker ) {
			return new \WP_Error( 'aiscs_' . $blocker, DraftManager::blockerMessage( $blocker ), array( 'status' => 424 ) );
		}
		$brief = self::brief( $request ) + array(
			'length'   => (string) $request->get_param( 'length' ),
			'sections' => array_values( array_intersect( (array) $request->get_param( 'sections' ), PageDocument::SECTION_TYPES ) ),
			'images'   => max( 0, min( PageGenerator::MAX_IMAGES, (int) $request->get_param( 'images' ) ) ),
			'cta_url'  => PageGenerator::ctaUrl( (string) $request->get_param( 'cta_url' ) ),
		);
		if ( '' === $brief['topic'] || '' === $brief['primary_keyword'] ) {
			return new \WP_Error( 'aiscs_brief_incomplete', __( 'Enter the page topic and the primary keyword.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		if ( '' !== trim( (string) $request->get_param( 'cta_url' ) ) && '' === $brief['cta_url'] ) {
			return new \WP_Error( 'aiscs_invalid_cta_url', __( 'The button link must be an http(s) URL, a path on this site (/contact/) or an anchor (#contact).', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		$project = $this->projects->create(
			array(
				'keyword'      => $brief['primary_keyword'],
				'content_type' => 'studio_generate',
			),
			$user
		);
		if ( $project <= 0 ) {
			return new \WP_Error( 'aiscs_project_not_created', __( 'The job could not be created.', 'ai-seo-content-studio' ), array( 'status' => 500 ) );
		}
		update_post_meta( $project, ProjectRepository::KIND_META, self::KIND );
		$this->projects->save(
			$project,
			'studio',
			array(
				'source'     => 'generator',
				'brief'      => $brief,
				'options'    => array(
					'profile_id' => $profile,
					'title'      => Sanitize::text( $request->get_param( 'title' ), 200 ),
				),
				// Read now, as the requesting user: only published public pages are offered.
				'candidates' => $request->get_param( 'links' ) ? $this->site->linkCandidates() : array(),
			)
		);
		try {
			$job = $this->jobs->start( $project, GeneratorJob::TYPE_WRITE, $user );
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
	 * POST /design/generate/{id}/build: create the draft with the approved links.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function buildGenerated( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$studio = (array) $this->projects->section( $id, 'studio' );
		if ( 'generator' !== ( $studio['source'] ?? '' ) || empty( $studio['design']['document']['sections'] ) ) {
			return new \WP_Error( 'aiscs_not_generated', __( 'Generate the page design first.', 'ai-seo-content-studio' ), array( 'status' => 409 ) );
		}
		if ( isset( $studio['result']['page_id'] ) ) {
			return new \WP_Error( 'aiscs_already_built', __( 'A draft was already created from this design.', 'ai-seo-content-studio' ), array( 'status' => 409 ) );
		}
		$known                    = array_column( (array) $studio['design']['links'], 'id' );
		$studio['approved_links'] = array_values( array_intersect( Sanitize::strings( $request->get_param( 'approved_links' ), 20, 10 ), $known ) );
		$title                    = Sanitize::text( $request->get_param( 'title' ), 200 );
		if ( '' !== $title ) {
			$studio['options']['title'] = $title;
		}
		unset( $studio['image_ids'] );
		$this->projects->save( $id, 'studio', $studio );
		try {
			$job = $this->jobs->start( $id, GeneratorJob::TYPE_BUILD, get_current_user_id() );
		} catch ( ProviderException $e ) {
			return new \WP_Error( 'aiscs_job_not_started', $e->getMessage(), array( 'status' => 409 ) );
		}
		return new \WP_REST_Response(
			array(
				'project_id' => $id,
				'job'        => $this->jobs->publicView( $job ),
			),
			202
		);
	}

	/**
	 * POST /design/generate/{id}/images: generate AI image candidates (not yet in the page).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function generateImages( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id     = (int) $request->get_param( 'id' );
		$studio = (array) $this->projects->section( $id, 'studio' );
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->forbidden();
		}
		if ( null === $this->images->provider() ) {
			return new \WP_Error( 'aiscs_no_image_provider', __( 'Choose a default image provider under AI Providers.', 'ai-seo-content-studio' ), array( 'status' => 424 ) );
		}
		if ( empty( $studio['images'] ) ) {
			return new \WP_Error( 'aiscs_no_image_slots', __( 'This draft has no image slots.', 'ai-seo-content-studio' ), array( 'status' => 409 ) );
		}
		$wanted  = Sanitize::strings( $request->get_param( 'slots' ), PageGenerator::MAX_IMAGES, 10 );
		$prompts = (array) $request->get_param( 'prompts' );
		$slots   = array();
		foreach ( $studio['images'] as $i => $slot ) {
			if ( ! in_array( $slot['id'], $wanted, true ) ) {
				continue;
			}
			$prompt = Sanitize::textarea( $prompts[ $slot['id'] ] ?? '', 1500 );
			if ( '' !== $prompt ) {
				$studio['images'][ $i ]['prompt'] = $prompt;
			}
			$studio['images'][ $i ]['error'] = '';
			$slots[]                         = $slot['id'];
		}
		if ( array() === $slots ) {
			return new \WP_Error( 'aiscs_no_image_slots', __( 'Choose at least one image.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		$this->projects->save( $id, 'studio', $studio );
		try {
			$job = $this->jobs->start(
				$id,
				GeneratorJob::TYPE_IMAGES,
				get_current_user_id(),
				array(
					'slots' => $slots,
					'run'   => wp_generate_password( 12, false ),
				)
			);
		} catch ( ProviderException $e ) {
			return new \WP_Error( 'aiscs_job_not_started', $e->getMessage(), array( 'status' => 409 ) );
		}
		return new \WP_REST_Response(
			array(
				'project_id' => $id,
				'job'        => $this->jobs->publicView( $job ),
			),
			202
		);
	}

	/**
	 * POST /design/generate/{id}/images/apply: put approved candidates into the draft.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function applyImages( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $this->reviewed->apply( (int) $request->get_param( 'id' ), Sanitize::strings( $request->get_param( 'slots' ), PageGenerator::MAX_IMAGES, 10 ), get_current_user_id() ) );
		} catch ( DraftException $e ) {
			return self::draftError( $e );
		}
	}

	/**
	 * POST /design/generate/{id}/images/{slot}/discard: delete a rejected candidate.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function discardImage( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'images' => $this->reviewed->discard( (int) $request->get_param( 'id' ), (string) $request->get_param( 'slot' ) ) ) );
	}

	/**
	 * Design document for review: image slots shown as placeholders, internal keys removed.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @return array<string, mixed>
	 */
	private static function designView( array $doc ): array {
		$clean = static function ( array $elements ) use ( &$clean ): array {
			$out = array();
			foreach ( $elements as $e ) {
				unset( $e['_words'] );
				if ( '_image' === ( $e['type'] ?? '' ) ) {
					$e = array(
						'type'   => 'image',
						'aspect' => $e['aspect'],
						'alt'    => $e['alt'],
						'prompt' => (string) ( $e['prompt'] ?? '' ),
					);
				}
				if ( 'group' === ( $e['type'] ?? '' ) ) {
					$e['elements'] = $clean( (array) $e['elements'] );
				}
				$out[] = $e;
			}
			return $out;
		};
		foreach ( (array) ( $doc['sections'] ?? array() ) as $i => $section ) {
			$doc['sections'][ $i ]['elements'] = $clean( (array) $section['elements'] );
		}
		return $doc;
	}

	/**
	 * GET /design/pages: pages the studio created.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function listPages( \WP_REST_Request $request ): \WP_REST_Response {
		$list  = $this->studio->pages()->listFor( get_current_user_id(), (int) $request->get_param( 'page' ) );
		$items = array();
		foreach ( $list['items'] as $row ) {
			$post = get_post( $row['page_id'] );
			if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$items[] = array(
				'page_id'         => $row['page_id'],
				'title'           => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				'status'          => $post->post_status,
				'generation_type' => $row['generation_type'],
				'source_page_id'  => $row['source_page_id'],
				'created_at'      => $row['created_at'],
				'elementor_url'   => add_query_arg(
					array(
						'post'   => $post->ID,
						'action' => 'elementor',
					),
					admin_url( 'post.php' )
				),
				'preview_url'     => (string) get_preview_post_link( $post ),
				'notes'           => $row['notes'],
			);
		}
		return new \WP_REST_Response(
			array(
				'items' => $items,
				'total' => $list['total'],
				'pages' => $list['pages'],
			)
		);
	}

	/**
	 * DraftException → WP_Error.
	 *
	 * @param DraftException $e Exception.
	 */
	private static function draftError( DraftException $e ): \WP_Error {
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
