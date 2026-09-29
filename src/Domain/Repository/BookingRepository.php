<?php
/**
 * Booking repository contract.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Repository;

use DateTimeImmutable;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Exception\InvalidStatusTransition;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Exception\SlotUnavailable;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\TimeRange;

/**
 * Stores bookings. Implementations must make create() and reschedule() atomic: two concurrent calls can never
 * both succeed for overlapping blocked ranges (appointment + buffer) of the same resource.
 */
interface BookingRepository {

	/**
	 * Finds a booking by its internal ID.
	 *
	 * @param int $id Booking ID.
	 */
	public function get( int $id ): ?Booking;

	/**
	 * Finds a booking by its public ID (the identifier exposed in URLs and REST).
	 *
	 * @param string $public_id Public ID.
	 */
	public function get_by_public_id( string $public_id ): ?Booking;

	/**
	 * Atomically stores a new active booking if its blocked range (appointment + buffer) is free.
	 *
	 * Bookings awaiting payment whose hold expired before `$now` do not block; they are marked `expired` on the way.
	 *
	 * @param Booking           $booking New booking (no ID, active status).
	 * @param DateTimeImmutable $now     Current time.
	 * @return Booking Stored booking (with ID, public ID and creation time).
	 * @throws SlotUnavailable When the range overlaps a blocking booking of the resource.
	 * @throws EntityNotFound  When the resource does not exist.
	 * @throws InvalidValue    When the booking already has an ID or is not active.
	 */
	public function create( Booking $booking, DateTimeImmutable $now ): Booking;

	/**
	 * Atomically moves an active booking to another time (and optionally resource).
	 *
	 * @param int               $id          Booking ID.
	 * @param TimeRange         $range       New appointment range (without buffer).
	 * @param int|null          $resource_id New resource, null = keep.
	 * @param DateTimeImmutable $now         Current time.
	 * @return Booking Updated booking.
	 * @throws SlotUnavailable When the new range is taken.
	 * @throws EntityNotFound  When the booking or the resource does not exist.
	 * @throws InvalidValue    When the booking is not active.
	 */
	public function reschedule( int $id, TimeRange $range, ?int $resource_id, DateTimeImmutable $now ): Booking;

	/**
	 * Changes the status following the state machine of BookingStatus. An inactive target status releases the slot.
	 *
	 * @param int           $id     Booking ID.
	 * @param BookingStatus $target Target status.
	 * @return Booking Updated booking.
	 * @throws InvalidStatusTransition When the transition is not allowed.
	 * @throws EntityNotFound          When the booking does not exist.
	 */
	public function change_status( int $id, BookingStatus $target ): Booking;

	/**
	 * Sets the WooCommerce order of a booking.
	 *
	 * @param int $id       Booking ID.
	 * @param int $order_id Order ID.
	 * @throws EntityNotFound When the booking does not exist.
	 */
	public function attach_order( int $id, int $order_id ): Booking;

	/**
	 * Blocked ranges (appointment + buffer, UTC) of bookings that block slots at `$now` and overlap `$range`,
	 * grouped by resource — one query for all resources.
	 *
	 * @param int[]             $resource_ids Resource IDs.
	 * @param TimeRange         $range        Range of interest.
	 * @param DateTimeImmutable $now          Current time (decides whether payment holds still block).
	 * @param int|null          $exclude_id   Booking to ignore (e.g. the one being rescheduled).
	 * @return array<int, TimeRange[]> Keyed by resource ID (every requested ID present), sorted by start.
	 */
	public function busy_ranges( array $resource_ids, TimeRange $range, DateTimeImmutable $now, ?int $exclude_id = null ): array;

	/**
	 * Bookings of the given resources overlapping a range (any status), sorted by start. For admin/REST listings.
	 *
	 * @param TimeRange  $range        Range.
	 * @param int[]|null $resource_ids Resources; null = all.
	 * @return Booking[]
	 */
	public function in_range( TimeRange $range, ?array $resource_ids = null ): array;

	/**
	 * Marks bookings awaiting payment whose hold expired as `expired` (releasing their slots).
	 *
	 * @param DateTimeImmutable $now   Current time.
	 * @param int               $limit Maximum number of bookings to expire in one call.
	 * @return int[] IDs of expired bookings.
	 */
	public function expire_holds( DateTimeImmutable $now, int $limit = 100 ): array;
}
