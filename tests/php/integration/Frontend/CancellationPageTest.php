<?php
/**
 * Integration tests: customer cancellation link and page.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Frontend;

use Terminarz\Application\BookingService;
use Terminarz\Application\CancellationRefused;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Frontend\CancellationPage;
use Terminarz\Infrastructure\Settings;
use Terminarz\Notifications\Templates;
use Terminarz\Tests\Integration\Rest\RestTestCase;
use Terminarz\Tests\Integration\Support\CapturedMails;

/**
 * "Now" = 2030-01-07 07:00 Warsaw; the booking is on 2030-01-08 at 10:00 Warsaw (09:00 UTC), auto-confirmed.
 * Cancellation limit: 24 hours (default), so the deadline is 2030-01-07 10:00 Warsaw.
 *
 * @covers \Terminarz\Frontend\CancellationPage
 * @covers \Terminarz\Application\BookingService
 * @covers \Terminarz\Application\CancelTokens
 * @covers \Terminarz\Notifications\BookingNotifier
 * @covers \Terminarz\Rest\RequestLimit
 */
final class CancellationPageTest extends RestTestCase {

	use CapturedMails;

	/**
	 * Resource.
	 *
	 * @var int
	 */
	private int $resource;

	/**
	 * Service.
	 *
	 * @var int
	 */
	private int $service;

	/**
	 * REMOTE_ADDR before the test.
	 *
	 * @var string|null
	 */
	private ?string $remote_addr;

