<?php
/**
 * Unit tests for the availability engine.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Unit\Domain\Availability;

use DateTimeImmutable;
use DateTimeZone;
use Terminarz\Domain\Availability\AvailabilityEngine;
use Terminarz\Domain\Availability\AvailabilityQuery;
use Terminarz\Domain\Availability\BusyIntervals;
use Terminarz\Domain\Availability\ResourceCalendar;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Domain\Model\ScheduleException;
use Terminarz\Domain\Model\Service;
use Terminarz\Domain\Model\TimeRange;

/**
 * 2026-10-05 is a Monday; Europe/Warsaw is on CEST (UTC+2) in early October.
 *
 * @covers \Terminarz\Domain\Availability\AvailabilityEngine
 * @covers \Terminarz\Domain\Availability\AvailabilityQuery
 * @covers \Terminarz\Domain\Availability\ResourceCalendar
 * @covers \Terminarz\Domain\Availability\BusyIntervals
 * @covers \Terminarz\Domain\Availability\WallClock
 */
final class AvailabilityEngineTest extends AvailabilityTestCase {

	public function test_slots_fill_working_hours_on_the_grid(): void {
		$slots = self::find( self::schedule( array( '09:00-12:00' ) ) );

		$this->assertSame(
			array( '2026-10-05 09:00-10:00', '2026-10-05 10:00-11:00', '2026-10-05 11:00-12:00' ),
			self::local( $slots )
		);
		$this->assertSame( '2026-10-05 07:00', $slots[0]->start()->format( 'Y-m-d H:i' ), 'Slots are in UTC.' );
		$this->assertSame( 'UTC', $slots[0]->start()->getTimezone()->getName() );
	}

	public function test_default_step_is_fifteen_minutes(): void {
		$query = new AvailabilityQuery(
			array( new ResourceCalendar( 1, self::schedule( array( '09:00-10:00' ) ) ) ),
			30,
			0,
			new DateTimeZone( 'Europe/Warsaw' ),
			'2026-10-05',
			'2026-10-05',
			new DateTimeImmutable( '2026-09-01 00:00 UTC' )
		);

		$this->assertSame( 15, $query->slot_step_minutes );
		$this->assertSame(
			array( '2026-10-05 09:00-09:30', '2026-10-05 09:15-09:45', '2026-10-05 09:30-10:00' ),
			self::local( ( new AvailabilityEngine() )->find_slots( $query ) )
		);
	}

	public function test_grid_starts_at_each_window_start(): void {
		$slots = self::find(
			self::schedule( array( '09:10-10:40' ) ),
			array(
				'duration' => 30,
				'step'     => 30,
			)
		);

		$this->assertSame( array( '2026-10-05 09:10-09:40', '2026-10-05 09:40-10:10', '2026-10-05 10:10-10:40' ), self::local( $slots ) );
	}

	public function test_breaks_split_the_day(): void {
		$slots = self::find( self::schedule( array( '09:00-13:00' ), array( 1 ), array( '10:30-11:00' ) ), array( 'step' => 30 ) );

		$this->assertSame(
			array( '2026-10-05 09:00-10:00', '2026-10-05 09:30-10:30', '2026-10-05 11:00-12:00', '2026-10-05 11:30-12:30', '2026-10-05 12:00-13:00' ),
			self::local( $slots )
		);
	}

	public function test_service_with_buffer_must_fit_in_the_window(): void {
		$slots = self::find(
			self::schedule( array( '09:00-11:00' ) ),
			array(
				'duration' => 45,
				'buffer'   => 15,
				'step'     => 15,
			)
		);

		$this->assertSame(
			array( '2026-10-05 09:00-09:45', '2026-10-05 09:15-10:00', '2026-10-05 09:30-10:15', '2026-10-05 09:45-10:30', '2026-10-05 10:00-10:45' ),
			self::local( $slots ),
			'10:15 would need until 11:15 including the buffer.'
		);
	}

