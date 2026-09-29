<?php
/**
 * Booking status and its state machine.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Model;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Exception\InvalidStatusTransition;

/**
 * Lifecycle of a booking.
 *
 * Allowed transitions:
 *
 *     pending_payment -> pending | confirmed | cancelled | expired
 *     pending         -> confirmed | cancelled
 *     confirmed       -> cancelled | completed
 *     cancelled, expired, completed: terminal
 *
 * The backed values are stored in the database; never rename them.
 */
enum BookingStatus: string {

	/**
	 * Awaiting manual confirmation by the business.
	 */
	case Pending = 'pending';

	/**
	 * Slot held until `hold_expires_at` while the customer pays.
	 */
	case PendingPayment = 'pending_payment';

	/**
	 * Confirmed (paid or approved).
	 */
	case Confirmed = 'confirmed';

	/**
	 * Cancelled by the customer or the business.
	 */
	case Cancelled = 'cancelled';

	/**
	 * Payment hold elapsed without payment.
	 */
	case Expired = 'expired';

	/**
	 * The appointment took place.
	 */
	case Completed = 'completed';

	/**
	 * Whether a booking in this status occupies its slot.
	 *
	 * Note: a `pending_payment` booking occupies the slot only until its hold expires —
	 * use {@see Booking::blocks_slot_at()} when the current time matters.
	 */
	public function is_active(): bool {
		return match ( $this ) {
			self::Pending, self::PendingPayment, self::Confirmed => true,
			self::Cancelled, self::Expired, self::Completed => false,
		};
	}

	/**
	 * Whether no further transition is possible.
	 */
	public function is_terminal(): bool {
		return array() === $this->allowed_transitions();
	}

	/**
	 * Statuses reachable from this one.
	 *
	 * @return list<self>
	 */
	public function allowed_transitions(): array {
		return match ( $this ) {
			self::PendingPayment => array( self::Pending, self::Confirmed, self::Cancelled, self::Expired ),
			self::Pending => array( self::Confirmed, self::Cancelled ),
			self::Confirmed => array( self::Cancelled, self::Completed ),
			self::Cancelled, self::Expired, self::Completed => array(),
		};
	}

	/**
	 * Whether the booking may move to the given status.
	 *
	 * @param self $target Requested status.
	 */
	public function can_transition_to( self $target ): bool {
		return in_array( $target, $this->allowed_transitions(), true );
	}

	/**
	 * Returns the target status or throws when the transition is not allowed.
	 *
	 * @param self $target Requested status.
	 *
	 * @throws InvalidStatusTransition When the transition is not allowed.
	 */
	public function transition_to( self $target ): self {
		if ( ! $this->can_transition_to( $target ) ) {
			// Domain exception messages are never printed as HTML; adapters translate and escape them.
			throw InvalidStatusTransition::between( $this, $target ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return $target;
	}

	/**
	 * Statuses that occupy a slot (for repository queries).
	 *
	 * @return list<self>
	 */
	public static function active(): array {
		return array_values( array_filter( self::cases(), static fn ( self $status ): bool => $status->is_active() ) );
	}
}
