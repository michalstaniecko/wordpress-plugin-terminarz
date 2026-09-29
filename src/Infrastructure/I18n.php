<?php
/**
 * Translations loader.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure;

defined( 'ABSPATH' ) || exit; // No direct access.

/**
 * Loads the plugin text domain from the bundled languages/ directory.
 */
final class I18n implements Module {

	public const TEXT_DOMAIN = 'terminarz';

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Loads bundled translations (translations from translate.wordpress.org take precedence).
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( TRMZ_FILE ) ) . '/languages' );
	}
}
