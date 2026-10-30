<?php
/**
 * Settings persistence.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin settings option.
 */
class SettingsRepository {

	public const OPTION = 'aiscs_settings';

	/**
	 * Request-level cache.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * All settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = SettingsSchema::merge( SettingsSchema::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return $this->cache;
	}

	/**
	 * Get a value by dotted path, e.g. "ai.providers.openai.text_model".
	 *
	 * @param string $path    Dotted path.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public function get( string $path, mixed $fallback = null ): mixed {
		$node = $this->all();
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				return $fallback;
			}
			$node = $node[ $segment ];
		}
		return $node;
	}

	/**
	 * Sanitise and persist a (partial) update.
	 *
	 * @param array<string, mixed> $input Untrusted input.
	 * @return array<string, mixed> The stored settings.
	 */
	public function update( array $input ): array {
		$clean = SettingsSchema::sanitize( $input, $this->all() );
		update_option( self::OPTION, $clean );
		$this->cache = $clean;
		return $clean;
	}
}
