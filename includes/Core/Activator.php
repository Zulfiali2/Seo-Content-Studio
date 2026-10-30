<?php
/**
 * Activation routine.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Core;

use AiSeoContentStudio\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation.
 */
final class Activator {

	/**
	 * Install capabilities, default settings and database tables.
	 *
	 * Existing settings and credentials are kept so re-activation is lossless.
	 */
	public static function activate(): void {
		Capabilities::install();

		if ( false === get_option( SettingsRepository::OPTION ) ) {
			add_option( SettingsRepository::OPTION, ( new SettingsRepository() )->all() );
		}

		Migrations::migrate();
	}
}
