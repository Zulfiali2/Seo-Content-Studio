<?php
/**
 * Secret-safe logger.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Logging;

use AiSeoContentStudio\Security\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Writes redacted messages to the PHP error log.
 *
 * Errors are logged when WP_DEBUG_LOG is enabled; debug/info messages only when
 * the plugin's debug logging setting is on. Request/response bodies are never
 * logged, only metadata.
 */
class Logger {

	/**
	 * Destination, injectable for tests.
	 *
	 * @var callable(string): void
	 */
	private $writer;

	/**
	 * Constructor.
	 *
	 * @param Redactor      $redactor Redactor.
	 * @param bool          $debug    Whether debug/info messages are written.
	 * @param callable|null $writer   Receives the final line.
	 */
	public function __construct( private readonly Redactor $redactor, private readonly bool $debug = false, ?callable $writer = null ) {
		$this->writer = $writer ?? static function ( string $line ): void {
			error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional, redacted.
		};
	}

	/**
	 * Log an error.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function error( string $message, array $context = array() ): void {
		if ( $this->debug || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
			$this->write( 'error', $message, $context );
		}
	}

	/**
	 * Log a warning.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function warning( string $message, array $context = array() ): void {
		if ( $this->debug || ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
			$this->write( 'warning', $message, $context );
		}
	}

	/**
	 * Log debug information.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	public function debug( string $message, array $context = array() ): void {
		if ( $this->debug ) {
			$this->write( 'debug', $message, $context );
		}
	}

	/**
	 * Format and write.
	 *
	 * @param string               $level   Level.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 */
	private function write( string $level, string $message, array $context ): void {
		$line = sprintf( '[AI SEO Content Studio] %s: %s', strtoupper( $level ), $this->redactor->redact( $message ) );
		if ( array() !== $context ) {
			$line .= ' ' . wp_json_encode( $this->redactor->redactArray( $context ) );
		}
		( $this->writer )( $line );
	}
}
