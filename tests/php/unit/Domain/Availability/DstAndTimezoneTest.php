<?php
/**
 * DST and time-zone tests for the availability engine.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Unit\Domain\Availability;

use DateTimeImmutable;
use DateTimeZone;
use Terminarz\Domain\Availability\AvailabilityEngine;
use Terminarz\Domain\Availability\ResourceCalendar;
use Terminarz\Domain\Availability\WallClock;
use Terminarz\Domain\Model\Slot;

/**
 * Rule under test (ADR-016): slots are generated on the real (UTC) time line inside working windows
 * whose local boundaries are converted with WallClock — a repeated local time maps to its earliest
 * occurrence, a non-existent local time maps to the DST transition instant.
 *
 * @covers \Terminarz\Domain\Availability\AvailabilityEngine
 * @covers \Terminarz\Domain\Availability\WallClock
 * @covers \Terminarz\Domain\Availability\AvailabilityQuery
 * @covers \Terminarz\Domain\Availability\ResourceCalendar
 */
final class DstAndTimezoneTest extends AvailabilityTestCase {

	/**
	 * Local start times with UTC offset, e.g. "02:30+02:00".
	 *
	 * @param Slot[] $slots    Slots.
	 * @param string $timezone Zone.
	 *
	 * @return list<string>
	 */
	private static function local_starts_with_offset( array $slots, string $timezone ): array {
		$tz = new DateTimeZone( $timezone );

		return array_values( array_map( static fn ( Slot $s ): string => $s->start()->setTimezone( $tz )->format( 'H:iP' ), $slots ) );
	}

	/**
	 * Asserts that slot starts are strictly increasing (hence unique) in UTC.
	 *
	 * @param Slot[] $slots Slots of one resource.
	 */
	private function assert_strictly_increasing( array $slots ): void {
		$previous = PHP_INT_MIN;
		foreach ( $slots as $slot ) {
			$this->assertGreaterThan( $previous, $slot->start()->getTimestamp(), 'Duplicate or unordered slot.' );
			$previous = $slot->start()->getTimestamp();
		}
	}


	public function test_warsaw_spring_forward_has_no_slots_in_the_missing_hour(): void {
		$slots = self::find(
			self::schedule( array( '00:00-06:00' ), array( 7 ) ),
			array(
				'from'     => '2026-03-29',
				'duration' => 30,
				'step'     => 30,
			)
		);

		$this->assertSame(
			array(
				'00:00+01:00',
				'00:30+01:00',
				'01:00+01:00',
				'01:30+01:00',
				'03:00+02:00',
				'03:30+02:00',
				'04:00+02:00',
				'04:30+02:00',
				'05:00+02:00',
				'05:30+02:00',
			),
			self::local_starts_with_offset( $slots, 'Europe/Warsaw' ),
			'Five real hours, no slot at 02:xx.'
		);
		$this->assert_strictly_increasing( $slots );
	}

	public function test_warsaw_spring_forward_window_boundaries_in_the_gap(): void {
		$base = array(
			'from'     => '2026-03-29',
			'duration' => 30,
			'step'     => 30,
		);

		// Window entirely inside the missing hour: nothing.
		$this->assertSame( array(), self::find( self::schedule( array( '02:00-02:45' ), array( 7 ) ), $base ) );

		// End in the gap → ends at the transition (03:00 CEST).
		$slots = self::find( self::schedule( array( '01:00-02:30' ), array( 7 ) ), $base );
		$this->assertSame( array( '01:00+01:00', '01:30+01:00' ), self::local_starts_with_offset( $slots, 'Europe/Warsaw' ) );

		// Start in the gap → starts at the transition (03:00 CEST).
		$slots = self::find( self::schedule( array( '02:30-04:00' ), array( 7 ) ), $base );
		$this->assertSame( array( '03:00+02:00', '03:30+02:00' ), self::local_starts_with_offset( $slots, 'Europe/Warsaw' ) );

		// Both windows on the same day: no overlap, no duplicates.
		$slots = self::find( self::schedule( array( '01:00-02:30', '02:30-04:00' ), array( 7 ) ), $base );
		$this->assertSame(
			array( '01:00+01:00', '01:30+01:00', '03:00+02:00', '03:30+02:00' ),
			self::local_starts_with_offset( $slots, 'Europe/Warsaw' )
		);
		$this->assert_strictly_increasing( $slots );
	}

