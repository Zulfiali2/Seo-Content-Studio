<?php
/**
 * Encrypted, server-side credential storage.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Stores API credentials encrypted in a non-autoloaded option.
 *
 * Plaintext values are only ever returned to server-side adapters. Anything sent
 * to the browser goes through status(), which exposes a masked hint only.
 */
class CredentialStore {

	public const OPTION = 'aiscs_credentials';

	/** Maximum accepted secret length. */
	private const MAX_LENGTH = 4096;

	/**
	 * Request cache of decrypted values.
	 *
	 * @var array<string, string|null>
	 */
	private array $plain = array();

	/**
	 * Constructor.
	 *
	 * @param Encryption $encryption Cipher.
	 */
	public function __construct( private readonly Encryption $encryption, private readonly ?Encryption $previous = null ) {}

	/**
	 * Store a secret.
	 *
	 * @param string $owner Provider/service id.
	 * @param string $field Field name, e.g. "api_key".
	 * @param string $value Plaintext secret.
	 * @throws \InvalidArgumentException When the value is empty or too long.
	 */
	public function set( string $owner, string $field, string $value ): void {
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > self::MAX_LENGTH || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid credential value.' );
		}
		$all                            = $this->raw();
		$all[ $owner ][ $field ]        = $this->encryption->encrypt( $value );
		$this->plain[ "$owner.$field" ] = $value;
		$this->save( $all );
	}

	/**
	 * Get a decrypted secret, or null when absent/unreadable.
	 *
	 * @param string $owner Provider/service id.
	 * @param string $field Field name.
	 */
	public function get( string $owner, string $field ): ?string {
		$key = "$owner.$field";
		if ( ! array_key_exists( $key, $this->plain ) ) {
			$cipher              = $this->raw()[ $owner ][ $field ] ?? null;
			$this->plain[ $key ] = is_string( $cipher ) ? $this->encryption->decrypt( $cipher ) : null;
			// Key rotation: a value the current key cannot open is tried with the previous key
			// and, when that works, re-encrypted with the current key.
			if ( null === $this->plain[ $key ] && is_string( $cipher ) && null !== $this->previous ) {
				$old = $this->previous->decrypt( $cipher );
				if ( null !== $old ) {
					$all                     = $this->raw();
					$all[ $owner ][ $field ] = $this->encryption->encrypt( $old );
					$this->save( $all );
					$this->plain[ $key ] = $old;
				}
			}
		}
		return $this->plain[ $key ];
	}

	/**
	 * Whether a secret is stored and decryptable.
	 *
	 * @param string $owner Provider/service id.
	 * @param string $field Field name.
	 */
	public function has( string $owner, string $field ): bool {
		return null !== $this->get( $owner, $field );
	}

	/**
	 * Delete a secret.
	 *
	 * @param string $owner Provider/service id.
	 * @param string $field Field name.
	 */
	public function delete( string $owner, string $field ): void {
		$all = $this->raw();
		unset( $all[ $owner ][ $field ] );
		if ( empty( $all[ $owner ] ) ) {
			unset( $all[ $owner ] );
		}
		unset( $this->plain[ "$owner.$field" ] );
		$this->save( $all );
	}

	/**
	 * Browser-safe status: never contains the secret.
	 *
	 * @param string $owner Provider/service id.
	 * @param string $field Field name.
	 * @return array{stored: bool, readable: bool, hint: string}
	 */
	public function status( string $owner, string $field ): array {
		$stored = is_string( $this->raw()[ $owner ][ $field ] ?? null );
		$value  = $stored ? $this->get( $owner, $field ) : null;
		return array(
			'stored'   => $stored,
			'readable' => null !== $value,
			'hint'     => null !== $value && strlen( $value ) >= 12 ? '…' . substr( $value, -4 ) : ( null !== $value ? '…' : '' ),
		);
	}

	/**
	 * All decryptable secrets (used only by the log Redactor).
	 *
	 * @return string[]
	 */
	public function allPlaintextSecrets(): array {
		$out = array();
		foreach ( $this->raw() as $owner => $fields ) {
			foreach ( array_keys( (array) $fields ) as $field ) {
				$value = $this->get( (string) $owner, (string) $field );
				if ( null !== $value ) {
					$out[] = $value;
				}
			}
		}
		return $out;
	}

	/**
	 * Raw encrypted array.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function raw(): array {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Persist without autoloading.
	 *
	 * @param array<string, array<string, string>> $all Encrypted values.
	 */
	private function save( array $all ): void {
		update_option( self::OPTION, $all, false );
	}
}
