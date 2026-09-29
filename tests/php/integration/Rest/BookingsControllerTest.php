<?php
/**
 * Integration tests for POST /terminarz/v1/bookings.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Rest;

use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Infrastructure\Settings;
use Terminarz\Rest\BookingsController;
use WP_REST_Request;

/**
 * Site time zone Europe/Warsaw, grid 30 min, "now" = Monday 2030-01-07 07:00 local; resources open 09:00–17:00.
 *
 * @covers \Terminarz\Rest\BookingsController
 * @covers \Terminarz\Rest\ErrorMapper
 */
final class BookingsControllerTest extends RestTestCase {

	/**
	 * Resource.
	 *
	 * @var int
	 */
	private int $resource;

	/**
	 * Service (60 min).
	 *
	 * @var int
	 */
	private int $service;

	public function set_up(): void {
		parent::set_up();
		$this->resource = $this->make_resource( 'Anna' );
		$this->service  = $this->make_service( 60, 0, array( $this->resource ) );
		$this->open_daily( $this->resource );
	}

	/**
	 * Valid request body.
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function body( array $overrides = array() ): array {
		return array_merge(
			array(
				'service'  => $this->service,
				'resource' => (string) $this->resource,
				'start'    => '2030-01-07T10:00:00+01:00',
				'name'     => 'Jan Kowalski',
				'email'    => 'jan@example.org',
				'phone'    => '+48 600 000 000',
				'note'     => 'Proszę o kontakt.',
				'consent'  => true,
			),
			$overrides
		);
	}

	public function test_creates_pending_booking_and_returns_public_representation(): void {
		$created = array();
		add_action(
			'trmz_booking_created',
			static function ( Booking $booking ) use ( &$created ): void {
				$created[] = $booking;
			}
		);

		$response = $this->request( 'POST', '/bookings', $this->body() );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( 'public_id', 'status', 'service', 'resource', 'start', 'end', 'start_utc' ), array_keys( $data ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $data['public_id'] );
		$this->assertSame( 'pending', $data['status'] );
		$this->assertSame( $this->resource, $data['resource'] );
		$this->assertSame( '2030-01-07T10:00:00+01:00', $data['start'] );
		$this->assertSame( '2030-01-07T11:00:00+01:00', $data['end'] );
		$this->assertSame( '2030-01-07T09:00:00Z', $data['start_utc'] );

		$this->assertCount( 1, $created, 'trmz_booking_created fires once.' );
		$stored = $this->container->bookings()->get_by_public_id( $data['public_id'] );
		$this->assertNotNull( $stored );
		$this->assertSame( 'Jan Kowalski', $stored->customer->name );
		$this->assertSame( '+48 600 000 000', $stored->customer->phone );
		$this->assertSame( 'Proszę o kontakt.', $stored->customer->note );
		$this->assertNull( $stored->customer->user_id );
		$this->assertStringNotContainsString( 'cancel', (string) wp_json_encode( $data ), 'The cancellation token is not returned.' );
		$this->assertStringNotContainsString( 'jan@example.org', (string) wp_json_encode( $data ) );
	}

	public function test_start_in_utc_and_without_seconds_is_accepted(): void {
		$response = $this->request( 'POST', '/bookings', $this->body( array( 'start' => '2030-01-07T12:30Z' ) ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( '2030-01-07T13:30:00+01:00', $response->get_data()['start'] );
	}

	public function test_auto_confirm_filter_confirms_new_bookings(): void {
		add_filter( 'trmz_auto_confirm_bookings', '__return_true' );

		$response = $this->request( 'POST', '/bookings', $this->body() );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'confirmed', $response->get_data()['status'] );
	}

	public function test_auto_confirm_setting_confirms_new_bookings(): void {
		update_option( Settings::OPTION, array( 'auto_confirm' => '1' ) );

		$response = $this->request( 'POST', '/bookings', $this->body() );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'confirmed', $response->get_data()['status'] );
	}

	public function test_any_resource_picks_a_free_one(): void {
		$bartek = $this->make_resource( 'Bartek' );
		$this->open_daily( $bartek );
		$this->container->services()->assign_resources( $this->service, array( $this->resource, $bartek ) );

		$first  = $this->request( 'POST', '/bookings', $this->body( array( 'resource' => 'any' ) ) );
		$second = $this->request(
			'POST',
			'/bookings',
			$this->body(
				array(
					'resource' => 'any',
					'email'    => 'ola@example.org',
				)
			)
		);
		$third  = $this->request( 'POST', '/bookings', $this->body( array( 'resource' => 'any' ) ) );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 201, $second->get_status() );
		$this->assertSame( array( $this->resource, $bartek ), array( $first->get_data()['resource'], $second->get_data()['resource'] ) );
		$this->assertSame( 409, $third->get_status() );
		$this->assertSame( 'trmz_slot_unavailable', self::error_code( $third ) );
	}

	public function test_taken_slot_returns_409(): void {
		$this->assertSame( 201, $this->request( 'POST', '/bookings', $this->body() )->get_status() );

		$again = $this->request( 'POST', '/bookings', $this->body( array( 'start' => '2030-01-07T10:30:00+01:00' ) ) );

		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'trmz_slot_unavailable', self::error_code( $again ) );
		$this->assertStringNotContainsString( 'UTC', $again->get_data()['message'], 'The developer message of the exception does not leak.' );
	}

	/**
	 * @dataProvider unavailable_starts
	 *
	 * @param string $start Start.
	 */
	public function test_slots_not_offered_by_the_schedule_return_409( string $start ): void {
		$response = $this->request( 'POST', '/bookings', $this->body( array( 'start' => $start ) ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'trmz_slot_unavailable', self::error_code( $response ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function unavailable_starts(): array {
		return array(
			'in the past'        => array( '2030-01-06T10:00:00+01:00' ),
			'outside hours'      => array( '2030-01-07T20:00:00+01:00' ),
			'ends after closing' => array( '2030-01-07T16:30:00+01:00' ),
			'off the grid'       => array( '2030-01-07T10:10:00+01:00' ),
		);
	}

	/**
	 * @dataProvider invalid_bodies
	 *
	 * @param array<string, mixed> $overrides Overrides.
	 * @param string               $code      Expected error code.
	 */
	public function test_invalid_data_returns_400( array $overrides, string $code ): void {
		$response = $this->request( 'POST', '/bookings', array_filter( $this->body( $overrides ), static fn( $value ): bool => null !== $value ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $code, self::error_code( $response ) );
		$this->assertSame( array(), $this->container->bookings()->in_range( new \Terminarz\Domain\Model\TimeRange( self::warsaw( '2030-01-01 00:00' ), self::warsaw( '2030-02-01 00:00' ) ) ) );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public function invalid_bodies(): array {
		return array(
			'no consent'           => array( array( 'consent' => false ), 'trmz_consent_required' ),
			'consent missing'      => array( array( 'consent' => null ), 'rest_missing_callback_param' ),
			'honeypot filled'      => array( array( 'website' => 'http://spam.example' ), 'trmz_rejected' ),
			'bad e-mail'           => array( array( 'email' => 'not-an-email' ), 'rest_invalid_param' ),
			'name missing'         => array( array( 'name' => null ), 'rest_missing_callback_param' ),
			'name only tags'       => array( array( 'name' => '<b></b>' ), 'trmz_invalid_customer' ),
			'name too long'        => array( array( 'name' => str_repeat( 'a', 192 ) ), 'rest_invalid_param' ),
			'phone with letters'   => array( array( 'phone' => 'call me' ), 'rest_invalid_param' ),
			'note too long'        => array( array( 'note' => str_repeat( 'a', 1001 ) ), 'rest_invalid_param' ),
			'start without offset' => array( array( 'start' => '2030-01-07T10:00:00' ), 'rest_invalid_param' ),
			'impossible start'     => array( array( 'start' => '2030-02-30T10:00:00+01:00' ), 'trmz_invalid_date' ),
			'bad resource'         => array( array( 'resource' => 'someone' ), 'rest_invalid_param' ),
			'unassigned resource'  => array( array( 'resource' => '999999' ), 'trmz_invalid_resource' ),
		);
	}

	public function test_input_is_sanitised(): void {
		$response = $this->request(
			'POST',
			'/bookings',
			$this->body(
				array(
					'name' => ' <script>alert(1)</script>Jan <b>K</b> ',
					'note' => "Linia 1\nLinia 2 <img src=x onerror=alert(1)>",
				)
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$stored = $this->container->bookings()->get_by_public_id( $response->get_data()['public_id'] );
		$this->assertNotNull( $stored );
		$this->assertSame( 'Jan K', $stored->customer->name );
		$this->assertSame( "Linia 1\nLinia 2", $stored->customer->note );
	}

	public function test_inactive_or_missing_service_returns_404(): void {
		$response = $this->request( 'POST', '/bookings', $this->body( array( 'service' => 999999 ) ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'trmz_service_not_found', self::error_code( $response ) );
	}

	public function test_logged_in_user_needs_a_valid_nonce(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user );

		$without = $this->request( 'POST', '/bookings', $this->body() );
		$this->assertSame( 403, $without->get_status() );
		$this->assertSame( 'rest_cookie_invalid_nonce', self::error_code( $without ) );

		$request = new WP_REST_Request( 'POST', '/terminarz/v1/bookings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body( (string) wp_json_encode( $this->body() ) );
		$with = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $with->get_status() );
		$stored = $this->container->bookings()->get_by_public_id( $with->get_data()['public_id'] );
		$this->assertNotNull( $stored );
		$this->assertSame( $user, $stored->customer->user_id );
	}

	public function test_permission_callback_is_not_return_true(): void {
		$routes = rest_get_server()->get_routes( 'terminarz/v1' );
		foreach ( $routes['/terminarz/v1/bookings'] as $handler ) {
			if ( isset( $handler['methods']['POST'] ) ) {
				$this->assertSame( array( BookingsController::class, 'create_item_permissions_check' ), array( get_class( $handler['permission_callback'][0] ), $handler['permission_callback'][1] ) );
			}
		}
	}

	public function test_booking_status_is_pending_in_the_repository(): void {
		$data = $this->request( 'POST', '/bookings', $this->body() )->get_data();

		$stored = $this->container->bookings()->get_by_public_id( $data['public_id'] );
		$this->assertNotNull( $stored );
		$this->assertSame( BookingStatus::Pending, $stored->status );
		$this->assertNotNull( $stored->cancel_secret );
	}
}