	public function test_too_short_window_gives_no_slots(): void {
		$this->assertSame( array(), self::find( self::schedule( array( '09:00-09:30' ) ) ) );
		$this->assertSame( array(), self::find( self::schedule( array( '09:00-10:00' ) ), array( 'buffer' => 1 ) ) );
	}

	public function test_adjacent_bookings_leave_touching_slots_free(): void {
		$slots = self::find(
			self::schedule( array( '09:00-13:00' ) ),
			array(),
			array(),
			array( self::busy( '2026-10-05 10:00', '2026-10-05 11:00' ) )
		);

		$this->assertSame(
			array( '2026-10-05 09:00-10:00', '2026-10-05 11:00-12:00', '2026-10-05 12:00-13:00' ),
			self::local( $slots )
		);
	}

	public function test_busy_interval_with_buffer_pushes_next_slot_to_the_grid(): void {
		// Booking 10:00–11:00 with a 10-minute buffer: busy until 11:10 → next grid point 11:15.
		$slots = self::find(
			self::schedule( array( '09:00-13:00' ) ),
			array( 'step' => 15 ),
			array(),
			array( self::busy( '2026-10-05 10:00', '2026-10-05 11:10' ) )
		);

		$starts = array_map( static fn ( string $s ): string => substr( $s, 11, 5 ), self::local( $slots ) );
		$this->assertSame( array( '09:00', '11:15', '11:30', '11:45', '12:00' ), $starts );
	}

	public function test_new_booking_buffer_must_not_overlap_existing_booking(): void {
		$slots = self::find(
			self::schedule( array( '09:00-12:00' ) ),
			array(
				'duration' => 45,
				'buffer'   => 15,
				'step'     => 15,
			),
			array(),
			array( self::busy( '2026-10-05 10:00', '2026-10-05 11:00' ) )
		);

		$starts = array_map( static fn ( string $s ): string => substr( $s, 11, 5 ), self::local( $slots ) );
		$this->assertSame( array( '09:00', '11:00' ), $starts, '09:15 would block until 10:15.' );
	}

	public function test_overlapping_busy_intervals_in_any_order(): void {
		$slots = self::find(
			self::schedule( array( '08:00-16:00' ) ),
			array(),
			array(),
			array(
				self::busy( '2026-10-05 13:30', '2026-10-05 14:00' ),
				self::busy( '2026-10-05 09:00', '2026-10-05 10:00' ),
				self::busy( '2026-10-05 09:30', '2026-10-05 11:00' ),
				self::busy( '2026-10-04 23:00', '2026-10-05 08:15' ),
			)
		);

		$starts = array_map( static fn ( string $s ): string => substr( $s, 11, 5 ), self::local( $slots ) );
		$this->assertSame( array( '11:00', '12:00', '14:00', '15:00' ), $starts );
	}

	public function test_global_exception_closes_the_day(): void {
		$slots = self::find(
			self::schedule( array( '09:00-11:00' ) ),
			array( 'to' => '2026-10-06' ),
			array( ScheduleException::closed( null, '2026-10-05' ) )
		);

		$this->assertSame( array( '2026-10-06 09:00-10:00', '2026-10-06 10:00-11:00' ), self::local( $slots ) );
	}

	public function test_resource_exception_takes_precedence_over_global(): void {
		$exceptions = array(
			ScheduleException::custom_hours( 1, '2026-10-05', self::windows( array( '14:00-16:00' ) ) ),
			ScheduleException::closed( null, '2026-10-05' ),
			ScheduleException::closed( null, '2026-10-06' ),
		);

		$slots = self::find( self::schedule( array( '09:00-11:00' ) ), array( 'to' => '2026-10-06' ), $exceptions );
		$this->assertSame( array( '2026-10-05 14:00-15:00', '2026-10-05 15:00-16:00' ), self::local( $slots ) );

		// Same result regardless of the order of exceptions.
		$slots = self::find( self::schedule( array( '09:00-11:00' ) ), array( 'to' => '2026-10-06' ), array_reverse( $exceptions ) );
		$this->assertSame( array( '2026-10-05 14:00-15:00', '2026-10-05 15:00-16:00' ), self::local( $slots ) );
	}

