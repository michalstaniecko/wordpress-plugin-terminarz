<?php
/**
 * Integration tests: customer cancellation of paid bookings.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\WooCommerce;

use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Frontend\CancellationPage;
use Terminarz\Tests\Integration\Support\CapturedMails;

/**
 * @group woocommerce
 * @covers \Terminarz\Frontend\CancellationPage
 * @covers \Terminarz\Integrations\WooCommerce\OrderStatusSync
 */
final class CustomerCancellationTest extends WooCommerceTestCase {

	use CapturedMails;

	/**
	 * Cancels through the page (POST).
	 *
	 * @param \Terminarz\Domain\Model\Booking $booking Booking.
	 * @return array<string, mixed> Page.
	 */
	private function cancel( $booking ): array {
		$token = $this->container->booking_service()->cancel_token( $booking );
		return ( new CancellationPage( $this->container ) )->respond( 'POST', array( 'trmz_cancel' => $booking->public_id ), array( 'token' => $token ) );
	}

	public function test_unpaid_booking_cancelled_by_the_customer_cancels_the_order_silently(): void {
		$booking = $this->book_paid( '2030-01-08T10:00:00+01:00' );
		reset_phpmailer_instance();

		$page = $this->cancel( $booking );

		$this->assertTrue( $page['cancelled'] );
		$this->assertSame( BookingStatus::Cancelled, $this->reload( $booking )->status );
		$this->assertSame( 'cancelled', self::order( $booking->order_id )->get_status() );
		$this->assertSame( array(), self::mails_with_subject( 'Booking cancelled' ), 'Never confirmed: no cancellation e-mails.' );
	}

	public function test_paid_booking_cancelled_by_the_customer_keeps_the_payment_for_a_human_decision(): void {
		$booking = $this->book_paid( '2030-01-08T10:00:00+01:00' );
		self::order( $booking->order_id )->payment_complete( 'txn-1' );
		$this->assertSame( BookingStatus::Confirmed, $this->reload( $booking )->status );
		reset_phpmailer_instance();

		$this->cancel( $this->reload( $booking ) );

		$this->assertSame( BookingStatus::Cancelled, $this->reload( $booking )->status );
		$order = self::order( $booking->order_id );
		$this->assertContains( $order->get_status(), array( 'processing', 'completed' ) );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertStringContainsString( 'NOT refunded', implode( ' ', wp_list_pluck( $notes, 'content' ) ) );
		$this->assertCount( 1, self::mails_with_subject( 'Your booking has been cancelled' ) );
		$this->assertCount( 1, self::mails_with_subject( 'Booking cancelled' ) );
	}
}
