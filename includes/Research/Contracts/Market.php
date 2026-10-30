<?php
/**
 * Target market (location + language).
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * A search market. location_code is the research provider's id (0 when unknown,
 * e.g. when no research provider is configured and only the name is used).
 */
final readonly class Market {

	/**
	 * Constructor.
	 *
	 * @param string $locationName  Display name, e.g. "United Kingdom".
	 * @param string $languageCode  ISO language code, e.g. "en".
	 * @param int    $locationCode  Provider location code (0 = none).
	 * @param string $languageName  Language display name.
	 * @param string $countryIso    ISO 3166-1 alpha-2 code.
	 */
	public function __construct(
		public string $locationName,
		public string $languageCode = 'en',
		public int $locationCode = 0,
		public string $languageName = 'English',
		public string $countryIso = '',
	) {}

	/**
	 * Build from untrusted array input.
	 *
	 * @param array<string, mixed> $data Input.
	 */
	public static function fromArray( array $data ): self {
		return new self(
			substr( sanitize_text_field( (string) ( $data['location_name'] ?? '' ) ), 0, 120 ),
			substr( (string) preg_replace( '/[^a-zA-Z\-]/', '', (string) ( $data['language_code'] ?? 'en' ) ), 0, 12 ) ?: 'en',
			max( 0, (int) ( $data['location_code'] ?? 0 ) ),
			substr( sanitize_text_field( (string) ( $data['language_name'] ?? 'English' ) ), 0, 60 ),
			strtoupper( substr( (string) preg_replace( '/[^a-zA-Z]/', '', (string) ( $data['country_iso'] ?? '' ) ), 0, 2 ) )
		);
	}

	/**
	 * Language the content is written in, from project inputs ("English" when none is set).
	 *
	 * @param array<string, mixed> $inputs Project inputs.
	 */
	public static function languageOf( array $inputs ): string {
		$name = self::fromArray( is_array( $inputs['market'] ?? null ) ? $inputs['market'] : array() )->languageName;
		return '' !== trim( $name ) ? $name : 'English';
	}

	/**
	 * Array form.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'location_name' => $this->locationName,
			'language_code' => $this->languageCode,
			'location_code' => $this->locationCode,
			'language_name' => $this->languageName,
			'country_iso'   => $this->countryIso,
		);
	}
}