	public function test_custom_hours_ignore_weekly_breaks_and_open_closed_days(): void {
		$schedule = self::schedule( array( '09:00-12:00' ), array( 1 ), array( '10:00-11:00' ) );

		$slots = self::find( $schedule, array(), array( ScheduleException::custom_hours( null, '2026-10-05', self::windows( array( '09:00-12:00' ) ) ) ) );
		$this->assertCount( 3, $slots );

		// Sunday is closed in the weekly schedule but opened by an exception.
		$slots = self::find( $schedule, array( 'from' => '2026-10-11' ), array( ScheduleException::custom_hours( 1, '2026-10-11', self::windows( array( '10:00-11:00' ) ) ) ) );
		$this->assertSame( array( '2026-10-11 10:00-11:00' ), self::local( $slots ) );
	}

	public function test_exception_of_another_resource_is_rejected(): void {
		$this->expectException( InvalidValue::class );
		new ResourceCalendar( 1, self::schedule( array( '09:00-11:00' ) ), array( ScheduleException::closed( 2, '2026-10-05' ) ) );
	}

	public function test_past_slots_and_minimum_lead_time(): void {
		$schedule = self::schedule( array( '09:00-13:00' ) );

		// now = 09:20 local: 09:00 already started.
		$slots = self::find( $schedule, array( 'now' => '2026-10-05 07:20 UTC' ) );
		$this->assertSame( array( '10:00', '11:00', '12:00' ), array_map( static fn ( string $s ): string => substr( $s, 11, 5 ), self::local( $slots ) ) );

		// now = 09:00 local exactly: slot starting now is allowed with no lead time.
		$slots = self::find( $schedule, array( 'now' => '2026-10-05 07:00 UTC' ) );
		$this->assertCount( 4, $slots );

		// 90 minutes lead from 09:00 → earliest 10:30 → next grid point 11:00.
		$slots = self::find(
			$schedule,
			array(
				'now'  => '2026-10-05 07:00 UTC',
				'lead' => 90,
			)
		);
		$this->assertSame( array( '11:00', '12:00' ), array_map( static fn ( string $s ): string => substr( $s, 11, 5 ), self::local( $slots ) ) );
	}

	public function test_maximum_horizon_in_local_days(): void {
		$options = array(
			'from'    => '2026-10-05',
			'to'      => '2026-10-10',
			'now'     => '2026-10-04 22:30 UTC', // 2026-10-05 00:30 local.
			'horizon' => 2,
		);

		$slots = self::find( self::schedule( array( '09:00-10:00' ) ), $options );
		$this->assertSame(
			array( '2026-10-05 09:00-10:00', '2026-10-06 09:00-10:00', '2026-10-07 09:00-10:00' ),
			self::local( $slots ),
			'Horizon counts from the local date of now (05), not the UTC date (04).'
		);

		$options['horizon'] = 0;
		$this->assertCount( 1, self::find( self::schedule( array( '09:00-10:00' ) ), $options ) );
	}

	public function test_multiple_resources_are_sorted_by_start_then_resource(): void {
		$query = self::query(
			array(
				new ResourceCalendar( 7, self::schedule( array( '09:00-11:00' ) ) ),
				new ResourceCalendar( 3, self::schedule( array( '10:00-12:00' ) ), array(), array( self::busy( '2026-10-05 11:00', '2026-10-05 12:00' ) ) ),
				new ResourceCalendar( 5, self::schedule( array( '09:00-10:00' ) ) ),
			)
		);

		$this->assertSame(
			array( '#5 2026-10-05 09:00-10:00', '#7 2026-10-05 09:00-10:00', '#3 2026-10-05 10:00-11:00', '#7 2026-10-05 10:00-11:00' ),
			self::local( ( new AvailabilityEngine() )->find_slots( $query ), 'Europe/Warsaw', true )
		);
	}

