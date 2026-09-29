<?php
/**
 * Thrown when a slot cannot be booked because it is (or just became) taken.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Exception;

use DateTimeImmutable;

/**
 * Expected, user-facing condition (REST maps it to 409 Conflict), not a server error.
 */
final class SlotUnavailable extends \DomainException implements DomainError {

	/**
	 * Named constructor.
	 *
	 * @param int               $resource_id Resource ID.
	 * @param DateTimeImmutable $start       Requested start (UTC).
	 */
	public static function at( int $resource_id, DateTimeImmutable $start ): self {
		return new self( sprintf( 'The slot of resource #%d at %s UTC is not available.', $resource_id, $start->format( 'Y-m-d H:i' ) ) );
	}
}