	public function test_warsaw_spring_forward_duration_and_buffer_are_real_time(): void {
		// 01:00 CET → 04:00 CEST is two real hours; a 90-minute service starting 01:00 CET ends at 03:30 CEST,
		// and with its 15-minute buffer nothing else fits.
		$slots = self::find(
			self::schedule( array( '01:00-04:00' ), array( 7 ) ),
			array(
				'from'     => '2026-03-29',
				'duration' => 90,
				'buffer'   => 15,
				'step'     => 60,
			)
		);

		$this->assertSame( array( '2026-03-29 01:00-03:30' ), self::local( $slots ) );
		$this->assertSame( 90, $slots[0]->range->duration_minutes() );
	}

	public function test_warsaw_fall_back_lead_time_is_real_time(): void {
		// now = 02:10 CET (second occurrence, 01:10 UTC), lead 30 min → earliest 01:40 UTC → next grid point 02:00 UTC = 03:00 CET.
		$slots = self::find(
			self::schedule( array( '01:00-05:00' ), array( 7 ) ),
			array(
				'from'     => '2026-10-25',
				'duration' => 60,
				'step'     => 60,
				'now'      => '2026-10-25 01:10 UTC',
				'lead'     => 30,
			)
		);

		$this->assertSame( array( '03:00+01:00', '04:00+01:00' ), self::local_starts_with_offset( $slots, 'Europe/Warsaw' ) );
	}

	public function test_warsaw_fall_back_offers_the_repeated_hour_twice_without_duplicates(): void {
		$slots = self::find(
			self::schedule( array( '01:00-04:00' ), array( 7 ) ),
			array(
				'from'     => '2026-10-25',
				'duration' => 60,
				'step'     => 60,
			)
		);

		$this->assertSame(
			array( '01:00+02:00', '02:00+02:00', '02:00+01:00', '03:00+01:00' ),
			self::local_starts_with_offset( $slots, 'Europe/Warsaw' ),
			'Four real hours between 01:00 CEST and 04:00 CET.'
		);
		$this->assert_strictly_increasing( $slots );
	}

	public function test_warsaw_fall_back_window_starting_in_the_repeated_hour_uses_the_first_occurrence(): void {
		$slots = self::find(
			self::schedule( array( '02:00-03:00' ), array( 7 ) ),
			array(
				'from'     => '2026-10-25',
				'duration' => 30,
				'step'     => 30,
			)
		);

		$this->assertSame(
			array( '02:00+02:00', '02:30+02:00', '02:00+01:00', '02:30+01:00' ),
			self::local_starts_with_offset( $slots, 'Europe/Warsaw' )
		);
	}

	public function test_warsaw_fall_back_busy_interval_in_the_second_occurrence(): void {
		// Booked 02:00–03:00 CET (the second 02:00), i.e. 01:00–02:00 UTC.
		$busy  = array( self::busy( '2026-10-25 01:00', '2026-10-25 02:00', 'UTC' ) );
		$slots = self::find(
			self::schedule( array( '01:00-04:00' ), array( 7 ) ),
			array(
				'from'     => '2026-10-25',
				'duration' => 60,
				'step'     => 60,
			),
			array(),
			$busy
		);

		$this->assertSame( array( '01:00+02:00', '02:00+02:00', '03:00+01:00' ), self::local_starts_with_offset( $slots, 'Europe/Warsaw' ) );
	}

	public function test_warsaw_week_around_dst_keeps_local_hours(): void {
		$slots = self::find(
			self::schedule( array( '09:00-10:00' ) ),
			array(
				'from' => '2026-10-24',
				'to'   => '2026-10-26',
			)
		);

		$this->assertSame(
			array( '2026-10-24 07:00', '2026-10-25 08:00', '2026-10-26 08:00' ),
			self::utc_starts( $slots ),
			'09:00 local every day; the UTC time shifts after the change.'
		);
	}


	public function test_new_york_spring_forward(): void {
		$slots = self::find(
			self::schedule( array( '01:00-04:00' ), array( 7 ) ),
			array(
				'timezone' => 'America/New_York',
				'from'     => '2026-03-08',
			)
		);

		$this->assertSame( array( '01:00-05:00', '03:00-04:00' ), self::local_starts_with_offset( $slots, 'America/New_York' ) );
	}

	public function test_new_york_fall_back(): void {
		$slots = self::find(
			self::schedule( array( '01:00-04:00' ), array( 7 ) ),
			array(
				'timezone' => 'America/New_York',
				'from'     => '2026-11-01',
			)
		);

		$this->assertSame(
			array( '01:00-04:00', '01:00-05:00', '02:00-05:00', '03:00-05:00' ),
			self::local_starts_with_offset( $slots, 'America/New_York' )
		);
		$this->assert_strictly_increasing( $slots );
	}

