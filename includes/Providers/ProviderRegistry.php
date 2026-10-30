<?php
/**
 * Provider discovery.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers;

use AiSeoContentStudio\Http\HttpClient;
use AiSeoContentStudio\Providers\Anthropic\AnthropicProvider;
use AiSeoContentStudio\Providers\Contracts\ProviderInterface;
use AiSeoContentStudio\Providers\Custom\OpenAICompatibleProvider;
use AiSeoContentStudio\Providers\Gemini\GeminiProvider;
use AiSeoContentStudio\Providers\OpenAI\OpenAIProvider;
use AiSeoContentStudio\Providers\XAI\GrokProvider;
use AiSeoContentStudio\Security\CredentialStore;
use AiSeoContentStudio\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Holds provider adapters. Third parties can add adapters with the
 * `aiscs_providers` filter (array of ProviderInterface keyed by id).
 */
class ProviderRegistry {

	/**
	 * Lazily built adapters.
	 *
	 * @var array<string, ProviderInterface>|null
	 */
	private ?array $providers = null;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings    Settings.
	 * @param CredentialStore    $credentials Credentials.
	 * @param HttpClient         $http        HTTP client.
	 */
	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly CredentialStore $credentials,
		private readonly HttpClient $http,
	) {}

	/**
	 * All providers keyed by id.
	 *
	 * @return array<string, ProviderInterface>
	 */
	public function all(): array {
		if ( null === $this->providers ) {
			$built = array();
			foreach ( array( OpenAIProvider::class, AnthropicProvider::class, GeminiProvider::class, GrokProvider::class, OpenAICompatibleProvider::class ) as $class ) {
				$provider                 = new $class( $this->settings, $this->credentials, $this->http );
				$built[ $provider->id() ] = $provider;
			}
			/**
			 * Filter the available AI providers.
			 *
			 * @param array<string, ProviderInterface> $built Providers keyed by id.
			 */
			$filtered        = apply_filters( 'aiscs_providers', $built );
			$this->providers = array();
			foreach ( (array) $filtered as $id => $provider ) {
				if ( $provider instanceof ProviderInterface && $provider->id() === $id ) {
					$this->providers[ $id ] = $provider;
				}
			}
		}
		return $this->providers;
	}

	/**
	 * Get one provider.
	 *
	 * @param string $id Provider id.
	 */
	public function get( string $id ): ?ProviderInterface {
		return $this->all()[ $id ] ?? null;
	}
}
