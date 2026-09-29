<?php
/**
 * Synchronisation of order and booking statuses.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Integrations\WooCommerce;

use Terminarz\Admin\Labels;
use Terminarz\Application\BookingService;
use Terminarz\Domain\Exception\DomainError;
use Terminarz\Domain\Exception\SlotUnavailable;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Infrastructure\Database\DatabaseError;
use Terminarz\Infrastructure\Module;
use Terminarz\Infrastructure\Services;
use WC_Order;

/**
 * Keeps the booking of an order in step with the order:
 *
 * - order paid (`processing`/`completed`) → booking `confirmed`; if its payment hold already ran out, the slot is booked
 *   again as a new confirmed booking when still free, otherwise the order goes `on-hold` and the business is notified
 *   (a refund or another appointment is a human decision);
 * - order `cancelled`/`failed`/`refunded` → active booking `cancelled` (slot released; a later payment of a failed order
 *   books the slot again when it is still free);
 * - booking hold expired → the unpaid order (`pending`/`failed`) is cancelled with a note;
 * - booking cancelled elsewhere (panel, customer) → an unpaid order is cancelled, a paid one only gets a note — refunds
 *   are never automatic (a human decision);
 * - booking confirmed in the panel while awaiting payment → note on the order.
 *
 * Every handler checks the current state first (idempotent) and changes made here do not trigger the opposite handler
 * (re-entrancy flag).
 */
final class OrderStatusSync implements Module {

	/**
	 * Order statuses meaning "paid".
	 */
	public const PAID_STATUSES = array( 'processing', 'completed' );

	/**
	 * Order statuses that release the booking.
	 */
	public const RELEASING_STATUSES = array( 'cancelled', 'failed', 'refunded' );

	/**
	 * Order meta: why the plugin released the booking's slot (`expired`, `order_status`), so that a later payment may
	 * book the slot again automatically.
	 */
	public const RELEASED_META = '_trmz_slot_released';

	/**
	 * Order meta: set when a paid order needs a human decision (notification sent once).
	 */
	public const ATTENTION_META = '_trmz_needs_attention';

	/**
	 * Whether a handler of this class is changing an order or a booking right now.
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

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
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 20, 4 );
		add_action( BookingService::EVENT_STATUS_CHANGED, array( $this, 'booking_status_changed' ), 20, 2 );
	}

	/**
	 * Whether a synchronisation is in progress (other listeners may skip their own side effects).
	 */
	public static function is_syncing(): bool {
		return self::$syncing;
	}