	public function test_new_york_local_day_is_not_the_utc_day(): void {
		// Evening slots of 2026-10-05 EDT fall on 2026-10-06 in UTC and still belong to the 5th.
		$slots = self::find(
			self::schedule( array( '20:00-23:00' ) ),
			array(
				'timezone' => 'America/New_York',
				'from'     => '2026-10-05',
			)
		);
		$this->assertSame( array( '2026-10-06 00:00', '2026-10-06 01:00', '2026-10-06 02:00' ), self::utc_starts( $slots ) );

		// now = 2026-10-06 02:00 UTC = 2026-10-05 22:00 EDT → horizon counts from the 5th (local), so 1 day = up to the 6th.
		$slots = self::find(
			self::schedule( array( '22:00-23:00' ) ),
			array(
				'timezone' => 'America/New_York',
				'from'     => '2026-10-05',
				'to'       => '2026-10-09',
				'now'      => '2026-10-06 02:00 UTC',
				'horizon'  => 1,
			)
		);
		$this->assertSame( array( '2026-10-05 22:00-23:00', '2026-10-06 22:00-23:00' ), self::local( $slots, 'America/New_York' ) );
	}


	public function test_lord_howe_spring_forward_by_thirty_minutes(): void {
		$slots = self::find(
			self::schedule( array( '01:00-04:00' ), array( 7 ) ),
			array(
				'timezone' => 'Australia/Lord_Howe',
				'from'     => '2026-10-04',
				'duration' => 30,
				'step'     => 30,
			)
		);

		$this->assertSame(
			array( '01:00+10:30', '01:30+10:30', '02:30+11:00', '03:00+11:00', '03:30+11:00' ),
			self::local_starts_with_offset( $slots, 'Australia/Lord_Howe' ),
			'02:00–02:30 does not exist.'
		);
	}

	public function test_lord_howe_fall_back_by_thirty_minutes(): void {
		$slots = self::find(
			self::schedule( array( '01:00-04:00' ), array( 7 ) ),
			array(
				'timezone' => 'Australia/Lord_Howe',
				'from'     => '2026-04-05',
				'duration' => 30,
				'step'     => 30,
			)
		);

		$this->assertSame(
			array( '01:00+11:00', '01:30+11:00', '01:30+10:30', '02:00+10:30', '02:30+10:30', '03:00+10:30', '03:30+10:30' ),
			self::local_starts_with_offset( $slots, 'Australia/Lord_Howe' ),
			'01:30–02:00 happens twice.'
		);
		$this->assert_strictly_increasing( $slots );
	}


	public function test_kiritimati_local_day_is_a_day_ahead_of_utc(): void {
		$slots = self::find(
			self::schedule( array( '09:00-11:00' ), array( 1 ) ), // Mondays only.
			array(
				'timezone' => 'Pacific/Kiritimati',
				'from'     => '2026-10-04',
				'to'       => '2026-10-06',
			)
		);

		$this->assertSame( array( '2026-10-04 19:00', '2026-10-04 20:00' ), self::utc_starts( $slots ), 'Monday 5th local = Sunday 4th UTC.' );
		$this->assertSame( array( '2026-10-05 09:00-10:00', '2026-10-05 10:00-11:00' ), self::local( $slots, 'Pacific/Kiritimati' ) );
	}

	public function test_kiritimati_horizon_uses_the_local_date(): void {
		// now = 2026-10-04 12:00 UTC = 2026-10-05 02:00 local; horizon 0 → only the local 5th.
		$slots = self::find(
			self::schedule( array( '09:00-10:00' ) ),
			array(
				'timezone' => 'Pacific/Kiritimati',
				'from'     => '2026-10-04',
				'to'       => '2026-10-07',
				'now'      => '2026-10-04 12:00 UTC',
				'horizon'  => 0,
			)
		);

		$this->assertSame( array( '2026-10-05 09:00-10:00' ), self::local( $slots, 'Pacific/Kiritimati' ), 'The 4th local is already over.' );
	}

	public function test_fixed_offset_zone_ignores_dst_dates(): void {
		foreach ( array( '2026-03-29', '2026-10-25', '2026-07-01' ) as $date ) {
			$slots    = self::find(
				self::schedule( array( '01:00-04:00' ) ),
				array(
					'timezone' => '+02:00',
					'from'     => $date,
				)
			);
			$previous = ( new DateTimeImmutable( $date ) )->modify( '-1 day' )->format( 'Y-m-d' );
			$this->assertSame( array( "{$previous} 23:00", "{$date} 00:00", "{$date} 01:00" ), self::utc_starts( $slots ) );
			$this->assertSame( array( '01:00+02:00', '02:00+02:00', '03:00+02:00' ), self::local_starts_with_offset( $slots, '+02:00' ) );
		}
	}


