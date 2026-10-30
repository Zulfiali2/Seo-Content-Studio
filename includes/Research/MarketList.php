<?php
/**
 * Market (country/language) options.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research;

use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Research\Contracts\ResearchProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the market picker. With a research provider configured, markets and
 * their provider location codes come from the provider (cached 30 days).
 * Otherwise a short list of country names is offered for AI context only; no
 * provider codes are guessed.
 */
class MarketList {

	/**
	 * Constructor.
	 *
	 * @param ResearchGateway $gateway Gateway.
	 */
	public function __construct( private readonly ResearchGateway $gateway ) {}

	/**
	 * Markets.
	 *
	 * @return array{source: string, markets: array<int, array<string, mixed>>, message: string}
	 */
	public function get(): array {
		if ( $this->gateway->provider()->isConfigured() ) {
			try {
				$result = $this->gateway->call(
					'markets',
					array(),
					fn ( ResearchProviderInterface $p ) => $p->markets(),
					false,
					30 * DAY_IN_SECONDS
				);
				if ( array() !== (array) ( $result['data']['markets'] ?? array() ) ) {
					return array(
						'source'  => $this->gateway->provider()->id(),
						'markets' => (array) $result['data']['markets'],
						'message' => '',
					);
				}
			} catch ( ProviderException $e ) {
				return self::fallback( $e->getMessage() );
			}
		}
		return self::fallback( __( 'No SEO data provider is configured: choose a country for context only. Search data will be unavailable.', 'ai-seo-content-studio' ) );
	}

	/**
	 * Name-only fallback list.
	 *
	 * @param string $message Explanation for the UI.
	 * @return array{source: string, markets: array<int, array<string, mixed>>, message: string}
	 */
	public static function fallback( string $message ): array {
		$countries = array(
			'AU' => 'Australia',
			'CA' => 'Canada',
			'IE' => 'Ireland',
			'IN' => 'India',
			'NZ' => 'New Zealand',
			'SG' => 'Singapore',
			'ZA' => 'South Africa',
			'AE' => 'United Arab Emirates',
			'GB' => 'United Kingdom',
			'US' => 'United States',
		);
		$markets   = array();
		foreach ( $countries as $iso => $name ) {
			$markets[] = array(
				'location_code' => 0,
				'location_name' => $name,
				'country_iso'   => $iso,
				'languages'     => array(
					array(
						'language_code' => 'en',
						'language_name' => 'English',
					),
				),
			);
		}
		return array(
			'source'  => 'fallback',
			'markets' => $markets,
			'message' => $message,
		);
	}
}
