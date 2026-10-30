<?php
/**
 * Draft creation failure with the reasons.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Engine;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by DraftManager; carries a machine code, user-facing errors and warnings.
 */
final class DraftException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string   $reason   Code (invalid_document, forbidden, not_studio_page, published, ...).
	 * @param string[] $errors   Messages.
	 * @param string[] $warnings Warnings.
	 */
	public function __construct( public readonly string $reason, public readonly array $errors = array(), public readonly array $warnings = array() ) {
		parent::__construct( $errors[0] ?? $reason );
	}

	/**
	 * HTTP status for the REST layer.
	 */
	public function status(): int {
		return match ( $this->reason ) {
			'forbidden'                                  => 403,
			'not_found'                                  => 404,
			'not_studio_page', 'published'               => 409,
			'invalid_document'                           => 422,
			'elementor_inactive', 'elementor_too_old', 'widgets_missing' => 424,
			default                                      => 500,
		};
	}
}
