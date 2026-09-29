<?php
/**
 * Multisite support: sites created or deleted after network activation.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure;

use Terminarz\Infrastructure\Database\Schema;
use WP_Site;

/**
 * Keeps per-site plugin data in sync with the network (ADR-045):
 *
 * - a site created while the plugin is network-active gets the schema and the capability (`wp_initialize_site`),
 * - deleting a site drops the plugin tables together with the core ones (`wpmu_drop_tables`).
 *
 * Every hook is a no-op on single-site installations.
 */
final class Multisite implements Module {

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		if ( ! is_multisite() ) {
			return;
		}
		// Priority 200: after core created the site tables and populated its options/roles (priority 10–100).
		add_action( 'wp_initialize_site', array( $this, 'initialize_site' ), 200 );
		add_filter( 'wpmu_drop_tables', array( $this, 'drop_tables' ), 10, 2 );
	}

	/**
	 * `wp_initialize_site`: installs the plugin in a new site when the plugin is network-active.
	 *
	 * @param mixed $site New site.
	 */
	public function initialize_site( $site ): void {
		if ( ! $site instanceof WP_Site || ! self::is_network_active() ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		try {
			Lifecycle::activate_site();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * `wpmu_drop_tables`: adds the plugin tables of the deleted site to the list of tables to drop.
	 *
	 * @param mixed $tables  Tables to drop.
	 * @param mixed $site_id Site ID.
	 * @return mixed Tables to drop.
	 */
	public function drop_tables( $tables, $site_id = 0 ) {
		global $wpdb;
		if ( ! is_array( $tables ) || (int) $site_id <= 0 ) {
			return $tables;
		}

		$schema = new Schema( $wpdb, $wpdb->get_blog_prefix( (int) $site_id ) );
		foreach ( $schema->tables() as $table ) {
			if ( ! in_array( $table, $tables, true ) ) {
				$tables[] = $table;
			}
		}
		return $tables;
	}

	/**
	 * Whether Terminarz is network-activated.
	 */
	public static function is_network_active(): bool {
		if ( ! is_multisite() || ! defined( 'TRMZ_FILE' ) ) {
			return false;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active_for_network( plugin_basename( TRMZ_FILE ) );
	}
}
