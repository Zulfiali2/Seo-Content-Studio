<?php
/**
 * DataForSEO research adapter.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research\DataForSEO;

use AiSeoContentStudio\Http\HttpClient;
use AiSeoContentStudio\Providers\Contracts\ConnectionResult;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Research\Contracts\Market;
use AiSeoContentStudio\Research\Contracts\ProviderCost;
use AiSeoContentStudio\Research\Contracts\ResearchProviderInterface;
use AiSeoContentStudio\Research\Contracts\ResearchResult;
use AiSeoContentStudio\Security\CredentialStore;
use AiSeoContentStudio\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * DataForSEO API v3 (verified against the official dataforseo-client 2.1.9 models):
 *
 * - GET  /v3/appendix/user_data                          account check
 * - GET  /v3/dataforseo_labs/locations_and_languages      markets
 * - POST /v3/serp/google/organic/live/advanced           SERP
 * - POST /v3/dataforseo_labs/google/keyword_overview/live keyword metrics
 * - POST /v3/dataforseo_labs/google/related_keywords/live related keywords
 * - POST /v3/dataforseo_labs/google/ranked_keywords/live  keywords a competitor page ranks for
 *
 * Auth is HTTP Basic with the API login/password. Errors are reported in the
 * response envelope (status_code 20000 = OK) even when HTTP status is 200.
 */
class DataForSeoProvider implements ResearchProviderInterface {

