<?php
/**
 * AI-suggested competitor pages, for when no SEO data provider is connected.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research;

use AiSeoContentStudio\Providers\Contracts\Message;
use AiSeoContentStudio\Providers\Contracts\TextRequest;
use AiSeoContentStudio\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the AI which pages are likely to rank for the keyword. These are suggestions, not
 * live Google rankings: every page is then downloaded (robots.txt respected), so only pages
 * that really exist are read, and the UI labels them "AI-suggested".
 */
final class CompetitorSuggestions {

	/** Maximum pages the AI may suggest. */
	public const MAX = 5;

	/**
	 * Strict schema.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$str = array( 'type' => 'string' );
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'pages' ),
			'properties'           => array(
				'pages' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'url', 'title', 'reason' ),
						'properties'           => array(
							'url'    => $str,
							'title'  => $str,
							'reason' => $str,
						),
					),
				),
			),
		);
	}

	/**
	 * Build the request.
	 *
	 * @param array<string, mixed> $inputs     Project inputs.
	 * @param string               $own_host   This site's host (never suggested).
	 * @param int                  $max_tokens Output cap.
	 */
	public static function request( array $inputs, string $own_host, int $max_tokens = 1500 ): TextRequest {
		$system  = implode(
			"\n",
			array(
				'You are an SEO researcher. List up to ' . self::MAX . ' web pages that are most likely among the top Google organic results for the keyword in the given market.',
				'Rules:',
				'- Only pages you are confident exist at that exact URL: well-known publishers, official bodies, software vendors and established businesses in this field. Give the full https URL of the specific article or guide page, not a home page.',
				'- Never invent or guess URLs. If you are not confident about a page, leave it out; returning fewer pages is fine.',
				'- No search result pages, social media, video sites or forums. Never include the site_host.',
				'- title: the page title as you know it. reason: in a few words, why it likely ranks (for example "official guidance" or "major accounting software guide").',
				'- These are suggestions only; do not claim rankings, traffic or positions.',
			)
		);
		$payload = array(
			'keyword'   => (string) ( $inputs['keyword'] ?? '' ),
			'market'    => (string) ( $inputs['market']['location_name'] ?? '' ),
			'site_host' => $own_host,
		);
		return new TextRequest(
			array( Message::user( "Research request (JSON):\n" . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ),
			$system,
			$max_tokens,
			null,
			self::schema(),
			'competitor_suggestions',
			'competitor_suggestions'
		);
	}

	/**
	 * Sanitise the AI output into candidate rows.
	 *
	 * @param array<string, mixed> $data     AI output.
	 * @param string               $own_host This site's host.
	 * @return array<int, array{url: string, title: string, reason: string, origin: string, rank: int}>
	 */
	public static function sanitize( array $data, string $own_host ): array {
		$out = array();
		foreach ( (array) ( $data['pages'] ?? array() ) as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$url  = esc_url_raw( trim( (string) ( $page['url'] ?? '' ) ), array( 'http', 'https' ) );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $host || strtolower( $own_host ) === $host || in_array( $url, array_column( $out, 'url' ), true ) ) {
				continue;
			}
			$out[] = array(
				'url'    => $url,
				'title'  => Sanitize::text( $page['title'] ?? '', 200 ),
				'reason' => Sanitize::text( $page['reason'] ?? '', 200 ),
				'origin' => 'ai',
				'rank'   => 0,
			);
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}
}
