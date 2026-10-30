<?php
/**
 * Connection test outcome.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Result of ProviderInterface::testConnection().
 */
final readonly class ConnectionResult {

	public const OK      = 'ok';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	/**
	 * Constructor.
	 *
	 * @param string      $status    OK, WARNING or ERROR.
	 * @param string      $message   Human-readable, secret-free message.
	 * @param ModelInfo[] $models    Models discovered during the test.
	 * @param string      $errorCode Error category when not OK.
	 */
	public function __construct(
		public string $status,
		public string $message,
		public array $models = array(),
		public string $errorCode = '',
	) {}

	/**
	 * Build from an exception.
	 *
	 * @param ProviderException $e Exception.
	 */
	public static function fromException( ProviderException $e ): self {
		return new self( self::ERROR, $e->getMessage(), array(), $e->category );
	}

	/**
	 * Array form for REST responses.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'status'     => $this->status,
			'message'    => $this->message,
			'error_code' => $this->errorCode,
			'models'     => array_map( static fn ( ModelInfo $m ): array => $m->toArray(), $this->models ),
		);
	}
}
