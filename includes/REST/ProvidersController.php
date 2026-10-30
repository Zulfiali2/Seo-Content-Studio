<?php
/**
 * Provider configuration endpoints.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\Providers\AbstractProvider;
use AiSeoContentStudio\Providers\Contracts\Capability;
use AiSeoContentStudio\Providers\Contracts\ProviderInterface;
use AiSeoContentStudio\Providers\ProviderRegistry;
use AiSeoContentStudio\Security\CredentialStore;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\Support\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * Routes:
 * - GET    /providers                    list with masked credential status
 * - POST   /providers/{id}/credentials   store API key (write-only)
 * - DELETE /providers/{id}/credentials   remove API key
 * - POST   /providers/{id}/test          connection test + model list
 */
class ProvidersController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param ProviderRegistry   $providers   Providers.
	 * @param CredentialStore    $credentials Credentials.
	 * @param SettingsRepository $settings    Settings.
	 */
	public function __construct(
		private readonly ProviderRegistry $providers,
		private readonly CredentialStore $credentials,
		private readonly SettingsRepository $settings,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$id_arg = array(
			'id' => array(
				'type'     => 'string',
				'pattern'  => '^[a-z0-9_-]{1,32}$',
				'required' => true,
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/providers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'canManage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/providers/(?P<id>[a-z0-9_-]{1,32})/credentials',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'saveCredentials' ),
					'permission_callback' => array( $this, 'canManage' ),
					'args'                => $id_arg + array(
						'api_key' => array(
							'type'      => 'string',
							'required'  => true,
							'minLength' => 8,
							'maxLength' => 4096,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'deleteCredentials' ),
					'permission_callback' => array( $this, 'canManage' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/providers/(?P<id>[a-z0-9_-]{1,32})/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( $this, 'canManage' ),
				'args'                => $id_arg,
			)
		);
	}

	/**
	 * List providers.
	 */
	public function index(): \WP_REST_Response {
		$out = array();
		foreach ( $this->providers->all() as $provider ) {
			$out[] = $this->describe( $provider );
		}
		return new \WP_REST_Response( $out );
	}

	/**
	 * Store an API key. The key is never echoed back.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function saveCredentials( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$provider = $this->find( $request );
		if ( $provider instanceof \WP_Error ) {
			return $provider;
		}
		try {
			$this->credentials->set( $provider->id(), 'api_key', (string) $request->get_param( 'api_key' ) );
		} catch ( \InvalidArgumentException ) {
			return new \WP_Error( 'aiscs_invalid_key', __( 'The API key format is not valid.', 'ai-seo-content-studio' ), array( 'status' => 400 ) );
		}
		return new \WP_REST_Response( $this->describe( $provider ) );
	}

	/**
	 * Remove an API key.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function deleteCredentials( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$provider = $this->find( $request );
		if ( $provider instanceof \WP_Error ) {
			return $provider;
		}
		$this->credentials->delete( $provider->id(), 'api_key' );
		return new \WP_REST_Response( $this->describe( $provider ) );
	}

	/**
	 * Test a connection. Only one test per provider runs at a time.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function test( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$provider = $this->find( $request );
		if ( $provider instanceof \WP_Error ) {
			return $provider;
		}
		$lock = 'test:' . $provider->id();
		if ( ! Lock::acquire( $lock, 120 ) ) {
			return new \WP_Error( 'aiscs_busy', __( 'A connection test for this provider is already running.', 'ai-seo-content-studio' ), array( 'status' => 409 ) );
		}
		try {
			$result = $provider->testConnection();
		} finally {
			Lock::release( $lock );
		}
		return new \WP_REST_Response( $result->toArray() );
	}

	/**
	 * Browser-safe description of a provider.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return array<string, mixed>
	 */
	private function describe( ProviderInterface $provider ): array {
		return array(
			'id'               => $provider->id(),
			'label'            => $provider->label(),
			'capabilities'     => array_map( static fn ( Capability $c ): string => $c->value, $provider->capabilities() ),
			'configured'       => $provider->isConfigured(),
			'requires_api_key' => $provider instanceof AbstractProvider ? $provider->requiresApiKey() : true,
			'credential'       => $this->credentials->status( $provider->id(), 'api_key' ),
			'settings'         => (array) $this->settings->get( 'ai.providers.' . $provider->id(), array() ),
			'is_default_text'  => $this->settings->get( 'ai.default_text_provider' ) === $provider->id(),
		);
	}

	/**
	 * Resolve the provider from the route.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	private function find( \WP_REST_Request $request ): ProviderInterface|\WP_Error {
		$provider = $this->providers->get( (string) $request->get_param( 'id' ) );
		return $provider ?? new \WP_Error( 'aiscs_unknown_provider', __( 'Unknown provider.', 'ai-seo-content-studio' ), array( 'status' => 404 ) );
	}
}
