<?php
/**
 * Content, evaluation, versions and draft endpoints.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\Content\ContentJob;
use AiSeoContentStudio\Content\ContentModel;
use AiSeoContentStudio\Content\DraftWriter;
use AiSeoContentStudio\Content\ManualBrief;
use AiSeoContentStudio\Content\VersionStore;
use AiSeoContentStudio\Content\WritingProfile;
use AiSeoContentStudio\Core\Environment;
use AiSeoContentStudio\Elementor\ElementorWriter;
use AiSeoContentStudio\Jobs\JobRunner;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\SEO\Evaluator;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /projects/{id}: content/generate, content, evaluate, evaluate/ai,
 * versions, versions/{vid}, versions/{vid}/restore, draft, preview.
 * All use the projects ownership check.
 */
class ContentController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param ProjectsController   $projectsController Provides the ownership check.
	 * @param ProjectRepository    $projects           Projects.
	 * @param JobRunner            $jobs               Jobs.
	 * @param Evaluator            $evaluator          Evaluator.
	 * @param VersionStore         $versions           Versions.
	 * @param DraftWriter          $drafts             Drafts.
	 * @param SettingsRepository   $settings           Settings.
	 * @param Environment          $environment        Environment.
	 * @param ElementorWriter|null $elementor     Elementor writer.
	 */
	public function __construct(
		private readonly ProjectsController $projectsController,
		private readonly ProjectRepository $projects,
		private readonly JobRunner $jobs,
		private readonly Evaluator $evaluator,
		private readonly VersionStore $versions,
		private readonly DraftWriter $drafts,
		private readonly SettingsRepository $settings,
		private readonly Environment $environment,
		private readonly ?ElementorWriter $elementor = null,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$routes = array(
			'/content/generate'                       => array( \WP_REST_Server::CREATABLE, 'generate' ),
			'/content'                                => array( \WP_REST_Server::EDITABLE, 'save' ),
			'/evaluate'                               => array( \WP_REST_Server::CREATABLE, 'evaluate' ),
			'/evaluate/ai'                            => array( \WP_REST_Server::CREATABLE, 'evaluateAi' ),
			'/versions'                               => array( \WP_REST_Server::READABLE, 'listVersions' ),
			'/versions/(?P<vid>[a-f0-9]{12})'         => array( \WP_REST_Server::READABLE, 'getVersion' ),
			'/versions/(?P<vid>[a-f0-9]{12})/restore' => array( \WP_REST_Server::CREATABLE, 'restore' ),
			'/draft'                                  => array( \WP_REST_Server::CREATABLE, 'draft' ),
			'/preview'                                => array( \WP_REST_Server::READABLE, 'preview' ),
		);
		foreach ( $routes as $suffix => [ $methods, $callback ] ) {
			register_rest_route(
				self::NAMESPACE,
				'/projects/(?P<id>\d+)' . $suffix,
				array(
					'methods'             => $methods,
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this->projectsController, 'canAccessProject' ),
				)
			);
		}
	}

	/**
	 * Start generation (all parts, or the listed parts).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function generate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request->get_param( 'id' );
		$brief = $this->projects->section( $id, 'brief' );
		if ( null === $brief ) {
			return new \WP_Error( 'aiscs_no_brief', __( 'Create the content brief first.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		// The chat-style writer plans its own outline when none is given; the other modes write
		// part by part from the outline, so they need at least one H2.
		$chat = 'single' === WritingProfile::fromSettings( $this->settings )->style( 'generation_mode' );
		if ( ! $chat && ( ManualBrief::isUntouched( $brief ) || ! ManualBrief::hasSections( $brief ) ) ) {
			return new \WP_Error( 'aiscs_no_sections', __( 'Add at least one H2 section to the outline in the Brief step, then save it.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		$parts   = array_values( array_filter( (array) $request->get_param( 'parts' ), 'is_string' ) );
		$options = array( 'parts' => $parts );
		// Optional per-part instructions for this run only (not stored with the content).
		$instructions = array();
		foreach ( (array) $request->get_param( 'instructions' ) as $part => $text ) {
			$text = is_string( $text ) ? Sanitize::textarea( $text, 1000 ) : '';
			if ( is_string( $part ) && preg_match( '/^(intro|conclusion|section-\d{1,3}|faqs)$/', $part ) && '' !== $text ) {
				$instructions[ $part ] = $text;
			}
		}
		if ( array() !== $instructions ) {
			$options['instructions'] = $instructions;
		}
		if ( 'missing' === $request->get_param( 'faq_mode' ) ) {
			$options['faq_mode'] = 'missing';
		}
		return $this->start( $id, 'content', $options );
	}

	/**
	 * Save edited content (HTML is re-sanitised; links restricted to known URLs).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function save( \WP_REST_Request $request ): \WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$input  = (array) $request->get_param( 'content' );
		$stored = $this->projects->section( $id, 'content' ) ?? array();
		// Checked sources and the edit report are written by the server only.
		$input['sources']     = (array) ( $stored['sources'] ?? array() );
		$input['edit_report'] = $stored['edit_report'] ?? null;
		$content              = ContentModel::sanitize( $input, $this->allowedLinks( $id ) );
		$this->projects->save( $id, 'content', $content );
		$this->versions->snapshot( $id, $content, __( 'Edited', 'ai-seo-content-studio' ) );
		$this->evaluator->evaluate( $id, false );
		return new \WP_REST_Response( $this->state( $id ) );
	}

	/**
	 * Run deterministic checks now (free).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function evaluate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$report = $this->evaluator->evaluate( (int) $request->get_param( 'id' ), true );
		return null === $report
			? new \WP_Error( 'aiscs_no_content', __( 'Generate content first.', 'ai-seo-content-studio' ), array( 'status' => 400 ) )
			: new \WP_REST_Response( $report );
	}

	/**
	 * Start the AI review (billable).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function evaluateAi( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->start( (int) $request->get_param( 'id' ), 'evaluate', array() );
	}

	/**
	 * Version list.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function listVersions( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( $this->versions->summaries( (int) $request->get_param( 'id' ) ) );
	}

	/**
	 * One version with its assembled HTML (for comparing).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function getVersion( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$version = $this->versions->get( (int) $request->get_param( 'id' ), (string) $request->get_param( 'vid' ) );
		if ( null === $version ) {
			return new \WP_Error( 'aiscs_not_found', __( 'Version not found.', 'ai-seo-content-studio' ), array( 'status' => 404 ) );
		}
		$version['html'] = wp_kses_post( ContentModel::bodyHtml( (array) $version['content'] ) );
		return new \WP_REST_Response( $version );
	}

	/**
	 * Restore a version (the current content is snapshotted first).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function restore( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id      = (int) $request->get_param( 'id' );
		$version = $this->versions->get( $id, (string) $request->get_param( 'vid' ) );
		if ( null === $version ) {
			return new \WP_Error( 'aiscs_not_found', __( 'Version not found.', 'ai-seo-content-studio' ), array( 'status' => 404 ) );
		}
		$current = $this->projects->section( $id, 'content' );
		if ( null !== $current ) {
			$this->versions->snapshot( $id, $current, __( 'Before restore', 'ai-seo-content-studio' ) );
		}
		$this->projects->save( $id, 'content', ContentModel::sanitize( (array) $version['content'], $this->allowedLinks( $id ) ) );
		$this->evaluator->evaluate( $id );
		return new \WP_REST_Response( $this->state( $id ) );
	}

	/**
	 * Save as a WordPress draft (never publishes).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function draft( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id        = (int) $request->get_param( 'id' );
		$post_type = 'page' === $request->get_param( 'post_type' ) ? 'page' : 'post';
		$object    = get_post_type_object( $post_type );
		$existing  = (int) ( $this->projects->section( $id, 'draft' )['post_id'] ?? 0 );
		$as_new    = (bool) $request->get_param( 'as_new' );

		if ( ! $object || ! current_user_can( $object->cap->create_posts ) ) {
			return $this->forbidden();
		}
		if ( ! $as_new && $existing > 0 && get_post( $existing ) && ! current_user_can( 'edit_post', $existing ) ) {
			return $this->forbidden();
		}
		$inputs = $this->projects->section( $id, 'inputs' ) ?? array();
		try {
			$optimize        = 'optimize' === ( $inputs['mode'] ?? '' );
			$elementor_input = $optimize
				// Revisions of Elementor pages become new Elementor drafts (never the original).
				? 'elementor' === ( $this->projects->section( $id, 'optimization' )['format'] ?? '' ) && $this->environment->elementor()['active']
				: 'elementor' === ( $inputs['output'] ?? '' );
			if ( $elementor_input && null !== $this->elementor ) {
				$result = $this->elementor->save(
					$id,
					$post_type,
					$as_new,
					$optimize ? 'content' : (string) ( $inputs['elementor_style'] ?? 'content' ),
					array(
						'text' => (string) ( $inputs['cta_text'] ?? '' ),
						'url'  => (string) ( $inputs['cta_url'] ?? '' ),
					),
					array(
						'accent' => (string) ( $inputs['elementor_accent'] ?? '' ),
						'form'   => (string) ( $inputs['elementor_form'] ?? '' ),
					)
				);
			} else {
				$result = $this->drafts->save( $id, $post_type, $as_new, (string) $this->settings->get( 'seo.draft_format', 'blocks' ) );
			}
		} catch ( ProviderException $e ) {
			return new \WP_Error( 'aiscs_draft_' . $e->category, $e->getMessage(), array( 'status' => ProviderException::LIMIT === $e->category ? 409 : 400 ) );
		}
		return new \WP_REST_Response( $result + array( 'project' => $this->state( $id ) ) );
	}

	/**
	 * Preview payload: sanitised HTML and metadata, plus which plugin controls metadata output.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$content = $this->projects->section( (int) $request->get_param( 'id' ), 'content' );
		if ( null === $content ) {
			return new \WP_Error( 'aiscs_no_content', __( 'Generate content first.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		$seo = $this->environment->seoPlugins();
		return new \WP_REST_Response(
			array(
				'title'       => $content['title'],
				'html'        => wp_kses_post( ContentModel::bodyHtml( $content ) ),
				'meta'        => $content['meta'],
				'word_count'  => ContentModel::wordCount( ContentModel::bodyHtml( $content ) ),
				'meta_owner'  => array() === $seo ? 'aiscs' : $seo[0]['name'],
				'output_meta' => array() === $seo && (bool) $this->settings->get( 'seo.output_meta', true ),
			)
		);
	}

	/**
	 * Start a job.
	 *
	 * @param int                  $id      Project.
	 * @param string               $type    Type.
	 * @param array<string, mixed> $options Options.
	 */
	private function start( int $id, string $type, array $options ): \WP_REST_Response|\WP_Error {
		try {
			$job = $this->jobs->start( $id, $type, get_current_user_id(), $options );
		} catch ( ProviderException $e ) {
			return new \WP_Error( 'aiscs_job_' . $e->category, $e->getMessage(), array( 'status' => 409 ) );
		}
		return new \WP_REST_Response( $this->jobs->publicView( $job ), 202 );
	}

	/**
	 * Links allowed in content: brief internal links, the business's own pages, verified
	 * official sources and the optimizer source's own links.
	 *
	 * @param int $id Project.
	 * @return string[]
	 */
	private function allowedLinks( int $id ): array {
		$brief = $this->projects->section( $id, 'brief' ) ?? array();
		$opt   = $this->projects->section( $id, 'optimization' ) ?? array();
		$own   = ContentJob::allowedLinks( $brief, $this->projects->section( $id, 'content' ), WritingProfile::fromSettings( $this->settings ) );
		return array_values( array_unique( array_merge( $own, (array) ( $opt['links'] ?? array() ) ) ) );
	}

	/**
	 * Content, evaluation and draft state.
	 *
	 * @param int $id Project.
	 * @return array<string, mixed>
	 */
	private function state( int $id ): array {
		return array(
			'content'    => $this->projects->section( $id, 'content' ),
			'evaluation' => $this->projects->section( $id, 'evaluation' ),
			'draft'      => $this->projects->section( $id, 'draft' ),
			'stage'      => (string) get_post_meta( $id, ProjectRepository::STAGE_META, true ),
		);
	}
}
