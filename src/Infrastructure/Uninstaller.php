<?php
/**
 * Plugin uninstallation (called from uninstall.php).
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Infrastructure\Database\Migrator;
use Terminarz\Infrastructure\Database\Schema;
use Terminarz\Notifications\ReminderScheduler;
use Terminarz\Notifications\Templates;

/**
 * Removes the plugin from every site (ADR-046):
 *
 * - scheduled jobs (hold expiry, reminders, any other Action Scheduler action of the `terminarz` group) are ALWAYS
 *   removed, so nothing runs a callback that no longer exists;
 * - data (tables, `trmz_*` options, transients, the capability) is removed only when the site enabled
 *   "Delete data on uninstall" in the plugin settings.
 *
 * WooCommerce order meta (`_trmz_*`) belongs to the shop's orders and is always kept.
 * Runs without the plugin being active: must not depend on hooks registered by the plugin modules.
 */
final class Uninstaller {

	/**
	 * Uninstalls the plugin from the current site or, on multisite, from every site of the network.
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::uninstall_site();
			return;
		}

		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				self::uninstall_site();
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Uninstalls the plugin from the current site.
	 *
	 * @return bool Whether the site data was deleted.
	 */
	public static function uninstall_site(): bool {
		self::unschedule_jobs();

		if ( ! Settings::load()->delete_data_on_uninstall() ) {
			return false;
		}

		self::delete_data();
		return true;
	}

	/**
	 * Removes every scheduled job of the plugin (WP-Cron and Action Scheduler).
	 */
	public static function unschedule_jobs(): void {
		HoldExpiryScheduler::unschedule();
		ReminderScheduler::unschedule_all();

		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) > 0 ) {
			// Empty hook + group: cancels every pending action of the group.
			as_unschedule_all_actions( '', array(), HoldExpiryScheduler::GROUP );
		}
	}

	/**
	 * Deletes the tables, options, transients and the capability of the current site.
	 */
	public static function delete_data(): void {
		global $wpdb;

		Schema::from_globals()->drop();

		foreach ( array( Settings::OPTION, Templates::OPTION, Schema::VERSION_OPTION, Migrator::LOCK_OPTION ) as $option ) {
			delete_option( $option );
		}

		// Remaining `trmz_*` options and transients (rate limiter, admin notices); their names are not known upfront.
		// Deleted one by one through the options API, so the object cache stays consistent. With a persistent object
		// cache transients never reach the database; they are short-lived and expire on their own.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off lookup of option names.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'trmz_' ) . '%',
				$wpdb->esc_like( '_transient_trmz_' ) . '%'
			)
		);
		foreach ( $names as $name ) {
			$name = (string) $name;
			if ( str_starts_with( $name, '_transient_' ) ) {
				delete_transient( substr( $name, strlen( '_transient_' ) ) );
			} else {
				delete_option( $name );
			}
		}

		Capabilities::revoke();
	}
}
