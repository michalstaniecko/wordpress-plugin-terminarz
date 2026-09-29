<?php
/**
 * Forbidden booking status transition.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Exception;

use Terminarz\Domain\Model\BookingStatus;

/**
 * Thrown when a booking is moved to a status that is not reachable from its current one.
 */
final class InvalidStatusTransition extends \DomainException implements DomainError {

	/**
	 * Creates the exception for a concrete transition.
	 *
	 * @param BookingStatus $from Current status.
	 * @param BookingStatus $to   Requested status.
	 */
	public static function between( BookingStatus $from, BookingStatus $to ): self {
		return new self( sprintf( 'Booking status cannot change from "%s" to "%s".', $from->value, $to->value ) );
	}
}
