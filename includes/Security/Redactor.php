<?php
/**
 * Removes secrets from strings and arrays before they are logged or returned.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Secret redactor.
 */
class Redactor {

	private const MASK = '[redacted]';

	/** Array keys whose values are always masked. */
	private const SENSITIVE_KEYS = array( 'api_key', 'apikey', 'key', 'authorization', 'x-api-key', 'x-goog-api-key', 'password', 'secret', 'token', 'access_token', 'login' );

	/** Patterns for well-known credential formats. */
	private const PATTERNS = array(
		'/\bsk-[A-Za-z0-9_\-]{16,}/',          // OpenAI / Anthropic style keys.
		'/\bAIza[0-9A-Za-z_\-]{30,}/',         // Google API keys.
		'/\bBearer\s+[A-Za-z0-9._\-]{8,}/i',   // Bearer tokens.
		'/([?&](?:key|api_key|token)=)[^&\s]+/i', // Keys in query strings.
	);

	/**
	 * Callables returning known secret values.
	 *
	 * @var array<int, callable(): string[]>
	 */
	private array $sources = array();

	/**
	 * Register a source of exact secret values to mask.
	 *
	 * @param callable $source Callable returning string[].
	 */
	public function addSecretSource( callable $source ): void {
		$this->sources[] = $source;
	}

	/**
	 * Redact a string.
	 *
	 * @param string $text Input.
	 */
	public function redact( string $text ): string {
		foreach ( $this->sources as $source ) {
			foreach ( (array) $source() as $secret ) {
				if ( is_string( $secret ) && strlen( $secret ) >= 6 ) {
					$text = str_replace( $secret, self::MASK, $text );
				}
			}
		}
		foreach ( self::PATTERNS as $pattern ) {
			$text = (string) preg_replace_callback(
				$pattern,
				static fn ( array $m ): string => isset( $m[1] ) && str_starts_with( $m[0], $m[1] ) ? $m[1] . self::MASK : self::MASK,
				$text
			);
		}
		return $text;
	}

	/**
	 * Redact recursively in an array (keys and values).
	 *
	 * @param array<mixed> $data Input.
	 * @return array<mixed>
	 */
	public function redactArray( array $data ): array {
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), self::SENSITIVE_KEYS, true ) ) {
				$out[ $key ] = self::MASK;
			} elseif ( is_array( $value ) ) {
				$out[ $key ] = $this->redactArray( $value );
			} elseif ( is_string( $value ) ) {
				$out[ $key ] = $this->redact( $value );
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}
}
