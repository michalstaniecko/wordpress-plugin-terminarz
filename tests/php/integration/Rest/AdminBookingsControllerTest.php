<?php
/**
 * Integration tests for the administrative booking endpoints.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Rest;

use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Infrastructure\Capabilities;

/**
 * Site time zone Europe/Warsaw, grid 30 min, "now" = Monday 2030-01-07 07:00 local; resources open 09:00–17:00.
 *
 * @covers \Terminarz\Rest\AdminBookingsController
 * @covers \Terminarz\Infrastructure\Persistence\WpdbBookingRepository
 * @covers \Terminarz\Domain\Repository\BookingCriteria
 */
final class AdminBookingsControllerTest extends RestTestCase {

	/**
	 * Resources.
	 *
	 * @var int
	 */
	private int $anna;

	/**
	 * Resources.
	 *
	 * @var int
	 */
	private int $bartek;

	/**
	 * Service (60 min).
	 *
	 * @var int
	 */
	private int $service;

	public function set_up(): void {
		parent::set_up();
		$this->anna    = $this->make_resource( 'Anna' );
		$this->bartek  = $this->make_resource( 'Bartek' );
		$this->service = $this->make_service( 60, 0, array( $this->anna, $this->bartek ) );
		$this->open_daily( $this->anna );
		$this->open_daily( $this->bartek );
	}