	public function test_result_is_deterministic(): void {
		$query  = self::query(
			array(
				new ResourceCalendar( 2, self::schedule( array( '09:00-17:00' ) ), array(), array( self::busy( '2026-10-06 12:00', '2026-10-06 13:30' ) ) ),
				new ResourceCalendar( 1, self::schedule( array( '08:00-16:00' ) ) ),
			),
			array(
				'to'       => '2026-10-11',
				'step'     => 15,
				'duration' => 50,
			)
		);
		$engine = new AvailabilityEngine();

		$first  = self::local( $engine->find_slots( $query ), 'UTC', true );
		$second = self::local( $engine->find_slots( $query ), 'UTC', true );
		$this->assertSame( $first, $second );

		$sorted = $engine->find_slots( $query );
		for ( $i = 1, $n = count( $sorted ); $i < $n; $i++ ) {
			$order = $sorted[ $i - 1 ]->start() <=> $sorted[ $i ]->start();
			if ( 0 === $order ) {
				$order = $sorted[ $i - 1 ]->resource_id <=> $sorted[ $i ]->resource_id;
			}
			$this->assertSame( -1, $order, 'Slots must be strictly ordered by start, then resource.' );
		}
	}

	public function test_window_until_midnight_and_next_day_from_midnight(): void {
		$slots = self::find(
			self::schedule( array( '22:00-24:00', '00:00-02:00' ) ),
			array(
				'duration' => 120,
				'step'     => 60,
				'from'     => '2026-10-05',
				'to'       => '2026-10-06',
			)
		);

		$this->assertSame(
			array( '2026-10-05 00:00-02:00', '2026-10-05 22:00-00:00', '2026-10-06 00:00-02:00', '2026-10-06 22:00-00:00' ),
			self::local( $slots ),
			'Windows touching at midnight are not merged: a slot never spans two windows.'
		);
	}

	public function test_busy_intervals_from_bookings_apply_status_and_hold_rules(): void {
		$now      = new DateTimeImmutable( '2026-10-01 12:00 UTC' );
		$customer = new Customer( 'Anna', 'anna@example.com' );
		$range    = static fn ( string $start ): TimeRange => self::busy( "2026-10-05 {$start}", '2026-10-05 ' . sprintf( '%02d', (int) substr( $start, 0, 2 ) + 1 ) . ':00' );
		$bookings = array(
			new Booking( 1, 1, $range( '09:00' ), BookingStatus::Confirmed, $customer, 30 ),
			new Booking( 1, 1, $range( '11:00' ), BookingStatus::Cancelled, $customer ),
			new Booking( 1, 1, $range( '12:00' ), BookingStatus::PendingPayment, $customer, 0, new DateTimeImmutable( '2026-10-01 11:59 UTC' ) ),
			new Booking( 1, 1, $range( '13:00' ), BookingStatus::PendingPayment, $customer, 0, new DateTimeImmutable( '2026-10-01 12:10 UTC' ) ),
			new Booking( 2, 1, $range( '14:00' ), BookingStatus::Confirmed, $customer ),
		);

		$busy = BusyIntervals::from_bookings( $bookings, $now, 1 );
		$this->assertCount( 2, $busy );
		$this->assertSame( '2026-10-05 08:30', $busy[0]->end->format( 'Y-m-d H:i' ), 'Buffer included (10:30 local).' );
		$this->assertCount( 3, BusyIntervals::from_bookings( $bookings, $now ) );

		$slots  = self::find(
			self::schedule( array( '09:00-15:00' ) ),
			array(
				'step' => 30,
				'now'  => '2026-10-01 12:00 UTC',
			),
			array(),
			$busy
		);
		$starts = array_map( static fn ( string $s ): string => substr( $s, 11, 5 ), self::local( $slots ) );
		$this->assertSame( array( '10:30', '11:00', '11:30', '12:00', '14:00' ), $starts );
	}

