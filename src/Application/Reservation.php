<?php
/**
 * Result of a successful reservation.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Model\Booking;

/**
 * The stored booking plus the token of its cancellation link. The token is derived from a per-booking secret and a
 * site key (`CancelTokens`, ADR-042), so it can also be rebuilt later with `BookingService::cancel_token()`.
 */
final class Reservation {

	/**
	 * Constructor.
	 *
	 * @param Booking $booking      Stored booking.
	 * @param string  $cancel_token Cancellation link token (64 hex characters).
	 */
	public function __construct(
		public readonly Booking $booking,
		public readonly string $cancel_token
	) {
	}
}
