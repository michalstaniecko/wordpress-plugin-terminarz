<?php
/**
 * Booking entity.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Model;

use DateTimeImmutable;
use DateTimeZone;
use Terminarz\Domain\Exception\InvalidStatusTransition;
use Terminarz\Domain\Exception\InvalidValue;

/**
 * A customer's booking of a service on a resource.
 *
 * Immutable: every change returns a new instance. All instants are UTC.
 * `range` is the appointment itself; the resource is additionally blocked for
 * `buffer_after_minutes` after it (a snapshot of the service buffer at booking time,
 * so later service edits do not move existing blocks) — see {@see self::blocked_range()}.
 */
final class Booking {

	/**
	 * Payment hold end (UTC), required for `pending_payment`.
	 *
	 * @var ?DateTimeImmutable
	 */
	public readonly ?DateTimeImmutable $hold_expires_at;

	/**
	 * Creation time (UTC), null when not stored yet.
	 *
	 * @var ?DateTimeImmutable
	 */
	public readonly ?DateTimeImmutable $created_at;

	/**
	 * Creates the booking.
	 *
	 * @param int                $resource_id          Resource id (positive).
	 * @param int                $service_id           Service id (positive).
	 * @param TimeRange          $range                Appointment time without buffer (UTC).
	 * @param BookingStatus      $status               Current status.
	 * @param Customer           $customer             Customer contact data.
	 * @param int                $buffer_after_minutes Buffer blocking the resource after the appointment, 0–1440.
	 * @param ?DateTimeImmutable $hold_expires_at      Payment hold end; required for `pending_payment`.
	 * @param ?int               $id                   Persistence id, null when not stored yet.
	 * @param ?int               $order_id             WooCommerce order id, if any.
	 * @param ?string            $cancel_secret        Random secret of the customer's cancellation link (ADR-042), if any.
	 * @param ?DateTimeImmutable $created_at           Creation time.
	 * @param ?string            $public_id            Random public identifier (used in URLs/REST instead of `id`).
	 *
	 * @throws InvalidValue On invalid data.
	 */
	public function __construct(
		public readonly int $resource_id,
		public readonly int $service_id,
		public readonly TimeRange $range,
		public readonly BookingStatus $status,
		public readonly Customer $customer,
		public readonly int $buffer_after_minutes = 0,
		?DateTimeImmutable $hold_expires_at = null,
		public readonly ?int $id = null,
		public readonly ?int $order_id = null,
		public readonly ?string $cancel_secret = null,
		?DateTimeImmutable $created_at = null,
		public readonly ?string $public_id = null
	) {
		if ( null !== $id && $id <= 0 ) {
			throw new InvalidValue( 'Id must be a positive integer.' );
		}
		if ( $resource_id <= 0 || $service_id <= 0 ) {
			throw new InvalidValue( 'Resource and service ids must be positive integers.' );
		}
		if ( $buffer_after_minutes < 0 || $buffer_after_minutes > Service::MAX_MINUTES ) {
			throw new InvalidValue( 'Booking buffer must be between 0 and 1440 minutes.' );
		}
		if ( null !== $order_id && $order_id <= 0 ) {
			throw new InvalidValue( 'Order id must be a positive integer.' );
		}
		if ( null !== $public_id && '' === $public_id ) {
			throw new InvalidValue( 'Public id must not be empty.' );
		}
		if ( null !== $cancel_secret && '' === $cancel_secret ) {
			throw new InvalidValue( 'Cancel secret must not be empty.' );
		}
		if ( BookingStatus::PendingPayment === $status && null === $hold_expires_at ) {
			throw new InvalidValue( 'A booking awaiting payment needs a hold expiry time.' );
		}

		$utc                   = new DateTimeZone( 'UTC' );
		$this->hold_expires_at = $hold_expires_at?->setTimezone( $utc );
		$this->created_at      = $created_at?->setTimezone( $utc );
	}

	/**
	 * Time the resource is blocked: the appointment plus the buffer.
	 */
	public function blocked_range(): TimeRange {
		return $this->range->extend_end_by_minutes( $this->buffer_after_minutes );
	}

	/**
	 * Whether the payment hold has elapsed at the given time (false when there is no hold).
	 *
	 * @param DateTimeImmutable $now Current time.
	 */
	public function is_hold_expired( DateTimeImmutable $now ): bool {
		return null !== $this->hold_expires_at && $this->hold_expires_at <= $now;
	}

	/**
	 * Whether the booking occupies its slot at the given time.
	 *
	 * Active statuses block the slot, except `pending_payment` whose hold has already elapsed
	 * (the expiry job may not have run yet — availability must not wait for it).
	 *
	 * @param DateTimeImmutable $now Current time.
	 */
	public function blocks_slot_at( DateTimeImmutable $now ): bool {
		if ( ! $this->status->is_active() ) {
			return false;
		}

		return ! ( BookingStatus::PendingPayment === $this->status && $this->is_hold_expired( $now ) );
	}

	/**
	 * Returns a copy in the given status.
	 *
	 * @param BookingStatus $target Requested status.
	 *
	 * @throws InvalidStatusTransition When the transition is not allowed.
	 */
	public function with_status( BookingStatus $target ): self {
		return $this->copy( status: $this->status->transition_to( $target ) );
	}

	/**
	 * Returns a copy moved to another time (and optionally another resource).
	 *
	 * @param TimeRange $range       New appointment time (UTC, without buffer).
	 * @param ?int      $resource_id New resource id; null keeps the current one.
	 *
	 * @throws InvalidValue When the booking is no longer active.
	 */
	public function rescheduled( TimeRange $range, ?int $resource_id = null ): self {
		if ( ! $this->status->is_active() ) {
			throw new InvalidValue( 'Only active bookings can be rescheduled.' );
		}

		return $this->copy( range: $range, resource_id: $resource_id ?? $this->resource_id );
	}

	/**
	 * Returns a copy with the persistence id set.
	 *
	 * @param int $id Persistence id.
	 */
	public function with_id( int $id ): self {
		return $this->copy( id: $id );
	}

	/**
	 * Returns a copy linked to a WooCommerce order.
	 *
	 * @param int $order_id Order id.
	 */
	public function with_order_id( int $order_id ): self {
		return $this->copy( order_id: $order_id );
	}

	/**
	 * Copies the booking with selected fields replaced.
	 *
	 * @param ?int           $id          New id.
	 * @param ?int           $resource_id New resource id.
	 * @param ?TimeRange     $range       New range.
	 * @param ?BookingStatus $status      New status.
	 * @param ?int           $order_id    New order id.
	 */
	private function copy(
		?int $id = null,
		?int $resource_id = null,
		?TimeRange $range = null,
		?BookingStatus $status = null,
		?int $order_id = null
	): self {
		return new self(
			resource_id: $resource_id ?? $this->resource_id,
			service_id: $this->service_id,
			range: $range ?? $this->range,
			status: $status ?? $this->status,
			customer: $this->customer,
			buffer_after_minutes: $this->buffer_after_minutes,
			hold_expires_at: $this->hold_expires_at,
			id: $id ?? $this->id,
			order_id: $order_id ?? $this->order_id,
			cancel_secret: $this->cancel_secret,
			created_at: $this->created_at,
			public_id: $this->public_id
		);
	}
}
