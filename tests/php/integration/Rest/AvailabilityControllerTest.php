<?php
/**
 * Integration tests for GET /terminarz/v1/availability.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Rest;

use DateTimeImmutable;
use DateTimeZone;
use Terminarz\Domain\Model\BookableResource;
use Terminarz\Domain\Model\ScheduleException;
use Terminarz\Domain\Model\Service;
use Terminarz\Infrastructure\Database\Schema;

/**
 * Site time zone Europe/Warsaw, grid 30 min, "now" = Monday 2030-01-07 07:00 local.
 *
 * @covers \Terminarz\Rest\AvailabilityController
 */
final class AvailabilityControllerTest extends RestTestCase {

	public function test_slots_grouped_by_local_day_with_offsets_and_utc(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$this->open_daily( $resource, '09:00', '11:00' );
		$this->container->schedule_exceptions()->save( ScheduleException::closed( null, '2030-01-08' ) );

		$response = $this->request(
			'GET',
			'/availability',
			array(
				'service'  => $service,
				'resource' => (string) $resource,
				'from'     => self::MONDAY,
				'to'       => '2030-01-09',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $service, $data['service'] );
		$this->assertSame( $resource, $data['resource'] );
		$this->assertSame( 'Europe/Warsaw', $data['timezone'] );
		$this->assertSame( array( '2030-01-07', '2030-01-08', '2030-01-09' ), wp_list_pluck( $data['days'], 'date' ), 'Every day of the range is listed, also without slots.' );
		$this->assertSame( array(), $data['days'][1]['slots'] );
		$this->assertSame(
			array(
				'start'     => '2030-01-07T09:00:00+01:00',
				'end'       => '2030-01-07T10:00:00+01:00',
				'start_utc' => '2030-01-07T08:00:00Z',
				'resource'  => $resource,
			),
			$data['days'][0]['slots'][0]
		);
		$this->assertSame( array( '09:00', '09:30', '10:00' ), $this->local_times( $data['days'][0]['slots'] ) );
		$this->assertSame( array( '09:00', '09:30', '10:00' ), $this->local_times( $data['days'][2]['slots'] ) );
	}

	public function test_any_resource_gives_one_slot_per_start_without_resource(): void {
		$anna    = $this->make_resource( 'Anna' );
		$bartek  = $this->make_resource( 'Bartek' );
		$service = $this->make_service( 60, 0, array( $anna, $bartek ) );
		$this->open_daily( $anna, '09:00', '10:00' );
		$this->open_daily( $bartek, '09:00', '11:00' );

		$data = $this->request(
			'GET',
			'/availability',
			array(
				'service' => $service,
				'from'    => self::MONDAY,
				'to'      => self::MONDAY,
			)
		)->get_data();

		$this->assertSame( 'any', $data['resource'] );
		$slots = $data['days'][0]['slots'];
		$this->assertSame( array( '09:00', '09:30', '10:00' ), $this->local_times( $slots ) );
		$this->assertSame( array( null, null, null ), wp_list_pluck( $slots, 'resource' ) );
	}

	public function test_booked_and_past_slots_are_not_offered(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$this->open_daily( $resource, '06:00', '10:00' );
		$this->container->booking_service()->reserve( $service, $resource, self::warsaw( '2030-01-07 08:00' ), new \Terminarz\Domain\Model\Customer( 'Jan', 'jan@example.org' ) );

		$data = $this->request(
			'GET',
			'/availability',
			array(
				'service' => $service,
				'from'    => self::MONDAY,
				'to'      => self::MONDAY,
			)
		)->get_data();

		// Now = 07:00 local; 08:00–09:00 is booked.
		$this->assertSame( array( '07:00', '09:00' ), $this->local_times( $data['days'][0]['slots'] ) );
	}

	public function test_dst_change_is_reflected_in_offsets(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$this->open_daily( $resource, '09:00', '10:00' );

		$data = $this->request(
			'GET',
			'/availability',
			array(
				'service'  => $service,
				'resource' => (string) $resource,
				'from'     => '2030-03-30',
				'to'       => '2030-03-31',
			)
		)->get_data();

		$this->assertSame( '2030-03-30T09:00:00+01:00', $data['days'][0]['slots'][0]['start'] );
		$this->assertSame( '2030-03-30T08:00:00Z', $data['days'][0]['slots'][0]['start_utc'] );
		$this->assertSame( '2030-03-31T09:00:00+02:00', $data['days'][1]['slots'][0]['start'] );
		$this->assertSame( '2030-03-31T07:00:00Z', $data['days'][1]['slots'][0]['start_utc'] );
	}

	/**
	 * @dataProvider invalid_ranges
	 *
	 * @param array<string, string> $dates Dates.
	 * @param string                $code  Expected error code.
	 */
	public function test_invalid_dates_and_ranges_are_rejected( array $dates, string $code ): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );

