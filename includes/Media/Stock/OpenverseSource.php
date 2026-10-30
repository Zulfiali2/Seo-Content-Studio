<?php
/**
 * Openverse: openly licensed images; only public domain and CC0 are used.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Media\Stock;

use AiSeoContentStudio\Http\HttpClient;

defined( 'ABSPATH' ) || exit;

/**
 * GET https://api.openverse.org/v1/images/?q=&license=cc0,pdm&mature=false&aspect_ratio=&page_size=
 * and GET /v1/images/{id}/ (checked against Openverse's API serializers and the generated
 *
 * @openverse/api-client types). Anonymous requests are allowed (page size up to 20).
 * Images are hosted by the original sites (Flickr, Wikimedia and others), so their file URLs
 * are treated as untrusted and SSRF-checked before download.
 */
final class OpenverseSource implements StockSource {

	private const API = 'https://api.openverse.org/v1/images/';

	/** Public domain mark and CC0 only: no copyright restrictions. */
	private const LICENSES = 'cc0,pdm';

	/**
	 * Constructor.
	 *
	 * @param HttpClient $http HTTP client.
	 */
	public function __construct( private readonly HttpClient $http ) {}

	/**
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'openverse';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label(): string {
		return 'Openverse';
	}

	/**
	 * {@inheritDoc}
	 */
	public function kind(): string {
		return self::COPYRIGHT_FREE;
	}

	/**
	 * {@inheritDoc}
	 */
	public function needsKey(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	public function isConfigured(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function keyUrl(): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $query  Query.
	 * @param string $aspect Aspect.
	 */
	public function search( string $query, string $aspect ): array {
		$url = add_query_arg(
			array(
				'q'            => rawurlencode( $query ),
				'license'      => self::LICENSES,
				'mature'       => 'false',
				'aspect_ratio' => match ( $aspect ) {
					'2:3' => 'tall',
					'1:1' => 'square',
					default => 'wide',
				},
				'page_size'    => 20,
			),
			self::API
		);
		$json = $this->http->requestJson( 'GET', $url, array(), null, array( 'provider' => 'Openverse' ) );
		$out  = array();
		foreach ( (array) ( $json['results'] ?? array() ) as $item ) {
			$result = is_array( $item ) ? self::normalise( $item ) : null;
			if ( null !== $result ) {
				$out[] = $result;
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
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $id ) ) {
			return null;
		}
		$json   = $this->http->requestJson( 'GET', self::API . $id . '/', array(), null, array( 'provider' => 'Openverse' ) );
		$result = self::normalise( $json );
		if ( null === $result || ! is_string( $json['url'] ?? null ) ) {
			return null;
		}
		return $result + array( 'download' => (string) $json['url'] );
	}

	/**
	 * Normalise one Openverse image, or null when it is not public domain / CC0.
	 *
	 * @param array<string, mixed> $item Item.
	 * @return array<string, mixed>|null
	 */
	private static function normalise( array $item ): ?array {
		$license = strtolower( (string) ( $item['license'] ?? '' ) );
		if ( ! is_string( $item['id'] ?? null ) || ! in_array( $license, array( 'cc0', 'pdm' ), true ) || ! empty( $item['mature'] ) ) {
			return null;
		}
		return array(
			'id'          => (string) $item['id'],
			'thumb'       => esc_url_raw( (string) ( $item['thumbnail'] ?? '' ) ),
			'width'       => (int) ( $item['width'] ?? 0 ),
			'height'      => (int) ( $item['height'] ?? 0 ),
			'title'       => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
			'author'      => sanitize_text_field( (string) ( $item['creator'] ?? '' ) ),
			'author_url'  => esc_url_raw( (string) ( $item['creator_url'] ?? '' ) ),
			'source_url'  => esc_url_raw( (string) ( $item['foreign_landing_url'] ?? '' ) ),
			'license'     => 'pdm' === $license ? 'Public Domain Mark' : 'CC0 ' . sanitize_text_field( (string) ( $item['license_version'] ?? '1.0' ) ),
			'license_url' => esc_url_raw( (string) ( $item['license_url'] ?? '' ) ),
			'library'     => 'Openverse',
		);
	}
}
