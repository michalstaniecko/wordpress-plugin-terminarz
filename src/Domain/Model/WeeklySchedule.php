<?php
/**
 * Weekly working hours with breaks.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Model;

use Terminarz\Domain\Exception\InvalidValue;

/**
 * Recurring weekly schedule in the site's local time.
 *
 * Days are ISO-8601 weekday numbers: 1 = Monday … 7 = Sunday. A day without working hours is closed.
 * Working hours of one day must not overlap each other; breaks may be anywhere and are subtracted
 * from the working hours of the same day.
 */
final class WeeklySchedule {

	/**
	 * Working hours per ISO weekday, sorted by start.
	 *
	 * @var array<int, list<TimeWindow>>
	 */
	private array $working_hours;

	/**
	 * Breaks per ISO weekday, sorted by start.
	 *
	 * @var array<int, list<TimeWindow>>
	 */
	private array $breaks;

	/**
	 * Creates the schedule.
	 *
	 * @param array<int, list<TimeWindow>> $working_hours Working hours keyed by ISO weekday (1–7).
	 * @param array<int, list<TimeWindow>> $breaks        Breaks keyed by ISO weekday (1–7).
	 *
	 * @throws InvalidValue On invalid weekdays or overlapping working hours.
	 */
	public function __construct( array $working_hours, array $breaks = array() ) {
		$this->working_hours = self::normalise( $working_hours );
		$this->breaks        = self::normalise( $breaks );

		foreach ( $this->working_hours as $windows ) {
			$count = count( $windows );
			for ( $i = 1; $i < $count; $i++ ) {
				if ( $windows[ $i - 1 ]->overlaps( $windows[ $i ] ) ) {
					throw new InvalidValue( 'Working hours within one day must not overlap.' );
				}
			}
		}
	}

	/**
	 * Schedule with no working hours at all.
	 */
	public static function closed(): self {
		return new self( array() );
	}

	/**
	 * Working hours of the given day as configured (breaks not subtracted).
	 *
	 * @param int $iso_weekday 1 = Monday … 7 = Sunday.
	 *
	 * @return list<TimeWindow>
	 */
	public function working_hours( int $iso_weekday ): array {
		self::assert_weekday( $iso_weekday );

		return $this->working_hours[ $iso_weekday ] ?? array();
	}

	/**
	 * Breaks of the given day.
	 *
	 * @param int $iso_weekday 1 = Monday … 7 = Sunday.
	 *
	 * @return list<TimeWindow>
	 */
	public function breaks( int $iso_weekday ): array {
		self::assert_weekday( $iso_weekday );

		return $this->breaks[ $iso_weekday ] ?? array();
	}

	/**
	 * Bookable windows of the given day: working hours minus breaks, sorted.
	 *
	 * @param int $iso_weekday 1 = Monday … 7 = Sunday.
	 *
	 * @return list<TimeWindow>
	 */
	public function windows_for( int $iso_weekday ): array {
		$breaks = $this->breaks( $iso_weekday );
		$result = array();
		foreach ( $this->working_hours( $iso_weekday ) as $window ) {
			foreach ( $window->subtract( $breaks ) as $part ) {
				$result[] = $part;
			}
		}

		return $result;
	}

	/**
	 * Validates weekday keys and window types, sorts windows.
	 *
	 * @param array<int, list<TimeWindow>> $days Windows keyed by weekday.
	 *
	 * @return array<int, list<TimeWindow>>
	 *
	 * @throws InvalidValue On invalid input.
	 */
	private static function normalise( array $days ): array {
		$result = array();
		foreach ( $days as $weekday => $windows ) {
			self::assert_weekday( $weekday );
			foreach ( $windows as $window ) {
				if ( ! $window instanceof TimeWindow ) {
					throw new InvalidValue( 'Schedule entries must be TimeWindow instances.' );
				}
			}
			$windows = array_values( $windows );
			usort( $windows, static fn ( TimeWindow $a, TimeWindow $b ): int => $a->start->minutes <=> $b->start->minutes );
			if ( array() !== $windows ) {
				$result[ $weekday ] = $windows;
			}
		}
		ksort( $result );

		return $result;
	}

	/**
	 * Checks the ISO weekday number.
	 *
	 * @param mixed $weekday Value to check.
	 *
	 * @throws InvalidValue When not an integer 1–7.
	 */
	private static function assert_weekday( mixed $weekday ): void {
		if ( ! is_int( $weekday ) || $weekday < 1 || $weekday > 7 ) {
			throw new InvalidValue( 'Weekday must be an ISO-8601 number from 1 (Monday) to 7 (Sunday).' );
		}
	}
}
