<?php
/**
 * Availability input for one resource.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Availability;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\ScheduleException;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Domain\Model\WeeklySchedule;

/**
 * Everything the engine needs to know about one resource: its weekly schedule, the schedule
 * exceptions that apply to it (its own and global ones) and the time it is already busy.
 *
 * `busy` must contain only intervals that really block the resource, already extended by their
 * buffers — build it with {@see BusyIntervals::from_bookings()} which applies the status and
 * payment-hold rules explicitly.
 */
final class ResourceCalendar {

	/**
	 * Schedule exception per local date; a resource exception replaces a global one.
	 *
	 * @var array<string, ScheduleException>
	 */
	private array $exceptions_by_date = array();

	/**
	 * Busy intervals as sorted, merged [start, end) timestamp pairs.
	 *
	 * @var list<array{int, int}>
	 */
	private array $busy_timestamps;

	/**
	 * Creates the calendar.
	 *
	 * @param int                 $resource_id Resource id (positive).
	 * @param WeeklySchedule      $schedule    Weekly schedule in site-local time.
	 * @param ScheduleException[] $exceptions  Exceptions for this resource and global ones (any order).
	 * @param TimeRange[]         $busy        Busy intervals (UTC, with buffers), any order, may overlap.
	 *
	 * @throws InvalidValue On an invalid resource id or an exception of another resource.
	 */
	public function __construct(
		public readonly int $resource_id,
		public readonly WeeklySchedule $schedule,
		array $exceptions = array(),
		array $busy = array()
	) {
		if ( $resource_id <= 0 ) {
			throw new InvalidValue( 'Resource id must be a positive integer.' );
		}

		foreach ( $exceptions as $exception ) {
			if ( ! $exception instanceof ScheduleException ) {
				throw new InvalidValue( 'Exceptions must be ScheduleException instances.' );
			}
			if ( ! $exception->is_global() && $exception->resource_id !== $resource_id ) {
				throw new InvalidValue( 'Schedule exception belongs to another resource.' );
			}
			$current = $this->exceptions_by_date[ $exception->date ] ?? null;
			if ( null === $current || ( $current->is_global() && ! $exception->is_global() ) ) {
				$this->exceptions_by_date[ $exception->date ] = $exception;
			}
		}

		$this->busy_timestamps = self::merge( $busy );
	}

	/**
	 * Bookable local windows on the given date (exception if any, otherwise the weekly schedule).
	 *
	 * @param string $date        Local date "Y-m-d".
	 * @param int    $iso_weekday ISO weekday of that date.
	 *
	 * @return list<\Terminarz\Domain\Model\TimeWindow>
	 */
	public function windows_on( string $date, int $iso_weekday ): array {
		$exception = $this->exceptions_by_date[ $date ] ?? null;

		return null !== $exception ? $exception->windows : $this->schedule->windows_for( $iso_weekday );
	}

	/**
	 * Busy time as sorted, disjoint [start, end) timestamp pairs.
	 *
	 * @return list<array{int, int}>
	 */
	public function busy_timestamps(): array {
		return $this->busy_timestamps;
	}

	/**
	 * Sorts and merges overlapping or touching intervals.
	 *
	 * @param TimeRange[] $busy Intervals.
	 *
	 * @return list<array{int, int}>
	 *
	 * @throws InvalidValue On a non-TimeRange entry.
	 */
	private static function merge( array $busy ): array {
		$pairs = array();
		foreach ( $busy as $range ) {
			if ( ! $range instanceof TimeRange ) {
				throw new InvalidValue( 'Busy intervals must be TimeRange instances.' );
			}
			$pairs[] = array( $range->start_timestamp(), $range->end_timestamp() );
		}
		sort( $pairs );

		$merged = array();
		foreach ( $pairs as $pair ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $pair[0] <= $merged[ $last ][1] ) {
				$merged[ $last ][1] = max( $merged[ $last ][1], $pair[1] );
				continue;
			}
			$merged[] = $pair;
		}

		return $merged;
	}
}
