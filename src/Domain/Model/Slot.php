<?php
/**
 * Free slot offered to a customer.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Model;

use DateTimeImmutable;
use Terminarz\Domain\Exception\InvalidValue;

/**
 * A free appointment slot on a resource. The range covers the service itself (without the buffer), in UTC.
 */
final class Slot {

	/**
	 * Creates the slot.
	 *
	 * @param int       $resource_id Resource id (positive).
	 * @param TimeRange $range       Appointment time, UTC, without buffer.
	 *
	 * @throws InvalidValue On an invalid resource id.
	 */
	public function __construct(
		public readonly int $resource_id,
		public readonly TimeRange $range
	) {
		if ( $resource_id <= 0 ) {
			throw new InvalidValue( 'Resource id must be a positive integer.' );
		}
	}

	/**
	 * Start (UTC).
	 */
	public function start(): DateTimeImmutable {
		return $this->range->start;
	}

	/**
	 * End (UTC).
	 */
	public function end(): DateTimeImmutable {
		return $this->range->end;
	}

	/**
	 * Whether both slots are on the same resource and cover the same time.
	 *
	 * @param self $other Other slot.
	 */
	public function equals( self $other ): bool {
		return $this->resource_id === $other->resource_id && $this->range->equals( $other->range );
	}
}
