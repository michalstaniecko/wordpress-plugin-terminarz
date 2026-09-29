<?php
/**
 * Base class of WooCommerce integration tests.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\WooCommerce;

use Terminarz\Domain\Model\Booking;
use Terminarz\Infrastructure\Settings;
use Terminarz\Tests\Integration\Rest\RestTestCase;
use WC_Order;
use WP_REST_Response;

/**
 * Payments enabled (full price), one resource open 09:00–17:00 daily, a 60-minute service for 100.00.
 * "Now" = Monday 2030-01-07 06:00 UTC (07:00 Europe/Warsaw).
 */
abstract class WooCommerceTestCase extends RestTestCase {

	/**
	 * Resource.
	 *
	 * @var int
	 */
	protected int $resource;

	/**
	 * Paid service (100.00).
	 *
	 * @var int
	 */
	protected int $service;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded (TRMZ_TESTS_WOOCOMMERCE=0).' );
		}
		update_option( 'woocommerce_currency', 'PLN' );
		update_option( 'woocommerce_price_num_decimals', '2' );
		$this->use_payment_settings( array( 'payment_mode' => Settings::PAYMENT_FULL ) );

		$this->resource = $this->make_resource( 'Anna' );
		$this->service  = $this->make_service( 60, 0, array( $this->resource ) );
		$this->open_daily( $this->resource );
	}

	/**
	 * Stores plugin settings and makes the composition root read them again.
	 *
	 * @param array<string, mixed> $values Settings.
	 */
	protected function use_payment_settings( array $values ): void {
		update_option( Settings::OPTION, $values );
		$this->use_settings( $this->container->availability_settings() );
	}

	/**
	 * Books via REST.
	 *
	 * @param string               $start     Start (ISO with offset).
	 * @param array<string, mixed> $overrides Body overrides.
	 */
	protected function book( string $start = '2030-01-07T10:00:00+01:00', array $overrides = array() ): WP_REST_Response {
		return $this->request(
			'POST',
			'/bookings',
			array_merge(
				array(
					'service'  => $this->service,
					'resource' => (string) $this->resource,
					'start'    => $start,
					'name'     => 'Jan Maria Kowalski',
					'email'    => 'jan@example.org',
					'phone'    => '+48 600 000 000',
					'consent'  => true,
				),
				$overrides
			)
		);
	}

	/**
	 * Books via REST and returns the stored booking (asserts success).
	 *
	 * @param string $start Start.
	 */
	protected function book_paid( string $start = '2030-01-07T10:00:00+01:00' ): Booking {
		$response = $this->book( $start );
		$this->assertSame( 201, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$booking = $this->container->bookings()->get_by_public_id( $response->get_data()['public_id'] );
		$this->assertNotNull( $booking );
		return $booking;
	}

	/**
	 * Fresh copy of an order from the store.
	 *
	 * @param int|null $id Order ID.
	 */
	protected static function order( ?int $id ): WC_Order {
		$order = wc_get_order( (int) $id );
		self::assertInstanceOf( WC_Order::class, $order );
		return $order;
	}

	/**
	 * Fresh copy of a booking.
	 *
	 * @param Booking $booking Booking.
	 */
	protected function reload( Booking $booking ): Booking {
		$fresh = $this->container->bookings()->get( (int) $booking->id );
		$this->assertNotNull( $fresh );
		return $fresh;
	}
}
