<?php
/**
 * Authenticated symmetric encryption for stored credentials.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Libsodium secretbox (XSalsa20-Poly1305) wrapper.
 *
 * Encryption at rest protects keys from casual exposure in database dumps and
 * backups. It cannot protect against an attacker who can execute PHP on the site,
 * because the key must be available to PHP. See docs/SECURITY.md.
 */
class Encryption {

	private const PREFIX = 'aiscs1:';

	/**
	 * Constructor.
	 *
	 * @param string $key 32-byte binary key.
	 * @throws \InvalidArgumentException When the key length is wrong.
	 */
	public function __construct( private readonly string $key ) {
		if ( SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			throw new \InvalidArgumentException( 'Encryption key must be 32 bytes.' );
		}
	}

	/**
	 * Build from AISCS_ENCRYPTION_KEY if defined, otherwise derive from WordPress salts.
	 */
	public static function fromEnvironment(): self {
		if ( defined( 'AISCS_ENCRYPTION_KEY' ) && '' !== (string) constant( 'AISCS_ENCRYPTION_KEY' ) ) {
			$material = (string) constant( 'AISCS_ENCRYPTION_KEY' );
		} else {
			$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}
		return new self( hash_hmac( 'sha256', 'aiscs-credentials-v1', $material, true ) );
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * @param string $plaintext Secret.
	 */
	public function encrypt( string $plaintext ): string {
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );
		return self::PREFIX . sodium_bin2base64( $nonce . $cipher, SODIUM_BASE64_VARIANT_ORIGINAL );
	}

	/**
	 * Decrypt; returns null when the value is malformed or was encrypted with a different key.
	 *
	 * @param string $encoded Ciphertext produced by encrypt().
	 */
	public function decrypt( string $encoded ): ?string {
		if ( ! str_starts_with( $encoded, self::PREFIX ) ) {
			return null;
		}
		try {
			$raw = sodium_base642bin( substr( $encoded, strlen( self::PREFIX ) ), SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( \SodiumException ) {
			return null;
		}
		if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key );
		return false === $plain ? null : $plain;
	}
}
