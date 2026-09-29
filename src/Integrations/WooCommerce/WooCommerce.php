<?php
/**
 * WooCommerce detection.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Integrations\WooCommerce;

/**
 * Tells whether a supported WooCommerce is loaded. The single place deciding if payments can be used.
 *
 * Reliable from `plugins_loaded` on (WooCommerce loads after this plugin, alphabetically).
 */
final class WooCommerce {

	/**
	 * Minimum supported WooCommerce version.
	 */
	public const MIN_VERSION = '8.0';

	/**
	 * Whether WooCommerce is loaded, whatever its version.
	 */
	public static function is_loaded(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_create_order' );
	}

	/**
	 * Loaded WooCommerce version ('' when not loaded).
	 */
	public static function version(): string {
		if ( ! self::is_loaded() ) {
			return '';
		}
		return defined( 'WC_VERSION' ) ? (string) constant( 'WC_VERSION' ) : '';
	}

	/**
	 * Whether a supported WooCommerce (>= MIN_VERSION) is loaded — payments and the integration are available.
	 */
	public static function is_active(): bool {
		$version = self::version();
		$active  = '' !== $version && version_compare( $version, self::MIN_VERSION, '>=' );

		/**
		 * Filters whether the WooCommerce integration (online payments) is available.
		 *
		 * Returning true without a loaded WooCommerce is not supported.
		 *
		 * @param bool   $active  Whether a supported WooCommerce is loaded.
		 * @param string $version Loaded WooCommerce version ('' when not loaded).
		 */
		return (bool) apply_filters( 'trmz_woocommerce_active', $active, $version );
	}

	/**
	 * Whether WooCommerce is loaded but older than MIN_VERSION.
	 */
	public static function is_outdated(): bool {
		$version = self::version();
		return '' !== $version && version_compare( $version, self::MIN_VERSION, '<' );
	}
}
