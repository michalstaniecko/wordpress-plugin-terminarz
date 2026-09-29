<?php
/**
 * Price parsing and formatting in the shop currency.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

defined( 'ABSPATH' ) || exit; // No direct access.

/**
 * Prices are stored as integers in minor units (ADR-015). The currency and the number of decimals come from WooCommerce
 * when it is active; without WooCommerce there are no payments, the price is informational and the currency is taken
 * from the `trmz_currency` filter (default: none).
 */
final class Money {

	/**
	 * Upper bound of a price in minor units (keeps values far below the column and float precision limits).
	 */
	public const MAX_MINOR = 100000000000;

	/**
	 * Currency code (e.g. "PLN"), '' when unknown.
	 */
	public static function currency(): string {
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';

		/**
		 * Filters the currency code shown next to service prices.
		 *
		 * @param string $currency Currency code; WooCommerce currency when WooCommerce is active, '' otherwise.
		 */
		return sanitize_text_field( (string) apply_filters( 'trmz_currency', $currency ) );
	}

	/**
	 * Number of decimals of the currency (0–4).
	 */
	public static function decimals(): int {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		/**
		 * Filters the number of decimals of service prices.
		 *
		 * @param int $decimals Decimals; WooCommerce setting when active, 2 otherwise.
		 */
		return max( 0, min( 4, (int) apply_filters( 'trmz_price_decimals', $decimals ) ) );
	}

	/**
	 * Parses a price typed by the administrator ("150", "150.5", "150,50", "1 200,00") into minor units.
	 *
	 * @param string $value Input.
	 * @return int|null Minor units, or null when not a valid non-negative amount.
	 */
	public static function parse( string $value ): ?int {
		$decimals = self::decimals();
		$value    = str_replace( array( ' ', "\u{00A0}", "\u{202F}" ), '', trim( $value ) );
		if ( '' === $value ) {
			return 0;
		}
		$pattern = 0 === $decimals ? '/^\d+$/' : '/^(\d+)(?:[.,](\d{1,' . $decimals . '}))?$/';
		if ( 1 !== preg_match( $pattern, $value, $matches ) ) {
			return null;
		}
		$whole    = 0 === $decimals ? $value : $matches[1];
		$fraction = str_pad( $matches[2] ?? '', $decimals, '0' );
		if ( strlen( ltrim( $whole, '0' ) ) > 12 ) {
			return null;
		}
		$minor = (int) $whole * ( 10 ** $decimals ) + (int) ( '' === $fraction ? 0 : $fraction );
		return $minor > self::MAX_MINOR ? null : $minor;
	}

	/**
	 * Amount in minor units as a plain decimal string for form fields ("150.00").
	 *
	 * @param int $minor Minor units.
	 */
	public static function to_input( int $minor ): string {
		$decimals = self::decimals();
		if ( 0 === $decimals ) {
			return (string) $minor;
		}
		$divisor = 10 ** $decimals;
		return intdiv( $minor, $divisor ) . '.' . str_pad( (string) ( $minor % $divisor ), $decimals, '0', STR_PAD_LEFT );
	}

	/**
	 * Localised price with the currency code, e.g. "1 200,00 PLN"; "Free" for 0.
	 *
	 * @param int $minor Minor units.
	 */
	public static function format( int $minor ): string {
		if ( 0 === $minor ) {
			return __( 'Free', 'terminarz' );
		}
		$decimals = self::decimals();
		$amount   = number_format_i18n( $minor / ( 10 ** $decimals ), $decimals );
		$currency = self::currency();
		return '' === $currency ? $amount : $amount . ' ' . $currency;
	}
}
