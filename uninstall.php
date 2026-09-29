<?php
/**
 * Uninstall handler: runs when the plugin is deleted in WordPress (the plugin is not active at this point).
 *
 * Scheduled jobs are always removed; data only when "Delete data on uninstall" is enabled in the settings
 * (per site on multisite). See Terminarz\Infrastructure\Uninstaller.
 *
 * @package Terminarz
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.1', '<' ) || ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return; // The plugin code cannot run here: leave everything as it is.
}

require_once __DIR__ . '/vendor/autoload.php';

\Terminarz\Infrastructure\Uninstaller::run();
