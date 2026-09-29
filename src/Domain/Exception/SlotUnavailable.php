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
		return new self( sprintf( 'The slot of resource #%d at %s UTC is not available.', $resource_id, self::utc( $start ) ) );
	}

	/**
	 * Named constructor for "any resource" requests.
	 *
	 * @param DateTimeImmutable $start Requested start.
	 */
	public static function for_any_resource( DateTimeImmutable $start ): self {
		return new self( sprintf( 'No resource is available at %s UTC.', self::utc( $start ) ) );
	}

	/**
	 * Formats a time in UTC.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function utc( DateTimeImmutable $time ): string {
		return $time->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i' );
	}
}
