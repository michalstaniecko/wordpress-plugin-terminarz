<?php
/**
 * Integration tests for AvailabilityService (and schedule-aware reservations).
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Application;

use DateTimeZone;
use Terminarz\Application\AvailabilityService;
use Terminarz\Application\AvailabilitySettings;
use Terminarz\Application\BookingService;
use Terminarz\Application\FixedClock;
use DateTimeImmutable;
use Terminarz\Domain\Availability\AvailabilityEngine;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\BookableResource;
use Terminarz\Domain\Exception\SlotUnavailable;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Domain\Model\ScheduleException;
use Terminarz\Domain\Model\Slot;
use Terminarz\Domain\Model\TimeWindow;
use Terminarz\Domain\Model\WeeklySchedule;
use Terminarz\Infrastructure\Database\Schema;
use Terminarz\Infrastructure\Services;
use Terminarz\Tests\Integration\Support\BookingFixtures;
use WP_UnitTestCase;

/**
 * Site time zone Europe/Warsaw (UTC+1 in January). "Now" is Monday 2030-01-07 06:00 UTC (07:00 local).
 *
 * @covers \Terminarz\Application\AvailabilityService
 * @covers \Terminarz\Application\AvailabilitySettings
 * @covers \Terminarz\Application\BookingService
 * @covers \Terminarz\Infrastructure\Services
 */
final class AvailabilityServiceTest extends WP_UnitTestCase {

	use BookingFixtures;

	private const MONDAY = '2030-01-07';

	/**
	 * Clock.
	 *
	 * @var FixedClock
	 */
	private FixedClock $clock;

	public function set_up(): void {
		parent::set_up();
		$this->clock = new FixedClock( '2030-01-07 06:00' );
	}

	public function test_slots_follow_schedule_breaks_and_grid(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$this->schedule( $resource, '09:00', '12:00', array( '10:00', '10:30' ) );

		$slots = $this->availability( 30 )->slots( $service, self::MONDAY, self::MONDAY );

		$this->assertSame( array( '09:00', '10:30', '11:00' ), $this->local_starts( $slots ) );
		$this->assertSame( '08:00', $slots[0]->start()->format( 'H:i' ), 'Slots are returned in UTC.' );
	}

	public function test_bookings_with_buffers_block_and_can_be_excluded(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 30, array( $resource ) );
		$this->schedule( $resource, '09:00', '14:00' );
		$container = $this->container( 30 );
		$booking   = $container->booking_service()->reserve( $service, $resource, self::local( '10:00' ), $this->customer() )->booking;

