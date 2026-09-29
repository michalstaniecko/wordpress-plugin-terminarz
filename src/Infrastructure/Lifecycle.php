<?php
/**
 * Activation and deactivation handlers.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure;

/**
 * Callbacks for register_activation_hook() / register_deactivation_hook().
 */
final class Lifecycle {

	/**
	 * Runs on plugin activation.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated on multisite.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}

		self::activate_site();
	}

	/**
	 * Runs on plugin deactivation. Data and capabilities are kept; they are removed only by uninstall.php.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated on multisite.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		// Nothing to clean up yet (scheduled events will be unscheduled here in later milestones).
		unset( $network_wide );
	}

	/**
	 * Activation steps for a single site.
	 */
	private static function activate_site(): void {
		Capabilities::grant();
	}
}
