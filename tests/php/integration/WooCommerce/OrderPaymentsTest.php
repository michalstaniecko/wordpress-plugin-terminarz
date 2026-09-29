<?php
/**
 * Integration tests: a booking of a paid service creates a WooCommerce order.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\WooCommerce;

use Automattic\WooCommerce\Utilities\OrderUtil;
use Terminarz\Application\PaymentFailed;
use Terminarz\Application\PaymentProvider;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Service;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Infrastructure\Persistence\WpdbServiceRepository;
use Terminarz\Infrastructure\Settings;
use Terminarz\Integrations\WooCommerce\OrderAdmin;
use Terminarz\Integrations\WooCommerce\OrderLink;
use Terminarz\Integrations\WooCommerce\OrderPayments;
use WC_Order_Item_Product;
use WP_REST_Request;

/**
 * @group woocommerce
 * @covers \Terminarz\Integrations\WooCommerce\OrderPayments
 * @covers \Terminarz\Integrations\WooCommerce\OrderLink
 * @covers \Terminarz\Integrations\WooCommerce\OrderAdmin
 * @covers \Terminarz\Rest\BookingsController
 * @covers \Terminarz\Infrastructure\Services
 */
final class OrderPaymentsTest extends WooCommerceTestCase {

	public function test_orders_use_the_hpos_store(): void {
		$this->assertTrue( OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	public function test_paid_service_creates_booking_awaiting_payment_and_order(): void {
		$response = $this->book();

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'pending_payment', $data['status'] );
		$this->assertArrayHasKey( 'payment_url', $data );

		$booking = $this->container->bookings()->get_by_public_id( $data['public_id'] );
		$this->assertNotNull( $booking );
		$this->assertSame( BookingStatus::PendingPayment, $booking->status );
		$this->assertNotNull( $booking->order_id );
		$this->assertEquals( self::utc( '2030-01-07 06:15' ), $booking->hold_expires_at, 'Default hold: 15 minutes.' );

		$order = self::order( $booking->order_id );
		$this->assertSame( $order->get_checkout_payment_url(), $data['payment_url'] );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $data['payment_url'] );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( OrderLink::CREATED_VIA, $order->get_created_via() );
		$this->assertSame( (string) $booking->id, $order->get_meta( OrderLink::BOOKING_META ) );
		$this->assertSame( $booking->public_id, $order->get_meta( OrderLink::PUBLIC_ID_META ) );
		$this->assertSame( 'PLN', $order->get_currency() );
		$this->assertSame( '100.00', $order->get_total() );
		$this->assertSame( '0', (string) $order->get_total_tax() );
		$this->assertSame( 0, $order->get_customer_id() );
		$this->assertSame( 'Jan', $order->get_billing_first_name() );
		$this->assertSame( 'Maria Kowalski', $order->get_billing_last_name() );
		$this->assertSame( 'jan@example.org', $order->get_billing_email() );
		$this->assertSame( '+48 600 000 000', $order->get_billing_phone() );

		$items = array_values( $order->get_items() );
		$this->assertCount( 1, $items );
		$item = $items[0];
		$this->assertInstanceOf( WC_Order_Item_Product::class, $item );
		$this->assertSame( 'Service', $item->get_name() );
		$this->assertSame( 0, $item->get_product_id(), 'No catalogue product is created.' );
		$this->assertSame( 1, $item->get_quantity() );
		$this->assertStringContainsString( '10:00', (string) $item->get_meta( 'Appointment' ) );
		$this->assertSame( 'Anna', $item->get_meta( 'Resource' ) );
		$this->assertSame( '', $item->get_meta( 'Payment' ) );
		$this->assertSame( (string) $booking->id, $item->get_meta( OrderLink::BOOKING_META ) );
		$this->assertSame( 0, (int) wp_count_posts( 'product' )->publish );
	}

	public function test_deposit_mode_charges_a_percentage(): void {
		$this->use_payment_settings(
			array(
				'payment_mode'    => Settings::PAYMENT_DEPOSIT,
				'deposit_percent' => 30,
			)
		);

		$booking = $this->book_paid();
		$order   = self::order( $booking->order_id );

		$this->assertSame( '30.00', $order->get_total() );
		$items = array_values( $order->get_items() );
		$this->assertStringContainsString( '30%', (string) $items[0]->get_meta( 'Payment' ) );
		$this->assertStringContainsString( '100', (string) $items[0]->get_meta( 'Payment' ) );
	}

	public function test_hold_length_comes_from_the_settings(): void {
		$this->use_payment_settings(
			array(
				'payment_mode' => Settings::PAYMENT_FULL,
				'hold_minutes' => 30,
			)
		);

		$booking = $this->book_paid();

		$this->assertEquals( self::utc( '2030-01-07 06:30' ), $booking->hold_expires_at );
	}

	public function test_free_service_needs_no_payment(): void {
		global $wpdb;
		$repo = new WpdbServiceRepository( $wpdb );
		$free = (int) $repo->save( new Service( null, 'Free consultation', 60, 0 ) )->id;
		$repo->assign_resources( $free, array( $this->resource ) );

		$response = $this->book( '2030-01-07T10:00:00+01:00', array( 'service' => $free ) );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pending', $response->get_data()['status'] );
		$this->assertArrayNotHasKey( 'payment_url', $response->get_data() );
	}

	public function test_payment_mode_none_needs_no_payment(): void {
		$this->use_payment_settings( array( 'payment_mode' => Settings::PAYMENT_NONE ) );

		$response = $this->book();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pending', $response->get_data()['status'] );
		$this->assertArrayNotHasKey( 'payment_url', $response->get_data() );
	}