		// 10:00–11:00 + 30 min buffer blocks until 11:30; new slots need 90 min (60 + 30 buffer).
		$this->assertSame( array( '11:30', '12:00', '12:30' ), $this->local_starts( $container->availability_service()->slots( $service, self::MONDAY, self::MONDAY ) ) );
		$this->assertSame(
			array( '09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30' ),
			$this->local_starts( $container->availability_service()->slots( $service, self::MONDAY, self::MONDAY, null, $booking->id ) )
		);
	}

	public function test_exceptions_resource_overrides_global(): void {
		$a       = $this->make_resource( 'A' );
		$b       = $this->make_resource( 'B' );
		$service = $this->make_service( 60, 0, array( $a, $b ) );
		$this->schedule( $a, '09:00', '11:00' );
		$this->schedule( $b, '09:00', '11:00' );
		$container = $this->container( 60 );
		$container->schedule_exceptions()->save( ScheduleException::closed( null, self::MONDAY ) );
		$container->schedule_exceptions()->save( ScheduleException::custom_hours( $b, self::MONDAY, array( TimeWindow::from_strings( '15:00', '16:00' ) ) ) );

		$slots = $container->availability_service()->slots( $service, self::MONDAY, self::MONDAY );

		$this->assertSame( array( '15:00' ), $this->local_starts( $slots ) );
		$this->assertSame( $b, $slots[0]->resource_id );
	}

	public function test_single_resource_must_perform_service_and_inactive_resources_are_skipped(): void {
		$a       = $this->make_resource( 'A' );
		$other   = $this->make_resource( 'Other' );
		$service = $this->make_service( 60, 0, array( $a ) );
		$this->schedule( $a, '09:00', '10:00' );

		try {
			$this->availability( 60 )->slots( $service, self::MONDAY, self::MONDAY, $other );
			$this->fail( 'Unassigned resource accepted.' );
		} catch ( InvalidValue $e ) {
			$this->assertStringContainsString( 'resource', $e->getMessage() );
		}

		$container = $this->container( 60 );
		$this->assertCount( 1, $container->availability_service()->slots( $service, self::MONDAY, self::MONDAY ) );
		$container->resources()->save( new BookableResource( $a, 'A', false ) );
		$this->assertSame( array(), $container->availability_service()->slots( $service, self::MONDAY, self::MONDAY ) );
	}

	public function test_any_resource_uses_preference_order(): void {
		$a       = $this->make_resource( 'A' );
		$b       = $this->make_resource( 'B' );
		$service = $this->make_service( 60, 0, array( $b, $a ) );
		$this->schedule( $a, '09:00', '11:00' );
		$this->schedule( $b, '09:00', '10:00' );

		$slots = $this->availability( 60 )->any_resource_slots( $service, self::MONDAY, self::MONDAY );

		$this->assertSame( array( '09:00', '10:00' ), $this->local_starts( $slots ) );
		$this->assertSame( array( $b, $a ), array_map( static fn( Slot $s ): int => $s->resource_id, $slots ) );
	}

	public function test_any_resource_least_busy_strategy(): void {
		$a       = $this->make_resource( 'A' );
		$b       = $this->make_resource( 'B' );
		$service = $this->make_service( 60, 0, array( $b, $a ) );
		$this->schedule( $a, '09:00', '13:00' );
		$this->schedule( $b, '09:00', '13:00' );
		$container = $this->container( 60, AvailabilitySettings::STRATEGY_LEAST_BUSY );
		$container->booking_service()->reserve( $service, $b, self::local( '12:00' ), $this->customer() );

		$slots = $container->availability_service()->any_resource_slots( $service, self::MONDAY, self::MONDAY );

		$this->assertSame( $a, $slots[0]->resource_id, 'B (preferred) already has a booking that day.' );
		$this->assertSame( array( $a, $b ), $container->availability_service()->free_resources_at( $service, self::local( '09:00' ) ) );
		$this->assertSame( array( $b, $a ), $this->container( 60 )->availability_service()->free_resources_at( $service, self::local( '09:00' ) ) );
	}

	public function test_is_available_checks_hours_grid_and_lead_time(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$this->schedule( $resource, '07:00', '12:00' );
		$availability = new AvailabilityService(
			...$this->availability_dependencies( new AvailabilitySettings( new DateTimeZone( 'Europe/Warsaw' ), 120, 30, 30 ) )
		);

		$this->assertTrue( $availability->is_available( $service, $resource, self::local( '09:00' ) ) );
		$this->assertFalse( $availability->is_available( $service, $resource, self::local( '08:30' ) ), 'Lead time: now is 07:00 local, min. 120 min.' );
		$this->assertFalse( $availability->is_available( $service, $resource, self::local( '09:10' ) ), 'Off the grid.' );
		$this->assertFalse( $availability->is_available( $service, $resource, self::local( '11:30' ) ), 'Does not fit before 12:00.' );
		$this->assertFalse( $availability->is_available( $service, $resource, self::local( '09:00', '2030-03-11' ) ), 'Beyond the 30-day horizon.' );
		$this->assertFalse( $availability->is_available( 999999, $resource, self::local( '09:00' ) ), 'Unknown service.' );
	}

	public function test_reservations_are_validated_against_availability(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$this->schedule( $resource, '09:00', '11:00' );
		$bookings = $this->container( 60 )->booking_service();

		try {
			$bookings->reserve( $service, $resource, self::local( '13:00' ), $this->customer() );
			$this->fail( 'Reservation outside working hours accepted.' );
		} catch ( SlotUnavailable $e ) {
			$this->assertStringContainsString( '12:00', $e->getMessage() );
		}

		$booking = $bookings->reserve( $service, $resource, self::local( '09:00' ), $this->customer() )->booking;
		$this->assertSame( BookingStatus::Pending, $booking->status );

		$moved = $bookings->reschedule( (int) $booking->id, self::local( '10:00' ) );
		$this->assertSame( '09:00', $moved->range->start->format( 'H:i' ), 'UTC' );

		$this->expectException( SlotUnavailable::class );
		$bookings->reschedule( (int) $booking->id, self::local( '10:30' ) );
	}

	public function test_reserve_any_assigns_resources_until_none_is_left(): void {
		$a       = $this->make_resource( 'A' );
		$b       = $this->make_resource( 'B' );
		$service = $this->make_service( 60, 0, array( $b, $a ) );
		$this->schedule( $a, '09:00', '10:00' );
		$this->schedule( $b, '09:00', '10:00' );
		$bookings = $this->container( 60 )->booking_service();

		$this->assertSame( $b, $bookings->reserve_any( $service, self::local( '09:00' ), $this->customer() )->booking->resource_id );
		$this->assertSame( $a, $bookings->reserve_any( $service, self::local( '09:00' ), $this->customer() )->booking->resource_id );

		$this->expectException( SlotUnavailable::class );
		$bookings->reserve_any( $service, self::local( '09:00' ), $this->customer() );
	}

	public function test_query_count_does_not_depend_on_resources_or_days(): void {
		global $wpdb;
		$ids = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$ids[] = $this->make_resource( "R{$i}" );
			$this->schedule( $ids[ $i ], '09:00', '17:00' );
		}
		$service      = $this->make_service( 30, 0, $ids );
		$availability = $this->availability( 15 );

		$before = $wpdb->num_queries;
		$availability->slots( $service, self::MONDAY, '2030-02-05' );
		$this->assertLessThanOrEqual( 6, $wpdb->num_queries - $before );
	}

	public function test_settings_are_read_from_wordpress(): void {
		global $wpdb;
		update_option( 'timezone_string', 'Europe/Warsaw' );
		update_option(
			'trmz_settings',
			array(
				'min_lead_minutes'      => '90',
				'max_horizon_days'      => '60',
				'slot_step_minutes'     => '20',
				'any_resource_strategy' => 'least_busy',
			)
		);

		$settings = ( new Services( $wpdb, $this->clock ) )->availability_settings();
		$this->assertSame( 'Europe/Warsaw', $settings->timezone->getName() );
		$this->assertSame( 90, $settings->min_lead_minutes );
		$this->assertSame( 60, $settings->max_horizon_days );
		$this->assertSame( 20, $settings->slot_step_minutes );
		$this->assertSame( AvailabilitySettings::STRATEGY_LEAST_BUSY, $settings->strategy );

		// A corrupted stored value (bypassing the setting's sanitize_callback) falls back to the defaults.
		$corrupt  = static fn(): array => array(
			'any_resource_strategy' => 'bogus',
			'max_horizon_days'      => -3,
		);
		add_filter( 'pre_option_trmz_settings', $corrupt );
		$fallback = ( new Services( $wpdb, $this->clock ) )->availability_settings();
		remove_filter( 'pre_option_trmz_settings', $corrupt );
		$this->assertSame( AvailabilitySettings::STRATEGY_ORDER, $fallback->strategy );
		$this->assertSame( 90, $fallback->max_horizon_days );
		$this->assertSame( 60, $fallback->min_lead_minutes );
	}

	/**
	 * 30 days × 10 resources with ~1200 bookings. Threshold: TRMZ_BENCH_MAX_MS (default 300 ms; CI sets a looser one).
	 *
	 * @group benchmark
	 */
	public function test_benchmark_30_days_10_resources(): void {
		global $wpdb;
		$ids = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$ids[] = $this->make_resource( "R{$i}" );
			$this->schedule( $ids[ $i ], '08:00', '18:00', array( '12:00', '13:00' ), array( 1, 2, 3, 4, 5, 6 ) );
		}
		$service = $this->make_service( 30, 10, $ids );
		$this->make_bookings( $ids, $service, 30 );
		$container = $this->container( 15 );
		$container->schedule_exceptions()->save( ScheduleException::closed( null, '2030-01-15' ) );
		$container->schedule_exceptions()->save( ScheduleException::custom_hours( $ids[0], '2030-01-16', array( TimeWindow::from_strings( '10:00', '14:00' ) ) ) );
		$availability = $container->availability_service();

		$availability->slots( $service, self::MONDAY, '2030-02-05' ); // Warm-up (autoload, query cache).
		$times = array();
		for ( $run = 0; $run < 3; $run++ ) {
			$started = hrtime( true );
			$slots   = $availability->slots( $service, self::MONDAY, '2030-02-05' );
			$any     = $availability->any_resource_slots( $service, self::MONDAY, '2030-02-05' );
			$times[] = ( hrtime( true ) - $started ) / 1e6;
		}
		sort( $times );
		$median = $times[1];
		$limit  = (float) ( getenv( 'TRMZ_BENCH_MAX_MS' ) ? getenv( 'TRMZ_BENCH_MAX_MS' ) : 300 );

		fwrite( STDERR, sprintf( "\n[benchmark] availability 30 days x 10 resources (slots + any): median %.1f ms, limit %.0f ms, %d slots, %d bookings\n", $median, $limit, count( $slots ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::from_globals()->table( Schema::BOOKINGS ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertGreaterThan( 1000, count( $slots ) );
		$this->assertNotEmpty( $any );
		$this->assertLessThan( $limit, $median );
	}

	/**
	 * Inserts 4 confirmed bookings per resource per working day (directly, for speed).
	 *
	 * @param int[] $resources Resources.
	 * @param int   $service   Service.
	 * @param int   $days      Days from MONDAY.
	 */
	private function make_bookings( array $resources, int $service, int $days ): void {
		global $wpdb;
		$table = Schema::from_globals()->table( Schema::BOOKINGS );
		$tz    = new DateTimeZone( 'Europe/Warsaw' );
		$n     = 0;
		for ( $d = 0; $d < $days; $d++ ) {
			$date = ( new DateTimeImmutable( self::MONDAY, $tz ) )->modify( "+{$d} days" );
			if ( 7 === (int) $date->format( 'N' ) ) {
				continue;
			}
			foreach ( $resources as $r => $resource ) {
				foreach ( array( '08:30', '10:00', '14:15', '16:00' ) as $k => $time ) {
					$start = ( new DateTimeImmutable( $date->format( 'Y-m-d' ) . ' ' . $time, $tz ) )->modify( '+' . ( ( $r + $k ) % 3 ) * 15 . ' minutes' )->setTimezone( new DateTimeZone( 'UTC' ) );
					$wpdb->insert(
						$table,
						array(
							'public_id'        => 'bench' . ( ++$n ),
							'service_id'       => $service,
							'resource_id'      => $resource,
							'start_utc'        => $start->format( 'Y-m-d H:i:s' ),
							'end_utc'          => $start->modify( '+30 minutes' )->format( 'Y-m-d H:i:s' ),
							'buffer_end_utc'   => $start->modify( '+40 minutes' )->format( 'Y-m-d H:i:s' ),
							'active_start_utc' => $start->format( 'Y-m-d H:i:s' ),
							'status'           => 'confirmed',
							'customer_name'    => 'Bench',
							'customer_email'   => 'bench@example.org',
							'created_at'       => '2030-01-01 00:00:00',
							'updated_at'       => '2030-01-01 00:00:00',
						)
					);
				}
			}
		}
	}

	/**
	 * Saves a weekly schedule (same hours on the given weekdays, Monday only by default).
	 *
	 * @param int      $resource_id Resource.
	 * @param string   $from     Start (local).
	 * @param string   $to       End (local).
	 * @param string[] $pause    Optional break [start, end].
	 * @param int[]    $weekdays ISO weekdays.
	 */
	private function schedule( int $resource_id, string $from, string $to, array $pause = array(), array $weekdays = array( 1 ) ): void {
		$work   = array();
		$breaks = array();
		foreach ( $weekdays as $day ) {
			$work[ $day ] = array( TimeWindow::from_strings( $from, $to ) );
			if ( array() !== $pause ) {
				$breaks[ $day ] = array( TimeWindow::from_strings( $pause[0], $pause[1] ) );
			}
		}
		$this->container( 15 )->schedules()->save( $resource_id, new WeeklySchedule( $work, $breaks ) );
	}

	/**
	 * Container with explicit settings (Europe/Warsaw).
	 *
	 * @param int    $step     Grid step.
	 * @param string $strategy Strategy.
	 */
	private function container( int $step, string $strategy = AvailabilitySettings::STRATEGY_ORDER ): Services {
		global $wpdb;
		return new Services( $wpdb, $this->clock, new AvailabilitySettings( new DateTimeZone( 'Europe/Warsaw' ), 0, null, $step, $strategy ) );
	}

	/**
	 * Availability service with explicit settings.
	 *
	 * @param int $step Grid step.
	 */
	private function availability( int $step ): AvailabilityService {
		return $this->container( $step )->availability_service();
	}

	/**
	 * Constructor arguments for AvailabilityService.
	 *
	 * @param AvailabilitySettings $settings Settings.
	 * @return array<int, mixed>
	 */
	private function availability_dependencies( AvailabilitySettings $settings ): array {
		$c = $this->container( 15 );
		return array( $c->services(), $c->resources(), $c->schedules(), $c->schedule_exceptions(), $c->bookings(), new AvailabilityEngine(), $this->clock, $settings );
	}

	/**
	 * Local (Warsaw) starts as H:i.
	 *
	 * @param Slot[] $slots Slots.
	 * @return string[]
	 */
	private function local_starts( array $slots ): array {
		$tz = new DateTimeZone( 'Europe/Warsaw' );
		return array_map( static fn( Slot $s ): string => $s->start()->setTimezone( $tz )->format( 'H:i' ), $slots );
	}

	/**
	 * Local Warsaw time → DateTimeImmutable.
	 *
	 * @param string $time Time H:i.
	 * @param string $date Date.
	 */
	private static function local( string $time, string $date = self::MONDAY ): DateTimeImmutable {
		return new DateTimeImmutable( "{$date} {$time}", new DateTimeZone( 'Europe/Warsaw' ) );
	}

	private function customer(): Customer {
		return new Customer( 'Jan Kowalski', 'jan@example.org' );
	}
}
