<?php
/**
 * Provider-neutral cost settings for text requests.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * One place that decides, per request purpose, how much the AI may "think" (effort) and
 * whether the cheaper "quick tasks" model is used. Each provider adapter translates the
 * effort into its own API field, so switching providers keeps the same behaviour.
 *
 * - economy:  low effort everywhere; quick tasks on the quick model.
 * - balanced: medium effort for writing, low for quick tasks; quick tasks on the quick model.
 * - quality:  the provider's default effort and the main model for everything.
 */
final class CostPolicy {

	public const ECONOMY  = 'economy';
	public const BALANCED = 'balanced';
	public const QUALITY  = 'quality';

	/** Short, structured jobs that do not need the main writing model. */
	public const QUICK_PURPOSES = array( 'content_meta', 'seo_evaluation', 'optimize_analysis', 'connection_test' );

	/**
	 * Normalised mode.
	 *
	 * @param mixed $mode Setting value.
	 */
	public static function mode( mixed $mode ): string {
		return in_array( $mode, array( self::ECONOMY, self::BALANCED, self::QUALITY ), true ) ? (string) $mode : self::BALANCED;
	}

	/**
	 * Whether a purpose is a quick task.
	 *
	 * @param string $purpose Purpose.
	 */
	public static function isQuick( string $purpose ): bool {
		return in_array( $purpose, self::QUICK_PURPOSES, true );
	}

	/**
	 * Thinking effort for a purpose: low | medium | '' (provider default).
	 *
	 * @param string $purpose Purpose.
	 * @param string $mode    Cost mode.
	 */
	public static function effort( string $purpose, string $mode ): string {
		return match ( self::mode( $mode ) ) {
			self::ECONOMY => 'low',
			self::QUALITY => '',
			default => self::isQuick( $purpose ) ? 'low' : 'medium',
		};
	}

	/**
	 * Whether the quick model should be used for a purpose.
	 *
	 * @param string $purpose Purpose.
	 * @param string $mode    Cost mode.
	 */
	public static function useQuickModel( string $purpose, string $mode ): bool {
		return self::QUALITY !== self::mode( $mode ) && self::isQuick( $purpose );
	}
}
