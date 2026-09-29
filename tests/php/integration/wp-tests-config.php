<?php
/**
 * WordPress test suite configuration.
 *
 * Defaults match the wp-env tests environment (test-only credentials). Override with environment variables
 * to run against another database. The dedicated table prefix keeps the E2E site in the same database intact:
 * the test suite drops and recreates every table with this prefix.
 *
 * @package Terminarz\Tests
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- this is a wp-config file.

$trmz_env = static function ( string $name, string $fallback ): string {
	$value = getenv( $name );
	return false === $value || '' === $value ? $fallback : $value;
};

define( 'ABSPATH', rtrim( $trmz_env( 'TRMZ_TESTS_ABSPATH', '/var/www/html' ), '/' ) . '/' );

define( 'DB_NAME', $trmz_env( 'WORDPRESS_DB_NAME', 'wordpress' ) );
define( 'DB_USER', $trmz_env( 'WORDPRESS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', $trmz_env( 'WORDPRESS_DB_PASSWORD', 'password' ) );
define( 'DB_HOST', $trmz_env( 'WORDPRESS_DB_HOST', 'mysql' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = $trmz_env( 'TRMZ_TESTS_TABLE_PREFIX', 'wptests_' );

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );

// `WP_TESTS_MULTISITE=1` runs the suite on a multisite network (`composer test:integration:multisite`).
if ( '1' === $trmz_env( 'WP_TESTS_MULTISITE', '0' ) ) {
	define( 'WP_TESTS_MULTISITE', true );
}

unset( $trmz_env );
