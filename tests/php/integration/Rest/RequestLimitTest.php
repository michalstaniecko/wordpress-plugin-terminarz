<?php
/**
 * Integration tests for request limits of public write endpoints.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Rest;

use Terminarz\Infrastructure\Capabilities;
use Terminarz\Infrastructure\RateLimiter;
use Terminarz\Rest\RequestLimit;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \Terminarz\Rest\RequestLimit
 * @covers \Terminarz\Infrastructure\RateLimiter
 */
final class RequestLimitTest extends RestTestCase {

	/**
	 * Previous REMOTE_ADDR.
	 *
	 * @var string|null
	 */
	private ?string $remote_addr;

	/**
	 * Service.
	 *
	 * @var int
	 */
	private int $service;

	public function set_up(): void {
		parent::set_up();
		$this->remote_addr      = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$resource               = $this->make_resource();
		$this->service          = $this->make_service( 30, 0, array( $resource ) );
		$this->open_daily( $resource, '09:00', '17:00' );
	}

	public function tear_down(): void {
		if ( null === $this->remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr;
		}
		parent::tear_down();
	}

	/**
	 * Books the n-th slot of the day (each request asks for a different free slot).
	 *
	 * @param int $n Slot number.
	 */
	private function book( int $n ): WP_REST_Response {
		$start = self::warsaw( '2030-01-07 09:00' )->modify( '+' . ( 30 * $n ) . ' minutes' );
		return $this->request(
			'POST',
			'/bookings',
			array(
				'service' => $this->service,
				'start'   => $start->format( DATE_RFC3339 ),
				'name'    => 'Jan',
				'email'   => 'jan@example.org',
				'consent' => true,
			)
		);
	}

	public function test_sixth_booking_in_ten_minutes_gets_429_with_retry_after(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( 201, $this->book( $i )->get_status(), "Booking #{$i} is within the limit." );
		}

		$blocked = $this->book( 5 );

		$this->assertSame( 429, $blocked->get_status() );
		$this->assertSame( 'trmz_rate_limited', self::error_code( $blocked ) );
		$this->assertSame( '600', $blocked->get_headers()['Retry-After'] );
		$this->assertCount( 5, $this->container->bookings()->in_range( new \Terminarz\Domain\Model\TimeRange( self::warsaw( '2030-01-07 00:00' ), self::warsaw( '2030-01-08 00:00' ) ) ), 'The limited request did not book.' );
	}

	public function test_retry_after_counts_down_and_window_resets(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->book( $i );
		}

		$this->clock->set( '2030-01-07 06:04' );
		$this->assertSame( '360', $this->book( 5 )->get_headers()['Retry-After'] );

		$this->clock->set( '2030-01-07 06:10' );
		$this->assertSame( 201, $this->book( 6 )->get_status(), 'A new window starts after 10 minutes.' );
	}

	public function test_failed_attempts_count_too(): void {
		$this->assertSame( 201, $this->book( 0 )->get_status() );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertSame( 409, $this->book( 0 )->get_status(), 'The slot is taken.' );
		}

		$this->assertSame( 429, $this->book( 1 )->get_status() );
	}

	public function test_limit_is_per_ip_and_proxy_headers_are_ignored(): void {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
		for ( $i = 0; $i < 5; $i++ ) {
			$this->book( $i );
		}
		$this->assertSame( 429, $this->book( 5 )->get_status(), 'X-Forwarded-For does not reset the limit.' );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
		$this->assertSame( 201, $this->book( 5 )->get_status(), 'Another IP has its own limit.' );
	}

	public function test_client_ip_filter_and_invalid_ip(): void {
		$request = new WP_REST_Request( 'POST', '/terminarz/v1/bookings' );
		$this->assertSame( '203.0.113.10', RequestLimit::client_ip( $request ) );

		add_filter( 'trmz_client_ip', static fn(): string => '2001:db8::1' );
		$this->assertSame( '2001:db8::1', RequestLimit::client_ip( $request ) );

		add_filter( 'trmz_client_ip', static fn(): string => 'not-an-ip', 20 );
		$this->assertSame( 'unknown', RequestLimit::client_ip( $request ) );
	}

	public function test_limit_is_configurable_and_can_be_disabled(): void {
		add_filter(
			'trmz_rate_limit',
			static fn( array $config, string $bucket ): array => RequestLimit::BOOKING_CREATE === $bucket ? array(
				'limit'  => 2,
				'window' => 60,
			) : $config,
			10,
			2
		);
		$this->book( 0 );
		$this->book( 1 );
		$blocked = $this->book( 2 );
		$this->assertSame( 429, $blocked->get_status() );
		$this->assertSame( '60', $blocked->get_headers()['Retry-After'] );

		add_filter(
			'trmz_rate_limit',
			static fn(): array => array(
				'limit'  => 0,
				'window' => 60,
			),
			20
		);
		$this->assertSame( 201, $this->book( 2 )->get_status() );
	}

	public function test_managers_are_not_limited(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin )->add_cap( Capabilities::MANAGE_BOOKINGS );
		wp_set_current_user( $admin );

		for ( $i = 0; $i < 7; $i++ ) {
			$request = new WP_REST_Request( 'POST', '/terminarz/v1/bookings' );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			$request->set_body_params(
				array(
					'service' => $this->service,
					'start'   => self::warsaw( '2030-01-07 09:00' )->modify( '+' . ( 30 * $i ) . ' minutes' )->format( DATE_RFC3339 ),
					'name'    => 'Jan',
					'email'   => 'jan@example.org',
					'consent' => true,
				)
			);
			$this->assertSame( 201, rest_get_server()->dispatch( $request )->get_status() );
		}
	}

	public function test_ip_is_not_stored_in_plain_text(): void {
		$this->book( 0 );

		$key = RateLimiter::key( RequestLimit::BOOKING_CREATE, '203.0.113.10' );
		$this->assertStringNotContainsString( '203.0.113.10', $key );
		$this->assertSame(
			array(
				'count' => 1,
				'reset' => self::utc( '2030-01-07 06:10' )->getTimestamp(),
			),
			get_transient( $key )
		);
	}
}
