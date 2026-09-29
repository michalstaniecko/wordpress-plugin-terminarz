<?php
/**
 * Integration test bootstrap: loads the WordPress test suite and the plugin.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

$trmz_plugin_dir = dirname( __DIR__, 3 );

require_once $trmz_plugin_dir . '/vendor/autoload.php';

$trmz_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $trmz_tests_dir || '' === $trmz_tests_dir ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run the suite through `composer test:integration` (wp-env) or point WP_TESTS_DIR to the WordPress test library.\n" );
	exit( 1 );
}

define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $trmz_plugin_dir . '/vendor/yoast/phpunit-polyfills' );

require_once $trmz_tests_dir . '/includes/functions.php';

/*
 * WooCommerce (mounted by wp-env) is loaded by default, so the payment integration is tested against the real plugin.
 * `TRMZ_TESTS_WOOCOMMERCE=0` runs the suite without it — use with `--group no-woocommerce` (tests needing WooCommerce
 * are in the `woocommerce` group and skip themselves when it is missing).
 */
$trmz_load_woocommerce = '0' !== getenv( 'TRMZ_TESTS_WOOCOMMERCE' );

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $trmz_plugin_dir, $trmz_load_woocommerce ): void {
		$woocommerce = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
		if ( $trmz_load_woocommerce && is_readable( $woocommerce ) ) {
			// Same order as on a real site: Terminarz first (alphabetically), WooCommerce afterwards.
			require $trmz_plugin_dir . '/terminarz.php';
			require $woocommerce;
			return;
		}
		require $trmz_plugin_dir . '/terminarz.php';
	}
);

// Installs WooCommerce tables, pages and roles (like WooCommerce's own test bootstrap) with HPOS as the order store.
// Runs late on `init`, after Action Scheduler initialised its data store (the installer unschedules actions).
tests_add_filter(
	'init',
	static function (): void {
		if ( ! class_exists( 'WC_Install' ) ) {
			return;
		}
		if ( ! defined( 'WC_USE_TRANSACTIONS' ) ) {
			define( 'WC_USE_TRANSACTIONS', false );
		}
		WC_Install::install();
		if ( class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class ) ) {
			wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->create_database_tables();
			update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
			update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		}
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reload roles after the install added capabilities.
		wp_roles();
	},
	99
);

// Every test runs inside a transaction opened by the WordPress test suite: plugin transactions must use savepoints
// (a nested START TRANSACTION would commit the test's data). See Terminarz\Infrastructure\Database\Transaction.
tests_add_filter( 'trmz_db_inside_external_transaction', '__return_true' );

require $trmz_tests_dir . '/includes/bootstrap.php';
