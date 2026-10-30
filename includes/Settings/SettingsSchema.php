<?php
/**
 * Settings defaults and sanitisation rules.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Declarative settings schema.
 *
 * The type of each default value decides how input is sanitised; integer and
 * float fields additionally have bounds. Unknown keys are dropped. API keys are
 * NOT settings: they live encrypted in the CredentialStore.
 */
final class SettingsSchema {

	/** Provider ids with per-provider settings. */
	public const PROVIDER_IDS = array( 'openai', 'anthropic', 'gemini', 'xai', 'custom' );

	/** Supported content languages (initial release: English only). */
	public const LANGUAGES = array( 'en' );

	/** Operation types tracked for usage and limits. */
	public const OPERATIONS = array( 'text', 'research', 'image' );

	/** Words and phrases the writer avoids by default (editable in Brand and Writing Style). */
	public const DEFAULT_BANNED_WORDS = 'delve, digital age, cutting-edge, game-changer, unlock the power, unleash, realm, embark on, navigate the complexities, ever-evolving, tapestry, testament to, in today\'s world, in today\'s fast-paced world, it is important to note, it is worth noting, in conclusion';

	/**
	 * The default list before 1.6.4. It also banned ordinary words (specifically, consequently,
	 * landscape, evolve…), which pushed the AI into awkward phrasing. A site still using it
	 * unchanged is moved to the new default (see Migrations).
	 */
	public const LEGACY_BANNED_WORDS = 'delve, digital age, cutting-edge, leverage, proactive, pivotal, seamless, streamlined, fast-paced, game-changer, quest, realm, landscape, evolve, resilient, thrill, unravel, embark, indeed, furthermore, thus, moreover, notwithstanding, ostensibly, consequently, specifically, notably, alternatively, in today\'s world, it is important to note';

	/** Official sites facts may be taken from by default (editable). */
	public const DEFAULT_TRUSTED_DOMAINS = "gov.uk\nlegislation.gov.uk\nnhs.uk\nons.gov.uk\nbankofengland.co.uk\nfca.org.uk\nicaew.com\naccaglobal.com\nirs.gov\nsba.gov\nusa.gov\neuropa.eu\nwho.int";

	/** Settings stored as multi-line text. */
	private const TEXTAREAS = array( 'business.about', 'business.author_bio', 'style.banned_words', 'facts.trusted_domains' );