	/**
	 * @return iterable<string, array{string}>
	 */
	public static function zones(): iterable {
		foreach ( array( 'Europe/Warsaw', 'America/New_York', 'Australia/Lord_Howe', 'Pacific/Kiritimati', '+02:00', 'UTC' ) as $zone ) {
			yield $zone => array( $zone );
		}
	}

	/**
	 * Every real hour of 2026 is offered exactly once when the resource works around the clock.
	 *
	 * @dataProvider zones
	 *
	 * @param string $zone Time zone.
	 */
	public function test_whole_year_round_the_clock_has_no_gaps_or_duplicates( string $zone ): void {
		$tz    = new DateTimeZone( $zone );
		$slots = ( new AvailabilityEngine() )->find_slots(
			self::query(
				array( new ResourceCalendar( 1, self::schedule( array( '00:00-24:00' ) ) ) ),
				array(
					'timezone' => $zone,
					'from'     => '2026-01-01',
					'to'       => '2026-12-31',
					'now'      => '2025-12-01 00:00 UTC',
				)
			)
		);

		// Expected: per local day, whole hours that fit between consecutive local midnights.
		$expected = 0;
		$day      = new DateTimeImmutable( '2026-01-01 00:00', $tz );
		for ( $i = 0; $i < 365; $i++ ) {
			$next      = $day->modify( '+1 day' );
			$expected += intdiv( $next->getTimestamp() - $day->getTimestamp(), 3600 );
			$day       = $next;
		}

		$this->assertCount( $expected, $slots );
		$this->assert_strictly_increasing( $slots );
		$this->assertSame( ( new DateTimeImmutable( '2026-01-01 00:00', $tz ) )->getTimestamp(), $slots[0]->start()->getTimestamp() );
	}


	public function test_wall_clock_rules(): void {
		$warsaw = new DateTimeZone( 'Europe/Warsaw' );

		// Regular time.
		$this->assertSame( '2026-07-01 07:00', gmdate( 'Y-m-d H:i', WallClock::to_timestamp( $warsaw, '2026-07-01', 9 * 60 ) ) );
		// 24:00 = next local midnight.
		$this->assertSame( '2026-07-01 22:00', gmdate( 'Y-m-d H:i', WallClock::to_timestamp( $warsaw, '2026-07-01', 1440 ) ) );
		// Non-existent 02:30 → transition instant 01:00 UTC (03:00 CEST).
		$this->assertSame( '2026-03-29 01:00', gmdate( 'Y-m-d H:i', WallClock::to_timestamp( $warsaw, '2026-03-29', 150 ) ) );
		// Repeated 02:30 → earliest occurrence (CEST, 00:30 UTC).
		$this->assertSame( '2026-10-25 00:30', gmdate( 'Y-m-d H:i', WallClock::to_timestamp( $warsaw, '2026-10-25', 150 ) ) );
		// Fixed offset.
		$this->assertSame( '2026-03-29 00:30', gmdate( 'Y-m-d H:i', WallClock::to_timestamp( new DateTimeZone( '+02:00' ), '2026-03-29', 150 ) ) );
		// Local date of an instant.
		$this->assertSame( '2026-10-05', WallClock::local_date( new DateTimeImmutable( '2026-10-04 12:00 UTC' ), new DateTimeZone( 'Pacific/Kiritimati' ) ) );
	}

	public function test_wall_clock_is_monotonic_across_transitions(): void {
		foreach ( array(
			'Europe/Warsaw'       => array( '2026-03-29', '2026-10-25' ),
			'Australia/Lord_Howe' => array( '2026-04-05', '2026-10-04' ),
			'America/New_York'    => array( '2026-03-08', '2026-11-01' ),
		) as $zone => $dates ) {
			$tz = new DateTimeZone( $zone );
			foreach ( $dates as $date ) {
				$previous = PHP_INT_MIN;
				for ( $minute = 0; $minute <= 1440; $minute += 5 ) {
					$ts = WallClock::to_timestamp( $tz, $date, $minute );
					$this->assertGreaterThanOrEqual( $previous, $ts, "{$zone} {$date} minute {$minute}" );
					$previous = $ts;
				}
			}
		}
	}
}
