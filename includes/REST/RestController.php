<?php
/**
 * Base REST controller.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Shared namespace and permission callbacks.
 *
 * Cookie-authenticated requests are additionally protected by core's wp_rest
 * nonce check; capabilities are always checked here regardless of auth method.
 */
abstract class RestController {

	public const NAMESPACE = 'aiscs/v1';

	/**
	 * Register routes.
	 */
	abstract public function register(): void;

	/**
	 * Permission callback for settings/credentials.
	 */
	public function canManage(): bool|\WP_Error {
		return current_user_can( Capabilities::MANAGE ) ? true : $this->forbidden();
	}

	/**
	 * Permission callback for content operations.
	 */
	public function canGenerate(): bool|\WP_Error {
		return current_user_can( Capabilities::GENERATE ) ? true : $this->forbidden();
	}

	/**
	 * Standard 401/403 error.
	 */
	protected function forbidden(): \WP_Error {
		return new \WP_Error(
			'aiscs_forbidden',
			__( 'You are not allowed to do this.', 'ai-seo-content-studio' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
