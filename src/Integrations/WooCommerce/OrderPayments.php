<?php
/**
 * Payments of bookings through WooCommerce orders.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Integrations\WooCommerce;

use Terminarz\Admin\Labels;
use Terminarz\Admin\Money;
use Terminarz\Application\PaymentAmount;
use Terminarz\Application\PaymentFailed;
use Terminarz\Application\PaymentProvider;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\Service;
use Terminarz\Infrastructure\Module;
use Terminarz\Infrastructure\Services;
use Terminarz\Infrastructure\Settings;
use WC_Order;
use WC_Order_Item_Product;

/**
 * Creates a WooCommerce order for a booking awaiting payment and returns its "pay for order" URL.
 *
 * The order has one line item without a catalogue product (name = service, meta = appointment time and resource);
 * its amount is the full price or the deposit, taken as the final amount (taxes are not added on top).
 */
final class OrderPayments implements PaymentProvider, Module {

	/**
	 * Constructor.
	 *
	 * @param Services|null $services Composition root; null = the shared one (resolved when used).
	 */
	public function __construct( private readonly ?Services $services = null ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'trmz_payment_provider', array( self::class, 'provide' ), 10, 2 );
	}

	/**
	 * `trmz_payment_provider` filter: the WooCommerce provider bound to the given composition root.
	 *
	 * @param mixed    $provider Provider from earlier callbacks.
	 * @param Services $services Composition root.
	 */
	public static function provide( $provider, Services $services ): PaymentProvider {
		return $provider instanceof PaymentProvider ? $provider : new self( $services );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Booking $booking Stored booking awaiting payment.
	 * @param Service $service Booked service.
	 * @throws PaymentFailed When the order cannot be created or linked.
	 */
	public function start_payment( Booking $booking, Service $service ): string {
		if ( null === $booking->id ) {
			throw new PaymentFailed( 'The booking must be stored before its payment is created.' );
		}

		$services = $this->services ?? Services::instance();
		$settings = $services->settings();
		$deposit  = Settings::PAYMENT_DEPOSIT === $settings->payment_mode() ? $settings->deposit_percent() : null;
		$amount   = PaymentAmount::due( $service->price_minor, $deposit );
		if ( $amount <= 0 ) {
			throw new PaymentFailed( 'A free service needs no payment.' );
		}

		$order = null;
		try {
			$order = wc_create_order(
				array(
					'status'      => 'pending',
					'customer_id' => $booking->customer->user_id ?? 0,
					'created_via' => OrderLink::CREATED_VIA,
				)
			);
			if ( ! $order instanceof WC_Order ) {
				throw new PaymentFailed( 'wc_create_order() failed.' );
			}

			$order->add_item( $this->line_item( $booking, $service, $amount, $deposit, $services ) );
			$this->set_billing( $order, $booking );
			OrderLink::store( $order, $booking );
			$order->calculate_totals( false );
			$order->save();

			$order->add_order_note(
				sprintf(
					/* translators: 1: public booking ID, 2: date and time the slot is held until. */
					__( 'Order created for booking %1$s. The slot is held until %2$s.', 'terminarz' ),
					(string) $booking->public_id,
					null === $booking->hold_expires_at ? '—' : Labels::datetime( $booking->hold_expires_at )
				)
			);

			$services->bookings()->attach_order( $booking->id, $order->get_id() );
		} catch ( \Exception $e ) {
			if ( $order instanceof WC_Order && $order->get_id() > 0 ) {
				$order->update_status( 'cancelled', __( 'The booking could not be linked to this order.', 'terminarz' ) );
			}
			throw new PaymentFailed( 'Creating the WooCommerce order failed: ' . $e->getMessage(), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not printed.
		}

		return $order->get_checkout_payment_url();
	}

	/**
	 * Line item of the booking.
	 *
	 * @param Booking  $booking  Booking.
	 * @param Service  $service  Service.
	 * @param int      $amount   Amount due (minor units).
	 * @param int|null $deposit  Deposit percentage, null = full price.
	 * @param Services $services Composition root.
	 */
	private function line_item( Booking $booking, Service $service, int $amount, ?int $deposit, Services $services ): WC_Order_Item_Product {
		$total = Money::to_input( $amount );
		$item  = new WC_Order_Item_Product();
		$item->set_name( $service->name );
		$item->set_quantity( 1 );
		$item->set_subtotal( $total );
		$item->set_total( $total );

		$item->add_meta_data( __( 'Appointment', 'terminarz' ), Labels::datetime( $booking->range->start ) . '–' . Labels::time( $booking->range->end ), true );
		$resource = $services->resources()->get( $booking->resource_id );
		if ( null !== $resource ) {
			$item->add_meta_data( __( 'Resource', 'terminarz' ), $resource->name, true );
		}
		if ( null !== $deposit ) {
			$item->add_meta_data(
				__( 'Payment', 'terminarz' ),
				sprintf(
					/* translators: 1: deposit percentage, 2: full price with currency. */
					__( 'Deposit %1$d%% of %2$s', 'terminarz' ),
					$deposit,
					html_entity_decode( wp_strip_all_tags( wc_price( (float) Money::to_input( $service->price_minor ) ) ), ENT_QUOTES, 'UTF-8' )
				),
				true
			);
		}
		$item->add_meta_data( OrderLink::BOOKING_META, (string) $booking->id, true );

		return $item;
	}

	/**
	 * Billing data from the booking's customer.
	 *
	 * @param WC_Order $order   Order.
	 * @param Booking  $booking Booking.
	 */
	private function set_billing( WC_Order $order, Booking $booking ): void {
		$parts = preg_split( '/\s+/u', trim( $booking->customer->name ), 2 );
		$parts = false === $parts ? array( $booking->customer->name ) : $parts;

		$order->set_billing_first_name( $parts[0] );
		$order->set_billing_last_name( $parts[1] ?? '' );
		$order->set_billing_email( $booking->customer->email );
		$order->set_billing_phone( $booking->customer->phone );
	}
}
