<?php
/**
 * Existing Content Optimizer endpoints.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\Jobs\JobRunner;
use AiSeoContentStudio\Keywords\KeywordPlan;
use AiSeoContentStudio\Optimizer\OptimizeJobs;
use AiSeoContentStudio\Optimizer\SourceContent;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * Routes:
 * - GET  /optimizer/posts                    posts/pages the user can edit
 * - POST /optimizer/projects                 start an optimisation (reads the post; no AI)
 * - POST /projects/{id}/optimize/analyze     AI recommendations (job)
 * - POST /projects/{id}/optimize/decisions   accept/reject recommendations
 * - POST /projects/{id}/optimize/rewrite     revised version from accepted items (job)
 */
class OptimizerController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param ProjectsController $projectsController Ownership check.
	 * @param ProjectRepository  $projects           Projects.
	 * @param JobRunner          $jobs               Jobs.
	 */
	public function __construct(
		private readonly ProjectsController $projectsController,
		private readonly ProjectRepository $projects,
		private readonly JobRunner $jobs,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/optimizer/posts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'posts' ),
				'permission_callback' => array( $this, 'canGenerate' ),
				'args'                => array(
					'search' => array(
						'type'    => 'string',
						'default' => '',
					),
					'page'   => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/optimizer/projects',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'canGenerate' ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'keyword' => array(
						'type'      => 'string',
						'required'  => true,
						'minLength' => 2,
						'maxLength' => 200,
					),
				),
			)
		);
		foreach ( array(
			'analyze'   => 'analyze',
			'decisions' => 'decisions',
			'rewrite'   => 'rewrite',
		) as $suffix => $callback ) {
			register_rest_route(
				self::NAMESPACE,
				'/projects/(?P<id>\d+)/optimize/' . $suffix,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this->projectsController, 'canAccessProject' ),
				)
			);
		}
	}

	/**
	 * Posts and pages the current user can edit.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function posts( \WP_REST_Request $request ): \WP_REST_Response {
		$query = new \WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				's'              => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'posts_per_page' => 20,
				'paged'          => (int) $request->get_param( 'page' ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				continue;
			}
			$items[] = array(
				'id'       => $post->ID,
				'title'    => wp_strip_all_tags( get_the_title( $post ) ),
				'type'     => $post->post_type,
				'status'   => $post->post_status,
				'format'   => SourceContent::format( $post ),
				'modified' => $post->post_modified_gmt,
				'link'     => (string) get_permalink( $post ),
			);
		}
		return new \WP_REST_Response(
			array(
				'items' => $items,
				'total' => (int) $query->found_posts,
			)
		);
	}

	/**
	 * Create an optimisation project from a post.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'aiscs_forbidden', __( 'You cannot optimise this content.', 'ai-seo-content-studio' ), array( 'status' => 403 ) );
		}

		$inputs                   = ProjectsController::sanitizeInputs( (array) $request->get_params() );
		$inputs['mode']           = 'optimize';
		$inputs['source_post_id'] = $post_id;
		if ( 'page' === $post->post_type && 'blog_post' === $inputs['content_type'] && ! $request->get_param( 'content_type' ) ) {
			$inputs['content_type'] = 'service_page';
		}

		$id = $this->projects->create( $inputs, get_current_user_id() );
		if ( 0 === $id ) {
			return new \WP_Error( 'aiscs_create_failed', __( 'The project could not be created.', 'ai-seo-content-studio' ), array( 'status' => 500 ) );
		}

		$source  = SourceContent::html( $post );
		$content = SourceContent::toContent(
			wp_strip_all_tags( get_the_title( $post ) ),
			$source['html'],
			array(
				'meta_title'       => (string) get_post_meta( $post_id, '_aiscs_seo_title', true ),
				'meta_description' => (string) get_post_meta( $post_id, '_aiscs_meta_description', true ),
				'slug'             => $post->post_name,
			)
		);
		$plan    = KeywordPlan::empty( $inputs['keyword'] );
		$format  = SourceContent::format( $post );

		$this->projects->save( $id, 'keyword_plan', $plan );
		$this->projects->save(
			$id,
			'optimization',
			array(
				'source_post_id'  => $post_id,
				'source_title'    => $content['title'],
				'source_status'   => $post->post_status,
				'format'          => $format,
				'notice'          => 'elementor' === $format
					? __( 'This page is built with Elementor. It will not be changed; the revised version is saved as a separate new draft (built with Elementor while Elementor is active). Its design is not copied: compare the two and move the changes you want.', 'ai-seo-content-studio' )
					: __( 'The original is never changed. The revised version is saved as a new draft for you to review.', 'ai-seo-content-studio' ),
				'source_content'  => $content,
				'links'           => $source['links'],
				'baseline'        => OptimizeJobs::baseline( $content, $inputs, $plan ),
				'recommendations' => array(),
				'summary'         => '',
				'change_summary'  => array(),
			)
		);
		$this->projects->setStage( $id, 'created' );
		return new \WP_REST_Response( array( 'id' => $id ), 201 );
	}

	/**
	 * Start AI analysis.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function analyze( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->start( (int) $request->get_param( 'id' ), 'optimize_analysis' );
	}

	/**
	 * Record accept/reject decisions.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function decisions( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id  = (int) $request->get_param( 'id' );
		$opt = $this->projects->section( $id, 'optimization' );
		if ( null === $opt ) {
			return new \WP_Error( 'aiscs_not_optimization', __( 'Not an optimisation project.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		$decisions = (array) $request->get_param( 'decisions' );
		foreach ( $opt['recommendations'] as $i => $rec ) {
			$value = $decisions[ $rec['id'] ] ?? null;
			if ( in_array( $value, array( 'accepted', 'rejected', 'pending' ), true ) ) {
				$opt['recommendations'][ $i ]['decision'] = $value;
			}
		}
		$this->projects->save( $id, 'optimization', $opt );
		return new \WP_REST_Response( $opt['recommendations'] );
	}

	/**
	 * Start the rewrite.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rewrite( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->start( (int) $request->get_param( 'id' ), 'optimize_rewrite' );
	}

	/**
	 * Start a job.
	 *
	 * @param int    $id   Project.
	 * @param string $type Type.
	 */
	private function start( int $id, string $type ): \WP_REST_Response|\WP_Error {
		try {
			$job = $this->jobs->start( $id, $type, get_current_user_id() );
		} catch ( ProviderException $e ) {
			return new \WP_Error( 'aiscs_job_' . $e->category, $e->getMessage(), array( 'status' => 409 ) );
		}
		return new \WP_REST_Response( $this->jobs->publicView( $job ), 202 );
	}
}
