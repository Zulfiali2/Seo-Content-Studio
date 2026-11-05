<?php
/**
 * A project import step that cannot continue.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Carries a machine code (for the REST error and tests) and a message for the user that says
 * what failed and, where known, which file caused it and what to do next.
 */
final class ImportException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string   $error_code Machine code, e.g. "zip_invalid".
	 * @param string   $message    Message for the user.
	 * @param string[] $details    Further lines (files, reasons).
	 */
	public function __construct( public readonly string $error_code, string $message, public readonly array $details = array() ) {
		parent::__construct( $message );
	}
}