	public function set_up(): void {
		parent::set_up();
		$this->remote_addr      = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
		update_option( 'timezone_string', 'Europe/Warsaw' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		update_option(
			Settings::OPTION,
			array(
				'notification_email' => 'office@example.org',
				'auto_confirm'       => true,
			)
		);
		delete_option( Templates::OPTION );
		$this->use_settings( $this->container->availability_settings() );
		$this->resource = $this->make_resource( 'Anna' );
		$this->service  = $this->make_service( 60, 0, array( $this->resource ) );
		$this->open_daily( $this->resource );
		reset_phpmailer_instance();
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
	 * Books through REST and returns the booking.
	 *
	 * @param string $start Start (ISO with offset).
	 */
	private function book( string $start = '2030-01-08T10:00:00+01:00' ): Booking {
		$response = $this->request(
			'POST',
			'/bookings',
			array(
				'service'  => $this->service,
				'resource' => (string) $this->resource,
				'start'    => $start,
				'name'     => 'Jan Kowalski',
				'email'    => 'jan@example.org',
				'consent'  => true,
			)
		);
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$booking = $this->container->bookings()->get_by_public_id( $response->get_data()['public_id'] );
		$this->assertNotNull( $booking );
		return $booking;
	}

	/**
	 * Query arguments of the cancellation link in the latest e-mail to the customer.
	 *
	 * @return array<string, string>
	 */
	private static function link_from_mail(): array {
		$mails = self::mails_with_subject( 'Your booking is confirmed' );
		self::assertNotEmpty( $mails );
		self::assertSame( 1, preg_match( '/href="([^"]*trmz_cancel[^"]*)"/', $mails[0]['body'], $match ) );
		$url = html_entity_decode( $match[1] );
		self::assertStringStartsWith( home_url( '/' ), $url );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		return $query;
	}

	/**
	 * Start times offered on 2030-01-08 (UTC ISO).
	 *
	 * @return string[]
	 */
	private function offered(): array {
		$response = $this->request(
			'GET',
			'/availability',
			array(
				'service'  => $this->service,
				'resource' => (string) $this->resource,
				'from'     => '2030-01-08',
				'to'       => '2030-01-08',
			)
		);
		return array_column( $response->get_data()['days'][0]['slots'] ?? array(), 'start_utc' );
	}

	public function test_link_from_the_confirmation_e_mail_shows_a_confirmation_page_without_cancelling(): void {
		$booking = $this->book();
		$query   = self::link_from_mail();

		$this->assertSame( $booking->public_id, $query['trmz_cancel'] );
		$this->assertStringContainsString( 'by 2030-01-07 10:00', self::mails_with_subject( 'Your booking is confirmed' )[0]['body'] );

		$page = ( new CancellationPage( $this->container ) )->respond( 'GET', $query, array() );

		$this->assertSame( 200, $page['status'] );
		$this->assertFalse( $page['cancelled'] );
		$this->assertStringContainsString( '<form method="post"', $page['body'] );
		$this->assertStringContainsString( 'value="' . $query['token'] . '"', $page['body'] );
		$this->assertStringContainsString( '2030-01-08', $page['body'] );
		$this->assertStringContainsString( 'until 2030-01-07 10:00', $page['body'] );
		$this->assertSame( BookingStatus::Confirmed, $this->container->bookings()->get( (int) $booking->id )?->status, 'GET never cancels.' );
	}

	public function test_post_cancels_frees_the_slot_and_notifies_customer_and_business(): void {
		$booking = $this->book();
		$query   = self::link_from_mail();
		$this->assertNotContains( '2030-01-08T09:00:00Z', $this->offered() );
		reset_phpmailer_instance();

		$page = ( new CancellationPage( $this->container ) )->respond( 'POST', array( 'trmz_cancel' => $query['trmz_cancel'] ), array( 'token' => $query['token'] ) );

		$this->assertSame( 200, $page['status'] );
		$this->assertTrue( $page['cancelled'] );
		$this->assertStringContainsString( 'has been cancelled', $page['body'] );
		$this->assertSame( BookingStatus::Cancelled, $this->container->bookings()->get( (int) $booking->id )?->status );
		$this->assertContains( '2030-01-08T09:00:00Z', $this->offered() );
		$this->assertSame(
			array( 'jan@example.org', 'office@example.org' ),
			array_column( self::mails(), 'to' )
		);
		$this->assertStringContainsString( 'Your booking has been cancelled', self::mails()[0]['subject'] );
		$this->assertStringContainsString( 'Booking cancelled', self::mails()[1]['subject'] );

		// The same link again: nothing more to cancel, no e-mails.
		reset_phpmailer_instance();
		$again = ( new CancellationPage( $this->container ) )->respond( 'POST', array( 'trmz_cancel' => $query['trmz_cancel'] ), array( 'token' => $query['token'] ) );
		$this->assertSame( 'Booking not active', $again['title'] );
		$this->assertSame( array(), self::mails() );
		$get = ( new CancellationPage( $this->container ) )->respond( 'GET', $query, array() );
		$this->assertStringContainsString( 'already cancelled', $get['body'] );
	}

	public function test_invalid_links_are_rejected_without_revealing_the_booking(): void {
		$booking = $this->book();
		$query   = self::link_from_mail();
		$page    = new CancellationPage( $this->container );

		foreach ( array(
			array(
				'trmz_cancel' => $query['trmz_cancel'],
				'token'       => str_repeat( '0', 64 ),
			),
			array(
				'trmz_cancel' => str_repeat( 'a', 32 ),
				'token'       => $query['token'],
			),
			array( 'trmz_cancel' => $query['trmz_cancel'] ),
			array(
				'trmz_cancel' => '<script>',
				'token'       => '"><script>',
			),
		) as $bad ) {
			$result = $page->respond( 'GET', $bad, array() );
			$this->assertSame( 404, $result['status'] );
			$this->assertSame( 'Invalid link', $result['title'] );
			$this->assertNull( $result['booking'] );
			$this->assertStringNotContainsString( '<script', $result['body'] );
		}

		$post = $page->respond( 'POST', array( 'trmz_cancel' => $query['trmz_cancel'] ), array( 'token' => str_repeat( 'f', 64 ) ) );
		$this->assertSame( 404, $post['status'] );
		$this->assertSame( BookingStatus::Confirmed, $this->container->bookings()->get( (int) $booking->id )?->status );
	}

	public function test_after_the_deadline_the_customer_is_asked_to_contact_the_business(): void {
		$booking = $this->book();
		$query   = self::link_from_mail();
		$this->clock->set( '2030-01-07 09:00' ); // 10:00 Warsaw = exactly 24 h before the start.

		$get  = ( new CancellationPage( $this->container ) )->respond( 'GET', $query, array() );
		$post = ( new CancellationPage( $this->container ) )->respond( 'POST', array( 'trmz_cancel' => $query['trmz_cancel'] ), array( 'token' => $query['token'] ) );

		$this->assertSame( 'Too late to cancel online', $get['title'] );
		$this->assertStringNotContainsString( '<form', $get['body'] );
		$this->assertSame( 'Too late to cancel online', $post['title'] );
		$this->assertSame( BookingStatus::Confirmed, $this->container->bookings()->get( (int) $booking->id )?->status );
	}

	public function test_limit_zero_allows_cancelling_until_the_start(): void {
		update_option(
			Settings::OPTION,
			array(
				'auto_confirm'                => true,
				'customer_cancel_limit_hours' => 0,
			)
		);
		$this->use_settings( $this->container->availability_settings() );
		$booking = $this->book();
		$service = $this->container->booking_service();
		$token   = $service->cancel_token( $booking );

		$this->clock->set( '2030-01-08 08:59' );
		$this->assertSame( $booking->id, $service->check_customer_cancellation( (string) $booking->public_id, $token, 0 )->id );

		$this->clock->set( '2030-01-08 09:00' );
		try {
			$service->cancel_by_customer( (string) $booking->public_id, $token, 0 );
			$this->fail( 'Expected CancellationRefused.' );
		} catch ( CancellationRefused $e ) {
			$this->assertSame( CancellationRefused::TOO_LATE, $e->reason );
		}
		$this->assertSame( '2030-01-08 09:00', BookingService::cancellation_deadline( $booking, 0 )->format( 'Y-m-d H:i' ) );
	}

	public function test_cancelling_counts_against_the_request_limit(): void {
		add_filter(
			'trmz_rate_limit',
			static fn( array $config, string $bucket ): array => 'booking_cancel' === $bucket ? array(
				'limit'  => 2,
				'window' => 600,
			) : $config,
			10,
			2
		);
		$booking = $this->book();
		$page    = new CancellationPage( $this->container );
		$valid   = self::link_from_mail();

		$this->assertSame( 200, $page->respond( 'GET', $valid, array() )['status'], 'Valid links are not counted.' );
		$this->assertSame( 200, $page->respond( 'GET', $valid, array() )['status'] );
		$this->assertSame( 404, $page->respond( 'GET', array( 'trmz_cancel' => $booking->public_id, 'token' => str_repeat( '1', 64 ) ), array() )['status'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 404, $page->respond( 'GET', array( 'trmz_cancel' => $booking->public_id, 'token' => str_repeat( '2', 64 ) ), array() )['status'] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$blocked = $page->respond( 'POST', array( 'trmz_cancel' => $valid['trmz_cancel'] ), array( 'token' => $valid['token'] ) );
		$this->assertSame( 429, $blocked['status'] );
		$this->assertGreaterThan( 0, $blocked['retry_after'] );
		$this->assertSame( BookingStatus::Confirmed, $this->container->bookings()->get( (int) $booking->id )?->status );
	}

	public function test_pending_booking_can_be_cancelled_and_its_e_mail_has_a_link(): void {
		update_option( Settings::OPTION, array( 'auto_confirm' => false ) );
		$this->use_settings( $this->container->availability_settings() );
		$booking = $this->book();
		$mail    = self::mails_with_subject( 'received your booking request' );
		$this->assertStringContainsString( 'trmz_cancel=' . $booking->public_id, $mail[0]['body'] );

		$cancelled = $this->container->booking_service()->cancel_by_customer( (string) $booking->public_id, $this->container->booking_service()->cancel_token( $booking ), 24 );

		$this->assertSame( BookingStatus::Cancelled, $cancelled->status );
	}

	public function test_document_is_noindex_and_escaped(): void {
		update_option( 'blogname', '<b>Studio</b>' );

		$html = ( new CancellationPage( $this->container ) )->document( 'Title <i>', '<p>Body</p>' );

		$this->assertStringContainsString( '<meta name="robots" content="noindex, nofollow" />', $html );
		$this->assertStringContainsString( '<meta name="referrer" content="no-referrer" />', $html );
		$this->assertStringContainsString( 'Title &lt;i&gt;', $html );
		$this->assertStringNotContainsString( '<b>Studio', $html );
		$this->assertStringContainsString( '<p>Body</p>', $html );
	}

	public function test_url_uses_the_home_page(): void {
		$booking = $this->book();

		$url = CancellationPage::url( $booking, 'abc' );

		$this->assertSame( add_query_arg( array( 'trmz_cancel' => $booking->public_id, 'token' => 'abc' ), home_url( '/' ) ), $url ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}
}
