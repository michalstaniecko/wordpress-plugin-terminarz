<?php
/**
 * Integration tests: order and booking statuses stay in step.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\WooCommerce;

use Terminarz\Application\BookingService;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Integrations\WooCommerce\OrderLink;
use Terminarz\Integrations\WooCommerce\OrderStatusSync;
use WC_Order;

/**
 * @group woocommerce
 * @covers \Terminarz\Integrations\WooCommerce\OrderStatusSync
 */
final class OrderStatusSyncTest extends WooCommerceTestCase {

	/**
	 * Booking status events fired during the test.
	 *
	 * @var int
	 */
	private int $booking_events = 0;

	/**
	 * Order status changes during the test.
	 *
	 * @var int
	 */
	private int $order_events = 0;

	public function set_up(): void {
		parent::set_up();
		add_action(
			BookingService::EVENT_STATUS_CHANGED,
			function (): void {
				++$this->booking_events;
			}
		);
		add_action(
			'woocommerce_order_status_changed',
			function (): void {
				++$this->order_events;
			}
		);
	}

	/**
	 * All note texts of an order.
	 *
	 * @param WC_Order $order Order.
	 */
	private static function notes( WC_Order $order ): string {
		return implode( "\n", wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' ) );
	}

	public function test_paid_order_confirms_the_booking_once(): void {
		$booking = $this->book_paid();

		self::order( $booking->order_id )->payment_complete( 'txn-1' );
		self::order( $booking->order_id )->update_status( 'completed' );
		self::order( $booking->order_id )->update_status( 'processing' );

		$this->assertSame( BookingStatus::Confirmed, $this->reload( $booking )->status );
		$this->assertSame( 1, $this->booking_events );
		$this->assertStringContainsString( 'confirmed', self::notes( self::order( $booking->order_id ) ) );
	}

	public function test_completed_order_confirms_the_booking(): void {
		$booking = $this->book_paid();

		self::order( $booking->order_id )->update_status( 'completed' );

		$this->assertSame( BookingStatus::Confirmed, $this->reload( $booking )->status );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function releasing_statuses(): array {
		return array(
			'cancelled' => array( 'cancelled' ),
			'failed'    => array( 'failed' ),
		);
	}

	/**
	 * @dataProvider releasing_statuses
	 *
	 * @param string $status Order status.
	 */
	public function test_cancelled_or_failed_order_cancels_the_booking( string $status ): void {
		$booking = $this->book_paid();

		self::order( $booking->order_id )->update_status( $status );
		self::order( $booking->order_id )->update_status( $status ); // Repeated: no second change.

		$this->assertSame( BookingStatus::Cancelled, $this->reload( $booking )->status );
		$this->assertSame( 1, $this->booking_events );
		$order = self::order( $booking->order_id );
		$this->assertSame( 'order_status', $order->get_meta( OrderStatusSync::RELEASED_META ) );
		$this->assertStringContainsString( 'slot released', self::notes( $order ) );
		$this->assertSame( 201, $this->book()->get_status(), 'The slot is free.' );
	}

	public function test_refunded_order_cancels_a_confirmed_booking(): void {
		$booking = $this->book_paid();
		self::order( $booking->order_id )->payment_complete( 'txn-1' );

		wc_create_refund(
			array(
				'order_id' => (int) $booking->order_id,
				'amount'   => '100.00',
				'reason'   => 'Test',
			)
		);

		$this->assertSame( 'refunded', self::order( $booking->order_id )->get_status() );
		$this->assertSame( BookingStatus::Cancelled, $this->reload( $booking )->status );
	}

	public function test_failed_payment_retried_later_books_the_slot_again(): void {
		$booking = $this->book_paid();
		self::order( $booking->order_id )->update_status( 'failed' );

		self::order( $booking->order_id )->payment_complete( 'txn-retry' );

		$order = self::order( $booking->order_id );
		$new   = OrderLink::booking( $order, $this->container );
		$this->assertNotNull( $new );
		$this->assertNotSame( $booking->id, $new->id );
		$this->assertSame( BookingStatus::Confirmed, $new->status );
		$this->assertSame( BookingStatus::Cancelled, $this->reload( $booking )->status );
	}

	public function test_cancelling_the_booking_cancels_the_unpaid_order_without_a_loop(): void {
		$booking              = $this->book_paid();
		$this->booking_events = 0;
		$this->order_events   = 0;

		$this->container->booking_service()->cancel( (int) $booking->id );

		$order = self::order( $booking->order_id );
		$this->assertSame( 'cancelled', $order->get_status() );
		$this->assertSame( '', $order->get_meta( OrderStatusSync::RELEASED_META ), 'Cancelled on purpose: no automatic rebooking.' );
		$this->assertSame( 1, $this->booking_events );
		$this->assertSame( 1, $this->order_events );
		$this->assertStringContainsString( 'unpaid order was cancelled', self::notes( $order ) );
	}

	public function test_cancelling_a_paid_booking_only_adds_a_note(): void {
		$booking = $this->book_paid();
		self::order( $booking->order_id )->payment_complete( 'txn-1' );
		$this->order_events = 0;

		$this->container->booking_service()->cancel( (int) $booking->id );

		$order = self::order( $booking->order_id );
		$this->assertContains( $order->get_status(), OrderStatusSync::PAID_STATUSES );
		$this->assertSame( 0, $this->order_events );
		$this->assertSame( 0.0, (float) $order->get_total_refunded(), 'No automatic refund.' );
		$this->assertStringContainsString( 'NOT refunded', self::notes( $order ) );
	}

	public function test_payment_of_a_manually_cancelled_booking_needs_attention(): void {
		$booking = $this->book_paid();
		$order   = self::order( $booking->order_id );
		$this->container->booking_service()->cancel( (int) $booking->id );
		$this->assertSame( 'cancelled', self::order( $booking->order_id )->get_status() );

		self::order( $booking->order_id )->payment_complete( 'txn-late' ); // Gateway callback after the cancellation.

		$order = self::order( $booking->order_id );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertStringContainsString( 'cancelled in the booking panel', self::notes( $order ) );
		$this->assertSame( BookingStatus::Cancelled, $this->reload( $booking )->status );
	}

	public function test_manual_confirmation_of_an_unpaid_booking_is_noted(): void {
		$booking = $this->book_paid();

		$this->container->booking_service()->change_status( (int) $booking->id, BookingStatus::Confirmed );

		$order = self::order( $booking->order_id );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertStringContainsString( 'although this order is not paid', self::notes( $order ) );
	}

	public function test_admin_rest_cancellation_is_synchronised(): void {
		$booking = $this->book_paid();
		\Terminarz\Infrastructure\Capabilities::grant();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->request( 'POST', '/bookings/' . $booking->id . '/cancel' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'cancelled', self::order( $booking->order_id )->get_status() );
	}

	public function test_other_orders_are_ignored(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );

		$order->update_status( 'processing' );
		$order->update_status( 'cancelled' );

		$this->assertSame( 0, $this->booking_events );
	}

	public function test_order_pointing_to_another_booking_is_ignored(): void {
		$booking = $this->book_paid();
		$other   = $this->book_paid( '2030-01-07T12:00:00+01:00' );
		$order   = self::order( $booking->order_id );
		$order->update_meta_data( OrderLink::BOOKING_META, (string) $other->id ); // Tampered link: $other has its own order.
		$order->save();
		$this->booking_events = 0;

		$order->update_status( 'cancelled' );

		$this->assertSame( 0, $this->booking_events );
		$this->assertSame( BookingStatus::PendingPayment, $this->reload( $other )->status );
	}
}
