<?php
/**
 * Conversion between local wall-clock time and absolute time.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Availability;

use DateTimeImmutable;
use DateTimeZone;
use Terminarz\Domain\Exception\InvalidValue;

/**
 * Maps local wall-clock times to UTC instants with explicit, deterministic DST rules.
 *
 * - A local time that occurs once maps to that instant.
 * - A **repeated** local time (clocks go back) maps to its **earliest** occurrence.
 * - A **non-existent** local time (clocks go forward) maps to the instant of the transition,
 *   i.e. the first existing local time after the gap (02:30 on a 02:00→03:00 day becomes 03:00).
 *
 * The mapping is monotonic (a later wall time never maps to an earlier instant), so windows
 * that do not overlap in local time never overlap in UTC. PHP's own `DateTimeImmutable` behaviour
 * differs (it shifts non-existent times forward by the gap and its choice for repeated times
 * varies between versions), which is why this class exists.
 */
final class WallClock {

	/**
	 * Look-around for time-zone transitions (seconds). Offsets never differ by more than a day.
	 */
	private const SEARCH_WINDOW = 2 * 86400;

	/**
	 * Converts a local date and minutes since local midnight to a UNIX timestamp.
	 *
	 * @param DateTimeZone $timezone Site time zone.
	 * @param string       $date     Local date "Y-m-d".
	 * @param int          $minutes  Minutes since local midnight, 0–1440 (1440 = next midnight).
	 *
	 * @throws InvalidValue On a malformed date.
	 */
	public static function to_timestamp( DateTimeZone $timezone, string $date, int $minutes ): int {
		return self::wall_to_timestamp( $timezone, self::naive_midnight( $date ) + $minutes * 60 );
	}

	/**
	 * Local calendar date ("Y-m-d") of an instant in the given time zone.
	 *
	 * @param DateTimeImmutable $instant  Instant.
	 * @param DateTimeZone      $timezone Time zone.
	 */
	public static function local_date( DateTimeImmutable $instant, DateTimeZone $timezone ): string {
		return $instant->setTimezone( $timezone )->format( 'Y-m-d' );
	}

	/**
	 * Wall-clock seconds of local midnight, counted as if the local time were UTC.
	 *
	 * @param string $date Local date "Y-m-d".
	 *
	 * @throws InvalidValue On a malformed date.
	 */
	public static function naive_midnight( string $date ): int {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, new DateTimeZone( 'UTC' ) );
		if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			throw new InvalidValue( 'Date must be a valid Y-m-d date.' );
		}

		return $parsed->getTimestamp();
	}

	/**
	 * Converts "naive" wall-clock seconds (local time read as if it were UTC) to a timestamp.
	 *
	 * @param DateTimeZone $timezone Time zone.
	 * @param int          $wall     Naive wall-clock seconds.
	 */
	private static function wall_to_timestamp( DateTimeZone $timezone, int $wall ): int {
		$transitions = $timezone->getTransitions( $wall - self::SEARCH_WINDOW, $wall + self::SEARCH_WINDOW );

		if ( false === $transitions || array() === $transitions ) {
			// Fixed-offset zone ("+02:00") or no data: a single offset applies.
			return $wall - $timezone->getOffset( new DateTimeImmutable( '@' . $wall ) );
		}

		// Periods [from, next from) with a constant offset; the first entry describes the state at the search start.
		$periods = array();
		foreach ( $transitions as $transition ) {
			$periods[] = array(
				'from'   => (int) $transition['ts'],
				'offset' => (int) $transition['offset'],
			);
		}
		$count = count( $periods );

		// Earliest instant whose local wall time equals $wall.
		for ( $i = 0; $i < $count; $i++ ) {
			$candidate = $wall - $periods[ $i ]['offset'];
			$until     = $i + 1 < $count ? $periods[ $i + 1 ]['from'] : PHP_INT_MAX;
			if ( $candidate >= $periods[ $i ]['from'] && $candidate < $until ) {
				return $candidate;
			}
		}

		// Non-existent wall time: return the transition that jumps over it.
		for ( $i = 1; $i < $count; $i++ ) {
			$at = $periods[ $i ]['from'];
			if ( $at + $periods[ $i - 1 ]['offset'] <= $wall && $wall < $at + $periods[ $i ]['offset'] ) {
				return $at;
			}
		}

		// Unreachable with consistent tz data; fall back to PHP's own resolution.
		return $wall - $timezone->getOffset( new DateTimeImmutable( '@' . $wall ) );
	}
}
