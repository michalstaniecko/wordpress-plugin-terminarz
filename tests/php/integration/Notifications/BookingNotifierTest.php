<?php
/**
 * Integration tests: booking confirmation e-mails.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Notifications;

use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Infrastructure\Capabilities;
use Terminarz\Infrastructure\Settings;
use Terminarz\Notifications\BookingNotifier;
use Terminarz\Notifications\MessageType;
use Terminarz\Notifications\Templates;
use Terminarz\Tests\Integration\Rest\RestTestCase;
use Terminarz\Tests\Integration\Support\CapturedMails;
use WP_REST_Response;

/**
 * One resource open 09:00–17:00 Warsaw time, a free 60-minute service; "now" = 2030-01-07 07:00 Warsaw.
 *
 * @covers \Terminarz\Notifications\BookingNotifier
 * @covers \Terminarz\Infrastructure\Persistence\WpdbNotificationLog
 */
final class BookingNotifierTest extends RestTestCase {

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

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Warsaw' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		update_option( Settings::OPTION, array( 'notification_email' => 'office@example.org' ) );
		delete_option( Templates::OPTION );
		$this->use_settings( $this->container->availability_settings() );
		$this->resource = $this->make_resource( 'Anna' );
		$this->service  = $this->make_service( 60, 0, array( $this->resource ) );
		$this->open_daily( $this->resource );
		reset_phpmailer_instance();
	}

	/**
	 * Books 10:00 Warsaw time through the public REST endpoint.
	 */
	private function book(): Booking {
		$response = $this->request(
			'POST',
			'/bookings',
			array(
				'service'  => $this->service,
				'resource' => (string) $this->resource,
				'start'    => '2030-01-07T10:00:00+01:00',
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
	 * Logs in a manager (admin REST endpoints).
	 */
	private function login_manager(): void {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $user )->add_cap( Capabilities::MANAGE_BOOKINGS );
		wp_set_current_user( $user );
	}

	/**
	 * Recipient => subject of the captured e-mails.
	 *
	 * @return array<int, string>
	 */
	private static function summary(): array {
		return array_map( static fn( array $mail ): string => $mail['to'] . ': ' . $mail['subject'], self::mails() );
	}

	public function test_pending_booking_notifies_customer_and_business(): void {
		$booking = $this->book();

		$this->assertSame( BookingStatus::Pending, $booking->status );
		$this->assertSame(
			array(
				'jan@example.org: [Test Blog] We have received your booking request',
				'office@example.org: [Test Blog] New booking: Service, 2030-01-07 10:00',
			),
			self::summary()
		);
		$this->assertStringContainsString( '10:00–11:00', self::mails()[0]['body'], 'Site time zone.' );
		$this->assertSame( array( 'admin_new', 'customer_pending' ), $this->container->notification_log()->keys( (int) $booking->id ) );
	}

	public function test_confirmation_by_staff_sends_the_confirmation_once(): void {
		$booking = $this->book();
		reset_phpmailer_instance();
		$this->login_manager();

		$this->assertSame( 200, $this->request( 'POST', "/bookings/{$booking->id}/confirm" )->get_status() );
		$this->assertSame( 422, $this->request( 'POST', "/bookings/{$booking->id}/confirm" )->get_status() );

		$this->assertSame( array( 'jan@example.org: [Test Blog] Your booking is confirmed: 2030-01-07 10:00' ), self::summary() );
	}

	public function test_auto_confirmed_booking_sends_the_confirmation_right_away(): void {
		add_filter( 'trmz_auto_confirm_bookings', '__return_true' );

		$this->book();

		$this->assertSame(
			array(
				'jan@example.org: [Test Blog] Your booking is confirmed: 2030-01-07 10:00',
				'office@example.org: [Test Blog] New booking: Service, 2030-01-07 10:00',
			),
			self::summary()
		);
	}

	public function test_repeated_events_do_not_send_twice(): void {
		add_filter( 'trmz_auto_confirm_bookings', '__return_true' );
		$booking = $this->book();

		do_action( 'trmz_booking_created', $booking );
		do_action( 'trmz_booking_status_changed', $booking, BookingStatus::Pending );

		$this->assertCount( 2, self::mails() );
	}

	public function test_booking_awaiting_payment_sends_nothing_until_confirmed(): void {
		$notifier = new BookingNotifier( $this->container );
		$booking  = $this->book();
		reset_phpmailer_instance();
		$awaiting = new Booking(
			resource_id: $booking->resource_id,
			service_id: $booking->service_id,
			range: $booking->range,
			status: BookingStatus::PendingPayment,
			customer: $booking->customer,
			hold_expires_at: self::utc( '2030-01-07 06:15' ),
			id: 999999,
			public_id: str_repeat( 'a', 32 )
		);

		$notifier->booking_created( $awaiting );
		$notifier->booking_status_changed( $awaiting, BookingStatus::PendingPayment );
		$this->assertSame( array(), self::mails() );

		$notifier->booking_status_changed( $awaiting->with_status( BookingStatus::Confirmed ), BookingStatus::PendingPayment );
		$this->assertCount( 2, self::mails(), 'Customer confirmation + business notice after payment.' );
	}

	public function test_disabled_messages_are_not_sent_nor_recorded(): void {
		( new Templates() )->set_enabled( MessageType::AdminNew, false );

		$booking = $this->book();

		$this->assertSame( array( 'jan@example.org: [Test Blog] We have received your booking request' ), self::summary() );
		$this->assertSame( array( 'customer_pending' ), $this->container->notification_log()->keys( (int) $booking->id ) );
	}

	public function test_failed_sending_is_not_recorded(): void {
		add_filter( 'pre_wp_mail', '__return_false' );

		$booking = $this->book();

		$this->assertSame( array(), $this->container->notification_log()->keys( (int) $booking->id ) );
		$this->assertSame( BookingStatus::Pending, $booking->status, 'The booking itself succeeded.' );
	}

	public function test_booking_response_is_unaffected_by_mail_errors(): void {
		add_filter( 'pre_wp_mail', '__return_false' );

		$response = $this->request(
			'POST',
			'/bookings',
			array(
				'service'  => $this->service,
				'resource' => (string) $this->resource,
				'start'    => '2030-01-07T11:00:00+01:00',
				'name'     => 'Ola',
				'email'    => 'ola@example.org',
				'consent'  => true,
			)
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
	}
}
