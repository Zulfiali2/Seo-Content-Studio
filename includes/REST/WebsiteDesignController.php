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
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\Support\Sanitize;
use AiSeoContentStudio\WebsiteStudio\Analysis\PageCatalog;
use AiSeoContentStudio\WebsiteStudio\Cloner\CloneJob;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftManager;
use AiSeoContentStudio\WebsiteStudio\Engine\PageDocument;
use AiSeoContentStudio\WebsiteStudio\Persistence\BrandProfileRepository;
use AiSeoContentStudio\WebsiteStudio\StudioServices;

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
	 */
	public function __construct(
		private readonly StudioServices $studio,
		private readonly ProjectRepository $projects,
		private readonly JobRunner $jobs,
		private readonly BrandProfileRepository $profiles,
		private readonly SettingsRepository $settings,
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
