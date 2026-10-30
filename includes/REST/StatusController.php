<?php
/**
 * Diagnostics endpoint.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\REST;

use AiSeoContentStudio\Core\Capabilities;
use AiSeoContentStudio\Core\Environment;
use AiSeoContentStudio\Providers\ProviderRegistry;
use AiSeoContentStudio\Providers\Contracts\Capability;
use AiSeoContentStudio\Security\CredentialStore;
use AiSeoContentStudio\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * GET /aiscs/v1/status — environment and integration status (no secrets).
 */
class StatusController extends RestController {

	/**
	 * Constructor.
	 *
	 * @param Environment             $environment Environment probe.
	 * @param ProviderRegistry        $providers   Providers.
	 * @param CredentialStore         $credentials Credentials.
	 * @param SettingsRepository|null $settings Settings.
	 */
	public function __construct(
		private readonly Environment $environment,
		private readonly ProviderRegistry $providers,
		private readonly CredentialStore $credentials,
		private readonly ?SettingsRepository $settings = null,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'canGenerate' ),
			)
		);
	}

	/**
	 * Status payload.
	 */
	public function index(): \WP_REST_Response {
		$providers  = array();
		$unreadable = false;
		foreach ( $this->providers->all() as $provider ) {
			$status      = $this->credentials->status( $provider->id(), 'api_key' );
			$unreadable  = $unreadable || ( $status['stored'] && ! $status['readable'] );
			$providers[] = array(
				'id'         => $provider->id(),
				'label'      => $provider->label(),
				'configured' => $provider->isConfigured(),
			);
		}

		$data = array(
			'providers'              => $providers,
			'credentials_unreadable' => $unreadable,
			'can_manage'             => current_user_can( Capabilities::MANAGE ),
			'setup'                  => $this->setup(),
		);
		// Server details are only useful (and only shown) to administrators.
		$data['environment'] = current_user_can( Capabilities::MANAGE )
			? $this->environment->diagnostics()
			: array( 'elementor' => $this->environment->elementor() );

		return new \WP_REST_Response( $data );
	}

	/**
	 * What is ready for a real run: writing AI, image AI, search data.
	 *
	 * @return array<string, mixed>
	 */
	private function setup(): array {
		$check = function ( string $field, string $model_field ): array {
			$id       = null === $this->settings ? '' : (string) $this->settings->get( 'ai.' . $field, '' );
			$provider = '' === $id ? null : $this->providers->get( $id );
			$model    = null === $this->settings ? '' : (string) $this->settings->get( 'ai.providers.' . $id . '.' . $model_field, '' );
			$ok       = null !== $provider && $provider->isConfigured() && '' !== $model
				&& ( 'image_model' !== $model_field || $provider->supports( Capability::IMAGE ) );
			return array(
				'provider' => $id,
				'label'    => $provider ? $provider->label() : '',
				'model'    => $model,
				'ready'    => $ok,
			);
		};
		return array(
			'text'     => $check( 'default_text_provider', 'text_model' ),
			'image'    => $check( 'default_image_provider', 'image_model' ),
			'research' => $this->credentials->has( 'dataforseo', 'login' ) && $this->credentials->has( 'dataforseo', 'password' ),
			'brand'    => null !== $this->settings && '' !== trim( (string) $this->settings->get( 'business.name', '' ) ),
		);
	}
}
