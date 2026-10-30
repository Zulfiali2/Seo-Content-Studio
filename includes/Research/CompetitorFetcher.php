<?php
/**
 * Polite, SSRF-safe competitor page retrieval.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research;

use AiSeoContentStudio\Http\HttpClient;
use AiSeoContentStudio\Providers\Contracts\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches one competitor page and returns its outline, or a skip reason.
 *
 * - robots.txt is checked for our product token and "*" (cached per host for a
 *   day); a disallowed page is skipped, never fetched.
 * - All URLs, including redirects, pass the SSRF validator.
 * - Only HTML responses up to 2 MB are parsed. Pages marked noindex are kept as
 *   observations but flagged.
 */
class CompetitorFetcher {

	public const AGENT_TOKEN = 'AI-SEO-Content-Studio';

	/**
	 * Constructor.
	 *
	 * @param HttpClient    $http      HTTP client.
	 * @param HtmlExtractor $extractor HTML extractor.
	 */
	public function __construct( private readonly HttpClient $http, private readonly HtmlExtractor $extractor = new HtmlExtractor() ) {}

	/**
	 * Fetch and extract.
	 *
	 * @param string $url Competitor URL from the SERP.
	 * @return array{url: string, status: string, reason: string, outline: array<string, mixed>|null}
	 */
	public function fetch( string $url ): array {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			return self::skipped( $url, 'invalid_url' );
		}

		$path = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		if ( ! $this->robotsAllow( $parts, $path ) ) {
			return self::skipped( $url, 'robots_disallowed' );
		}

		try {
			$page = $this->http->fetchPage( $url );
		} catch ( ProviderException $e ) {
			return self::skipped( $url, ProviderException::UNSAFE_URL === $e->category ? 'unsafe_url' : 'fetch_failed' );
		}

		if ( 200 !== $page['status'] ) {
			return self::skipped( $url, 'http_' . $page['status'] );
		}
		if ( ! str_contains( $page['content_type'], 'html' ) ) {
			return self::skipped( $url, 'not_html' );
		}

		return array(
			'url'     => $url,
			'status'  => 'fetched',
			'reason'  => '',
			'outline' => $this->extractor->extract( $page['body'] ),
		);
	}

	/**
	 * Check robots.txt, with a per-host daily cache. Unreachable or missing
	 * robots.txt (4xx) means allowed, per RFC 9309; a 5xx means "assume disallowed".
	 *
	 * @param array<string, mixed> $parts Parsed URL.
	 * @param string               $path  Path and query.
	 */
	private function robotsAllow( array $parts, string $path ): bool {
		$origin = strtolower( $parts['scheme'] . '://' . $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$key    = 'aiscs_robots_' . md5( $origin );
		$cached = get_transient( $key );

		if ( ! is_array( $cached ) ) {
			try {
				$page   = $this->http->fetchPage( $origin . '/robots.txt', 10, 512000 );
				$cached = match ( true ) {
					200 === $page['status'] => array(
						'mode' => 'rules',
						'body' => $page['body'],
					),
					$page['status'] >= 500 => array(
						'mode' => 'deny',
						'body' => '',
					),
					default => array(
						'mode' => 'allow',
						'body' => '',
					),
				};
			} catch ( ProviderException ) {
				$cached = array(
					'mode' => 'allow',
					'body' => '',
				);
			}
			set_transient( $key, $cached, DAY_IN_SECONDS );
		}

		return match ( $cached['mode'] ) {
			'deny' => false,
			'rules' => ( new RobotsTxt( (string) $cached['body'], self::AGENT_TOKEN ) )->isAllowed( $path ),
			default => true,
		};
	}

	/**
	 * Skip record.
	 *
	 * @param string $url    URL.
	 * @param string $reason Machine reason.
	 * @return array{url: string, status: string, reason: string, outline: null}
	 */
	private static function skipped( string $url, string $reason ): array {
		return array(
			'url'     => $url,
			'status'  => 'skipped',
			'reason'  => $reason,
			'outline' => null,
		);
	}
}
