<?php
/**
 * Cache for paid research calls.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Research;

defined( 'ABSPATH' ) || exit;

/**
 * Transient-backed cache keyed by provider, call and parameters, so repeating
 * research (or retrying an interrupted job) does not pay for the same data twice.
 */
class ResearchCache {

	/**
	 * Get a cached value.
	 *
	 * @param string               $call   Call name.
	 * @param array<string, mixed> $params Parameters.
	 * @return array<string, mixed>|null Stored payload with 'data' and 'cached_at'.
	 */
	public function get( string $call, array $params ): ?array {
		$value = get_transient( $this->key( $call, $params ) );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Store a value.
	 *
	 * @param string               $call   Call name.
	 * @param array<string, mixed> $params Parameters.
	 * @param array<string, mixed> $data   Data.
	 * @param int                  $ttl    Seconds.
	 */
	public function set( string $call, array $params, array $data, int $ttl ): void {
		set_transient(
			$this->key( $call, $params ),
			array(
				'data'      => $data,
				'cached_at' => time(),
			),
			max( 60, $ttl )
		);
	}

	/**
	 * Transient key (prefixed so uninstall can remove it).
	 *
	 * @param string               $call   Call name.
	 * @param array<string, mixed> $params Parameters.
	 */
	private function key( string $call, array $params ): string {
		ksort( $params );
		return 'aiscs_rc_' . substr( hash( 'sha256', $call . '|' . wp_json_encode( $params ) ), 0, 32 );
	}
}
