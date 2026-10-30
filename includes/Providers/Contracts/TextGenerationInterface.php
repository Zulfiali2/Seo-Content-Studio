<?php
/**
 * Text and structured generation contract.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by adapters that can generate text.
 */
interface TextGenerationInterface {

	/**
	 * Generate text. When $request->jsonSchema is set, the adapter uses the API's
	 * native structured-output mechanism (where supported), decodes the JSON and
	 * returns it in TextResponse::$data. Invalid JSON raises ProviderException.
	 *
	 * @param TextRequest $request Request.
	 * @throws ProviderException On any failure.
	 */
	public function generate( TextRequest $request ): TextResponse;
}
