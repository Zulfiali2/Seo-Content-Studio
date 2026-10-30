<?php
/**
 * Usage and cost reporting endpoint.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\Usage\UsageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * GET /aiscs/v1/usage?days=30
 */
class UsageController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param UsageRepository $usage Usage log.
	 */
	public function __construct( private readonly UsageRepository $usage ) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/usage',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'canManage' ),
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 366,
						'default' => 30,
					),
				),
			)
		);
	}

	/**
	 * Summary and recent entries.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$days = (int) $request->get_param( 'days' );
		return new \WP_REST_Response(
			array(
				'days'    => $days,
				'summary' => $this->usage->summary( $days ),
				'recent'  => $this->usage->recent( 50 ),
				'note'    => __( 'Costs are estimates calculated from the prices you entered. Your provider invoice is authoritative.', 'ai-seo-content-studio' ),
			)
		);
	}
}
