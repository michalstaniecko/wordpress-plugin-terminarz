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

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $trmz_plugin_dir ): void {
		require $trmz_plugin_dir . '/terminarz.php';
	}
);

require $trmz_tests_dir . '/includes/bootstrap.php';
