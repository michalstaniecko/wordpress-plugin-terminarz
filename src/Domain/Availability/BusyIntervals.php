<?php
/**
 * Busy intervals derived from bookings.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Availability;

use DateTimeImmutable;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\TimeRange;

/**
 * Turns bookings into the busy intervals the engine consumes.
 */
final class BusyIntervals {

	/**
	 * Blocked ranges (appointment + buffer) of the bookings that block their slot at `$now`:
	 * active statuses only, and `pending_payment` only while its hold has not expired.
	 *
	 * @param Booking[]         $bookings    Bookings (any status, any resource).
	 * @param DateTimeImmutable $now         Current time.
	 * @param ?int              $resource_id When given, only bookings of this resource are used.
	 *
	 * @return list<TimeRange>
	 */
	public static function from_bookings( array $bookings, DateTimeImmutable $now, ?int $resource_id = null ): array {
		$busy = array();
		foreach ( $bookings as $booking ) {
			if ( null !== $resource_id && $booking->resource_id !== $resource_id ) {
				continue;
			}
			if ( $booking->blocks_slot_at( $now ) ) {
				$busy[] = $booking->blocked_range();
			}
		}

		return $busy;
	}
}
