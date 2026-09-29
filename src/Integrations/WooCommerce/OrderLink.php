<?php
/**
 * Link between WooCommerce orders and bookings.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Model\Booking;
use Terminarz\Infrastructure\Services;
use WC_Order;

/**
 * The order stores the booking ID in the `_trmz_booking_id` meta (CRUD API, HPOS-safe); the booking stores the order ID
 * (`order_id` column). Both sides must agree — an order is linked to a booking only when they point at each other.
 */
final class OrderLink {

	/**
	 * Order meta: internal booking ID.
	 */
	public const BOOKING_META = '_trmz_booking_id';

	/**
	 * Order meta: public booking ID (for people looking at the order).
	 */
	public const PUBLIC_ID_META = '_trmz_booking_public_id';

	/**
	 * Value of `created_via` of orders created for bookings.
	 */
	public const CREATED_VIA = 'terminarz';

	/**
	 * Booking ID stored on the order, or null.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function booking_id( WC_Order $order ): ?int {
		$id = (int) $order->get_meta( self::BOOKING_META );
		return $id > 0 ? $id : null;
	}

	/**
	 * Booking linked to the order (both sides agree), or null.
	 *
	 * @param WC_Order $order    Order.
	 * @param Services $services Composition root.
	 */
	public static function booking( WC_Order $order, Services $services ): ?Booking {
		$id = self::booking_id( $order );
		if ( null === $id ) {
			return null;
		}
		$booking = $services->bookings()->get( $id );
		return null !== $booking && $booking->order_id === $order->get_id() ? $booking : null;
	}

	/**
	 * Order of a booking, or null.
	 *
	 * @param Booking $booking Booking.
	 */
	public static function order( Booking $booking ): ?WC_Order {
		if ( null === $booking->order_id ) {
			return null;
		}
		$order = wc_get_order( $booking->order_id );
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Stores the booking on the order (does not save the order).
	 *
	 * @param WC_Order $order   Order.
	 * @param Booking  $booking Stored booking.
	 */
	public static function store( WC_Order $order, Booking $booking ): void {
		$order->update_meta_data( self::BOOKING_META, (string) $booking->id );
		$order->update_meta_data( self::PUBLIC_ID_META, (string) $booking->public_id );
	}
}
