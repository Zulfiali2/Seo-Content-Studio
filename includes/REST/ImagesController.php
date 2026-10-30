<?php
/**
 * Project image routes.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\Jobs\JobRunner;
use AiSeoContentStudio\Media\ImagePlan;
use AiSeoContentStudio\Media\MediaImporter;
use AiSeoContentStudio\Media\PlaceholderImage;
use AiSeoContentStudio\Media\StockImages;
use AiSeoContentStudio\Elementor\BlogTemplates;
use AiSeoContentStudio\Security\CredentialStore;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Providers\ImageGenerationService;

defined( 'ABSPATH' ) || exit;

/**
 * GET/PUT /projects/{id}/images, POST …/images/generate, POST …/images/{slot}/discard,
 * GET …/images/stock (search), POST …/images/{slot}/stock, POST …/images/{slot}/placeholder,
 * and POST/DELETE /stock-sources/{id}/key (settings managers only).
 * Access uses the same project ownership check as the other project routes; generating,
 * discarding, stock search/use and placeholders also require the WordPress `upload_files` capability.
 */
class ImagesController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param ProjectsController     $projectsController Access checks.
	 * @param ProjectRepository      $projects           Projects.
	 * @param JobRunner              $jobs               Jobs.
	 * @param MediaImporter          $media              Media.
	 * @param ImageGenerationService $images             Image service.
	 * @param StockImages            $stock              Stock photos and placeholders.
	 * @param CredentialStore        $credentials        Stock library keys.
	 */
	public function __construct(
		private readonly ProjectsController $projectsController,
		private readonly ProjectRepository $projects,
		private readonly JobRunner $jobs,
		private readonly MediaImporter $media,
		private readonly ImageGenerationService $images,
		private readonly StockImages $stock,
		private readonly CredentialStore $credentials,
	) {}

	/**
	 * Routes.
	 */
	public function register(): void {
		$routes = array(
			'/images'                                   => array(
				array( \WP_REST_Server::READABLE, 'index' ),
				array( \WP_REST_Server::EDITABLE, 'update' ),
			),
			'/images/generate'                          => array( array( \WP_REST_Server::CREATABLE, 'generate' ) ),
			'/images/suggestions'                       => array( array( \WP_REST_Server::CREATABLE, 'resetSuggestions' ) ),
			'/images/(?P<slot>[a-z0-9-]{1,20})/discard' => array( array( \WP_REST_Server::CREATABLE, 'discard' ) ),
			'/images/stock'                             => array( array( \WP_REST_Server::READABLE, 'stockSearch' ) ),
			'/images/(?P<slot>[a-z0-9-]{1,20})/stock'   => array( array( \WP_REST_Server::CREATABLE, 'stockUse' ) ),
			'/images/(?P<slot>[a-z0-9-]{1,20})/placeholder' => array( array( \WP_REST_Server::CREATABLE, 'placeholder' ) ),
		);
		register_rest_route(
			self::NAMESPACE,
			'/stock-sources',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn (): \WP_REST_Response => new \WP_REST_Response( $this->stock->describe() ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/stock-sources/(?P<source>pexels|pixabay)/key',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveKey' ),
					'permission_callback' => array( $this, 'canManage' ),
					'args'                => array(
						'api_key' => array(
							'type'      => 'string',
							'required'  => true,
							'minLength' => 8,
							'maxLength' => 200,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'deleteKey' ),
					'permission_callback' => array( $this, 'canManage' ),
				),
			)
		);
		foreach ( $routes as $suffix => $endpoints ) {
			register_rest_route(
				self::NAMESPACE,
				'/projects/(?P<id>\d+)' . $suffix,
				array_map(
					fn ( array $e ): array => array(
						'methods'             => $e[0],
						'callback'            => array( $this, $e[1] ),
						'permission_callback' => array( $this->projectsController, 'canAccessProject' ),
					),
					$endpoints
				)
			);
		}
	}

	/**
	 * Current plan (created from the content's suggestions on first use).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id   = (int) $request->get_param( 'id' );
		$plan = $this->plan( $id );
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}
		return new \WP_REST_Response( $this->view( $id, $plan ) );
	}

	/**
	 * Save prompts, alt text, captions, aspect, "use" flags and inline placements.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function update( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id      = (int) $request->get_param( 'id' );
		$current = $this->plan( $id );
		if ( $current instanceof \WP_Error ) {
			return $current;
		}
		$content = (array) $this->projects->section( $id, 'content' );
		$plan    = ImagePlan::sanitize( array( 'slots' => (array) $request->get_param( 'slots' ) ), $current, $content );

		// Removed slots: delete their image only if the plugin created it and nothing uses it.
		$kept = array_column( $plan['slots'], 'attachment_id' );
		foreach ( $current['slots'] as $old ) {
			if ( (int) $old['attachment_id'] > 0 && ! in_array( (int) $old['attachment_id'], $kept, true ) ) {
				$this->media->deleteUnused( (int) $old['attachment_id'] );
			}
		}
		// Alt text and captions also update the Media Library copy.
		foreach ( $plan['slots'] as $slot ) {
			if ( (int) $slot['attachment_id'] > 0 ) {
				$this->media->updateText( (int) $slot['attachment_id'], (string) $slot['alt'], (string) $slot['caption'] );
			}
		}
		$this->projects->save( $id, 'images', $plan );
		return new \WP_REST_Response( $this->view( $id, $plan ) );
	}

	/**
	 * Start the image job for the listed slots.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function generate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->forbidden();
		}
		$id = (int) $request->get_param( 'id' );
		if ( null === $this->images->provider() ) {
			return new \WP_Error( 'aiscs_no_image_provider', __( 'Choose a default image provider under AI Providers first.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		$slots = array_values( array_filter( (array) $request->get_param( 'slots' ), 'is_string' ) );
		try {
			$job = $this->jobs->start( $id, 'images', get_current_user_id(), array( 'slots' => $slots ) );
		} catch ( ProviderException $e ) {
			return new \WP_Error( 'aiscs_job_' . $e->category, $e->getMessage(), array( 'status' => ProviderException::INVALID_REQUEST === $e->category ? 400 : 409 ) );
		}
		return new \WP_REST_Response( $this->jobs->publicView( $job ), 202 );
	}

	/**
	 * Rebuild the slots from the current content suggestions (keeps nothing generated).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function resetSuggestions( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id      = (int) $request->get_param( 'id' );
		$content = $this->projects->section( $id, 'content' );
		if ( null === $content ) {
			return new \WP_Error( 'aiscs_no_content', __( 'Generate content first.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		$current = $this->projects->section( $id, 'images' ) ?? array( 'slots' => array() );
		foreach ( (array) $current['slots'] as $old ) {
			if ( (int) $old['attachment_id'] > 0 ) {
				$this->media->deleteUnused( (int) $old['attachment_id'] );
			}
		}
		$plan = ImagePlan::fromContent( $content );
		$this->projects->save( $id, 'images', $plan );
		return new \WP_REST_Response( $this->view( $id, $plan ) );
	}

	/**
	 * Remove a slot's generated image (deleted from the Media Library only if unused and plugin-made).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function discard( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->forbidden();
		}
		$id   = (int) $request->get_param( 'id' );
		$plan = $this->plan( $id );
		if ( $plan instanceof \WP_Error ) {
			return $plan;
		}
		$deleted = false;
		foreach ( $plan['slots'] as $i => $slot ) {
			if ( $slot['id'] === (string) $request->get_param( 'slot' ) && (int) $slot['attachment_id'] > 0 ) {
				$deleted             = $this->media->deleteUnused( (int) $slot['attachment_id'] );
				$plan['slots'][ $i ] = ImagePlan::slot(
					array_merge(
						$slot,
						array(
							'attachment_id'  => 0,
							'url'            => '',
							'revised_prompt' => '',
						)
					)
				);
			}
		}
		$this->projects->save( $id, 'images', $plan );
		return new \WP_REST_Response( $this->view( $id, $plan ) + array( 'deleted' => $deleted ) );
	}

	/**
	 * Load (or create) the plan.
	 *
	 * @param int $id Project.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function plan( int $id ): array|\WP_Error {
		$plan = $this->projects->section( $id, 'images' );
		if ( null !== $plan ) {
			return $plan;
		}
		$content = $this->projects->section( $id, 'content' );
		if ( null === $content ) {
			return new \WP_Error( 'aiscs_no_content', __( 'Generate content first.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		$plan = ImagePlan::fromContent( $content );
		$this->projects->save( $id, 'images', $plan );
		return $plan;
	}

	/**
	 * Response: plan, sections for placement, provider status and the running job.
	 *
	 * @param int                  $id   Project.
	 * @param array<string, mixed> $plan Plan.
	 * @return array<string, mixed>
	 */
	private function view( int $id, array $plan ): array {
		$content  = (array) $this->projects->section( $id, 'content' );
		$provider = $this->images->provider();
		return array(
			'slots'                 => array_map(
				static function ( array $slot ): array {
					// Refresh the URL in case sizes were regenerated or the file was removed.
					$exists      = (int) $slot['attachment_id'] > 0 && 'attachment' === get_post_type( (int) $slot['attachment_id'] );
					$slot['url'] = $exists ? (string) wp_get_attachment_image_url( (int) $slot['attachment_id'], 'large' ) : '';
					if ( ! $exists ) {
						$slot['attachment_id'] = 0;
					}
					return $slot;
				},
				(array) $plan['slots']
			),
			'sections'              => array_map(
				static fn ( array $s ): array => array(
					'id'      => (string) $s['id'],
					'heading' => (string) $s['heading'],
				),
				(array) ( $content['sections'] ?? array() )
			),
			'provider'              => $provider ? array(
				'id'    => $provider->id(),
				'label' => $provider->label(),
				'model' => $provider->defaultImageModel(),
			) : null,
			'can_upload'            => current_user_can( 'upload_files' ),
			'stock'                 => $this->stock->describe(),
			'placeholder_available' => PlaceholderImage::available(),
			'job'                   => $this->jobs->publicView( $this->projects->section( $id, 'job' ) ),
		);
	}

	/**
	 * Search a stock library: ?source=&q=&aspect=.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function stockSearch( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Searching uses the site's stock library quota and only leads to an upload.
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->forbidden();
		}
		try {
			$results = $this->stock->search( (string) $request->get_param( 'source' ), (string) $request->get_param( 'q' ), ImagePlan::slot( array( 'aspect' => (string) $request->get_param( 'aspect' ) ) )['aspect'] );
		} catch ( ProviderException $e ) {
			return $this->error( $e );
		}
		return new \WP_REST_Response( array( 'results' => $results ) );
	}

	/**
	 * Use a stock image in a slot: { source, image_id }.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function stockUse( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->forbidden();
		}
		$id = (int) $request->get_param( 'id' );
		try {
			$this->stock->useStock( $id, (string) $request->get_param( 'slot' ), (string) $request->get_param( 'source' ), (string) $request->get_param( 'image_id' ) );
		} catch ( ProviderException $e ) {
			return $this->error( $e );
		}
		return new \WP_REST_Response( $this->view( $id, (array) $this->projects->section( $id, 'images' ) ) );
	}

	/**
	 * Create a placeholder for a slot (in the project's Elementor accent colour when set).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function placeholder( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->forbidden();
		}
		$id     = (int) $request->get_param( 'id' );
		$inputs = (array) $this->projects->section( $id, 'inputs' );
		try {
			$this->stock->usePlaceholder( $id, (string) $request->get_param( 'slot' ), BlogTemplates::accent( $inputs['elementor_accent'] ?? '' ) );
		} catch ( ProviderException $e ) {
			return $this->error( $e );
		}
		return new \WP_REST_Response( $this->view( $id, (array) $this->projects->section( $id, 'images' ) ) );
	}

	/**
	 * Save a stock library API key (never echoed back).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function saveKey( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$this->credentials->set( (string) $request->get_param( 'source' ), 'api_key', trim( (string) $request->get_param( 'api_key' ) ) );
		} catch ( \InvalidArgumentException ) {
			return new \WP_Error( 'aiscs_invalid_key', __( 'The API key format is not valid.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		return new \WP_REST_Response( $this->stock->describe() );
	}

	/**
	 * Remove a stock library API key.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function deleteKey( \WP_REST_Request $request ): \WP_REST_Response {
		$this->credentials->delete( (string) $request->get_param( 'source' ), 'api_key' );
		return new \WP_REST_Response( $this->stock->describe() );
	}

	/**
	 * Provider error as a REST error.
	 *
	 * @param ProviderException $e Error.
	 */
	private function error( ProviderException $e ): \WP_Error {
		$status = match ( $e->category ) {
			ProviderException::CONFIG, ProviderException::INVALID_REQUEST => 400,
			ProviderException::NOT_FOUND => 404,
			ProviderException::PERMISSION => 403,
			default => 502,
		};
		return new \WP_Error( 'aiscs_stock_' . $e->category, $e->getMessage(), array( 'status' => $status ) );
	}
}
