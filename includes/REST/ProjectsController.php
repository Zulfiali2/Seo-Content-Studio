<?php
/**
 * Content project endpoints (research, keyword plan, brief).
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\Content\Brief;
use AiSeoContentStudio\Content\ManualBrief;
use AiSeoContentStudio\Elementor\BlogTemplates;
use AiSeoContentStudio\Jobs\JobRunner;
use AiSeoContentStudio\Keywords\KeywordPlan;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Research\Contracts\Market;
use AiSeoContentStudio\Research\CostEstimates;
use AiSeoContentStudio\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * All routes require aiscs_generate_content; project routes additionally require
 * that the user owns the project (or can manage settings).
 */
class ProjectsController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository                                           $projects  Projects.
	 * @param JobRunner                                                   $jobs      Job runner.
	 * @param CostEstimates                                               $estimates Estimates.
	 * @param array<string, \AiSeoContentStudio\Jobs\JobHandlerInterface> $handlers Handlers by type (for step planning).
	 */
	public function __construct(
		private readonly ProjectRepository $projects,
		private readonly JobRunner $jobs,
		private readonly CostEstimates $estimates,
		private readonly array $handlers,
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
			'/projects',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
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
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'canGenerate' ),
					'args'                => array(
						'keyword' => array(
							'type'      => 'string',
							'required'  => true,
							'minLength' => 2,
							'maxLength' => 200,
						),
					),
				),
			)
		);

		$routes = array(
			''                => array(
				array( \WP_REST_Server::READABLE, 'show' ),
				array( \WP_REST_Server::EDITABLE, 'updateInputs' ),
				array( \WP_REST_Server::DELETABLE, 'destroy' ),
			),
			'/research'       => array( array( \WP_REST_Server::CREATABLE, 'startResearch' ) ),
			'/brief/generate' => array( array( \WP_REST_Server::CREATABLE, 'startBrief' ) ),
			'/estimate/(?P<type>research|brief|content|evaluate|analyze|rewrite|images)' => array( array( \WP_REST_Server::READABLE, 'estimate' ) ),
			'/job'            => array( array( \WP_REST_Server::READABLE, 'job' ) ),
			'/job/cancel'     => array( array( \WP_REST_Server::CREATABLE, 'cancelJob' ) ),
			'/keyword-plan'   => array( array( \WP_REST_Server::EDITABLE, 'savePlan' ) ),
			'/brief'          => array( array( \WP_REST_Server::EDITABLE, 'saveBrief' ) ),
		);
		foreach ( $routes as $suffix => $handlers ) {
			$definitions = array();
			foreach ( $handlers as [ $methods, $callback ] ) {
				$definitions[] = array(
					'methods'             => $methods,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'canAccessProject' ),
					'args'                => $id,
				);
			}
			register_rest_route( self::NAMESPACE, '/projects/(?P<id>\d+)' . $suffix, $definitions );
		}
	}

	/**
	 * Permission: capability + ownership.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function canAccessProject( \WP_REST_Request $request ): bool|\WP_Error {
		$can = $this->canGenerate();
		if ( true !== $can ) {
			return $can;
		}
		$id = (int) $request->get_param( 'id' );
		if ( ! $this->projects->exists( $id ) ) {
			return new \WP_Error( 'aiscs_not_found', __( 'Project not found.', 'ai-seo-content-studio' ), array( 'status' => 404 ) );
		}
		return $this->projects->canAccess( $id, get_current_user_id() ) ? true : $this->forbidden();
	}

	/**
	 * List projects.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( $this->projects->listFor( get_current_user_id(), (int) $request->get_param( 'page' ) ) );
	}

	/**
	 * Create a project.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$inputs = self::sanitizeInputs( (array) $request->get_params() );
		if ( '' === $inputs['keyword'] ) {
			return new \WP_Error( 'aiscs_invalid', __( 'Enter a primary keyword.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		$id = $this->projects->create( $inputs, get_current_user_id() );
		if ( 0 === $id ) {
			return new \WP_Error( 'aiscs_create_failed', __( 'The project could not be created.', 'ai-seo-content-studio' ), array( 'status' => 500 ) );
		}
		$this->projects->save( $id, 'keyword_plan', KeywordPlan::empty( $inputs['keyword'] ) );
		$this->refreshManualBrief( $id );
		return new \WP_REST_Response( $this->full( $id ), 201 );
	}

	/**
	 * Show a project.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( $this->full( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * Update inputs (content type, keyword, market, audience, notes).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function updateInputs( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id       = (int) $request->get_param( 'id' );
		$existing = $this->projects->section( $id, 'inputs' ) ?? array();
		$inputs   = self::sanitizeInputs( array_merge( $existing, (array) $request->get_param( 'inputs' ) ) );
		// Optimizer linkage is set only by the optimizer endpoint (which checks edit rights).
		foreach ( array( 'mode', 'source_post_id' ) as $locked ) {
			if ( isset( $existing[ $locked ] ) ) {
				$inputs[ $locked ] = $existing[ $locked ];
			}
		}
		if ( '' === $inputs['keyword'] ) {
			return new \WP_Error( 'aiscs_invalid', __( 'Enter a primary keyword.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		$this->projects->save( $id, 'inputs', $inputs );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => $inputs['keyword'],
			)
		);
		$this->refreshManualBrief( $id );
		return new \WP_REST_Response( $this->full( $id ) );
	}

	/**
	 * Trash a project (content posts are not affected).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request->get_param( 'id' );
		$this->jobs->cancel( $id );
		$this->projects->trash( $id );
		return new \WP_REST_Response( array( 'trashed' => true ) );
	}

	/**
	 * Estimate the cost of an operation.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function estimate( \WP_REST_Request $request ): \WP_REST_Response {
		$type = (string) $request->get_param( 'type' );
		$type = array(
			'analyze' => 'optimize_analysis',
			'rewrite' => 'optimize_rewrite',
		)[ $type ] ?? $type;
		try {
			$steps = isset( $this->handlers[ $type ] ) ? $this->handlers[ $type ]->steps( (int) $request->get_param( 'id' ), (array) $request->get_param( 'options' ) ) : array();
		} catch ( ProviderException ) {
			$steps = array(); // Nothing to run yet (e.g. no image selected).
		}
		return new \WP_REST_Response( $this->estimates->forSteps( $steps ) );
	}

	/**
	 * Start research.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function startResearch( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->startJob(
			(int) $request->get_param( 'id' ),
			'research',
			array(
				'refresh' => (bool) $request->get_param( 'refresh' ),
				'run_id'  => wp_generate_uuid4(),
			)
		);
	}

	/**
	 * Start brief generation (requires at least the primary keyword).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function startBrief( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->startJob( (int) $request->get_param( 'id' ), 'brief', array() );
	}

	/**
	 * Job status; advances a stalled job (WP-Cron fallback).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function job( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request->get_param( 'id' );
		$this->jobs->tick( $id );
		return new \WP_REST_Response( $this->jobs->publicView( $this->projects->section( $id, 'job' ) ) );
	}

	/**
	 * Cancel the active job.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function cancelJob( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request->get_param( 'id' );
		$this->jobs->cancel( $id );
		return new \WP_REST_Response( $this->jobs->publicView( $this->projects->section( $id, 'job' ) ) );
	}

	/**
	 * Save the edited keyword plan.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function savePlan( \WP_REST_Request $request ): \WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$plan = KeywordPlan::sanitize( (array) $request->get_param( 'plan' ) );
		// The primary keyword is owned by the project inputs.
		$plan['primary']['keyword'] = (string) ( $this->projects->section( $id, 'inputs' )['keyword'] ?? $plan['primary']['keyword'] );
		$this->projects->save( $id, 'keyword_plan', $plan );
		$this->projects->setStage( $id, 'planned' );
		$this->refreshManualBrief( $id );
		return new \WP_REST_Response( $this->full( $id ) );
	}

	/**
	 * Save the edited brief.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function saveBrief( \WP_REST_Request $request ): \WP_REST_Response {
		$id       = (int) $request->get_param( 'id' );
		$previous = $this->projects->section( $id, 'brief' ) ?? array();
		$brief    = Brief::sanitize( (array) $request->get_param( 'brief' ) );
		if ( isset( $previous['generated'] ) ) {
			$brief['generated'] = $previous['generated'];
		}
		$brief['edited_at'] = time();
		$this->projects->save( $id, 'brief', $brief );
		$this->projects->setStage( $id, 'briefed' );
		return new \WP_REST_Response( $this->full( $id ) );
	}

	/**
	 * Give the project the starting brief for a hand-written outline, or rebuild it from the
	 * current keyword and plan while the SEO has not saved it yet. A saved or AI brief is
	 * never touched.
	 *
	 * @param int $id Project.
	 */
	private function refreshManualBrief( int $id ): void {
		$brief = $this->projects->section( $id, 'brief' );
		if ( ! ManualBrief::enabled() || ( null !== $brief && ! ManualBrief::isUntouched( $brief ) ) ) {
			return;
		}
		$inputs = $this->projects->section( $id, 'inputs' ) ?? array();
		$plan   = $this->projects->section( $id, 'keyword_plan' ) ?? KeywordPlan::empty( (string) ( $inputs['keyword'] ?? '' ) );
		$this->projects->save( $id, 'brief', ManualBrief::skeleton( $inputs, $plan, $id ) );
	}

	/**
	 * Start a job and translate errors.
	 *
	 * @param int                  $id      Project.
	 * @param string               $type    Job type.
	 * @param array<string, mixed> $options Options.
	 */
	private function startJob( int $id, string $type, array $options ): \WP_REST_Response|\WP_Error {
		try {
			$job = $this->jobs->start( $id, $type, get_current_user_id(), $options );
		} catch ( ProviderException $e ) {
			return new \WP_Error( 'aiscs_job_' . $e->category, $e->getMessage(), array( 'status' => 409 ) );
		}
		return new \WP_REST_Response( $this->jobs->publicView( $job ), 202 );
	}

	/**
	 * Project payload with public job view.
	 *
	 * @param int $id Project.
	 * @return array<string, mixed>
	 */
	private function full( int $id ): array {
		$project        = $this->projects->get( $id );
		$project['job'] = $this->jobs->publicView( $project['job'] );
		return $project;
	}

	/**
	 * Sanitise project inputs.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public static function sanitizeInputs( array $input ): array {
		$type = Sanitize::enum( $input['content_type'] ?? '', Brief::CONTENT_TYPES, 'blog_post' );
		if ( (int) ( $input['length_max'] ?? 0 ) > 0 && (int) ( $input['length_min'] ?? 0 ) > (int) $input['length_max'] ) {
			$input['length_min'] = $input['length_max'];
		}
		return array(
			'content_type'     => $type,
			'post_type'        => Sanitize::enum( $input['post_type'] ?? '', array( 'post', 'page' ), in_array( $type, array( 'blog_post', 'informational_article' ), true ) ? 'post' : 'page' ),
			'keyword'          => Sanitize::text( $input['keyword'] ?? '', 200 ),
			'market'           => Market::fromArray( is_array( $input['market'] ?? null ) ? $input['market'] : array() )->toArray(),
			'audience'         => Sanitize::textarea( $input['audience'] ?? '', 500 ),
			'notes'            => Sanitize::textarea( $input['notes'] ?? '', 4000 ),
			// Optional word range for the whole article (0 = let the brief decide).
			'length_min'       => max( 0, min( 20000, (int) ( $input['length_min'] ?? 0 ) ) ),
			'length_max'       => max( 0, min( 20000, (int) ( $input['length_max'] ?? 0 ) ) ),
			// Competitor pages the user knows (used when no SEO data provider is connected).
			'competitor_urls'  => self::competitorUrls( $input['competitor_urls'] ?? array() ),
			// Output choices. The button link is validated again when saving.
			'output'           => Sanitize::enum( $input['output'] ?? '', array( 'wordpress', 'elementor' ), 'wordpress' ),
			'elementor_style'  => Sanitize::enum( $input['elementor_style'] ?? '', BlogTemplates::STYLES, 'content' ),
			'elementor_accent' => BlogTemplates::accent( $input['elementor_accent'] ?? '' ),
			'elementor_form'   => BlogTemplates::formShortcode( $input['elementor_form'] ?? '' ),
			'cta_text'         => Sanitize::text( $input['cta_text'] ?? '', 80 ),
			'cta_url'          => esc_url_raw( trim( (string) ( $input['cta_url'] ?? '' ) ), array( 'http', 'https' ) ),
		);
	}

	/**
	 * Up to 10 unique http(s) page addresses, from a list or one-per-line text.
	 *
	 * @param mixed $value Input.
	 * @return string[]
	 */
	public static function competitorUrls( mixed $value ): array {
		$lines = is_array( $value ) ? $value : preg_split( '/[\r\n,]+/', (string) $value );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = trim( (string) $line );
			// A full address only: scheme and a host with a dot (no guessing from plain words).
			if ( ! preg_match( '#^https?://[^\s/?\#]+\.[^\s/?\#]+#i', $line ) ) {
				continue;
			}
			$url = esc_url_raw( $line, array( 'http', 'https' ) );
			if ( '' !== $url && wp_parse_url( $url, PHP_URL_HOST ) && ! in_array( $url, $out, true ) ) {
				$out[] = $url;
			}
			if ( count( $out ) >= 10 ) {
				break;
			}
		}
		return $out;
	}
}
