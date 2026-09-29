<?php
/**
 * Booking use cases.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

use DateTimeImmutable;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Exception\InvalidStatusTransition;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Exception\SlotUnavailable;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Domain\Repository\BookingRepository;
use Terminarz\Domain\Repository\ResourceRepository;
use Terminarz\Domain\Repository\ServiceRepository;

/**
 * Reserves, reschedules and changes the status of bookings; publishes events.
 *
 * Application layer: no WordPress calls (hooks go through EventDispatcher), so it is unit-testable.
 * Atomicity against double booking is guaranteed by BookingRepository::create()/reschedule().
 *
 * Events (WordPress actions with the same names):
 * - `trmz_booking_created` (Booking $booking)
 * - `trmz_booking_status_changed` (Booking $booking, BookingStatus $previous)
 * - `trmz_booking_rescheduled` (Booking $booking, Booking $previous)
 */
final class BookingService {

	public const EVENT_CREATED        = 'trmz_booking_created';
	public const EVENT_STATUS_CHANGED = 'trmz_booking_status_changed';
	public const EVENT_RESCHEDULED    = 'trmz_booking_rescheduled';

	/**
	 * Optional check that a slot is offered by the schedule (set by AvailabilityService, #15).
	 *
	 * @var (callable(int, int, DateTimeImmutable, ?int): bool)|null
	 */
	private $slot_policy = null;