	/**
	 * `woocommerce_order_status_changed`.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $from     Previous status (without `wc-`).
	 * @param string $to       New status (without `wc-`).
	 * @param mixed  $order    Order.
	 */
	public function order_status_changed( $order_id, $from, $to, $order = null ): void {
		if ( self::$syncing ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( ! $order instanceof WC_Order || null === OrderLink::booking_id( $order ) ) {
			return;
		}

		if ( in_array( (string) $to, self::PAID_STATUSES, true ) ) {
			$this->sync( fn() => $this->order_paid( $order ) );
		} elseif ( in_array( (string) $to, self::RELEASING_STATUSES, true ) ) {
			$this->sync( fn() => $this->order_released( $order, (string) $to ) );
		}
	}

	/**
	 * `trmz_booking_status_changed`.
	 *
	 * @param mixed $booking  Booking (new state).
	 * @param mixed $previous Previous status.
	 */
	public function booking_status_changed( $booking, $previous = null ): void {
		if ( self::$syncing || ! $booking instanceof Booking || null === $booking->order_id ) {
			return;
		}
		$order = OrderLink::order( $booking );
		if ( null === $order || OrderLink::booking_id( $order ) !== $booking->id ) {
			return;
		}

		if ( BookingStatus::Expired === $booking->status ) {
			$this->sync( fn() => $this->hold_expired( $order, $booking ) );
		} elseif ( BookingStatus::Cancelled === $booking->status ) {
			$this->sync( fn() => $this->booking_cancelled( $order, $booking ) );
		} elseif ( BookingStatus::Confirmed === $booking->status && BookingStatus::PendingPayment === $previous && $order->needs_payment() ) {
			$this->sync( fn() => $this->booking_confirmed_unpaid( $order, $booking ) );
		}
	}

	/**
	 * The order was cancelled, failed or refunded: cancel the active booking (releases the slot).
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $status New order status.
	 */
	private function order_released( WC_Order $order, string $status ): void {
		$booking = OrderLink::booking( $order, $this->services() );
		if ( null === $booking || ! $booking->status->can_transition_to( BookingStatus::Cancelled ) ) {
			return; // Already inactive (expired, cancelled, completed): nothing to release.
		}

		$this->services()->booking_service()->cancel( (int) $booking->id );
		$order->update_meta_data( self::RELEASED_META, 'order_status' );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: 1: public booking ID, 2: order status name. */
				__( 'Booking %1$s was cancelled and its slot released because the order is now "%2$s".', 'terminarz' ),
				(string) $booking->public_id,
				wc_get_order_status_name( $status )
			)
		);
	}

	/**
	 * The booking was cancelled outside WooCommerce (panel, customer): cancel an unpaid order; a paid one only gets a
	 * note — the refund is a human decision.
	 *
	 * @param WC_Order $order   Order.
	 * @param Booking  $booking Cancelled booking.
	 */
	private function booking_cancelled( WC_Order $order, Booking $booking ): void {
		if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
			$order->update_status(
				'cancelled',
				sprintf(
					/* translators: %s: public booking ID. */
					__( 'Booking %s was cancelled, so this unpaid order was cancelled too.', 'terminarz' ),
					(string) $booking->public_id
				)
			);
			return;
		}
		if ( $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
			return;
		}

		$order->add_order_note(
			sprintf(
				/* translators: %s: public booking ID. */
				__( 'Booking %s was cancelled. The payment was NOT refunded automatically — decide whether to refund it.', 'terminarz' ),
				(string) $booking->public_id
			)
		);
	}

	/**
	 * A booking awaiting payment was confirmed manually: note on the still unpaid order.
	 *
	 * @param WC_Order $order   Order.
	 * @param Booking  $booking Confirmed booking.
	 */
	private function booking_confirmed_unpaid( WC_Order $order, Booking $booking ): void {
		$order->add_order_note(
			sprintf(
				/* translators: %s: public booking ID. */
				__( 'Booking %s was confirmed in the booking panel although this order is not paid.', 'terminarz' ),
				(string) $booking->public_id
			)
		);
	}

	/**
	 * The order was paid.
	 *
	 * @param WC_Order $order Order.
	 */
	private function order_paid( WC_Order $order ): void {
		$services = $this->services();
		$booking  = OrderLink::booking( $order, $services );
		if ( null === $booking ) {
			return;
		}

		$now = $services->clock()->now();
		if ( BookingStatus::PendingPayment === $booking->status && $booking->is_hold_expired( $now ) ) {
			// Paid too late: the slot may already be someone else's. Expire, then try to book it again atomically.
			$booking = $services->booking_service()->change_status( (int) $booking->id, BookingStatus::Expired );
		}

		switch ( $booking->status ) {
			case BookingStatus::PendingPayment:
			case BookingStatus::Pending:
				$services->booking_service()->change_status( (int) $booking->id, BookingStatus::Confirmed );
				$order->add_order_note(
					sprintf(
						/* translators: %s: public booking ID. */
						__( 'Payment received: booking %s confirmed.', 'terminarz' ),
						(string) $booking->public_id
					)
				);
				return;

			case BookingStatus::Expired:
				$this->book_again( $order, $booking );
				return;

			case BookingStatus::Cancelled:
				if ( '' !== (string) $order->get_meta( self::RELEASED_META ) ) {
					$this->book_again( $order, $booking );
				} else {
					$this->needs_attention( $order, $booking, __( 'Payment received for a booking that was cancelled in the booking panel. Contact the customer: refund the payment or arrange another appointment.', 'terminarz' ) );
				}
				return;

			default:
				return; // Confirmed or completed: nothing to do.
		}
	}

	/**
	 * A payment arrived after the slot had been released: book the same slot again if it is still free.
	 *
	 * @param WC_Order $order   Order.
	 * @param Booking  $booking Released booking.
	 */
	private function book_again( WC_Order $order, Booking $booking ): void {
		try {
			$new = $this->services()->booking_service()->rebook( (int) $booking->id )->booking;
		} catch ( SlotUnavailable $e ) {
			$this->needs_attention( $order, $booking, __( 'Payment received after the slot hold had expired, and the slot is no longer available. Contact the customer: refund the payment or arrange another appointment.', 'terminarz' ) );
			return;
		}

		OrderLink::store( $order, $new );
		$order->delete_meta_data( self::RELEASED_META );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: 1: previous public booking ID, 2: new public booking ID. */
				__( 'Payment received after booking %1$s had been released. The slot was still free, so it was booked again and confirmed as booking %2$s.', 'terminarz' ),
				(string) $booking->public_id,
				(string) $new->public_id
			)
		);
	}

	/**
	 * The hold of the booking expired: cancel the unpaid order.
	 *
	 * @param WC_Order $order   Order.
	 * @param Booking  $booking Expired booking.
	 */
	private function hold_expired( WC_Order $order, Booking $booking ): void {
		$order->update_meta_data( self::RELEASED_META, 'expired' );
		$note = sprintf(
			/* translators: %s: public booking ID. */
			__( 'The payment time of booking %s ran out and its slot was released.', 'terminarz' ),
			(string) $booking->public_id
		);

		if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
			$order->update_status( 'cancelled', $note );
			return;
		}
		$order->save();
		$order->add_order_note( $note );
	}

	/**
	 * Puts a paid order on hold and notifies the business (once per order).
	 *
	 * @param WC_Order $order   Order.
	 * @param Booking  $booking Booking.
	 * @param string   $reason  Translated explanation.
	 */
	private function needs_attention( WC_Order $order, Booking $booking, string $reason ): void {
		if ( '' !== (string) $order->get_meta( self::ATTENTION_META ) ) {
			return;
		}
		$order->update_meta_data( self::ATTENTION_META, (string) time() );
		$order->update_status( 'on-hold', $reason );

		$this->notify_business( $order, $booking, $reason );

		/**
		 * Fires when a paid order cannot be matched with a free slot and needs a human decision (refund or another
		 * appointment). The order is on hold; an e-mail was sent to the notification address.
		 *
		 * @param WC_Order $order   Order.
		 * @param Booking  $booking Booking (expired or cancelled).
		 * @param string   $reason  Translated explanation.
		 */
		do_action( 'trmz_payment_needs_attention', $order, $booking, $reason );
	}

	/**
	 * E-mail to the business about an order needing attention.
	 *
	 * @param WC_Order $order   Order.
	 * @param Booking  $booking Booking.
	 * @param string   $reason  Translated explanation.
	 */
	private function notify_business( WC_Order $order, Booking $booking, string $reason ): void {
		$subject = sprintf(
			/* translators: 1: site name, 2: order number. */
			__( '[%1$s] Order #%2$s needs attention', 'terminarz' ),
			wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
			$order->get_order_number()
		);
		$lines = array(
			$reason,
			'',
			/* translators: %s: order number. */
			sprintf( __( 'Order: #%s', 'terminarz' ), $order->get_order_number() ),
			/* translators: %s: public booking ID. */
			sprintf( __( 'Booking: %s', 'terminarz' ), (string) $booking->public_id ),
			/* translators: %s: appointment date and time. */
			sprintf( __( 'Appointment: %s', 'terminarz' ), Labels::datetime( $booking->range->start ) ),
			/* translators: 1: customer name, 2: customer e-mail. */
			sprintf( __( 'Customer: %1$s <%2$s>', 'terminarz' ), $booking->customer->name, $booking->customer->email ),
			'',
			$order->get_edit_order_url(),
		);

		wp_mail( $this->services()->settings()->notification_email(), $subject, implode( "\n", $lines ) );
	}

	/**
	 * Runs a change with the re-entrancy flag set; domain/database errors are logged on the order, never thrown into
	 * WooCommerce (a payment must never fail because of the booking side).
	 *
	 * @param callable(): void $change Change.
	 */
	private function sync( callable $change ): void {
		self::$syncing = true;
		try {
			$change();
		} catch ( DomainError | DatabaseError $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Booking synchronisation failed: ' . $e->getMessage(), array( 'source' => 'terminarz' ) );
			}
		} finally {
			self::$syncing = false;
		}
	}

	/**
	 * Composition root.
	 */
	private function services(): Services {
		return $this->services ?? Services::instance();
	}
}
