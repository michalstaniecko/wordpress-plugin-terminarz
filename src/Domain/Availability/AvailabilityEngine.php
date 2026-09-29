<?php
/**
 * Free slot calculation.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Availability;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Model\Slot;
use Terminarz\Domain\Model\TimeRange;

/**
 * Computes free slots. Pure: no I/O, no WordPress, no clock — `now` and the time zone come from the query.
 *
 * Algorithm (see ADR-016):
 * 1. For every local date in the range (skipping dates beyond the horizon) take the bookable windows:
 *    the date's schedule exception (resource before global) or the weekly schedule minus breaks.
 * 2. Convert each window's local boundaries to UTC with {@see WallClock} (DST rules defined there).
 * 3. Walk each UTC window in real time with the slot step, starting at the window start. A slot at `s`
 *    blocks [s, s + duration + buffer); it is offered when that block lies inside the window, starts
 *    no earlier than `now + lead`, and does not overlap busy time. Busy intervals are sorted and merged
 *    once and scanned with a forward-only pointer, so the cost is linear in windows + slots + bookings.
 * 4. Result: slots [s, s + duration) sorted by start, then resource id.
 */
final class AvailabilityEngine {

	private const SECONDS_PER_DAY = 86400;

	/**
	 * Returns free slots for all resources of the query, sorted by start time, then resource id.
	 *
	 * @param AvailabilityQuery $query Query.
	 *
	 * @return list<Slot>
	 */
	public function find_slots( AvailabilityQuery $query ): array {
		$found = array();
		foreach ( $query->resources as $calendar ) {
			foreach ( $this->slot_starts( $query, $calendar ) as $start ) {
				$found[] = array( $start, $calendar->resource_id );
			}
		}
		sort( $found );

		$duration = $query->duration_minutes * 60;
		$slots    = array();
		foreach ( $found as [ $start, $resource_id ] ) {
			$slots[] = new Slot( $resource_id, TimeRange::from_timestamps( $start, $start + $duration ) );
		}

		return $slots;
	}

	/**
	 * Free slot start timestamps for one resource, ascending.
	 *
	 * @param AvailabilityQuery $query    Query.
	 * @param ResourceCalendar  $calendar Resource.
	 *
	 * @return list<int>
	 */
	private function slot_starts( AvailabilityQuery $query, ResourceCalendar $calendar ): array {
		$step     = $query->slot_step_minutes * 60;
		$blocked  = ( $query->duration_minutes + $query->buffer_after_minutes ) * 60;
		$earliest = $query->now->getTimestamp() + $query->min_lead_minutes * 60;
		$busy     = $calendar->busy_timestamps();
		$busy_n   = count( $busy );
		$pointer  = 0;
		$starts   = array();

		foreach ( $this->utc_windows( $query, $calendar ) as [ $window_start, $window_end ] ) {
			$start = $window_start;
			if ( $start < $earliest ) {
				$start = $window_start + self::ceil_div( $earliest - $window_start, $step ) * $step;
			}

			while ( $start + $blocked <= $window_end ) {
				while ( $pointer < $busy_n && $busy[ $pointer ][1] <= $start ) {
					++$pointer;
				}
				if ( $pointer < $busy_n && $busy[ $pointer ][0] < $start + $blocked ) {
					// Conflict: jump to the first grid point at or after the end of the busy interval.
					$start = $window_start + self::ceil_div( $busy[ $pointer ][1] - $window_start, $step ) * $step;
					continue;
				}
				$starts[] = $start;
				$start   += $step;
			}
		}

		return $starts;
	}

	/**
	 * Bookable windows of a resource converted to UTC timestamps, ascending and disjoint.
	 *
	 * @param AvailabilityQuery $query    Query.
	 * @param ResourceCalendar  $calendar Resource.
	 *
	 * @return list<array{int, int}>
	 */
	private function utc_windows( AvailabilityQuery $query, ResourceCalendar $calendar ): array {
		$first = WallClock::naive_midnight( $query->from_date );
		$last  = WallClock::naive_midnight( $query->to_date );
		if ( null !== $query->max_horizon_days ) {
			$today = WallClock::naive_midnight( WallClock::local_date( $query->now, $query->timezone ) );
			$last  = min( $last, $today + $query->max_horizon_days * self::SECONDS_PER_DAY );
		}

		$windows  = array();
		$previous = PHP_INT_MIN;
		for ( $day = $first; $day <= $last; $day += self::SECONDS_PER_DAY ) {
			$date    = gmdate( 'Y-m-d', $day );
			$weekday = (int) gmdate( 'N', $day );
			foreach ( $calendar->windows_on( $date, $weekday ) as $window ) {
				$start = max( $previous, WallClock::to_timestamp( $query->timezone, $date, $window->start->minutes ) );
				$end   = WallClock::to_timestamp( $query->timezone, $date, $window->end->minutes );
				if ( $end <= $start ) {
					continue; // Window lies entirely in a DST gap.
				}
				$windows[] = array( $start, $end );
				$previous  = $end;
			}
		}

		return $windows;
	}

	/**
	 * Ceiling of a non-negative integer division.
	 *
	 * @param int $dividend Dividend (>= 0).
	 * @param int $divisor  Divisor (> 0).
	 */
	private static function ceil_div( int $dividend, int $divisor ): int {
		return intdiv( $dividend + $divisor - 1, $divisor );
	}
}
