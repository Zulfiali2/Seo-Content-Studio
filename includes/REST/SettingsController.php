<?php
/**
 * Settings endpoint.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST /aiscs/v1/settings. Contains no secrets.
 */
class SettingsController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings.
	 */
	public function __construct( private readonly SettingsRepository $settings ) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn (): \WP_REST_Response => new \WP_REST_Response( $this->settings->all() ),
					'permission_callback' => array( $this, 'canManage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'canManage' ),
					'args'                => array(
						'settings' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Save a partial update.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function update( \WP_REST_Request $request ): \WP_REST_Response {
		$input = $request->get_param( 'settings' );
		return new \WP_REST_Response( $this->settings->update( is_array( $input ) ? $input : array() ) );
	}
}