	/**
	 * Constructor.
	 *
	 * @param BookingRepository  $bookings  Bookings.
	 * @param ServiceRepository  $services  Services.
	 * @param ResourceRepository $resources Resources.
	 * @param EventDispatcher    $events    Event dispatcher.
	 * @param Clock              $clock     Clock.
	 */
	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly ServiceRepository $services,
		private readonly ResourceRepository $resources,
		private readonly EventDispatcher $events,
		private readonly Clock $clock
	) {
	}

	/**
	 * Sets a policy deciding whether (service ID, resource ID, start, ID of the booking being moved or null)
	 * is a bookable slot (working hours, lead time, horizon…). Without a policy only collisions and the past are checked.
	 *
	 * @param callable(int, int, DateTimeImmutable, ?int): bool $policy Policy.
	 */
	public function set_slot_policy( callable $policy ): void {
		$this->slot_policy = $policy;
	}

	/**
	 * Reserves a slot atomically.
	 *
	 * @param int               $service_id   Service ID.
	 * @param int               $resource_id  Resource ID (must be assigned to the service).
	 * @param DateTimeImmutable $start        Appointment start.
	 * @param Customer          $customer     Customer.
	 * @param BookingStatus     $status       Initial status: pending, confirmed or pending_payment.
	 * @param int               $hold_minutes Payment hold length for pending_payment (minutes).
	 * @throws EntityNotFound  When the service or resource does not exist.
	 * @throws InvalidValue    When the input is inconsistent (inactive service, unassigned resource, status…).
	 * @throws SlotUnavailable When the slot is in the past, not offered, or taken.
	 */
	public function reserve(
		int $service_id,
		int $resource_id,
		DateTimeImmutable $start,
		Customer $customer,
		BookingStatus $status = BookingStatus::Pending,
		int $hold_minutes = 15
	): Reservation {
		$service = $this->services->get( $service_id );
		if ( null === $service ) {
			throw EntityNotFound::with_id( 'service', $service_id );
		}
		if ( ! $service->is_active ) {
			throw new InvalidValue( 'The service is not active.' );
		}
		$resource = $this->resources->get( $resource_id );
		if ( null === $resource ) {
			throw EntityNotFound::with_id( 'resource', $resource_id );
		}
		if ( ! $resource->is_active || ! in_array( $resource_id, $this->services->resource_ids( $service_id ), true ) ) {
			throw new InvalidValue( 'The resource does not perform this service.' );
		}
		if ( ! $status->is_active() ) {
			throw new InvalidValue( 'A new booking must be pending, pending payment or confirmed.' );
		}
		if ( BookingStatus::PendingPayment === $status && $hold_minutes <= 0 ) {
			throw new InvalidValue( 'The payment hold must be at least one minute long.' );
		}

		$now = $this->clock->now();
		$this->assert_bookable( $service_id, $resource_id, $start, $now, null );

		$token   = bin2hex( random_bytes( 32 ) );
		$booking = new Booking(
			resource_id: $resource_id,
			service_id: $service_id,
			range: TimeRange::from_timestamps( $start->getTimestamp(), $start->getTimestamp() + $service->duration_minutes * 60 ),
			status: $status,
			customer: $customer,
			buffer_after_minutes: $service->buffer_after_minutes,
			hold_expires_at: BookingStatus::PendingPayment === $status ? $now->modify( sprintf( '+%d minutes', $hold_minutes ) ) : null,
			cancel_token_hash: self::hash_token( $token ),
			public_id: bin2hex( random_bytes( 16 ) )
		);

		$stored = $this->bookings->create( $booking, $now );
		$this->events->dispatch( self::EVENT_CREATED, $stored );

		return new Reservation( $stored, $token );
	}

	/**
	 * Moves a booking to a new start (same duration), optionally to another resource of the same service.
	 *
	 * @param int               $id          Booking ID.
	 * @param DateTimeImmutable $start       New start.
	 * @param int|null          $resource_id New resource, null = keep.
	 * @throws EntityNotFound  When the booking does not exist.
	 * @throws InvalidValue    When the booking is not active or the resource does not perform the service.
	 * @throws SlotUnavailable When the new slot is in the past, not offered, or taken.
	 */
	public function reschedule( int $id, DateTimeImmutable $start, ?int $resource_id = null ): Booking {
		$previous = $this->require_booking( $id );
		$target   = $resource_id ?? $previous->resource_id;
		if ( $target !== $previous->resource_id && ! in_array( $target, $this->services->resource_ids( $previous->service_id ), true ) ) {
			throw new InvalidValue( 'The resource does not perform this service.' );
		}

		$now = $this->clock->now();
		$this->assert_bookable( $previous->service_id, $target, $start, $now, $id );

		$range = TimeRange::from_timestamps( $start->getTimestamp(), $start->getTimestamp() + $previous->range->duration_seconds() );
		$moved = $this->bookings->reschedule( $id, $range, $resource_id, $now );
		$this->events->dispatch( self::EVENT_RESCHEDULED, $moved, $previous );

		return $moved;
	}

	/**
	 * Changes the status (validated by the BookingStatus state machine).
	 *
	 * @param int           $id     Booking ID.
	 * @param BookingStatus $target Target status.
	 * @throws EntityNotFound          When the booking does not exist.
	 * @throws InvalidStatusTransition When the transition is not allowed.
	 */
	public function change_status( int $id, BookingStatus $target ): Booking {
		$previous = $this->require_booking( $id );
		$changed  = $this->bookings->change_status( $id, $target );
		$this->events->dispatch( self::EVENT_STATUS_CHANGED, $changed, $previous->status );

		return $changed;
	}

	/**
	 * Cancels a booking (releases the slot).
	 *
	 * @param int $id Booking ID.
	 */
	public function cancel( int $id ): Booking {
		return $this->change_status( $id, BookingStatus::Cancelled );
	}

	/**
	 * Expires bookings whose payment hold ran out; dispatches a status event for each.
	 *
	 * @param int $limit Maximum number of bookings per call.
	 * @return int[] IDs of expired bookings.
	 */
	public function expire_holds( int $limit = 100 ): array {
		$ids = $this->bookings->expire_holds( $this->clock->now(), $limit );
		foreach ( $ids as $id ) {
			$booking = $this->bookings->get( $id );
			if ( null !== $booking ) {
				$this->events->dispatch( self::EVENT_STATUS_CHANGED, $booking, BookingStatus::PendingPayment );
			}
		}
		return $ids;
	}

	/**
	 * Whether a plain cancellation token matches the booking (constant-time comparison).
	 *
	 * @param Booking $booking Booking.
	 * @param string  $token   Plain token.
	 */
	public static function verify_cancel_token( Booking $booking, string $token ): bool {
		return null !== $booking->cancel_token_hash && '' !== $token && hash_equals( $booking->cancel_token_hash, self::hash_token( $token ) );
	}

	/**
	 * Hash stored for a cancellation token.
	 *
	 * @param string $token Plain token.
	 */
	public static function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Rejects starts in the past and (with a policy) slots not offered by the schedule.
	 *
	 * @param int               $service_id  Service ID.
	 * @param int               $resource_id Resource ID.
	 * @param DateTimeImmutable $start       Start.
	 * @param DateTimeImmutable $now         Now.
	 * @param int|null          $exclude_id  Booking being rescheduled (its current slot does not count as busy).
	 * @throws SlotUnavailable When the slot cannot be booked.
	 */
	private function assert_bookable( int $service_id, int $resource_id, DateTimeImmutable $start, DateTimeImmutable $now, ?int $exclude_id ): void {
		if ( $start <= $now ) {
			throw SlotUnavailable::at( $resource_id, $start );
		}
		if ( null !== $this->slot_policy && ! ( $this->slot_policy )( $service_id, $resource_id, $start, $exclude_id ) ) {
			throw SlotUnavailable::at( $resource_id, $start );
		}
	}

	/**
	 * Loads a booking that must exist.
	 *
	 * @param int $id Booking ID.
	 * @throws EntityNotFound When missing.
	 */
	private function require_booking( int $id ): Booking {
		$booking = $this->bookings->get( $id );
		if ( null === $booking ) {
			throw EntityNotFound::with_id( 'booking', $id );
		}
		return $booking;
	}
}
