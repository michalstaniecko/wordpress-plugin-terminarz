<?php
/**
 * Plugin Name:       Terminarz
 * Description:       Online appointment booking for service businesses, with optional WooCommerce payments.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Michał Staniećko
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       terminarz
 * Domain Path:       /languages
 *
 * @package Terminarz
 */

// This file must stay parseable by old PHP versions: it only checks requirements
// and hands over to the namespaced code in src/. Do not add logic here.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TRMZ_VERSION', '0.1.0' );
define( 'TRMZ_FILE', __FILE__ );
define( 'TRMZ_MIN_PHP', '8.1' );
define( 'TRMZ_MIN_WP', '6.5' );

/**
 * Returns a list of unmet requirements (empty when everything is fine).
 *
 * @return string[]
 */
function trmz_unmet_requirements() {
	$errors = array();

	if ( version_compare( PHP_VERSION, TRMZ_MIN_PHP, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			__( 'Terminarz requires PHP %1$s or newer. You are running PHP %2$s.', 'terminarz' ),
			TRMZ_MIN_PHP,
			PHP_VERSION
		);
	}

	$wp_version = (string) get_bloginfo( 'version' );
	if ( version_compare( $wp_version, TRMZ_MIN_WP, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			__( 'Terminarz requires WordPress %1$s or newer. You are running WordPress %2$s.', 'terminarz' ),
			TRMZ_MIN_WP,
			$wp_version
		);
	}

	if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
		$errors[] = __( 'Terminarz is missing its Composer autoloader. Run "composer install" in the plugin directory or install a release package.', 'terminarz' );
	}

	return $errors;
}

$trmz_requirement_errors = trmz_unmet_requirements();

if ( array() !== $trmz_requirement_errors ) {
	add_action(
		'admin_notices',
		function () use ( $trmz_requirement_errors ) {
			foreach ( $trmz_requirement_errors as $trmz_error ) {
				echo '<div class="notice notice-error"><p>' . esc_html( $trmz_error ) . '</p></div>';
			}
		}
	);
	return;
}

unset( $trmz_requirement_errors );

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook( __FILE__, array( \Terminarz\Infrastructure\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Terminarz\Infrastructure\Lifecycle::class, 'deactivate' ) );

\Terminarz\Plugin::instance()->boot();
