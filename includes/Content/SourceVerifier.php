<?php
/**
 * Checks that sources cited by the writer are real official pages.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Content;

use AiSeoContentStudio\Http\HttpClient;
use AiSeoContentStudio\Providers\Contracts\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * A cited source is kept as a link only when it is on a trusted domain AND the page loads
 * (HTTP 200, HTML or PDF). Results are cached for a day per URL. Every source, verified
 * or not, is listed for the site owner to check before publishing.
 *
 * Status: verified | not_found | untrusted | no_url.
 */
class SourceVerifier {

	/** Seconds one content step may spend checking sources. */
	private const BUDGET = 20;

	/**
	 * Constructor.
	 *
	 * @param HttpClient $http HTTP client (SSRF-safe page fetches).
	 */
	public function __construct( private readonly HttpClient $http ) {}

	/**
	 * Check sources returned by the writer.
	 *
	 * @param array<int, mixed> $sources Untrusted [{claim, url}].
	 * @param WritingProfile    $profile Fact policy.
	 * @return array<int, array{claim: string, url: string, status: string}>
	 */
	public function check( array $sources, WritingProfile $profile ): array {
		$out     = array();
		$started = time();
		foreach ( array_slice( $sources, 0, 10 ) as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			$claim = trim( sanitize_text_field( (string) ( $source['claim'] ?? '' ) ) );
			$url   = esc_url_raw( trim( (string) ( $source['url'] ?? '' ) ), array( 'https' ) );
			if ( '' === $claim ) {
				continue;
			}
			$status = match ( true ) {
				'' === $url => 'no_url',
				! $profile->isTrusted( $url ) => 'untrusted',
				time() - $started > self::BUDGET => 'not_checked',
				default => $this->exists( $url ) ? 'verified' : 'not_found',
			};
			$out[] = array(
				'claim'  => mb_substr( $claim, 0, 300 ),
				'url'    => $url,
				'status' => $status,
			);
		}
		return $out;
	}

	/**
	 * Whether the page loads.
	 *
	 * @param string $url URL.
	 */
	public function exists( string $url ): bool {
		$key    = 'aiscs_src_' . md5( $url );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return ! empty( $cached['ok'] );
		}
		try {
			$page = $this->http->fetchPage( $url, 10, 1048576, 3, 'text/html,application/xhtml+xml,application/pdf;q=0.9' );
			$ok   = 200 === (int) $page['status'] && ( str_contains( (string) $page['content_type'], 'html' ) || str_contains( (string) $page['content_type'], 'pdf' ) );
		} catch ( ProviderException ) {
			$ok = false;
		}
		set_transient( $key, array( 'ok' => $ok ), DAY_IN_SECONDS );
		return $ok;
	}

	/**
	 * A line for the "claims to verify" list.
	 *
	 * @param array{claim: string, url: string, status: string} $source Checked source.
	 */
	public static function claimLine( array $source ): string {
		return match ( $source['status'] ) {
			/* translators: 1: claim, 2: URL. */
			'verified' => sprintf( __( '%1$s (source: %2$s, page found; check it says this)', 'ai-seo-content-studio' ), $source['claim'], $source['url'] ),
			/* translators: 1: claim, 2: URL. */
			'not_found' => sprintf( __( '%1$s (cited page %2$s could not be found; find the official source)', 'ai-seo-content-studio' ), $source['claim'], $source['url'] ),
			/* translators: 1: claim, 2: URL. */
			'untrusted' => sprintf( __( '%1$s (cited %2$s is not on your trusted source list)', 'ai-seo-content-studio' ), $source['claim'], $source['url'] ),
			/* translators: 1: claim, 2: URL. */
			'not_checked' => sprintf( __( '%1$s (source: %2$s, not checked yet)', 'ai-seo-content-studio' ), $source['claim'], $source['url'] ),
			/* translators: %s: claim. */
			default => sprintf( __( '%s (no source page given; find the official source)', 'ai-seo-content-studio' ), $source['claim'] ),
		};
	}
}