		$response = $this->request( 'GET', '/availability', array( 'service' => $service ) + $dates );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $code, self::error_code( $response ) );
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: string}>
	 */
	public function invalid_ranges(): array {
		return array(
			'missing to'        => array( array( 'from' => '2030-01-07' ), 'rest_missing_callback_param' ),
			'bad format'        => array(
				array(
					'from' => '07.01.2030',
					'to'   => '2030-01-08',
				),
				'rest_invalid_param',
			),
			'impossible date'   => array(
				array(
					'from' => '2030-02-30',
					'to'   => '2030-03-01',
				),
				'trmz_invalid_date',
			),
			'to before from'    => array(
				array(
					'from' => '2030-01-08',
					'to'   => '2030-01-07',
				),
				'trmz_invalid_range',
			),
			'more than 31 days' => array(
				array(
					'from' => '2030-01-01',
					'to'   => '2030-02-01',
				),
				'trmz_invalid_range',
			),
		);
	}

	public function test_exactly_31_days_are_allowed(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );

		$response = $this->request(
			'GET',
			'/availability',
			array(
				'service' => $service,
				'from'    => '2030-01-01',
				'to'      => '2030-01-31',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 31, $response->get_data()['days'] );
	}

	public function test_service_and_resource_are_validated(): void {
		$anna     = $this->make_resource( 'Anna' );
		$other    = $this->make_resource( 'Other' );
		$inactive = (int) $this->container->resources()->save( new BookableResource( null, 'Off', false ) )->id;
		$service  = $this->make_service( 60, 0, array( $anna, $inactive ) );
		$hidden   = (int) $this->container->services()->save( new Service( null, 'Hidden', 60, 0, 0, false ) )->id;
		$dates    = array(
			'from' => self::MONDAY,
			'to'   => self::MONDAY,
		);

		foreach ( array( $hidden, 999999 ) as $id ) {
			$response = $this->request( 'GET', '/availability', array( 'service' => $id ) + $dates );
			$this->assertSame( 404, $response->get_status() );
			$this->assertSame( 'trmz_service_not_found', self::error_code( $response ) );
		}

		foreach ( array( $other, $inactive, 999999 ) as $id ) {
			$response = $this->request(
				'GET',
				'/availability',
				array(
					'service'  => $service,
					'resource' => (string) $id,
				) + $dates
			);
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( 'trmz_invalid_resource', self::error_code( $response ) );
		}

		$response = $this->request(
			'GET',
			'/availability',
			array(
				'service'  => $service,
				'resource' => 'someone',
			) + $dates
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', self::error_code( $response ) );
	}

	public function test_cache_headers(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$params   = array(
			'service' => $service,
			'from'    => self::MONDAY,
			'to'      => self::MONDAY,
		);

		$this->assertSame( 'no-store', $this->request( 'GET', '/availability', $params )->get_headers()['Cache-Control'] );

		add_filter( 'trmz_availability_cache_max_age', static fn(): int => 60 );
		$this->assertSame( 'public, max-age=60', $this->request( 'GET', '/availability', $params )->get_headers()['Cache-Control'] );
	}

	/**
	 * 30 days × 10 resources (~1200 bookings) through the REST endpoint, specific resource and "any".
	 * Threshold: TRMZ_BENCH_MAX_MS (default 300 ms; CI sets a looser one).
	 *
	 * @group benchmark
	 */
	public function test_benchmark_30_days_10_resources(): void {
		$ids = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$ids[] = $this->make_resource( "R{$i}" );
			$this->open_daily( $ids[ $i ], '08:00', '18:00' );
		}
		$service = $this->make_service( 30, 10, $ids );
		$this->make_bookings( $ids, $service, 30 );
		$params = array(
			'service' => $service,
			'from'    => self::MONDAY,
			'to'      => '2030-02-05',
		);

		$this->request( 'GET', '/availability', $params ); // Warm-up.
		$times = array();
		for ( $run = 0; $run < 3; $run++ ) {
			$started = hrtime( true );
			$any     = $this->request( 'GET', '/availability', $params );
			$times[] = ( hrtime( true ) - $started ) / 1e6;
		}
		sort( $times );
		$median = $times[1];
		$limit  = (float) ( getenv( 'TRMZ_BENCH_MAX_MS' ) ? getenv( 'TRMZ_BENCH_MAX_MS' ) : 300 );

		$started   = hrtime( true );
		$single    = $this->request( 'GET', '/availability', array( 'resource' => (string) $ids[3] ) + $params );
		$single_ms = ( hrtime( true ) - $started ) / 1e6;

		fwrite( STDERR, sprintf( "\n[benchmark] REST availability 30 days x 10 resources (any): median %.1f ms, one resource %.1f ms, limit %.0f ms\n", $median, $single_ms, $limit ) );
		$this->assertSame( 200, $any->get_status() );
		$this->assertCount( 30, $any->get_data()['days'] );
		$this->assertNotEmpty( $any->get_data()['days'][0]['slots'] );
		$this->assertSame( 200, $single->get_status() );
		$this->assertLessThan( $limit, $median );
		$this->assertLessThan( $limit, $single_ms );
	}

	/**
	 * Local times (H:i) of slots.
	 *
	 * @param array<int, array<string, mixed>> $slots Slots.
	 * @return string[]
	 */
	private function local_times( array $slots ): array {
		return array_map( static fn( array $slot ): string => substr( (string) $slot['start'], 11, 5 ), $slots );
	}

	/**
	 * Inserts 4 confirmed bookings per resource per day (directly, for speed).
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
			$date = ( new DateTimeImmutable( self::MONDAY, $tz ) )->modify( "+{$d} days" )->format( 'Y-m-d' );
			foreach ( $resources as $r => $resource ) {
				foreach ( array( '08:30', '10:00', '14:15', '16:00' ) as $k => $time ) {
					$start = ( new DateTimeImmutable( $date . ' ' . $time, $tz ) )->modify( '+' . ( ( $r + $k ) % 3 ) * 15 . ' minutes' )->setTimezone( new DateTimeZone( 'UTC' ) );
					$wpdb->insert(
						$table,
						array(
							'public_id'        => 'restbench' . ( ++$n ),
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
}