	public function test_logged_in_customer_owns_the_order(): void {
		$user = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $user );

		$request = new WP_REST_Request( 'POST', '/terminarz/v1/bookings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'service'  => $this->service,
					'resource' => (string) $this->resource,
					'start'    => '2030-01-07T10:00:00+01:00',
					'name'     => 'Ewa',
					'email'    => 'ewa@example.org',
					'consent'  => true,
				)
			)
		);
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $response->get_status() );
		$booking = $this->container->bookings()->get_by_public_id( $response->get_data()['public_id'] );
		$order   = self::order( $booking?->order_id );
		$this->assertSame( $user, $order->get_customer_id() );
		$this->assertSame( 'Ewa', $order->get_billing_first_name() );
		$this->assertSame( '', $order->get_billing_last_name() );
	}

	public function test_failed_payment_releases_the_slot(): void {
		add_filter(
			'trmz_payment_provider',
			static fn(): PaymentProvider => new class() implements PaymentProvider {
				public function start_payment( Booking $booking, Service $service ): string {
					throw new PaymentFailed( 'Gateway down.' );
				}
			},
			5
		);
		$failures = 0;
		add_action(
			'trmz_payment_start_failed',
			static function () use ( &$failures ): void {
				++$failures;
			}
		);

		$response = $this->book();

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'trmz_payment_unavailable', self::error_code( $response ) );
		$this->assertSame( 1, $failures );
		$bookings = $this->container->bookings()->in_range( new TimeRange( self::utc( '2030-01-07 00:00' ), self::utc( '2030-01-08 00:00' ) ) );
		$this->assertCount( 1, $bookings );
		$this->assertSame( BookingStatus::Cancelled, $bookings[0]->status );

		remove_all_filters( 'trmz_payment_provider' );
		( new OrderPayments() )->register();
		$this->assertSame( 201, $this->book()->get_status(), 'The slot is free again.' );
	}

	public function test_order_creation_error_releases_the_slot(): void {
		add_action(
			'woocommerce_before_order_object_save',
			static function (): void {
				throw new \Exception( 'Order store unavailable.' );
			}
		);

		$response = $this->book();

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'trmz_payment_unavailable', self::error_code( $response ) );
		$bookings = $this->container->bookings()->in_range( new TimeRange( self::utc( '2030-01-07 00:00' ), self::utc( '2030-01-08 00:00' ) ) );
		$this->assertCount( 1, $bookings );
		$this->assertSame( BookingStatus::Cancelled, $bookings[0]->status );
		$this->assertNull( $bookings[0]->order_id );
	}

	public function test_invalid_payment_url_is_rejected(): void {
		add_filter( 'woocommerce_get_checkout_payment_url', static fn(): string => 'javascript:alert(1)' );

		$response = $this->book();

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'trmz_payment_unavailable', self::error_code( $response ) );
	}

	public function test_off_site_payment_url_needs_an_allowed_redirect_host(): void {
		add_filter( 'woocommerce_get_checkout_payment_url', static fn(): string => 'https://198.51.100.7/checkout?id=1' );

		$refused = $this->book();
		$this->assertSame( 500, $refused->get_status(), 'An arbitrary host is refused.' );
		$this->assertSame( 'trmz_payment_unavailable', self::error_code( $refused ) );

		add_filter( 'allowed_redirect_hosts', static fn( array $hosts ): array => array_merge( $hosts, array( '198.51.100.7' ) ) );
		$allowed = $this->book( '2030-01-07T12:00:00+01:00' );
		$this->assertSame( 201, $allowed->get_status(), (string) wp_json_encode( $allowed->get_data() ) );
		$this->assertSame( 'https://198.51.100.7/checkout?id=1', $allowed->get_data()['payment_url'] );
	}

	public function test_booking_details_link_to_the_order(): void {
		$booking = $this->book_paid();
		$order   = self::order( $booking->order_id );

		$rows = (array) apply_filters( 'trmz_admin_booking_details_rows', array(), $booking );

		$this->assertArrayHasKey( 'Order', $rows );
		$this->assertStringContainsString( esc_url( $order->get_edit_order_url() ), $rows['Order'] );
		$this->assertStringContainsString( '#' . $order->get_order_number(), $rows['Order'] );
		$this->assertStringContainsString( 'Pending payment', $rows['Order'] );
	}

	public function test_order_screen_shows_the_booking_box(): void {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$booking = $this->book_paid();
		\Terminarz\Infrastructure\Capabilities::grant();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$order  = self::order( $booking->order_id );
		$admin  = new OrderAdmin();
		$screen = OrderAdmin::order_screen_id();

		$admin->add_meta_box( $screen, $order );

		global $wp_meta_boxes;
		$this->assertArrayHasKey( OrderAdmin::META_BOX, $wp_meta_boxes[ $screen ]['side']['high'] );

		ob_start();
		$admin->render_meta_box( $order );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( (string) $booking->public_id, $html );
		$this->assertStringContainsString( esc_url( OrderAdmin::booking_url( $booking ) ), $html );
		$this->assertStringContainsString( 'Awaiting payment', $html );
	}

	public function test_booking_box_is_not_added_to_other_orders(): void {
		$GLOBALS['wp_meta_boxes'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Reset between tests.
		\Terminarz\Infrastructure\Capabilities::grant();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$order = wc_create_order();
		$this->assertNotWPError( $order );

		( new OrderAdmin() )->add_meta_box( OrderAdmin::order_screen_id(), $order );

		global $wp_meta_boxes;
		$this->assertEmpty( $wp_meta_boxes[ OrderAdmin::order_screen_id() ]['side']['high'][ OrderAdmin::META_BOX ] ?? null );
	}
}
