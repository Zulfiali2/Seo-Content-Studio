<?php
/**
 * Normalised provider error.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by adapters and the HTTP client. Messages must never contain secrets.
 */
class ProviderException extends \RuntimeException {

	public const AUTH             = 'auth';
	public const PERMISSION       = 'permission';
	public const RATE_LIMIT       = 'rate_limit';
	public const QUOTA            = 'quota';
	public const TIMEOUT          = 'timeout';
	public const NETWORK          = 'network';
	public const INVALID_REQUEST  = 'invalid_request';
	public const NOT_FOUND        = 'not_found';
	public const SERVER           = 'server';
	public const INVALID_RESPONSE = 'invalid_response';
	public const REFUSAL          = 'refusal';
	public const TRUNCATED        = 'truncated';
	public const CONFIG           = 'config';
	public const LIMIT            = 'limit';
	public const UNSAFE_URL       = 'unsafe_url';

	/**
	 * Constructor.
	 *
	 * @param string   $message    Safe, human-readable message.
	 * @param string   $category   One of the constants above.
	 * @param bool     $retryable  Whether retrying later may succeed.
	 * @param int      $httpStatus Upstream HTTP status (0 if none).
	 * @param int|null $retryAfter Seconds suggested by the provider.
	 */
	public function __construct(
		string $message,
		public readonly string $category,
		public readonly bool $retryable = false,
		public readonly int $httpStatus = 0,
		public readonly ?int $retryAfter = null,
	) {
		parent::__construct( $message );
	}
}
