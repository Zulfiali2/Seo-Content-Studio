<?php
/**
 * Conversation message.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * One user or assistant turn.
 */
final readonly class Message {

	/**
	 * Constructor.
	 *
	 * @param string $role    "user" or "assistant".
	 * @param string $content Text content.
	 * @throws \InvalidArgumentException On an unknown role.
	 */
	public function __construct( public string $role, public string $content ) {
		if ( ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
			throw new \InvalidArgumentException( 'Unsupported message role.' );
		}
	}

	/**
	 * Shorthand for a user message.
	 *
	 * @param string $content Text.
	 */
	public static function user( string $content ): self {
		return new self( 'user', $content );
	}
}