	/** Settings stored as lists, each with its own sanitiser. */
	private const LISTS = array( 'pricing', 'business.services' );

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'general'  => array(
				'delete_data_on_uninstall' => false,
				'debug_logging'            => false,
				'content_language'         => 'en',
				'usage_retention_days'     => 365,
			),
			'ai'       => array(
				'default_text_provider'   => '',
				'default_image_provider'  => '',
				'cost_mode'               => 'balanced',
				'request_timeout'         => 90,
				'max_retries'             => 2,
				'max_output_tokens'       => 8000,
				'max_input_chars'         => 120000,
				'allow_private_endpoints' => false,
				'providers'               => array(
					'openai'    => array(
						'text_model'  => '',
						'quick_model' => '',
						'image_model' => '',
					),
					'anthropic' => array(
						'text_model'  => '',
						'quick_model' => '',
					),
					'gemini'    => array(
						'text_model'  => '',
						'quick_model' => '',
						'image_model' => '',
					),
					'xai'       => array(
						'text_model'  => '',
						'quick_model' => '',
						'image_model' => '',
					),
					'custom'    => array(
						'label'                => '',
						'base_url'             => '',
						'text_model'           => '',
						'quick_model'          => '',
						'supports_json_schema' => false,
					),
				),
			),
			'business' => array(
				'name'               => '',
				'about'              => '',
				'country'            => '',
				'contact_url'        => '',
				'author_name'        => '',
				'author_credentials' => '',
				'author_bio'         => '',
				// List of { name, url } (sanitised separately).
				'services'           => array(),
			),
			'style'    => array(
				// "single": the first draft in one chat-style request (plain brief, HTML answer);
				// "structured": one request with the full rule set and a JSON answer (before 1.6.6);
				// "sections": one request per part.
				'generation_mode'    => 'single',
				'english'            => 'en-GB',
				'reading_level'      => 'standard',
				'max_sentence_words' => 25,
				'tone'               => 'Professional but conversational: clear, direct and natural, like writing to a smart friend.',
				'banned_words'       => self::DEFAULT_BANNED_WORDS,
				'no_em_dashes'       => true,
				'advice_line'        => 'once',
				'intro_format'       => 'two_paragraphs',
				'worked_example'     => true,
				'conclusion_cta'     => true,
				'edit_pass'          => true,
			),
			'facts'    => array(
				'mode'            => 'trusted_sources',
				'trusted_domains' => self::DEFAULT_TRUSTED_DOMAINS,
				'outbound_links'  => true,
			),
			'seo'      => array(
				'output_meta'       => true,
				'output_schema'     => true,
				'output_faq_schema' => true,
				'draft_format'      => 'blocks',
			),
			'research' => array(
				'provider'                   => 'dataforseo',
				'use_sandbox'                => false,
				'serp_depth'                 => 10,
				'fetch_competitors'          => true,
				'competitor_pages'           => 10,
				'competitor_keywords'        => true,
				'competitor_keywords_limit'  => 10,
				'ai_competitors'             => true,
				'related_keywords_limit'     => 30,
				'metrics_for_ai_suggestions' => true,
				'cache_hours'                => 72,
				'default_location_code'      => 0,
				'default_location_name'      => '',
				'default_language_code'      => 'en',
			),
			'limits'   => array(
				'text_requests_per_hour'     => 60,
				'research_requests_per_hour' => 60,
				'image_requests_per_hour'    => 20,
				'confirm_cost_above'         => 0.5,
			),
			// List of user-entered prices. Nothing is shipped as fact; see docs/COST-CONTROLS.md.
			'pricing'  => array(),
		);
	}

	/**
	 * Numeric bounds keyed by dotted path: [min, max].
	 *
	 * @return array<string, array{0: int|float, 1: int|float}>
	 */
	public static function bounds(): array {
		return array(
			'general.usage_retention_days'       => array( 7, 3650 ),
			'style.max_sentence_words'           => array( 8, 40 ),
			'ai.request_timeout'                 => array( 10, 300 ),
			'ai.max_retries'                     => array( 0, 5 ),
			'ai.max_output_tokens'               => array( 256, 128000 ),
			'ai.max_input_chars'                 => array( 1000, 2000000 ),
			'research.serp_depth'                => array( 10, 100 ),
			'research.competitor_pages'          => array( 0, 10 ),
			'research.competitor_keywords_limit' => array( 1, 50 ),
			'research.related_keywords_limit'    => array( 0, 200 ),
			'research.cache_hours'               => array( 1, 720 ),
			'research.default_location_code'     => array( 0, 99999999 ),
			'limits.text_requests_per_hour'      => array( 0, 10000 ),
			'limits.research_requests_per_hour'  => array( 0, 10000 ),
			'limits.image_requests_per_hour'     => array( 0, 10000 ),
			'limits.confirm_cost_above'          => array( 0, 1000 ),
		);
	}

	/**
	 * Allowed values for enumerated string fields. Empty string means "not set".
	 *
	 * @return array<string, string[]>
	 */
	public static function enums(): array {
		return array(
			'general.content_language'  => self::LANGUAGES,
			'ai.default_text_provider'  => array_merge( array( '' ), self::PROVIDER_IDS ),
			'ai.default_image_provider' => array( '', 'openai', 'gemini', 'xai' ),
			'ai.cost_mode'              => array( 'economy', 'balanced', 'quality' ),
			'research.provider'         => array( 'dataforseo' ),
			'seo.draft_format'          => array( 'blocks', 'classic' ),
			'style.english'             => array( 'en-GB', 'en-US' ),
			'style.generation_mode'     => array( 'single', 'structured', 'sections' ),
			'style.reading_level'       => array( 'simple', 'uk_plain', 'standard', 'expert' ),
			'style.advice_line'         => array( 'once', 'none', 'as_needed' ),
			'style.intro_format'        => array( 'two_paragraphs', 'free' ),
			'facts.mode'                => array( 'trusted_sources', 'user_only' ),
		);
	}

	/**
	 * Sanitise an input array against the schema, falling back to $current for
	 * keys not present in the input (partial updates).
	 *
	 * @param array<string, mixed> $input   Untrusted input.
	 * @param array<string, mixed> $current Current stored settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input, array $current ): array {
		$defaults = self::defaults();
		$current  = self::merge( $defaults, $current );
		$result   = self::sanitizeNode( $defaults, $input, $current, '' );

		$result['pricing'] = array_key_exists( 'pricing', $input ) && is_array( $input['pricing'] )
			? self::sanitizePricing( $input['pricing'] )
			: self::sanitizePricing( is_array( $current['pricing'] ?? null ) ? $current['pricing'] : array() );

		$services                       = $input['business']['services'] ?? null;
		$result['business']['services'] = is_array( $services )
			? self::sanitizeServices( $services )
			: self::sanitizeServices( is_array( $current['business']['services'] ?? null ) ? $current['business']['services'] : array() );

		return $result;
	}

	/**
	 * Merge stored values over defaults, keeping only known keys.
	 *
	 * @param array<string, mixed> $defaults Defaults.
	 * @param array<string, mixed> $stored   Stored values.
	 * @return array<string, mixed>
	 */
	public static function merge( array $defaults, array $stored ): array {
		$out = $defaults;
		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				continue;
			}
			if ( is_array( $default ) && array() !== $default && is_array( $stored[ $key ] ) ) {
				$out[ $key ] = self::merge( $default, $stored[ $key ] );
			} else {
				$out[ $key ] = $stored[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Recursive sanitiser.
	 *
	 * @param array<string, mixed> $defaults Schema node.
	 * @param array<string, mixed> $input    Input node.
	 * @param array<string, mixed> $current  Current node.
	 * @param string               $path     Dotted path prefix.
	 * @return array<string, mixed>
	 */
	private static function sanitizeNode( array $defaults, array $input, array $current, string $path ): array {
		$out = array();
		foreach ( $defaults as $key => $default ) {
			$key_path = '' === $path ? $key : $path . '.' . $key;
			$has      = array_key_exists( $key, $input );

			if ( in_array( $key_path, self::LISTS, true ) ) {
				continue;
			}

			if ( is_array( $default ) ) {
				$out[ $key ] = self::sanitizeNode(
					$default,
					$has && is_array( $input[ $key ] ) ? $input[ $key ] : array(),
					is_array( $current[ $key ] ?? null ) ? $current[ $key ] : $default,
					$key_path
				);
				continue;
			}

			$out[ $key ] = $has
				? self::sanitizeScalar( $key_path, $default, $input[ $key ], $current[ $key ] ?? $default )
				: ( $current[ $key ] ?? $default );
		}
		return $out;
	}

	/**
	 * Sanitise one scalar according to the default's type.
	 *
	 * @param string $path     Dotted path.
	 * @param mixed  $type_of  Default value (defines the type).
	 * @param mixed  $value    Input value.
	 * @param mixed  $fallback Value to keep when input is invalid.
	 * @return mixed
	 */
	private static function sanitizeScalar( string $path, mixed $type_of, mixed $value, mixed $fallback ): mixed {
		$bounds = self::bounds()[ $path ] ?? null;
		$enums  = self::enums()[ $path ] ?? null;

		if ( is_bool( $type_of ) ) {
			return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? (bool) $fallback;
		}

		if ( is_int( $type_of ) ) {
			if ( ! is_numeric( $value ) ) {
				return $fallback;
			}
			$int = (int) $value;
			return $bounds ? max( (int) $bounds[0], min( (int) $bounds[1], $int ) ) : $int;
		}

		if ( is_float( $type_of ) ) {
			if ( ! is_numeric( $value ) ) {
				return $fallback;
			}
			$float = (float) $value;
			return $bounds ? max( (float) $bounds[0], min( (float) $bounds[1], $float ) ) : $float;
		}

		if ( ! is_scalar( $value ) ) {
			return $fallback;
		}

		if ( str_ends_with( $path, '.base_url' ) ) {
			$url = esc_url_raw( trim( (string) $value ), array( 'https', 'http' ) );
			return rtrim( $url, '/' );
		}

		if ( str_ends_with( $path, '_url' ) ) {
			return esc_url_raw( trim( (string) $value ), array( 'https', 'http' ) );
		}

		if ( in_array( $path, self::TEXTAREAS, true ) ) {
			return substr( sanitize_textarea_field( (string) $value ), 0, 4000 );
		}

		$string = sanitize_text_field( (string) $value );

		if ( null !== $enums ) {
			return in_array( $string, $enums, true ) ? $string : $fallback;
		}

		// Model identifiers: keep a conservative character set and length.
		if ( str_ends_with( $path, '_model' ) ) {
			return substr( (string) preg_replace( '/[^A-Za-z0-9._:\/@\-]/', '', $string ), 0, 191 );
		}

		return substr( $string, 0, 200 );
	}

	/**
	 * Sanitise the pricing table.
	 *
	 * @param array<int, mixed> $rows Untrusted rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitizePricing( array $rows ): array {
		$clean = array();
		foreach ( array_slice( array_values( $rows ), 0, 200 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$provider = sanitize_key( (string) ( $row['provider'] ?? '' ) );
			$model    = trim( (string) ( $row['model'] ?? '' ) );
			// "*" is the provider-wide default price; otherwise keep model-id characters only.
			$model = '*' === $model ? '*' : substr( (string) preg_replace( '/[^A-Za-z0-9._:\/@\-]/', '', $model ), 0, 191 );
			if ( '' === $provider || '' === $model ) {
				continue;
			}
			$currency = strtoupper( (string) preg_replace( '/[^A-Za-z]/', '', (string) ( $row['currency'] ?? 'USD' ) ) );
			$clean[]  = array(
				'provider'                 => $provider,
				'model'                    => $model,
				'input_per_million'        => self::nonNegativeFloat( $row['input_per_million'] ?? null ),
				'output_per_million'       => self::nonNegativeFloat( $row['output_per_million'] ?? null ),
				'cached_input_per_million' => self::nonNegativeFloat( $row['cached_input_per_million'] ?? null ),
				'per_image'                => self::nonNegativeFloat( $row['per_image'] ?? null ),
				'per_request'              => self::nonNegativeFloat( $row['per_request'] ?? null ),
				'currency'                 => 3 === strlen( $currency ) ? $currency : 'USD',
				'source_note'              => substr( sanitize_text_field( (string) ( $row['source_note'] ?? '' ) ), 0, 200 ),
			);
		}
		return $clean;
	}

	/**
	 * Sanitise the business's services: name and page address.
	 *
	 * @param array<int, mixed> $rows Untrusted rows.
	 * @return array<int, array{name: string, url: string}>
	 */
	public static function sanitizeServices( array $rows ): array {
		$clean = array();
		foreach ( array_slice( array_values( $rows ), 0, 30 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = substr( sanitize_text_field( (string) ( $row['name'] ?? '' ) ), 0, 120 );
			if ( '' === $name ) {
				continue;
			}
			$clean[] = array(
				'name' => $name,
				'url'  => esc_url_raw( trim( (string) ( $row['url'] ?? '' ) ), array( 'https', 'http' ) ),
			);
		}
		return $clean;
	}

	/**
	 * Parse a non-negative float or null.
	 *
	 * @param mixed $value Input.
	 */
	private static function nonNegativeFloat( mixed $value ): ?float {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		return max( 0.0, (float) $value );
	}
}
