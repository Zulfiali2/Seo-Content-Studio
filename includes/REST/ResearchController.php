<?php
/**
 * SEO research provider endpoints.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\Research\Contracts\ResearchProviderInterface;
use AiSeoContentStudio\Research\MarketList;
use AiSeoContentStudio\Research\ResearchGateway;
use AiSeoContentStudio\Security\CredentialStore;
use AiSeoContentStudio\Support\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * Routes:
 * - GET    /research/provider              status (masked)            manage
 * - POST   /research/provider/credentials  store login + password     manage
 * - DELETE /research/provider/credentials  remove                      manage
 * - POST   /research/provider/test         account check               manage
 * - GET    /research/markets               market picker options       generate
 */
class ResearchController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param ResearchGateway           $gateway     Gateway (active research source).
	 * @param ResearchProviderInterface $dataforseo  DataForSEO adapter (credentials and test).
	 * @param CredentialStore           $credentials Credentials.
	 * @param MarketList                $markets     Markets.
	 */
	public function __construct(
		private readonly ResearchGateway $gateway,
		private readonly ResearchProviderInterface $dataforseo,
		private readonly CredentialStore $credentials,
		private readonly MarketList $markets,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/research/provider',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn (): \WP_REST_Response => new \WP_REST_Response( $this->describe() ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/research/provider/credentials',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveCredentials' ),
					'permission_callback' => array( $this, 'canManage' ),
					'args'                => array(
						'login'    => array(
							'type'      => 'string',
							'required'  => true,
							'minLength' => 3,
							'maxLength' => 200,
						),
						'password' => array(
							'type'      => 'string',
							'required'  => true,
							'minLength' => 6,
							'maxLength' => 500,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'deleteCredentials' ),
					'permission_callback' => array( $this, 'canManage' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/research/provider/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/research/markets',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn (): \WP_REST_Response => new \WP_REST_Response( $this->markets->get() ),
				'permission_callback' => array( $this, 'canGenerate' ),
			)
		);
	}

	/**
	 * Store credentials (never echoed).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function saveCredentials( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = $this->dataforseo->id();
		try {
			$this->credentials->set( $id, 'login', (string) $request->get_param( 'login' ) );
			$this->credentials->set( $id, 'password', (string) $request->get_param( 'password' ) );
		} catch ( \InvalidArgumentException ) {
			return new \WP_Error( 'aiscs_invalid_credentials', __( 'The login or password format is not valid.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		return new \WP_REST_Response( $this->describe() );
	}

	/**
	 * Remove credentials.
	 */
	public function deleteCredentials(): \WP_REST_Response {
		$id = $this->dataforseo->id();
		$this->credentials->delete( $id, 'login' );
		$this->credentials->delete( $id, 'password' );
		return new \WP_REST_Response( $this->describe() );
	}

	/**
	 * Connection test.
	 */
	public function test(): \WP_REST_Response|\WP_Error {
		if ( ! Lock::acquire( 'test:research', 120 ) ) {
			return new \WP_Error( 'aiscs_busy', __( 'A connection test is already running.', 'ai-seo-content-studio' ), array( 'status' => 409 ) );
		}
		try {
			$result = $this->dataforseo->testConnection();
		} finally {
			Lock::release( 'test:research' );
		}
		return new \WP_REST_Response( $result->toArray() );
	}

	/**
	 * Browser-safe status. The login is shown as a hint only.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(): array {
		$provider = $this->dataforseo;
		$login    = $this->credentials->get( $provider->id(), 'login' );
		return array(
			'active'     => $this->gateway->provider()->id(),
			'id'         => $provider->id(),
			'label'      => $provider->label(),
			'configured' => $provider->isConfigured(),
			'login_hint' => null === $login ? '' : mb_substr( $login, 0, 2 ) . '…' . ( str_contains( $login, '@' ) ? strstr( $login, '@' ) : '' ),
			'password'   => $this->credentials->status( $provider->id(), 'password' ),
		);
	}
}
