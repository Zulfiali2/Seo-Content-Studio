<?php
/**
 * AI Website Studio design library endpoints.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\Support\Sanitize;
use AiSeoContentStudio\WebsiteStudio\Analysis\PageCatalog;
use AiSeoContentStudio\WebsiteStudio\Editor\ElementTree;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftException;
use AiSeoContentStudio\WebsiteStudio\Library\DesignLibrary;
use AiSeoContentStudio\WebsiteStudio\Persistence\BrandProfileRepository;
use AiSeoContentStudio\WebsiteStudio\Persistence\LibraryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Every route requires aiscs_generate_content; item routes require ownership of the item
 * (or the manage capability); saving requires edit permission on the source page, and
 * inserting goes through DraftManager (studio drafts only).
 */
class DesignLibraryController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param LibraryRepository      $items    Items.
	 * @param DesignLibrary          $library  Library service.
	 * @param BrandProfileRepository $profiles Brand profiles.
	 */
	public function __construct(
		private readonly LibraryRepository $items,
		private readonly DesignLibrary $library,
		private readonly BrandProfileRepository $profiles,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$id         = array(
			'id' => array(
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			),
		);
		$categories = array_values( array_unique( array_merge( LibraryRepository::SECTION_CATEGORIES, LibraryRepository::COMPONENT_CATEGORIES ) ) );
		register_rest_route(
			self::NAMESPACE,
			'/design/library',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'canGenerate' ),
					'args'                => array(
						'kind'     => array(
							'type'    => 'string',
							'enum'    => array_merge( array( '' ), LibraryRepository::KINDS ),
							'default' => '',
						),
						'category' => array(
							'type'    => 'string',
							'enum'    => array_merge( array( '' ), $categories ),
							'default' => '',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'canGenerate' ),
					'args'                => array(
						'page_id'    => array(
							'type'     => 'integer',
							'minimum'  => 1,
							'required' => true,
						),
						'element_id' => array(
							'type'     => 'string',
							'pattern'  => '^[a-z0-9]{7,8}$',
							'required' => true,
						),
						'name'       => array(
							'type'      => 'string',
							'maxLength' => 150,
							'default'   => '',
						),
						'category'   => array(
							'type'    => 'string',
							'enum'    => $categories,
							'default' => 'other',
						),
						'profile_id' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/design/library/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'canUseItem' ),
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'edit' ),
					'permission_callback' => array( $this, 'canUseItem' ),
					'args'                => $id + array(
						'name'     => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 150,
						),
						'category' => array(
							'type' => 'string',
							'enum' => $categories,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => array( $this, 'canUseItem' ),
					'args'                => $id,
				),
			)
		);
		foreach ( array( 'duplicate', 'preview' ) as $action ) {
			register_rest_route(
				self::NAMESPACE,
				'/design/library/(?P<id>\d+)/' . $action,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $action ),
					'permission_callback' => array( $this, 'canUseItem' ),
					'args'                => $id,
				)
			);
		}
		register_rest_route(
			self::NAMESPACE,
			'/design/library/(?P<id>\d+)/insert',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'insert' ),
				'permission_callback' => array( $this, 'canUseItem' ),
				'args'                => $id + array(
					'page_id'  => array(
						'type'     => 'integer',
						'minimum'  => 1,
						'required' => true,
					),
					'position' => array(
						'type'    => 'integer',
						'minimum' => -1,
						'default' => -1,
					),
				),
			)
		);
	}

	/**
	 * Permission: an item the user may use.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function canUseItem( \WP_REST_Request $request ): bool|\WP_Error {
		$can = $this->canGenerate();
		if ( true !== $can ) {
			return $can;
		}
		$id = (int) $request->get_param( 'id' );
		if ( null === $this->items->get( $id ) ) {
			return new \WP_Error( 'aiscs_not_found', __( 'Item not found.', 'ai-seo-content-studio' ), array( 'status' => 404 ) );
		}
		return $this->items->canAccess( $id, get_current_user_id() ) ? true : $this->forbidden();
	}

	/**
	 * GET /design/library
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'items' => $this->items->listFor( get_current_user_id(), (string) $request->get_param( 'kind' ), (string) $request->get_param( 'category' ) ) ) );
	}

	/**
	 * POST /design/library: save an element of a page.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$user    = get_current_user_id();
		$page    = (int) $request->get_param( 'page_id' );
		$post    = get_post( $page );
		$profile = (int) $request->get_param( 'profile_id' );
		if ( ! $post || ! in_array( $post->post_type, PageCatalog::POST_TYPES, true ) || ! current_user_can( 'edit_post', $page ) ) {
			return $this->forbidden();
		}
		if ( $profile > 0 && ! $this->profiles->canAccess( $profile, $user ) ) {
			return $this->forbidden();
		}
		try {
			$item = $this->library->save( $user, $page, (string) $request->get_param( 'element_id' ), Sanitize::text( $request->get_param( 'name' ), 150 ), (string) $request->get_param( 'category' ), $profile );
			return new \WP_REST_Response( self::view( $item ), 201 );
		} catch ( DraftException $e ) {
			return PageToolsController::draftError( $e );
		}
	}

	/**
	 * GET /design/library/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( self::view( (array) $this->items->get( (int) $request->get_param( 'id' ) ) ) );
	}

	/**
	 * PUT /design/library/{id}: rename or recategorise.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function edit( \WP_REST_Request $request ): \WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$fields = array();
		if ( null !== $request->get_param( 'name' ) ) {
			$fields['name'] = Sanitize::text( $request->get_param( 'name' ), 150 );
		}
		if ( null !== $request->get_param( 'category' ) ) {
			$fields['category'] = (string) $request->get_param( 'category' );
		}
		$this->items->update( $id, $fields );
		return new \WP_REST_Response( self::view( (array) $this->items->get( $id ) ) );
	}

	/**
	 * DELETE /design/library/{id}
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function remove( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'deleted' => $this->items->delete( (int) $request->get_param( 'id' ) ) ) );
	}

	/**
	 * POST /design/library/{id}/duplicate
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function duplicate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( self::view( $this->library->duplicate( (int) $request->get_param( 'id' ), get_current_user_id() ) ), 201 );
		} catch ( DraftException $e ) {
			return PageToolsController::draftError( $e );
		}
	}

	/**
	 * POST /design/library/{id}/preview
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response( $this->library->preview( (int) $request->get_param( 'id' ), get_current_user_id() ) );
		} catch ( DraftException $e ) {
			return PageToolsController::draftError( $e );
		}
	}

	/**
	 * POST /design/library/{id}/insert
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function insert( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$page = (int) $request->get_param( 'page_id' );
		if ( ! current_user_can( 'edit_post', $page ) ) {
			return $this->forbidden();
		}
		try {
			return new \WP_REST_Response( $this->library->insert( (int) $request->get_param( 'id' ), $page, (int) $request->get_param( 'position' ), get_current_user_id() ) );
		} catch ( DraftException $e ) {
			return PageToolsController::draftError( $e );
		}
	}

	/**
	 * Item view: no raw tree, an outline instead.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return array<string, mixed>
	 */
	private static function view( array $item ): array {
		$elements = (array) ( $item['elements'] ?? array() );
		unset( $item['elements'] );
		$item['outline'] = array_slice( array_map( static fn ( array $row ): array => array( 'type' => (string) ( $row['element']['widgetType'] ?? $row['element']['elType'] ?? '' ), 'depth' => count( $row['parents'] ) ), ElementTree::flatten( $elements ) ), 0, 200 );
		return $item;
	}
}
