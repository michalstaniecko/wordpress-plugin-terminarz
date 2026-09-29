<?php
/**
 * Integration tests: appointment reminders (Action Scheduler and WP-Cron).
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Notifications;

use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Infrastructure\HoldExpiryScheduler;
use Terminarz\Infrastructure\Settings;
use Terminarz\Notifications\ReminderScheduler;
use Terminarz\Notifications\Templates;
use Terminarz\Tests\Integration\Rest\RestTestCase;
use Terminarz\Tests\Integration\Support\CapturedMails;

/**
 * "Now" = 2030-01-07 06:00 UTC. Bookings on 2030-01-09 10:00 Warsaw (09:00 UTC); reminder 24 h before = 2030-01-08
 * 09:00 UTC. Every test runs against WP-Cron and (when WooCommerce is loaded) against Action Scheduler.
 *
 * @covers \Terminarz\Notifications\ReminderScheduler
 * @covers \Terminarz\Notifications\BookingNotifier
 * @covers \Terminarz\Infrastructure\HoldExpiryScheduler
 */
final class ReminderSchedulerTest extends RestTestCase {

	use CapturedMails;

	private const REMINDER_AT = '2030-01-08 09:00';

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
		$this->settings( array( 'auto_confirm' => true ) );
		delete_option( Templates::OPTION );
		$this->resource = $this->make_resource( 'Anna' );
		$this->service  = $this->make_service( 60, 0, array( $this->resource ) );
		$this->open_daily( $this->resource );
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		ReminderScheduler::unschedule_all();
		remove_all_filters( 'trmz_use_action_scheduler' );
		parent::tear_down();
	}

	/**
	 * Backends to test.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function backends(): array {
		return array(
			'WP-Cron'          => array( 'cron' ),
			'Action Scheduler' => array( 'as' ),
		);
	}

	/**
	 * Selects a backend (skips Action Scheduler when WooCommerce is not loaded).
	 *
	 * @param string $backend "cron" or "as".
	 */
	private function use_backend( string $backend ): void {
		if ( 'cron' === $backend ) {
			add_filter( 'trmz_use_action_scheduler', '__return_false' );
			$this->assertFalse( HoldExpiryScheduler::uses_action_scheduler() );
			return;
		}
		if ( ! function_exists( 'as_schedule_single_action' ) || 0 === did_action( 'action_scheduler_init' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded (WooCommerce missing).' );
		}
		$this->assertTrue( HoldExpiryScheduler::uses_action_scheduler() );
	}

	/**
	 * Stores settings and rebuilds the composition root.
	 *
	 * @param array<string, mixed> $values Settings.
	 */
	private function settings( array $values ): void {
		update_option( Settings::OPTION, $values );
		$this->use_settings( $this->container->availability_settings() );
	}

	/**
	 * Books through REST.
	 *
	 * @param string $start Start (ISO with offset).
	 */
	private function book( string $start = '2030-01-09T10:00:00+01:00' ): Booking {
		$response = $this->request(
			'POST',
			'/bookings',
			array(
				'service'  => $this->service,
				'resource' => (string) $this->resource,
				'start'    => $start,
				'name'     => 'Jan',
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
	 * Scheduled time of a booking's reminder in the given backend (null = none).
	 *
	 * @param string  $backend Backend.
	 * @param Booking $booking Booking.
	 */
	private static function scheduled( string $backend, Booking $booking ): ?int {
		if ( 'cron' === $backend ) {
			$next = wp_next_scheduled( ReminderScheduler::HOOK, array( $booking->id ) );
			return false === $next ? null : (int) $next;
		}
		$next = as_next_scheduled_action( ReminderScheduler::HOOK, array( $booking->id ), ReminderScheduler::GROUP );
		return is_int( $next ) ? $next : null;
	}

	/**
	 * Reminder e-mails sent.
	 *
	 * @return array<int, array{to: string, subject: string, body: string, header: string}>
	 */
	private static function reminders(): array {
		return self::mails_with_subject( 'Reminder:' );
	}

	/**
	 * @dataProvider backends
	 *
	 * @param string $backend Backend.
	 */
	public function test_confirmed_booking_schedules_one_reminder( string $backend ): void {
		$this->use_backend( $backend );

		$booking = $this->book();

		$this->assertSame( self::utc( self::REMINDER_AT )->getTimestamp(), self::scheduled( $backend, $booking ) );
		( new ReminderScheduler( $this->container ) )->schedule( $booking );
		if ( 'as' === $backend ) {
			$this->assertCount( 1, as_get_scheduled_actions( array( 'hook' => ReminderScheduler::HOOK, 'args' => array( $booking->id ), 'status' => 'pending' ), 'ids' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->assertFalse( wp_next_scheduled( ReminderScheduler::HOOK, array( $booking->id ) ) );
		} else {
			$this->assertSame( self::utc( self::REMINDER_AT )->getTimestamp(), self::scheduled( 'cron', $booking ) );
		}
	}

	/**
	 * @dataProvider backends
	 *
	 * @param string $backend Backend.
	 */
	public function test_pending_booking_is_scheduled_only_after_confirmation( string $backend ): void {
		$this->use_backend( $backend );
		$this->settings( array( 'auto_confirm' => false ) );

		$booking = $this->book();
		$this->assertNull( self::scheduled( $backend, $booking ) );

		$confirmed = $this->container->booking_service()->change_status( (int) $booking->id, BookingStatus::Confirmed );
		$this->assertSame( self::utc( self::REMINDER_AT )->getTimestamp(), self::scheduled( $backend, $confirmed ) );
	}

	/**
	 * @dataProvider backends
	 *
	 * @param string $backend Backend.
	 */
	public function test_moving_reschedules_and_cancelling_removes_the_reminder( string $backend ): void {
		$this->use_backend( $backend );
		$booking = $this->book();

		$moved = $this->container->booking_service()->reschedule( (int) $booking->id, self::warsaw( '2030-01-10 12:00' ) );
		$this->assertSame( self::utc( '2030-01-09 11:00' )->getTimestamp(), self::scheduled( $backend, $moved ) );

		$this->container->booking_service()->cancel( (int) $booking->id );
		$this->assertNull( self::scheduled( $backend, $moved ) );
	}

	/**
	 * @dataProvider backends
	 *
	 * @param string $backend Backend.
	 */
	public function test_job_sends_the_reminder_once( string $backend ): void {
		$this->use_backend( $backend );
		$booking = $this->book();
		reset_phpmailer_instance();
		$this->clock->set( self::REMINDER_AT );

		do_action( 'trmz_send_reminder', $booking->id );
		do_action( 'trmz_send_reminder', $booking->id );

		$reminders = self::reminders();
		$this->assertCount( 1, $reminders );
		$this->assertSame( 'jan@example.org', $reminders[0]['to'] );
		$this->assertStringContainsString( 'trmz_cancel=' . $booking->public_id, $reminders[0]['body'] );
	}

	public function test_moved_booking_gets_a_new_reminder(): void {
		$booking = $this->book();
		$this->clock->set( self::REMINDER_AT );
		$scheduler = new ReminderScheduler( $this->container );
		$this->assertTrue( $scheduler->send( (int) $booking->id ) );

		$this->clock->set( '2030-01-08 09:30' );
		$this->container->booking_service()->reschedule( (int) $booking->id, self::warsaw( '2030-01-10 12:00' ) );
		$this->clock->set( '2030-01-09 11:00' );

		$this->assertTrue( $scheduler->send( (int) $booking->id ) );
		$this->assertCount( 2, self::reminders() );
	}

	public function test_no_reminder_for_cancelled_expired_past_or_disabled(): void {
		$scheduler = new ReminderScheduler( $this->container );
		$cancelled = $this->book();
		$this->container->booking_service()->cancel( (int) $cancelled->id );
		$started = $this->book( '2030-01-07T12:00:00+01:00' );
		$this->clock->set( self::REMINDER_AT );

		$this->assertFalse( $scheduler->send( (int) $cancelled->id ) );
		$this->assertFalse( $scheduler->send( 999999 ) );
		$this->assertFalse( $scheduler->send( (int) $started->id ), 'Already started.' );

		$later = $this->book( '2030-01-09T11:00:00+01:00' );
		$this->settings(
			array(
				'auto_confirm'          => true,
				'reminder_hours_before' => 0,
			)
		);
		$this->assertFalse( ( new ReminderScheduler( $this->container ) )->send( (int) $later->id ), 'Reminders turned off.' );
		$this->assertSame( array(), self::reminders() );
	}

	public function test_booking_awaiting_payment_is_never_scheduled(): void {
		$booking   = $this->book();
		$pending   = new Booking(
			resource_id: $booking->resource_id,
			service_id: $booking->service_id,
			range: $booking->range,
			status: BookingStatus::PendingPayment,
			customer: $booking->customer,
			hold_expires_at: self::utc( '2030-01-07 06:15' ),
			id: 999998
		);
		$scheduler = new ReminderScheduler( $this->container );

		$scheduler->booking_created( $pending );
		$scheduler->booking_status_changed( $pending->with_status( BookingStatus::Expired ), BookingStatus::PendingPayment );

		$this->assertFalse( wp_next_scheduled( ReminderScheduler::HOOK, array( 999998 ) ) );
	}

	public function test_bookings_made_after_the_reminder_time_get_none(): void {
		$booking = $this->book( '2030-01-07T16:00:00+01:00' ); // 9 h ahead: the reminder time has passed.

		$this->assertNull( ( new ReminderScheduler( $this->container ) )->due_at( $booking ) );
		$this->assertFalse( wp_next_scheduled( ReminderScheduler::HOOK, array( $booking->id ) ) );
	}

	public function test_early_job_waits_for_the_right_time(): void {
		add_filter( 'trmz_use_action_scheduler', '__return_false' );
		$booking = $this->book();
		$this->settings(
			array(
				'auto_confirm'          => true,
				'reminder_hours_before' => 2,
			)
		);
		$this->clock->set( self::REMINDER_AT ); // 24 h before; the setting now says 2 h.

		$this->assertFalse( ( new ReminderScheduler( $this->container ) )->send( (int) $booking->id ) );
		$this->assertSame( self::utc( '2030-01-09 07:00' )->getTimestamp(), wp_next_scheduled( ReminderScheduler::HOOK, array( $booking->id ) ) );
		$this->assertSame( array(), self::reminders() );
	}

	public function test_setting_is_validated(): void {
		$this->assertSame( 24, Settings::load()->reminder_hours_before() );
		$this->assertSame( 24, ( new Settings( array( 'reminder_hours_before' => 500 ) ) )->reminder_hours_before() );
		$this->assertSame( 0, ( new Settings( array( 'reminder_hours_before' => '0' ) ) )->reminder_hours_before() );
	}
}
