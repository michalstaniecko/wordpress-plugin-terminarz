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
	 * Availability service used by reserve_any() (set by use_availability()).
	 *
	 * @var AvailabilityService|null
	 */
	private ?AvailabilityService $availability = null;

	/**
	 * Constructor.
	 *
	 * @param BookingRepository  $bookings  Bookings.
	 * @param ServiceRepository  $services  Services.
	 * @param ResourceRepository $resources Resources.
	 * @param EventDispatcher    $events    Event dispatcher.
	 * @param Clock              $clock     Clock.
	 * @param CancelTokens       $tokens    Cancellation link tokens.
	 */
	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly ServiceRepository $services,
		private readonly ResourceRepository $resources,
		private readonly EventDispatcher $events,
		private readonly Clock $clock,
		private readonly CancelTokens $tokens
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
	 * Validates every reservation against the availability engine (schedule, exceptions, lead time, horizon, grid)
	 * and enables reserve_any().
	 *
	 * @param AvailabilityService $availability Availability service.
	 */
	public function use_availability( AvailabilityService $availability ): void {
		$this->availability = $availability;
		$this->set_slot_policy( array( $availability, 'is_available' ) );
	}

	/**
	 * Reserves `$start` on "any" resource of the service: tries the free resources in the order of the configured
	 * strategy and takes the first one that can be booked atomically (another request may win a resource meanwhile).
	 *
	 * @param int               $service_id   Service ID.
	 * @param DateTimeImmutable $start        Appointment start.
	 * @param Customer          $customer     Customer.
	 * @param BookingStatus     $status       Initial status.
	 * @param int               $hold_minutes Payment hold length for pending_payment.
	 * @throws \LogicException When use_availability() was not called.
	 * @throws EntityNotFound  When the service does not exist.
	 * @throws InvalidValue    When the input is inconsistent.
	 * @throws SlotUnavailable When no resource is free at `$start`.
	 */
	public function reserve_any(
		int $service_id,
		DateTimeImmutable $start,
		Customer $customer,
		BookingStatus $status = BookingStatus::Pending,
		int $hold_minutes = 15
	): Reservation {
		if ( null === $this->availability ) {
			throw new \LogicException( 'reserve_any() needs an AvailabilityService (use_availability()).' );
		}

		$candidates = $this->availability->free_resources_at( $service_id, $start );
		foreach ( $candidates as $resource_id ) {
			try {
				return $this->reserve( $service_id, $resource_id, $start, $customer, $status, $hold_minutes );
			} catch ( SlotUnavailable $e ) {
				continue; // Lost the race for this resource — try the next one.
			}
		}

		throw SlotUnavailable::for_any_resource( $start );
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

		$booking = new Booking(
			resource_id: $resource_id,
			service_id: $service_id,
			range: TimeRange::from_timestamps( $start->getTimestamp(), $start->getTimestamp() + $service->duration_minutes * 60 ),
			status: $status,
			customer: $customer,
			buffer_after_minutes: $service->buffer_after_minutes,
			hold_expires_at: BookingStatus::PendingPayment === $status ? $now->modify( sprintf( '+%d minutes', $hold_minutes ) ) : null,
			cancel_secret: CancelTokens::new_secret(),
			public_id: bin2hex( random_bytes( 16 ) )
		);

		$stored = $this->bookings->create( $booking, $now );
		$this->events->dispatch( self::EVENT_CREATED, $stored );

		return new Reservation( $stored, $this->tokens->token( $stored ) );
	}

	/**
	 * Books again the slot of an inactive (expired or cancelled) booking as a new booking — e.g. a payment arrived
	 * after the payment hold ran out. The expired/cancelled booking stays untouched (terminal statuses never change);
	 * the new one copies its service, resource, time, buffer, customer and order.
	 *
	 * Only collisions are checked (atomically): the slot was valid when the customer chose it, so schedule changes,
	 * lead time and horizon do not apply. A start in the past is rejected.
	 *
	 * @param int           $id     ID of the inactive booking.
	 * @param BookingStatus $status Status of the new booking (active).
	 * @throws EntityNotFound  When the booking does not exist.
	 * @throws InvalidValue    When the booking is still active or the status is not active.
	 * @throws SlotUnavailable When the slot is taken or already started.
	 */
	public function rebook( int $id, BookingStatus $status = BookingStatus::Confirmed ): Reservation {
		$previous = $this->require_booking( $id );
		if ( $previous->status->is_active() ) {
			throw new InvalidValue( 'Only an expired or cancelled booking can be booked again.' );
		}
		if ( ! $status->is_active() || BookingStatus::PendingPayment === $status ) {
			throw new InvalidValue( 'A booking can only be booked again as pending or confirmed.' );
		}

		$now = $this->clock->now();
		if ( $previous->range->start <= $now ) {
			throw SlotUnavailable::at( $previous->resource_id, $previous->range->start );
		}

		$booking = new Booking(
			resource_id: $previous->resource_id,
			service_id: $previous->service_id,
			range: $previous->range,
			status: $status,
			customer: $previous->customer,
			buffer_after_minutes: $previous->buffer_after_minutes,
			order_id: $previous->order_id,
			cancel_secret: CancelTokens::new_secret(),
			public_id: bin2hex( random_bytes( 16 ) )
		);

		$stored = $this->bookings->create( $booking, $now );
		$this->events->dispatch( self::EVENT_CREATED, $stored );

		return new Reservation( $stored, $this->tokens->token( $stored ) );
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
	 * Token of the customer's cancellation link of a booking ('' when it has none).
	 *
	 * @param Booking $booking Booking.
	 */
	public function cancel_token( Booking $booking ): string {
		return $this->tokens->token( $booking );
	}

	/**
	 * Whether a cancellation token matches the booking (constant-time comparison).
	 *
	 * @param Booking $booking Booking.
	 * @param string  $token   Token from the link.
	 */
	public function verify_cancel_token( Booking $booking, string $token ): bool {
		return $this->tokens->verify( $booking, $token );
	}

	/**
	 * Checks that the customer holding a cancellation link may cancel the booking now: the token matches, the booking
	 * is active and the deadline (`$limit_hours` before the start; 0 = until the start) has not passed.
	 *
	 * @param string $public_id   Public booking ID from the link.
	 * @param string $token       Token from the link.
	 * @param int    $limit_hours Cancellation limit (hours before the start).
	 * @throws CancellationRefused When the customer cannot cancel.
	 */
	public function check_customer_cancellation( string $public_id, string $token, int $limit_hours ): Booking {
		$booking = 1 === preg_match( '/^[0-9a-f]{32}$/', $public_id ) ? $this->bookings->get_by_public_id( $public_id ) : null;
		if ( null === $booking || ! $this->tokens->verify( $booking, $token ) ) {
			throw new CancellationRefused( CancellationRefused::INVALID_TOKEN );
		}
		if ( ! $booking->status->can_transition_to( BookingStatus::Cancelled ) ) {
			throw new CancellationRefused( CancellationRefused::NOT_ACTIVE, $booking );
		}
		if ( $this->clock->now() >= self::cancellation_deadline( $booking, $limit_hours ) ) {
			throw new CancellationRefused( CancellationRefused::TOO_LATE, $booking );
		}
		return $booking;
	}

	/**
	 * Cancels a booking on behalf of the customer holding its cancellation link (see check_customer_cancellation()).
	 *
	 * @param string $public_id   Public booking ID from the link.
	 * @param string $token       Token from the link.
	 * @param int    $limit_hours Cancellation limit (hours before the start).
	 * @throws CancellationRefused When the customer cannot cancel.
	 */
	public function cancel_by_customer( string $public_id, string $token, int $limit_hours ): Booking {
		$booking = $this->check_customer_cancellation( $public_id, $token, $limit_hours );
		try {
			return $this->cancel( (int) $booking->id );
		} catch ( InvalidStatusTransition $e ) {
			// Changed concurrently (e.g. expired or cancelled by staff a moment ago).
			throw new CancellationRefused( CancellationRefused::NOT_ACTIVE, $booking );
		}
	}

	/**
	 * Last moment the customer may cancel: `$limit_hours` before the start.
	 *
	 * @param Booking $booking     Booking.
	 * @param int     $limit_hours Limit (hours, >= 0).
	 */
	public static function cancellation_deadline( Booking $booking, int $limit_hours ): DateTimeImmutable {
		return $booking->range->start->modify( sprintf( '-%d hours', max( 0, $limit_hours ) ) );
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
