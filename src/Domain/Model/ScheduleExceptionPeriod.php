<?php
/**
 * Schedule exception spanning a range of local dates.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Model;

defined( 'ABSPATH' ) || exit; // No direct access.

use DateTimeImmutable;
use DateTimeZone;
use Terminarz\Domain\Exception\InvalidValue;

/**
 * An exception as the administrator enters it: a range of local dates (inclusive) that is closed (holiday, leave)
 * or has different hours, for one resource or for all of them (global). The availability engine works with
 * one-day {@see ScheduleException} objects — {@see self::days()} expands the range.
 */
final class ScheduleExceptionPeriod {

	/**
	 * Longest allowed period in days.
	 */
	public const MAX_DAYS = 366;

	/**
	 * Replacement windows (sorted); empty = closed.
	 *
	 * @var list<TimeWindow>
	 */
	public readonly array $windows;

	/**
	 * Creates the period.
	 *
	 * @param ?int         $resource_id Resource id, or null for a global exception.
	 * @param string       $start_date  First local date "Y-m-d".
	 * @param string       $end_date    Last local date "Y-m-d" (inclusive), not before the start.
	 * @param TimeWindow[] $windows     Replacement windows; empty = closed.
	 * @param string       $note        Note for the administrator (e.g. "Christmas").
	 * @param ?int         $id          Persistence id.
	 *
	 * @throws InvalidValue On invalid dates, ids or windows.
	 */
	public function __construct(
		public readonly ?int $resource_id,
		public readonly string $start_date,
		public readonly string $end_date,
		array $windows = array(),
		public readonly string $note = '',
		public readonly ?int $id = null
	) {
		// Validates the resource id, the start date and the windows.
		$first = new ScheduleException( $resource_id, $start_date, $windows, $id );
		new ScheduleException( $resource_id, $end_date );

		if ( $end_date < $start_date ) {
			throw new InvalidValue( 'The end date must not be before the start date.' );
		}
		if ( $this->length_in_days() > self::MAX_DAYS ) {
			throw new InvalidValue( 'An exception period must not be longer than 366 days.' );
		}

		$this->windows = $first->windows;
	}

	/**
	 * Whether the period is closed.
	 */
	public function is_closed(): bool {
		return array() === $this->windows;
	}

	/**
	 * Whether the period applies to all resources.
	 */
	public function is_global(): bool {
		return null === $this->resource_id;
	}

	/**
	 * Number of days (inclusive).
	 */
	public function length_in_days(): int {
		$utc  = new DateTimeZone( 'UTC' );
		$from = new DateTimeImmutable( $this->start_date, $utc );
		$to   = new DateTimeImmutable( $this->end_date, $utc );
		return (int) $from->diff( $to )->days + 1;
	}

	/**
	 * Whether both periods apply to the same scope (the same resource, or both global) and share at least one day.
	 *
	 * @param self $other Other period.
	 */
	public function conflicts_with( self $other ): bool {
		return $this->resource_id === $other->resource_id
			&& $this->start_date <= $other->end_date
			&& $other->start_date <= $this->end_date;
	}

	/**
	 * One-day exceptions for every date of the period.
	 *
	 * @return list<ScheduleException>
	 */
	public function days(): array {
		$days = array();
		$day  = new DateTimeImmutable( $this->start_date, new DateTimeZone( 'UTC' ) );
		$last = $this->end_date;
		while ( $day->format( 'Y-m-d' ) <= $last ) {
			$days[] = new ScheduleException( $this->resource_id, $day->format( 'Y-m-d' ), $this->windows, $this->id );
			$day    = $day->modify( '+1 day' );
		}
		return $days;
	}

	/**
	 * Returns a copy with the persistence id set.
	 *
	 * @param int $id Persistence id.
	 */
	public function with_id( int $id ): self {
		return new self( $this->resource_id, $this->start_date, $this->end_date, $this->windows, $this->note, $id );
	}
}
