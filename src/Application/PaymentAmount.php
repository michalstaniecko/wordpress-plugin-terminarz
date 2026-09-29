<?php
/**
 * Amount due for a booking.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Exception\InvalidValue;

/**
 * Computes the amount charged online, in minor currency units.
 */
final class PaymentAmount {

	/**
	 * Amount due: the full price, or a deposit (percentage of the price, rounded half up, at least one minor unit
	 * for a paid service).
	 *
	 * @param int      $price_minor     Service price in minor units (>= 0).
	 * @param int|null $deposit_percent Deposit percentage 1–100; null = full price.
	 * @throws InvalidValue On a negative price or a percentage out of range.
	 */
	public static function due( int $price_minor, ?int $deposit_percent = null ): int {
		if ( $price_minor < 0 ) {
			throw new InvalidValue( 'The price must not be negative.' );
		}
		if ( null === $deposit_percent || 0 === $price_minor ) {
			return $price_minor;
		}
		if ( $deposit_percent < 1 || $deposit_percent > 100 ) {
			throw new InvalidValue( 'The deposit must be between 1 and 100 percent.' );
		}

		return max( 1, intdiv( $price_minor * $deposit_percent + 50, 100 ) );
	}
}
