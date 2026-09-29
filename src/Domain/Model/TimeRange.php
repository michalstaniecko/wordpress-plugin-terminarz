<?php
/**
 * Half-open range of absolute time.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Model;

use DateTimeImmutable;
use DateTimeZone;
use Terminarz\Domain\Exception\InvalidValue;

/**
 * Half-open interval [start, end) on the absolute time line.
 *
 * Both ends are normalised to UTC on construction, so two ranges describing the same
 * instants are equal regardless of the time zone they were created in.
 */
final class TimeRange {

	/**
	 * Inclusive start (UTC).
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $start;

	/**
	 * Exclusive end (UTC).
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $end;

	/**
	 * Creates the range.
	 *
	 * @param DateTimeImmutable $start Inclusive start.
	 * @param DateTimeImmutable $end   Exclusive end; must be later than the start.
	 *
	 * @throws InvalidValue When the end is not after the start.
	 */
	public function __construct( DateTimeImmutable $start, DateTimeImmutable $end ) {
		if ( $end <= $start ) {
			throw new InvalidValue( 'Time range end must be later than its start.' );
		}

		$utc         = new DateTimeZone( 'UTC' );
		$this->start = $start->setTimezone( $utc );
		$this->end   = $end->setTimezone( $utc );
	}

	/**
	 * Creates a range from UNIX timestamps (seconds).
	 *
	 * @param int $start Inclusive start.
	 * @param int $end   Exclusive end.
	 */
	public static function from_timestamps( int $start, int $end ): self {
		return new self( new DateTimeImmutable( '@' . $start ), new DateTimeImmutable( '@' . $end ) );
	}

	/**
	 * Start as a UNIX timestamp.
	 */
	public function start_timestamp(): int {
		return $this->start->getTimestamp();
	}

	/**
	 * End as a UNIX timestamp.
	 */
	public function end_timestamp(): int {
		return $this->end->getTimestamp();
	}

	/**
	 * Length in seconds.
	 */
	public function duration_seconds(): int {
		return $this->end_timestamp() - $this->start_timestamp();
	}

	/**
	 * Length in whole minutes (rounded down).
	 */
	public function duration_minutes(): int {
		return intdiv( $this->duration_seconds(), 60 );
	}

	/**
	 * Whether both ranges share at least one instant. Touching ranges ([a,b) and [b,c)) do not overlap.
	 *
	 * @param self $other Other range.
	 */
	public function overlaps( self $other ): bool {
		return $this->start < $other->end && $other->start < $this->end;
	}

	/**
	 * Whether the other range lies entirely within this one.
	 *
	 * @param self $other Other range.
	 */
	public function contains( self $other ): bool {
		return $this->start <= $other->start && $other->end <= $this->end;
	}

	/**
	 * Whether the instant lies within [start, end).
	 *
	 * @param DateTimeImmutable $instant Instant to check.
	 */
	public function contains_instant( DateTimeImmutable $instant ): bool {
		return $this->start <= $instant && $instant < $this->end;
	}

	/**
	 * Returns a copy with the end moved by the given number of minutes (e.g. to add a buffer).
	 *
	 * @param int $minutes Minutes to add (>= 0).
	 *
	 * @throws InvalidValue When minutes is negative.
	 */
	public function extend_end_by_minutes( int $minutes ): self {
		if ( $minutes < 0 ) {
			throw new InvalidValue( 'Extension must not be negative.' );
		}

		return self::from_timestamps( $this->start_timestamp(), $this->end_timestamp() + $minutes * 60 );
	}

	/**
	 * Whether both ranges describe the same instants.
	 *
	 * @param self $other Other range.
	 */
	public function equals( self $other ): bool {
		return $this->start_timestamp() === $other->start_timestamp()
			&& $this->end_timestamp() === $other->end_timestamp();
	}
}