	public const LIVE_URL    = 'https://api.dataforseo.com/v3';
	public const SANDBOX_URL = 'https://sandbox.dataforseo.com/v3';

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
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'dataforseo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'DataForSEO';
	}

	/**
	 * {@inheritDoc}
	 */
	public function isConfigured(): bool {
		return $this->credentials->has( $this->id(), 'login' ) && $this->credentials->has( $this->id(), 'password' );
	}

	/**
	 * Whether the free sandbox (dummy data) is used.
	 */
	public function usesSandbox(): bool {
		return (bool) $this->settings->get( 'research.use_sandbox', false );
	}

	/**
	 * {@inheritDoc}
	 */
	public function testConnection(): ConnectionResult {
		if ( ! $this->isConfigured() ) {
			return new ConnectionResult( ConnectionResult::ERROR, __( 'Add the DataForSEO API login and password first.', 'ai-seo-content-studio' ), array(), ProviderException::CONFIG );
		}
		try {
			$result = $this->firstResult( $this->call( 'GET', '/appendix/user_data' ) );
		} catch ( ProviderException $e ) {
			return ConnectionResult::fromException( $e );
		}
		$balance = $result['money']['balance'] ?? null;
		$message = is_numeric( $balance )
			/* translators: %s: account balance in USD. */
			? sprintf( __( 'Connected. Account balance reported by DataForSEO: $%s.', 'ai-seo-content-studio' ), number_format( (float) $balance, 2 ) )
			: __( 'Connected.', 'ai-seo-content-studio' );
		if ( $this->usesSandbox() ) {
			$message .= ' ' . __( 'Sandbox mode: research returns dummy data.', 'ai-seo-content-studio' );
		}
		return new ConnectionResult( ConnectionResult::OK, $message );
	}

	/**
	 * {@inheritDoc}
	 */
	public function markets(): ResearchResult {
		$json    = $this->call( 'GET', '/dataforseo_labs/locations_and_languages' );
		$markets = array();
		foreach ( $this->results( $json ) as $row ) {
			if ( ! is_array( $row ) || 'Country' !== ( $row['location_type'] ?? '' ) || ! is_numeric( $row['location_code'] ?? null ) ) {
				continue;
			}
			$languages = array();
			foreach ( (array) ( $row['available_languages'] ?? array() ) as $lang ) {
				if ( is_array( $lang ) && is_string( $lang['language_code'] ?? null ) ) {
					$languages[] = array(
						'language_code' => $lang['language_code'],
						'language_name' => (string) ( $lang['language_name'] ?? $lang['language_code'] ),
					);
				}
			}
			$markets[] = array(
				'location_code' => (int) $row['location_code'],
				'location_name' => (string) ( $row['location_name'] ?? '' ),
				'country_iso'   => (string) ( $row['country_iso_code'] ?? '' ),
				'languages'     => $languages,
			);
		}
		usort( $markets, static fn ( array $a, array $b ): int => strcmp( $a['location_name'], $b['location_name'] ) );
		return new ResearchResult( array( 'markets' => $markets ), $this->cost( $json ), 'dataforseo_labs/locations_and_languages' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $keyword Keyword.
	 * @param Market $market  Market.
	 * @param int    $depth   Results depth.
	 */
	public function serp( string $keyword, Market $market, int $depth = 10 ): ResearchResult {
		$json   = $this->call(
			'POST',
			'/serp/google/organic/live/advanced',
			array(
				$this->marketTask( $market ) + array(
					'keyword' => $keyword,
					'depth'   => max( 10, min( 100, $depth ) ),
				),
			)
		);
		$result = $this->firstResult( $json );

		$organic   = array();
		$questions = array();
		$related   = array();
		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			switch ( $item['type'] ?? '' ) {
				case 'organic':
					if ( ! is_string( $item['url'] ?? null ) ) {
						break;
					}
					$organic[] = array(
						'rank'          => (int) ( $item['rank_group'] ?? 0 ),
						'rank_absolute' => (int) ( $item['rank_absolute'] ?? 0 ),
						'url'           => esc_url_raw( $item['url'] ),
						'domain'        => sanitize_text_field( (string) ( $item['domain'] ?? '' ) ),
						'title'         => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
						'description'   => sanitize_text_field( (string) ( $item['description'] ?? '' ) ),
					);
					break;
				case 'people_also_ask':
					foreach ( (array) ( $item['items'] ?? array() ) as $question ) {
						if ( is_array( $question ) && is_string( $question['title'] ?? null ) ) {
							$questions[] = sanitize_text_field( $question['title'] );
						}
					}
					break;
				case 'related_searches':
				case 'people_also_search':
					foreach ( (array) ( $item['items'] ?? array() ) as $term ) {
						if ( is_string( $term ) ) {
							$related[] = sanitize_text_field( $term );
						}
					}
					break;
			}
		}

		return new ResearchResult(
			array(
				'organic'          => $organic,
				'questions'        => array_values( array_unique( $questions ) ),
				'related_searches' => array_values( array_unique( $related ) ),
				'item_types'       => array_values( array_filter( (array) ( $result['item_types'] ?? array() ), 'is_string' ) ),
				'se_results_count' => isset( $result['se_results_count'] ) ? (int) $result['se_results_count'] : null,
				'check_url'        => esc_url_raw( (string) ( $result['check_url'] ?? '' ) ),
				'datetime'         => sanitize_text_field( (string) ( $result['datetime'] ?? '' ) ),
			),
			$this->cost( $json ),
			'serp/google/organic/live/advanced'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string[] $keywords Keywords.
	 * @param Market   $market   Market.
	 */
	public function keywordMetrics( array $keywords, Market $market ): ResearchResult {
		$keywords = array_values( array_unique( array_filter( array_map( 'trim', $keywords ) ) ) );
		if ( array() === $keywords ) {
			return new ResearchResult( array( 'keywords' => array() ), new ProviderCost( 0.0 ), 'dataforseo_labs/google/keyword_overview/live' );
		}
		$json   = $this->call( 'POST', '/dataforseo_labs/google/keyword_overview/live', array( $this->marketTask( $market ) + array( 'keywords' => array_slice( $keywords, 0, 700 ) ) ) );
		$result = $this->firstResult( $json );
		$out    = array();
		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			$metric = is_array( $item ) ? self::metric( $item ) : null;
			if ( null !== $metric ) {
				$out[ strtolower( $metric['keyword'] ) ] = $metric;
			}
		}
		return new ResearchResult( array( 'keywords' => $out ), $this->cost( $json ), 'dataforseo_labs/google/keyword_overview/live' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $keyword Seed keyword.
	 * @param Market $market  Market.
	 * @param int    $limit   Maximum keywords.
	 */
	public function relatedKeywords( string $keyword, Market $market, int $limit = 30 ): ResearchResult {
		$json   = $this->call(
			'POST',
			'/dataforseo_labs/google/related_keywords/live',
			array(
				$this->marketTask( $market ) + array(
					'keyword' => $keyword,
					'depth'   => 2,
					'limit'   => max( 1, min( 1000, $limit ) ),
				),
			)
		);
		$result = $this->firstResult( $json );
		$out    = array();
		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			$metric = is_array( $item['keyword_data'] ?? null ) ? self::metric( $item['keyword_data'] ) : null;
			if ( null !== $metric && strtolower( $metric['keyword'] ) !== strtolower( $keyword ) ) {
				$out[] = $metric;
			}
		}
		return new ResearchResult( array( 'keywords' => $out ), $this->cost( $json ), 'dataforseo_labs/google/related_keywords/live' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * The page URL is sent with https:// so DataForSEO returns that page, not the whole domain.
	 *
	 * @param string $url    Page URL.
	 * @param Market $market Market.
	 * @param int    $limit  Maximum keywords.
	 */
	public function rankedKeywords( string $url, Market $market, int $limit = 10 ): ResearchResult {
		$json   = $this->call(
			'POST',
			'/dataforseo_labs/google/ranked_keywords/live',
			array(
				$this->marketTask( $market ) + array(
					'target'     => $url,
					'item_types' => array( 'organic' ),
					'limit'      => max( 1, min( 100, $limit ) ),
					'order_by'   => array( 'keyword_data.keyword_info.search_volume,desc' ),
				),
			)
		);
		$result = $this->firstResult( $json );
		$out    = array();
		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			$metric = is_array( $item['keyword_data'] ?? null ) ? self::metric( $item['keyword_data'] ) : null;
			if ( null === $metric ) {
				continue;
			}
			$serp  = is_array( $item['ranked_serp_element']['serp_item'] ?? null ) ? $item['ranked_serp_element']['serp_item'] : array();
			$out[] = $metric + array(
				'position' => is_numeric( $serp['rank_group'] ?? null ) ? (int) $serp['rank_group'] : null,
				'url'      => is_string( $serp['url'] ?? null ) ? esc_url_raw( $serp['url'] ) : '',
				'etv'      => is_numeric( $serp['etv'] ?? null ) ? round( (float) $serp['etv'], 1 ) : null,
			);
		}
		return new ResearchResult(
			array(
				'keywords'    => $out,
				'total_count' => isset( $result['total_count'] ) && is_numeric( $result['total_count'] ) ? (int) $result['total_count'] : null,
			),
			$this->cost( $json ),
			'dataforseo_labs/google/ranked_keywords/live'
		);
	}

	/**
	 * Normalise a Labs keyword data object. Absent values stay null.
	 *
	 * @param array<string, mixed> $item Keyword data.
	 * @return array<string, mixed>|null
	 */
	public static function metric( array $item ): ?array {
		if ( ! is_string( $item['keyword'] ?? null ) || '' === trim( $item['keyword'] ) ) {
			return null;
		}
		$info       = is_array( $item['keyword_info'] ?? null ) ? $item['keyword_info'] : array();
		$properties = is_array( $item['keyword_properties'] ?? null ) ? $item['keyword_properties'] : array();
		$intent     = is_array( $item['search_intent_info'] ?? null ) ? $item['search_intent_info'] : array();
		$num        = static fn ( $v ): ?float => is_numeric( $v ) ? (float) $v : null;

		return array(
			'keyword'            => sanitize_text_field( $item['keyword'] ),
			'search_volume'      => is_numeric( $info['search_volume'] ?? null ) ? (int) $info['search_volume'] : null,
			'cpc'                => $num( $info['cpc'] ?? null ),
			'competition'        => $num( $info['competition'] ?? null ),
			'competition_level'  => is_string( $info['competition_level'] ?? null ) ? sanitize_text_field( $info['competition_level'] ) : null,
			'keyword_difficulty' => is_numeric( $properties['keyword_difficulty'] ?? null ) ? (int) $properties['keyword_difficulty'] : null,
			'intent'             => is_string( $intent['main_intent'] ?? null ) ? sanitize_key( $intent['main_intent'] ) : null,
			'last_updated'       => is_string( $info['last_updated_time'] ?? null ) ? sanitize_text_field( $info['last_updated_time'] ) : null,
			'source'             => 'dataforseo',
		);
	}

	/**
	 * Location/language part of a task. Prefers the provider code when known.
	 *
	 * @param Market $market Market.
	 * @return array<string, mixed>
	 * @throws ProviderException When the market is unusable.
	 */
	private function marketTask( Market $market ): array {
		$task = array( 'language_code' => $market->languageCode );
		if ( $market->locationCode > 0 ) {
			$task['location_code'] = $market->locationCode;
		} elseif ( '' !== $market->locationName ) {
			$task['location_name'] = $market->locationName;
		} else {
			throw new ProviderException( __( 'Choose a target country.', 'ai-seo-content-studio' ), ProviderException::INVALID_REQUEST );
		}
		return $task;
	}

	/**
	 * Perform a call and validate the envelope.
	 *
	 * @param string                                $method HTTP method.
	 * @param string                                $path   Path after /v3.
	 * @param array<int, array<string, mixed>>|null $tasks  POST body (array of tasks).
	 * @return array<string, mixed>
	 * @throws ProviderException On HTTP, envelope or task errors.
	 */
	private function call( string $method, string $path, ?array $tasks = null ): array {
		$login    = $this->credentials->get( $this->id(), 'login' );
		$password = $this->credentials->get( $this->id(), 'password' );
		if ( null === $login || null === $password ) {
			throw new ProviderException( __( 'DataForSEO is not configured. Add the API login and password under SEO Research Settings.', 'ai-seo-content-studio' ), ProviderException::CONFIG );
		}

		$base = $this->usesSandbox() ? self::SANDBOX_URL : self::LIVE_URL;
		$json = $this->http->requestJson(
			$method,
			$base . $path,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
			array( 'Authorization' => 'Basic ' . base64_encode( $login . ':' . $password ) ),
			$tasks,
			array( 'provider' => $this->label() )
		);

		$this->assertOk( (int) ( $json['status_code'] ?? 0 ), (string) ( $json['status_message'] ?? '' ) );
		$task = $json['tasks'][0] ?? null;
		if ( ! is_array( $task ) ) {
			throw new ProviderException( __( 'DataForSEO returned no task in its response.', 'ai-seo-content-studio' ), ProviderException::INVALID_RESPONSE );
		}
		$this->assertOk( (int) ( $task['status_code'] ?? 0 ), (string) ( $task['status_message'] ?? '' ) );
		return $json;
	}

	/**
	 * Throw unless a DataForSEO status code means success.
	 *
	 * Only the documented success code (20000) and the HTTP-style class of the
	 * code are interpreted; the provider's message is passed through.
	 *
	 * @param int    $code    Status code.
	 * @param string $message Status message.
	 * @throws ProviderException When not successful.
	 */
	private function assertOk( int $code, string $message ): void {
		if ( 20000 === $code ) {
			return;
		}
		$class    = intdiv( $code, 100 );
		$category = match ( true ) {
			401 === $class => ProviderException::AUTH,
			402 === $class => ProviderException::QUOTA,
			403 === $class => ProviderException::PERMISSION,
			404 === $class => ProviderException::NOT_FOUND,
			429 === $class => ProviderException::RATE_LIMIT,
			$code >= 50000 => ProviderException::SERVER,
			default        => ProviderException::INVALID_REQUEST,
		};
		$detail = mb_substr( wp_strip_all_tags( $message ), 0, 200 );
		throw new ProviderException(
			/* translators: 1: status code, 2: provider message. */
			sprintf( __( 'DataForSEO error %1$d: %2$s', 'ai-seo-content-studio' ), $code, '' !== $detail ? $detail : __( 'no details', 'ai-seo-content-studio' ) ),
			$category,
			in_array( $category, array( ProviderException::RATE_LIMIT, ProviderException::SERVER ), true )
		);
	}

	/**
	 * Results array of the first task.
	 *
	 * @param array<string, mixed> $json Envelope.
	 * @return array<int, mixed>
	 */
	private function results( array $json ): array {
		$result = $json['tasks'][0]['result'] ?? array();
		return is_array( $result ) ? $result : array();
	}

	/**
	 * First result object of the first task.
	 *
	 * @param array<string, mixed> $json Envelope.
	 * @return array<string, mixed>
	 * @throws ProviderException When missing.
	 */
	private function firstResult( array $json ): array {
		$first = $this->results( $json )[0] ?? null;
		if ( ! is_array( $first ) ) {
			throw new ProviderException( __( 'DataForSEO returned no results for this request.', 'ai-seo-content-studio' ), ProviderException::INVALID_RESPONSE );
		}
		return $first;
	}

	/**
	 * Provider-reported cost (USD).
	 *
	 * @param array<string, mixed> $json Envelope.
	 */
	private function cost( array $json ): ProviderCost {
		return new ProviderCost( is_numeric( $json['cost'] ?? null ) ? (float) $json['cost'] : null, 'USD' );
	}
}
