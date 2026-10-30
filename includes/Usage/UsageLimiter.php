<?php
/**
 * Per-hour operation limits.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Usage;

use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Enforces the configured "requests per hour" limits for each operation type.
 * A limit of 0 disables that operation entirely.
 */
class UsageLimiter {

	/**
	 * Constructor.
	 *
	 * @param UsageRepository    $usage    Usage log.
	 * @param SettingsRepository $settings Settings.
	 */
	public function __construct( private readonly UsageRepository $usage, private readonly SettingsRepository $settings ) {}

	/**
	 * Throw when the operation is over its hourly limit.
	 *
	 * @param string $operation "text", "research" or "image".
	 * @throws ProviderException With category LIMIT.
	 */
	public function check( string $operation ): void {
		$limit = (int) $this->settings->get( 'limits.' . $operation . '_requests_per_hour', 0 );
		if ( $limit <= 0 ) {
			throw new ProviderException(
				/* translators: %s: operation type. */
				sprintf( __( '%s requests are disabled by the usage limits in settings.', 'ai-seo-content-studio' ), ucfirst( $operation ) ),
				ProviderException::LIMIT
			);
		}
		$used = $this->usage->countSince( $operation, time() - HOUR_IN_SECONDS );
		if ( $used >= $limit ) {
			throw new ProviderException(
				/* translators: 1: operation type, 2: limit. */
				sprintf( __( 'The hourly limit of %2$d %1$s requests has been reached. Try again later or raise the limit in settings.', 'ai-seo-content-studio' ), $operation, $limit ),
				ProviderException::LIMIT
			);
		}
	}
}
