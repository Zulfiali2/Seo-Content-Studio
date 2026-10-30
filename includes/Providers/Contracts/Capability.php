<?php
/**
 * Provider capability flags.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * What an adapter can do. Adapters declare only capabilities their API supports.
 */
enum Capability: string {
	case TEXT              = 'text';
	case STRUCTURED_OUTPUT = 'structured_output';
	case IMAGE             = 'image';
	case VISION            = 'vision';
	case MODEL_LISTING     = 'model_listing';
	case USAGE_REPORTING   = 'usage_reporting';
}
