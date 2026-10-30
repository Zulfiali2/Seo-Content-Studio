<?php
/**
 * Pexels: royalty-free photos (free API key).
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Media\Stock;

use AiSeoContentStudio\Http\HttpClient;
use AiSeoContentStudio\Security\CredentialStore;

defined( 'ABSPATH' ) || exit;

/**
 * GET https://api.pexels.com/v1/search?query=&per_page= and /v1/photos/{id}, with the API key
 * in the Authorization header (checked against the official `pexels` 1.4.0 client and its types).
 * Pexels asks apps to credit the photographer and Pexels; the credit is stored as the caption.
 */
final class PexelsSource implements StockSource {

	private const API = 'https://api.pexels.com/v1/';

	/**
	 * Constructor.
	 *
	 * @param HttpClient      $http        HTTP client.
	 * @param CredentialStore $credentials Credentials.
	 */
	public function __construct( private readonly HttpClient $http, private readonly CredentialStore $credentials ) {}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'pexels';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Pexels';
	}

	/**
	 * {@inheritDoc}
	 */
	public function kind(): string {
		return self::ROYALTY_FREE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function needsKey(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function isConfigured(): bool {
		return $this->credentials->has( $this->id(), 'api_key' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function keyUrl(): string {
		return 'https://www.pexels.com/api/';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $query  Query.
	 * @param string $aspect Aspect (Pexels search has no aspect filter in the client; results are cropped by WordPress sizes).
	 */
	public function search( string $query, string $aspect ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$json = $this->get(
			add_query_arg(
				array(
					'query'    => rawurlencode( $query ),
					'per_page' => 20,
				),
				self::API . 'search'
			)
		);
		$out  = array();
		foreach ( (array) ( $json['photos'] ?? array() ) as $photo ) {
			if ( is_array( $photo ) && isset( $photo['id'] ) ) {
				$out[] = self::normalise( $photo );
			}
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $id Id.
	 */
	public function find( string $id ): ?array {
		if ( ! ctype_digit( $id ) ) {
			return null;
		}
		$photo = $this->get( self::API . 'photos/' . $id );
		$src   = is_array( $photo['src'] ?? null ) ? $photo['src'] : array();
		$file  = (string) ( $src['large2x'] ?? $src['large'] ?? $src['original'] ?? '' );
		if ( ! isset( $photo['id'] ) || '' === $file ) {
			return null;
		}
		return self::normalise( $photo ) + array( 'download' => $file );
	}

	/**
	 * Authorised GET.
	 *
	 * @param string $url URL.
	 * @return array<string, mixed>
	 */
	private function get( string $url ): array {
		return $this->http->requestJson( 'GET', $url, array( 'Authorization' => (string) $this->credentials->get( $this->id(), 'api_key' ) ), null, array( 'provider' => 'Pexels' ) );
	}

	/**
	 * Normalise one photo.
	 *
	 * @param array<string, mixed> $photo Photo.
	 * @return array<string, mixed>
	 */
	private static function normalise( array $photo ): array {
		$src = is_array( $photo['src'] ?? null ) ? $photo['src'] : array();
		return array(
			'id'          => (string) (int) $photo['id'],
			'thumb'       => esc_url_raw( (string) ( $src['medium'] ?? $src['small'] ?? '' ) ),
			'width'       => (int) ( $photo['width'] ?? 0 ),
			'height'      => (int) ( $photo['height'] ?? 0 ),
			'title'       => sanitize_text_field( (string) ( $photo['alt'] ?? '' ) ),
			'author'      => sanitize_text_field( (string) ( $photo['photographer'] ?? '' ) ),
			'author_url'  => esc_url_raw( (string) ( $photo['photographer_url'] ?? '' ) ),
			'source_url'  => esc_url_raw( (string) ( $photo['url'] ?? '' ) ),
			'license'     => 'Pexels License',
			'license_url' => 'https://www.pexels.com/license/',
			'library'     => 'Pexels',
		);
	}
}