	public function test_for_service_factory(): void {
		$query = AvailabilityQuery::for_service(
			new Service( 1, 'Masaż', 50, 20000, 10 ),
			array( new ResourceCalendar( 1, self::schedule( array( '09:00-11:00' ) ) ) ),
			new DateTimeZone( 'Europe/Warsaw' ),
			'2026-10-05',
			'2026-10-05',
			new DateTimeImmutable( '2026-09-01 00:00 UTC' ),
			slot_step_minutes: 60
		);

		$this->assertSame( 50, $query->duration_minutes );
		$this->assertSame( 10, $query->buffer_after_minutes );
		$this->assertSame(
			array( '2026-10-05 09:00-09:50', '2026-10-05 10:00-10:50' ),
			self::local( ( new AvailabilityEngine() )->find_slots( $query ) )
		);
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function invalid_queries(): iterable {
		yield 'zero duration' => array( array( 'duration' => 0 ) );
		yield 'negative buffer' => array( array( 'buffer' => -1 ) );
		yield 'negative lead' => array( array( 'lead' => -1 ) );
		yield 'negative horizon' => array( array( 'horizon' => -1 ) );
		yield 'zero step' => array( array( 'step' => 0 ) );
		yield 'reversed dates' => array(
			array(
				'from' => '2026-10-05',
				'to'   => '2026-10-04',
			),
		);
		yield 'bad date' => array( array( 'from' => '2026-13-01' ) );
		yield 'too long range' => array(
			array(
				'from' => '2026-01-01',
				'to'   => '2027-01-02',
			),
		);
	}

	/**
	 * @dataProvider invalid_queries
	 *
	 * @param array<string, mixed> $options Overrides.
	 */
	public function test_invalid_query_is_rejected( array $options ): void {
		$this->expectException( InvalidValue::class );
		self::query( array( new ResourceCalendar( 1, self::schedule( array( '09:00-10:00' ) ) ) ), $options );
	}

	public function test_duplicate_resources_are_rejected(): void {
		$this->expectException( InvalidValue::class );
		self::query(
			array(
				new ResourceCalendar( 1, self::schedule( array( '09:00-10:00' ) ) ),
				new ResourceCalendar( 1, self::schedule( array( '11:00-12:00' ) ) ),
			)
		);
	}

	public function test_thirty_days_for_ten_resources_is_fast(): void {
		$resources = array();
		for ( $id = 1; $id <= 10; $id++ ) {
			$busy = array();
			for ( $day = 0; $day < 30; $day++ ) {
				$date = gmdate( 'Y-m-d', (int) strtotime( "2026-11-02 +{$day} days" ) );
				foreach ( array( '09:00', '11:30', '14:15', '16:00' ) as $start ) {
					$busy[] = self::busy( "{$date} {$start}", "{$date} {$start} +50 minutes" );
				}
			}
			$resources[] = new ResourceCalendar(
				$id,
				self::schedule( array( '08:00-12:00', '12:30-18:00' ), array( 1, 2, 3, 4, 5, 6 ), array( '10:00-10:15' ) ),
				array( ScheduleException::closed( null, '2026-11-11' ) ),
				$busy
			);
		}
		$query = self::query(
			$resources,
			array(
				'from'     => '2026-11-02',
				'to'       => '2026-12-01',
				'step'     => 15,
				'duration' => 30,
				'buffer'   => 5,
			)
		);

		$started = hrtime( true );
		$slots   = ( new AvailabilityEngine() )->find_slots( $query );
		$elapsed = ( hrtime( true ) - $started ) / 1e6;

		$this->assertSame( 3250, count( $slots ), '13 slots x 25 open days x 10 resources.' );
		$this->assertLessThan( 300, $elapsed, sprintf( 'Engine took %.1f ms.', $elapsed ) );
	}
}