	/**
	 * Logs in a user with trmz_manage_bookings.
	 */
	private function login_manager(): int {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $user )->add_cap( Capabilities::MANAGE_BOOKINGS );
		wp_set_current_user( $user );
		return $user;
	}

	/**
	 * Reserves a booking.
	 *
	 * @param string $local       Local start "Y-m-d H:i".
	 * @param int    $resource_id Resource.
	 * @param string $name        Customer name.
	 * @param string $email       Customer e-mail.
	 */
	private function reserve( string $local, int $resource_id, string $name = 'Jan Kowalski', string $email = 'jan@example.org' ): Booking {
		return $this->container->booking_service()->reserve( $this->service, $resource_id, self::warsaw( $local ), new Customer( $name, $email, '600', 'Uwagi' ) )->booking;
	}

	/**
	 * Every admin route with its method.
	 *
	 * @param int $id Booking ID.
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function admin_routes( int $id ): array {
		return array(
			array( 'GET', '/bookings' ),
			array( 'GET', "/bookings/{$id}" ),
			array( 'POST', "/bookings/{$id}/confirm" ),
			array( 'POST', "/bookings/{$id}/cancel" ),
			array( 'POST', "/bookings/{$id}/reschedule" ),
		);
	}

	public function test_anonymous_gets_401_and_subscriber_403(): void {
		$booking = $this->reserve( '2030-01-07 10:00', $this->anna );
		$id      = (int) $booking->id;
		$params  = array( 'start' => '2030-01-07T12:00:00+01:00' );

		foreach ( $this->admin_routes( $id ) as [ $method, $route ] ) {
			$response = $this->request( $method, $route, 'POST' === $method ? $params : array() );
			$this->assertSame( 401, $response->get_status(), "Anonymous {$method} {$route}" );
			$this->assertSame( 'rest_forbidden', self::error_code( $response ) );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		foreach ( $this->admin_routes( $id ) as [ $method, $route ] ) {
			$response = $this->request( $method, $route, 'POST' === $method ? $params : array() );
			$this->assertSame( 403, $response->get_status(), "Subscriber {$method} {$route}" );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->request( 'GET', '/bookings' )->get_status(), 'Editors without the capability are refused.' );

		$stored = $this->container->bookings()->get( $id );
		$this->assertNotNull( $stored );
		$this->assertSame( BookingStatus::Pending, $stored->status, 'Nothing changed.' );
		$this->assertSame( '2030-01-07 09:00', $stored->range->start->format( 'Y-m-d H:i' ) );
	}

	public function test_get_item_returns_admin_representation(): void {
		$this->login_manager();
		$booking = $this->reserve( '2030-01-07 10:00', $this->anna );

		$response = $this->request( 'GET', '/bookings/' . $booking->id );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $booking->id, $data['id'] );
		$this->assertSame( $booking->public_id, $data['public_id'] );
		$this->assertSame( 'pending', $data['status'] );
		$this->assertSame( array( 'confirmed', 'cancelled' ), $data['allowed_transitions'] );
		$this->assertSame( '2030-01-07T10:00:00+01:00', $data['start'] );
		$this->assertSame( '2030-01-07T10:00:00Z', $data['end_utc'] );
		$this->assertSame(
			array(
				'name'    => 'Jan Kowalski',
				'email'   => 'jan@example.org',
				'phone'   => '600',
				'note'    => 'Uwagi',
				'user_id' => null,
			),
			$data['customer']
		);
		$this->assertArrayNotHasKey( 'cancel_token_hash', $data );

		$missing = $this->request( 'GET', '/bookings/999999' );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'trmz_booking_not_found', self::error_code( $missing ) );
	}

	public function test_list_filters_search_order_and_paging(): void {
		$this->login_manager();
		$a = $this->reserve( '2030-01-07 10:00', $this->anna, 'Jan Kowalski', 'jan@example.org' );
		$b = $this->reserve( '2030-01-08 11:00', $this->bartek, 'Ola Nowak', 'ola@example.org' );
		$c = $this->reserve( '2030-01-09 09:00', $this->anna, 'Piotr Zieliński', 'piotr@example.org' );
		$this->container->booking_service()->cancel( (int) $b->id );
		$this->container->booking_service()->change_status( (int) $c->id, BookingStatus::Confirmed );

		$ids = fn( array $params ): array => wp_list_pluck( $this->request( 'GET', '/bookings', $params )->get_data(), 'id' );

		$this->assertSame( array( $a->id, $b->id, $c->id ), $ids( array() ) );
		$this->assertSame( array( $c->id, $b->id, $a->id ), $ids( array( 'order' => 'desc' ) ) );
		$this->assertSame( array( $a->id, $c->id ), $ids( array( 'status' => 'pending,confirmed' ) ) );
		$this->assertSame( array( $b->id ), $ids( array( 'status' => array( 'cancelled' ) ) ) );
		$this->assertSame( array( $a->id, $c->id ), $ids( array( 'resource' => $this->anna ) ) );
		$this->assertSame( array( $b->id ), $ids( array( 'search' => 'nowak' ) ) );
		$this->assertSame( array( $c->id ), $ids( array( 'search' => 'piotr@' ) ) );
		$this->assertSame( array( $a->id ), $ids( array( 'search' => (string) $a->public_id ) ) );
		$this->assertSame( array(), $ids( array( 'search' => '%' ) ), 'LIKE wildcards are escaped.' );
		$this->assertSame(
			array( $b->id ),
			$ids(
				array(
					'from' => '2030-01-08',
					'to'   => '2030-01-08',
				)
			)
		);
		$this->assertSame( array( $b->id, $c->id ), $ids( array( 'from' => '2030-01-08' ) ) );
		$this->assertSame( array( $a->id ), $ids( array( 'to' => '2030-01-07' ) ) );

		$page = $this->request(
			'GET',
			'/bookings',
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$this->assertSame( array( $c->id ), wp_list_pluck( $page->get_data(), 'id' ) );
		$this->assertSame( '3', $page->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $page->get_headers()['X-WP-TotalPages'] );
	}

	public function test_list_validates_arguments(): void {
		$this->login_manager();

		$this->assertSame( 400, $this->request( 'GET', '/bookings', array( 'status' => 'unknown' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/bookings', array( 'per_page' => 101 ) )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/bookings', array( 'orderby' => 'customer_email' ) )->get_status() );
		$this->assertSame( 'trmz_invalid_date', self::error_code( $this->request( 'GET', '/bookings', array( 'from' => '2030-02-30' ) ) ) );
		$this->assertSame(
			'trmz_invalid_range',
			self::error_code(
				$this->request(
					'GET',
					'/bookings',
					array(
						'from' => '2030-01-09',
						'to'   => '2030-01-08',
					)
				)
			)
		);
	}

	public function test_confirm_and_cancel_follow_the_state_machine_and_fire_hooks(): void {
		$this->login_manager();
		$booking = $this->reserve( '2030-01-07 10:00', $this->anna );
		$events  = array();
		add_action(
			'trmz_booking_status_changed',
			static function ( Booking $changed, BookingStatus $previous ) use ( &$events ): void {
				$events[] = $previous->value . '>' . $changed->status->value;
			},
			10,
			2
		);

		$confirmed = $this->request( 'POST', "/bookings/{$booking->id}/confirm" );
		$this->assertSame( 200, $confirmed->get_status() );
		$this->assertSame( 'confirmed', $confirmed->get_data()['status'] );

		$again = $this->request( 'POST', "/bookings/{$booking->id}/confirm" );
		$this->assertSame( 422, $again->get_status() );
		$this->assertSame( 'trmz_invalid_status_transition', self::error_code( $again ) );

		$cancelled = $this->request( 'POST', "/bookings/{$booking->id}/cancel" );
		$this->assertSame( 200, $cancelled->get_status() );
		$this->assertSame( 'cancelled', $cancelled->get_data()['status'] );
		$this->assertSame( array(), $cancelled->get_data()['allowed_transitions'] );

		$this->assertSame( 422, $this->request( 'POST', "/bookings/{$booking->id}/cancel" )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/bookings/999999/cancel' )->get_status() );
		$this->assertSame( array( 'pending>confirmed', 'confirmed>cancelled' ), $events );

		// The slot is free again.
		$this->assertTrue( $this->container->availability_service()->is_available( $this->service, $this->anna, self::warsaw( '2030-01-07 10:00' ) ) );
	}

	public function test_reschedule_moves_atomically_and_fires_hook(): void {
		$this->login_manager();
		$booking = $this->reserve( '2030-01-07 10:00', $this->anna );
		$moves   = array();
		add_action(
			'trmz_booking_rescheduled',
			static function ( Booking $moved, Booking $previous ) use ( &$moves ): void {
				$moves[] = $previous->range->start->format( 'H:i' ) . '>' . $moved->range->start->format( 'H:i' ) . '@' . $moved->resource_id;
			},
			10,
			2
		);

		$response = $this->request( 'POST', "/bookings/{$booking->id}/reschedule", array( 'start' => '2030-01-07T10:30:00+01:00' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2030-01-07T10:30:00+01:00', $response->get_data()['start'] );

		$other = $this->request(
			'POST',
			"/bookings/{$booking->id}/reschedule",
			array(
				'start'    => '2030-01-07T14:00:00+01:00',
				'resource' => $this->bartek,
			)
		);
		$this->assertSame( 200, $other->get_status() );
		$this->assertSame( $this->bartek, $other->get_data()['resource'] );
		$this->assertSame( array( '09:00>09:30@' . $this->anna, '09:30>13:00@' . $this->bartek ), $moves );
	}

	public function test_reschedule_conflicts_and_validation(): void {
		$this->login_manager();
		$booking = $this->reserve( '2030-01-07 10:00', $this->anna );
		$this->reserve( '2030-01-07 12:00', $this->anna, 'Ola', 'ola@example.org' );
		$outsider = $this->make_resource( 'Outsider' );

		$taken = $this->request( 'POST', "/bookings/{$booking->id}/reschedule", array( 'start' => '2030-01-07T12:30:00+01:00' ) );
		$this->assertSame( 409, $taken->get_status() );
		$this->assertSame( 'trmz_slot_unavailable', self::error_code( $taken ) );

		$this->assertSame( 409, $this->request( 'POST', "/bookings/{$booking->id}/reschedule", array( 'start' => '2030-01-07T20:00:00+01:00' ) )->get_status(), 'Outside working hours.' );
		$this->assertSame(
			'trmz_invalid_resource',
			self::error_code(
				$this->request(
					'POST',
					"/bookings/{$booking->id}/reschedule",
					array(
						'start'    => '2030-01-07T14:00:00+01:00',
						'resource' => $outsider,
					)
				)
			)
		);
		$this->assertSame( 400, $this->request( 'POST', "/bookings/{$booking->id}/reschedule", array( 'start' => '2030-01-07 14:00' ) )->get_status() );

		$this->container->booking_service()->cancel( (int) $booking->id );
		$cancelled = $this->request( 'POST', "/bookings/{$booking->id}/reschedule", array( 'start' => '2030-01-07T14:00:00+01:00' ) );
		$this->assertSame( 422, $cancelled->get_status() );

		$stored = $this->container->bookings()->get( (int) $booking->id );
		$this->assertNotNull( $stored );
		$this->assertSame( '2030-01-07 09:00', $stored->range->start->format( 'Y-m-d H:i' ), 'Failed moves left the booking in place.' );
	}
}
