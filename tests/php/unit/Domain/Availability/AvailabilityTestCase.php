<?php
/**
 * Shared helpers for availability engine tests.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Unit\Domain\Availability;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Terminarz\Domain\Availability\AvailabilityEngine;
use Terminarz\Domain\Availability\AvailabilityQuery;
use Terminarz\Domain\Availability\ResourceCalendar;
use Terminarz\Domain\Model\ScheduleException;
use Terminarz\Domain\Model\Slot;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Domain\Model\TimeWindow;
use Terminarz\Domain\Model\WeeklySchedule;

/**
 * Base class with builders and formatters.
 */
abstract class AvailabilityTestCase extends TestCase {

	/**
	 * Weekly schedule with the same windows on every given weekday.
	 *
	 * @param string[] $windows  Windows as "HH:MM-HH:MM".
	 * @param int[]    $weekdays ISO weekdays.
	 * @param string[] $breaks   Breaks as "HH:MM-HH:MM".
	 */
	protected static function schedule( array $windows, array $weekdays = array( 1, 2, 3, 4, 5, 6, 7 ), array $breaks = array() ): WeeklySchedule {
		$hours = array();
		$pause = array();
		foreach ( $weekdays as $weekday ) {
			$hours[ $weekday ] = self::windows( $windows );
			$pause[ $weekday ] = self::windows( $breaks );
		}

		return new WeeklySchedule( $hours, $pause );
	}

	/**
	 * Parses "HH:MM-HH:MM" strings.
	 *
	 * @param string[] $windows Windows.
	 *
	 * @return list<TimeWindow>
	 */
	protected static function windows( array $windows ): array {
		return array_map(
			static function ( string $window ): TimeWindow {
				[ $start, $end ] = explode( '-', $window );
				return TimeWindow::from_strings( $start, $end );
			},
			array_values( $windows )
		);
	}

	/**
	 * Range from two date-time strings interpreted in the given zone.
	 *
	 * @param string $start    Start.
	 * @param string $end      End.
	 * @param string $timezone Zone name.
	 */
	protected static function busy( string $start, string $end, string $timezone = 'Europe/Warsaw' ): TimeRange {
		$tz = new DateTimeZone( $timezone );

		return new TimeRange( new DateTimeImmutable( $start, $tz ), new DateTimeImmutable( $end, $tz ) );
	}

	/**
	 * Runs the engine for a single resource.
	 *
	 * @param WeeklySchedule      $schedule   Schedule.
	 * @param array<string,mixed> $options    Query overrides: duration, buffer, timezone, from, to, now, lead, horizon, step.
	 * @param ScheduleException[] $exceptions Exceptions.
	 * @param TimeRange[]         $busy       Busy intervals.
	 *
	 * @return list<Slot>
	 */
	protected static function find( WeeklySchedule $schedule, array $options = array(), array $exceptions = array(), array $busy = array() ): array {
		$resource_id = (int) ( $options['resource_id'] ?? 1 );

		return ( new AvailabilityEngine() )->find_slots(
			self::query( array( new ResourceCalendar( $resource_id, $schedule, $exceptions, $busy ) ), $options )
		);
	}

	/**
	 * Builds a query with sensible defaults.
	 *
	 * @param ResourceCalendar[]  $resources Resources.
	 * @param array<string,mixed> $options   Overrides.
	 */
	protected static function query( array $resources, array $options = array() ): AvailabilityQuery {
		$timezone = new DateTimeZone( (string) ( $options['timezone'] ?? 'Europe/Warsaw' ) );
		$from     = (string) ( $options['from'] ?? '2026-10-05' );

		return new AvailabilityQuery(
			resources: $resources,
			duration_minutes: (int) ( $options['duration'] ?? 60 ),
			buffer_after_minutes: (int) ( $options['buffer'] ?? 0 ),
			timezone: $timezone,
			from_date: $from,
			to_date: (string) ( $options['to'] ?? $from ),
			now: new DateTimeImmutable( (string) ( $options['now'] ?? '2025-01-01 00:00 UTC' ) ),
			min_lead_minutes: (int) ( $options['lead'] ?? 0 ),
			max_horizon_days: isset( $options['horizon'] ) ? (int) $options['horizon'] : null,
			slot_step_minutes: (int) ( $options['step'] ?? 60 )
		);
	}

	/**
	 * Formats slots as local "Y-m-d H:i-H:i" strings (with resource id when requested).
	 *
	 * @param Slot[] $slots         Slots.
	 * @param string $timezone      Zone to format in.
	 * @param bool   $with_resource Prefix with "#id ".
	 *
	 * @return list<string>
	 */
	protected static function local( array $slots, string $timezone = 'Europe/Warsaw', bool $with_resource = false ): array {
		$tz = new DateTimeZone( $timezone );

		return array_values(
			array_map(
				static fn ( Slot $slot ): string => ( $with_resource ? '#' . $slot->resource_id . ' ' : '' )
					. $slot->start()->setTimezone( $tz )->format( 'Y-m-d H:i' )
					. '-' . $slot->end()->setTimezone( $tz )->format( 'H:i' ),
				$slots
			)
		);
	}

	/**
	 * Formats slots as UTC "Y-m-d H:i" start strings.
	 *
	 * @param Slot[] $slots Slots.
	 *
	 * @return list<string>
	 */
	protected static function utc_starts( array $slots ): array {
		return array_values( array_map( static fn ( Slot $slot ): string => $slot->start()->format( 'Y-m-d H:i' ), $slots ) );
	}
}
