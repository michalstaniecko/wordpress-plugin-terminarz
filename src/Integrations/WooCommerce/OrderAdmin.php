<?php
/**
 * Order ↔ booking links in the admin.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit; // No direct access.

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Terminarz\Admin\BookingsPage;
use Terminarz\Admin\Labels;
use Terminarz\Domain\Model\Booking;
use Terminarz\Infrastructure\Capabilities;
use Terminarz\Infrastructure\Module;
use Terminarz\Infrastructure\Services;
use WC_Order;
use WP_Post;

/**
 * - "Booking" meta box on the order edit screen (HPOS `wc-orders` page and the legacy `shop_order` post screen),
 *   linking to the booking details;
 * - the "Order" row of the booking details links to the order edit screen and shows the order status.
 */
final class OrderAdmin implements Module {

	/**
	 * Meta box ID.
	 */
	public const META_BOX = 'trmz-booking';

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_filter( 'trmz_admin_booking_details_rows', array( $this, 'booking_details_rows' ), 10, 2 );
	}

	/**
	 * Screen ID of the order edit screen (HPOS or legacy posts).
	 */
	public static function order_screen_id(): string {
		$hpos = class_exists( CustomOrdersTableController::class )
			&& wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();

		return $hpos && function_exists( 'wc_get_page_screen_id' ) ? (string) wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
	}

	/**
	 * `add_meta_boxes`: adds the booking box to orders created for bookings.
	 *
	 * @param string $screen_id Screen ID or post type.
	 * @param mixed  $item      Order (HPOS) or post (legacy).
	 */
	public function add_meta_box( $screen_id, $item = null ): void {
		if ( self::order_screen_id() !== $screen_id || ! current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			return;
		}
		$order = self::order_from( $item );
		if ( null === $order || null === OrderLink::booking_id( $order ) ) {
			return;
		}

		add_meta_box( self::META_BOX, __( 'Booking', 'terminarz' ), array( $this, 'render_meta_box' ), $screen_id, 'side', 'high' );
	}

	/**
	 * Meta box content.
	 *
	 * @param mixed $item Order (HPOS) or post (legacy).
	 */
	public function render_meta_box( $item ): void {
		$order = self::order_from( $item );
		if ( null === $order ) {
			return;
		}
		$services = Services::instance();
		$booking  = OrderLink::booking( $order, $services );
		if ( null === $booking ) {
			echo '<p>' . esc_html__( 'The booking of this order no longer exists or is linked to another order.', 'terminarz' ) . '</p>';
			return;
		}

		$service = $services->services()->get( $booking->service_id );
		echo '<p><strong>' . esc_html( null === $service ? '#' . $booking->service_id : $service->name ) . '</strong><br />';
		echo esc_html( Labels::datetime( $booking->range->start ) . '–' . Labels::time( $booking->range->end ) ) . '</p>';
		echo '<p>' . esc_html__( 'Status:', 'terminarz' ) . ' <strong>' . esc_html( Labels::status( $booking->status ) ) . '</strong><br />';
		echo esc_html__( 'Booking ID:', 'terminarz' ) . ' <code>' . esc_html( (string) $booking->public_id ) . '</code></p>';
		echo '<p><a class="button" href="' . esc_url( self::booking_url( $booking ) ) . '">' . esc_html__( 'View booking', 'terminarz' ) . '</a></p>';
	}

	/**
	 * `trmz_admin_booking_details_rows`: the "Order" row links to the order.
	 *
	 * @param array<string, string> $rows    Label => escaped HTML.
	 * @param Booking               $booking Booking.
	 * @return array<string, string>
	 */
	public function booking_details_rows( $rows, $booking ): array {
		$rows = is_array( $rows ) ? $rows : array();
		if ( ! $booking instanceof Booking || null === $booking->order_id ) {
			return $rows;
		}
		$order = OrderLink::order( $booking );
		$label = __( 'Order', 'terminarz' );
		if ( null === $order ) {
			$rows[ $label ] = esc_html(
				/* translators: %d: order ID. */
				sprintf( __( '#%d (deleted)', 'terminarz' ), $booking->order_id )
			);
			return $rows;
		}

		$rows[ $label ] = sprintf(
			'<a href="%1$s">%2$s</a> (%3$s, %4$s)',
			esc_url( $order->get_edit_order_url() ),
			esc_html( '#' . $order->get_order_number() ),
			esc_html( wc_get_order_status_name( $order->get_status() ) ),
			wp_kses_post( $order->get_formatted_order_total() )
		);
		return $rows;
	}

	/**
	 * Booking details URL.
	 *
	 * @param Booking $booking Booking.
	 */
	public static function booking_url( Booking $booking ): string {
		return add_query_arg(
			array(
				'page' => BookingsPage::SLUG,
				'view' => 'view',
				'id'   => (int) $booking->id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Order from a meta box argument.
	 *
	 * @param mixed $item Order or post.
	 */
	private static function order_from( $item ): ?WC_Order {
		if ( $item instanceof WC_Order ) {
			return $item;
		}
		if ( $item instanceof WP_Post ) {
			$order = wc_get_order( $item->ID );
			return $order instanceof WC_Order ? $order : null;
		}
		return null;
	}
}
