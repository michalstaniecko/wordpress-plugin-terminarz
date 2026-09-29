<?php
/**
 * Integration tests: payment holds expire, unpaid orders are cancelled, late payments are reconciled.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\WooCommerce;

use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Infrastructure\HoldExpiryScheduler;
use Terminarz\Infrastructure\Lifecycle;
use Terminarz\Integrations\WooCommerce\OrderLink;
use Terminarz\Integrations\WooCommerce\OrderStatusSync;

/**
 * Hold: 15 minutes from "now" (06:00 UTC), i.e. until 06:15 UTC; the appointment is 10:00 Warsaw (09:00 UTC).
 *
 * @group woocommerce
 * @covers \Terminarz\Infrastructure\HoldExpiryScheduler
 * @covers \Terminarz\Integrations\WooCommerce\OrderStatusSync
 * @covers \Terminarz\Application\BookingService
 */
final class HoldExpiryTest extends WooCommerceTestCase {

	use \Terminarz\Tests\Integration\Support\CapturedMails;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
	}

	/**
	 * Slot starts offered on 2030-01-07 (UTC ISO strings).
	 */
	private function offered_starts(): array {
		$response = $this->request(
			'GET',
			'/availability',
			array(
				'service'  => $this->service,
				'resource' => (string) $this->resource,
				'from'     => self::MONDAY,
				'to'       => self::MONDAY,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		return array_column( $response->get_data()['days'][0]['slots'] ?? array(), 'start_utc' );
	}

	/**
	 * Bookings on Monday.
	 *
	 * @return Booking[]
	 */
	private function monday_bookings(): array {
		return $this->container->bookings()->in_range( new TimeRange( self::utc( '2030-01-07 00:00' ), self::utc( '2030-01-08 00:00' ) ) );
	}

	public function test_expired_hold_frees_the_slot_before_the_job_runs(): void {
		$booking = $this->book_paid();
		$this->assertNotContains( '2030-01-07T09:00:00Z', $this->offered_starts() );

		$this->clock->set( '2030-01-07 06:15' );

		$this->assertSame( BookingStatus::PendingPayment, $this->reload( $booking )->status );
		$this->assertContains( '2030-01-07T09:00:00Z', $this->offered_starts() );
		$this->assertSame( 201, $this->book()->get_status(), 'Another customer can take the slot.' );
		$this->assertSame( BookingStatus::Expired, $this->reload( $booking )->status, 'Expired on the way.' );
	}

	public function test_job_expires_holds_and_cancels_unpaid_orders(): void {
		$booking = $this->book_paid();
		$this->clock->set( '2030-01-07 06:14' );
		$this->assertSame( array(), ( new HoldExpiryScheduler() )->expire() );

		$this->clock->set( '2030-01-07 06:16' );
		do_action( 'trmz_expire_holds' );

		$this->assertSame( BookingStatus::Expired, $this->reload( $booking )->status );
		$order = self::order( $booking->order_id );
		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertSame( 'expired', $order->get_meta( OrderStatusSync::RELEASED_META ) );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertStringContainsString( 'ran out', implode( ' ', wp_list_pluck( $notes, 'content' ) ) );

		// Idempotent: nothing left to expire.
		$this->assertSame( array(), ( new HoldExpiryScheduler() )->expire() );
	}

	public function test_orders_not_awaiting_payment_are_not_cancelled(): void {
		$booking = $this->book_paid();
		$order   = self::order( $booking->order_id );
		$order->update_status( 'on-hold' ); // E.g. bank transfer awaiting confirmation.
		$this->clock->set( '2030-01-07 06:16' );

		( new HoldExpiryScheduler() )->run();

		$order = self::order( $booking->order_id );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertSame( BookingStatus::Expired, $this->reload( $booking )->status );
	}

	public function test_payment_within_the_hold_confirms_the_booking(): void {
		$booking = $this->book_paid();
		$this->clock->set( '2030-01-07 06:10' );

		self::order( $booking->order_id )->payment_complete( 'txn-1' );

		$this->assertSame( BookingStatus::Confirmed, $this->reload( $booking )->status );
		$this->assertCount( 1, $this->monday_bookings() );
	}

	public function test_late_payment_books_the_free_slot_again(): void {
		$booking = $this->book_paid();
		$this->clock->set( '2030-01-07 06:20' );
		( new HoldExpiryScheduler() )->run();
		$this->assertSame( 'cancelled', self::order( $booking->order_id )->get_status() );

		self::order( $booking->order_id )->payment_complete( 'txn-late' );

		$order = self::order( $booking->order_id );
		$this->assertContains( $order->get_status(), OrderStatusSync::PAID_STATUSES );
		$this->assertSame( BookingStatus::Expired, $this->reload( $booking )->status, 'Terminal statuses never change.' );
		$new_id = OrderLink::booking_id( $order );
		$this->assertNotSame( $booking->id, $new_id );
		$new = $this->container->bookings()->get( (int) $new_id );
		$this->assertNotNull( $new );
		$this->assertSame( BookingStatus::Confirmed, $new->status );
		$this->assertSame( $order->get_id(), $new->order_id );
		$this->assertEquals( $booking->range, $new->range );
		$this->assertSame( $new->public_id, $order->get_meta( OrderLink::PUBLIC_ID_META ) );
		$this->assertSame( '', $order->get_meta( OrderStatusSync::RELEASED_META ) );
		$this->assertNotContains( '2030-01-07T09:00:00Z', $this->offered_starts() );
	}

	public function test_late_payment_before_the_job_ran_books_the_slot_again(): void {
		$booking = $this->book_paid();
		$this->clock->set( '2030-01-07 06:20' );

		self::order( $booking->order_id )->payment_complete( 'txn-late' );

		$this->assertSame( BookingStatus::Expired, $this->reload( $booking )->status );
		$new = OrderLink::booking( self::order( $booking->order_id ), $this->container );
		$this->assertNotNull( $new );
		$this->assertSame( BookingStatus::Confirmed, $new->status );
	}

	public function test_late_payment_for_a_taken_slot_needs_attention(): void {
		$booking = $this->book_paid();
		$this->clock->set( '2030-01-07 06:20' );
		( new HoldExpiryScheduler() )->run();
		$this->assertSame( 201, $this->book( '2030-01-07T10:00:00+01:00', array( 'email' => 'ola@example.org' ) )->get_status() );
		$this->use_payment_settings(
			array(
				'payment_mode'       => 'full',
				'notification_email' => 'office@example.org',
			)
		);
		$attention = 0;
		add_action(
			'trmz_payment_needs_attention',
			static function () use ( &$attention ): void {
				++$attention;
			}
		);

		self::order( $booking->order_id )->payment_complete( 'txn-late' );

		$order = self::order( $booking->order_id );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertNotSame( '', $order->get_meta( OrderStatusSync::ATTENTION_META ) );
		$this->assertSame( $booking->id, OrderLink::booking_id( $order ), 'Still linked to the expired booking.' );
		$this->assertSame( 1, $attention );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertStringContainsString( 'no longer available', implode( ' ', wp_list_pluck( $notes, 'content' ) ) );

		$ours = self::mails_with_subject( 'needs attention' );
		$this->assertCount( 1, $ours );
		$this->assertSame( 'office@example.org', $ours[0]['to'] );
		$this->assertStringContainsString( (string) $booking->public_id, $ours[0]['body'] );

		// A second "paid" transition does not notify again.
		$order->update_status( 'processing' );
		$this->assertSame( 1, $attention );
		$this->assertCount( 2, $this->monday_bookings() );
	}

	public function test_schedules_the_job_in_action_scheduler(): void {
		$this->assertTrue( HoldExpiryScheduler::uses_action_scheduler() );
		set_current_screen( 'dashboard' );
		wp_schedule_event( time(), HoldExpiryScheduler::CRON_SCHEDULE, HoldExpiryScheduler::HOOK );

		( new HoldExpiryScheduler() )->schedule();
		( new HoldExpiryScheduler() )->schedule();

		$this->assertTrue( as_has_scheduled_action( HoldExpiryScheduler::HOOK, array(), HoldExpiryScheduler::GROUP ) );
		$this->assertCount(
			1,
			as_get_scheduled_actions(
				array(
					'hook'   => HoldExpiryScheduler::HOOK,
					'status' => 'pending',
				),
				'ids'
			)
		);
		$this->assertFalse( wp_next_scheduled( HoldExpiryScheduler::HOOK ), 'The WP-Cron fallback is removed.' );

		Lifecycle::deactivate();
		$this->assertFalse( as_has_scheduled_action( HoldExpiryScheduler::HOOK, array(), HoldExpiryScheduler::GROUP ) );
		set_current_screen( 'front' );
	}

	public function test_registers_a_five_minute_cron_interval(): void {
		$schedules = wp_get_schedules();

		$this->assertSame( 300, $schedules[ HoldExpiryScheduler::CRON_SCHEDULE ]['interval'] );
	}
}
