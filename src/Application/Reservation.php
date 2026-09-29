<?php
/**
 * Result of a successful reservation.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

use Terminarz\Domain\Model\Booking;

/**
 * The stored booking plus the plain cancellation token. Only its SHA-256 hash is stored, so the token
 * is available exactly once — to be put into the confirmation (e-mail link / REST response).
 */
final class Reservation {

	/**
	 * Constructor.
	 *
	 * @param Booking $booking      Stored booking.
	 * @param string  $cancel_token Plain cancellation token (64 hex characters).
	 */
	public function __construct(
		public readonly Booking $booking,
		public readonly string $cancel_token
	) {
	}
}
