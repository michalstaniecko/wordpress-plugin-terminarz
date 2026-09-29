<?php
/**
 * Integration tests for BookingService.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Application;

use Terminarz\Application\BookingService;
use Terminarz\Application\FixedClock;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Exception\SlotUnavailable;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Domain\Model\Service;
use Terminarz\Infrastructure\Services;
use Terminarz\Infrastructure\WpEventDispatcher;
use Terminarz\Tests\Integration\Support\BookingFixtures;
use WP_UnitTestCase;

/**
 * @covers \Terminarz\Application\BookingService
 * @covers \Terminarz\Infrastructure\Services
 * @covers \Terminarz\Infrastructure\WpEventDispatcher
 */
final class BookingServiceTest extends WP_UnitTestCase {

	use BookingFixtures;

	/**
	 * Clock.
	 *
	 * @var FixedClock
	 */
	private FixedClock $clock;

	/**
	 * Wired services.
	 *
	 * @var Services
	 */
	private Services $container;

	/**
	 * Service under test.
	 *
	 * @var BookingService
	 */
	private BookingService $service;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->clock     = new FixedClock( '2030-01-01 08:00' );
		$this->container = new Services( $wpdb, $this->clock );
		// Without the availability policy: these tests cover collisions, validation and events only
		// (schedule-aware reservations are covered by AvailabilityServiceTest).
		$this->service = new BookingService( $this->container->bookings(), $this->container->services(), $this->container->resources(), new WpEventDispatcher(), $this->clock );
	}

	public function test_reserve_stores_booking_fires_event_and_returns_token_once(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 45, 15, array( $resource ) );
		$events   = array();
		add_action(
			BookingService::EVENT_CREATED,
			static function ( $booking ) use ( &$events ): void {
				$events[] = $booking;
			}
		);

		$reservation = $this->service->reserve( $service, $resource, self::utc( '2030-01-02 10:00' ), $this->customer() );

		$booking = $reservation->booking;
		$this->assertSame( BookingStatus::Pending, $booking->status );
		$this->assertSame( '2030-01-02 10:45', $booking->range->end->format( 'Y-m-d H:i' ) );
		$this->assertSame( 15, $booking->buffer_after_minutes );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $reservation->cancel_token );
		$this->assertNotSame( $reservation->cancel_token, $booking->cancel_token_hash );
		$this->assertTrue( BookingService::verify_cancel_token( $booking, $reservation->cancel_token ) );
		$this->assertFalse( BookingService::verify_cancel_token( $booking, str_repeat( '0', 64 ) ) );
		$this->assertCount( 1, $events );
		$this->assertEquals( $booking, $events[0] );
	}

	public function test_pending_payment_gets_hold(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );

		$booking = $this->service->reserve( $service, $resource, self::utc( '2030-01-02 10:00' ), $this->customer(), BookingStatus::PendingPayment, 20 )->booking;

		$this->assertSame( '2030-01-01 08:20', $booking->hold_expires_at?->format( 'Y-m-d H:i' ) );
	}

	public function test_second_reservation_of_same_slot_fails(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$this->service->reserve( $service, $resource, self::utc( '2030-01-02 10:00' ), $this->customer() );

		$this->expectException( SlotUnavailable::class );
		$this->service->reserve( $service, $resource, self::utc( '2030-01-02 10:30' ), $this->customer() );
	}

	public function test_past_start_is_rejected(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );

		$this->expectException( SlotUnavailable::class );
		$this->service->reserve( $service, $resource, self::utc( '2030-01-01 08:00' ), $this->customer() );
	}

	public function test_resource_must_perform_active_service(): void {
		$resource = $this->make_resource();
		$other    = $this->make_resource( 'Other' );
		$service  = $this->make_service( 60, 0, array( $resource ) );

		try {
			$this->service->reserve( $service, $other, self::utc( '2030-01-02 10:00' ), $this->customer() );
			$this->fail( 'Unassigned resource accepted.' );
		} catch ( InvalidValue $e ) {
			$this->assertStringContainsString( 'resource', $e->getMessage() );
		}

		$this->container->services()->save( new Service( $service, 'Off', 60, 0, 0, false ) );
		$this->expectException( InvalidValue::class );
		$this->service->reserve( $service, $resource, self::utc( '2030-01-02 10:00' ), $this->customer() );
	}

	public function test_slot_policy_is_consulted(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$seen     = array();
		$this->service->set_slot_policy(
			static function ( int $service_id, int $resource_id, $start, ?int $exclude ) use ( &$seen ): bool {
				$seen[] = array( $service_id, $resource_id, $start->format( 'H:i' ), $exclude );
				return '10:00' === $start->format( 'H:i' );
			}
		);

		$booking = $this->service->reserve( $service, $resource, self::utc( '2030-01-02 10:00' ), $this->customer() )->booking;
		try {
			$this->service->reserve( $service, $resource, self::utc( '2030-01-02 12:00' ), $this->customer() );
			$this->fail( 'Policy ignored.' );
		} catch ( SlotUnavailable $e ) {
			$this->assertSame( array( $service, $resource, '12:00', null ), $seen[1] );
		}

		$this->service->reschedule( (int) $booking->id, self::utc( '2030-01-03 10:00' ) );
		$this->assertSame( $booking->id, $seen[2][3] );
	}

	public function test_reschedule_keeps_duration_and_fires_event(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 45, 0, array( $resource ) );
		$booking  = $this->service->reserve( $service, $resource, self::utc( '2030-01-02 10:00' ), $this->customer() )->booking;
		$fired    = did_action( BookingService::EVENT_RESCHEDULED );

		$moved = $this->service->reschedule( (int) $booking->id, self::utc( '2030-01-02 15:00' ) );

		$this->assertSame( '15:00-15:45', $moved->range->start->format( 'H:i' ) . '-' . $moved->range->end->format( 'H:i' ) );
		$this->assertSame( $fired + 1, did_action( BookingService::EVENT_RESCHEDULED ) );
	}

	public function test_cancel_fires_status_event_with_previous_status(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$booking  = $this->service->reserve( $service, $resource, self::utc( '2030-01-02 10:00' ), $this->customer(), BookingStatus::Confirmed )->booking;
		$previous = null;
		add_action(
			BookingService::EVENT_STATUS_CHANGED,
			static function ( $changed, $old ) use ( &$previous ): void {
				$previous = $old;
			},
			10,
			2
		);

		$this->assertSame( BookingStatus::Cancelled, $this->service->cancel( (int) $booking->id )->status );
		$this->assertSame( BookingStatus::Confirmed, $previous );
	}

	public function test_expire_holds_uses_clock_and_fires_events(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$booking  = $this->service->reserve( $service, $resource, self::utc( '2030-01-02 10:00' ), $this->customer(), BookingStatus::PendingPayment, 15 )->booking;
		$fired    = did_action( BookingService::EVENT_STATUS_CHANGED );

		$this->assertSame( array(), $this->service->expire_holds() );
		$this->clock->set( '2030-01-01 08:16' );
		$this->assertSame( array( $booking->id ), $this->service->expire_holds() );
		$this->assertSame( $fired + 1, did_action( BookingService::EVENT_STATUS_CHANGED ) );
	}

	private function customer(): Customer {
		return new Customer( 'Jan Kowalski', 'jan@example.org' );
	}
}
