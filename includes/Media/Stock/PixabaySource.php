<?php
/**
 * Pixabay: royalty-free images (free API key).
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Media\Stock;

use AiSeoContentStudio\Http\HttpClient;
use AiSeoContentStudio\Security\CredentialStore;

defined( 'ABSPATH' ) || exit;

/**
 * GET https://pixabay.com/api/?key=&q=&image_type=photo&orientation=&safesearch=true&per_page=
 * and ?id= for one image (fields checked against the typed `pixabay-api` client). Pixabay's
 * image URLs expire, so images are downloaded into the Media Library rather than linked.
 * `largeImageURL` (1280px) is used when the response includes it, otherwise `webformatURL` (640px).
 */
final class PixabaySource implements StockSource {

	private const API = 'https://pixabay.com/api/';

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
		return 'pixabay';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Pixabay';
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
		return 'https://pixabay.com/api/docs/';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $query  Query.
	 * @param string $aspect Aspect.
	 */
	public function search( string $query, string $aspect ): array {
		$args = array(
			'q'          => rawurlencode( mb_substr( $query, 0, 100 ) ),
			'image_type' => 'photo',
			'safesearch' => 'true',
			'per_page'   => 20,
		);
		if ( '1:1' !== $aspect ) {
			$args['orientation'] = '2:3' === $aspect ? 'vertical' : 'horizontal';
		}
		$out = array();
		foreach ( (array) ( $this->get( $args )['hits'] ?? array() ) as $hit ) {
			if ( is_array( $hit ) && isset( $hit['id'] ) ) {
				$out[] = self::normalise( $hit );
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
		$hit  = (array) ( $this->get( array( 'id' => $id ) )['hits'][0] ?? array() );
		$file = (string) ( $hit['largeImageURL'] ?? $hit['webformatURL'] ?? '' );
		if ( ! isset( $hit['id'] ) || '' === $file ) {
			return null;
		}
		return self::normalise( $hit ) + array( 'download' => $file );
	}

	/**
	 * GET with the key as a query parameter (Pixabay's documented authentication).
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	private function get( array $args ): array {
		$url = add_query_arg( array( 'key' => rawurlencode( (string) $this->credentials->get( $this->id(), 'api_key' ) ) ) + $args, self::API );
		return $this->http->requestJson( 'GET', $url, array(), null, array( 'provider' => 'Pixabay' ) );
	}

	/**
	 * Normalise one hit.
	 *
	 * @param array<string, mixed> $hit Hit.
	 * @return array<string, mixed>
	 */
	private static function normalise( array $hit ): array {
		return array(
			'id'          => (string) (int) $hit['id'],
			'thumb'       => esc_url_raw( (string) ( $hit['webformatURL'] ?? $hit['previewURL'] ?? '' ) ),
			'width'       => (int) ( $hit['imageWidth'] ?? 0 ),
			'height'      => (int) ( $hit['imageHeight'] ?? 0 ),
			'title'       => sanitize_text_field( (string) ( $hit['tags'] ?? '' ) ),
			'author'      => sanitize_text_field( (string) ( $hit['user'] ?? '' ) ),
			'author_url'  => '',
			'source_url'  => esc_url_raw( (string) ( $hit['pageURL'] ?? '' ) ),
			'license'     => 'Pixabay Content License',
			'license_url' => 'https://pixabay.com/service/license-summary/',
			'library'     => 'Pixabay',
		);
	}
}
