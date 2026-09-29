<?php
/**
 * Sends booking e-mails in reaction to booking events.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Notifications;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Application\BookingService;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Infrastructure\Database\DatabaseError;
use Terminarz\Infrastructure\Module;
use Terminarz\Infrastructure\Services;

/**
 * Listens to booking events and sends the matching e-mails, each at most once per booking (notification log):
 *
 * | Event                                              | Customer              | Business     |
 * |----------------------------------------------------|-----------------------|--------------|
 * | created `pending`                                  | `customer_pending`    | `admin_new`  |
 * | created `confirmed` (auto-confirm, rebooked paid)  | `customer_confirmed`  | `admin_new`  |
 * | created `pending_payment`                          | —                     | —            |
 * | `pending_payment` → `pending`                      | `customer_pending`    | `admin_new`  |
 * | `pending`/`pending_payment` → `confirmed`          | `customer_confirmed`  | `admin_new`¹ |
 * | `pending`/`confirmed` → `cancelled`                | `customer_cancelled`  | `admin_cancelled` |
 *
 * ¹ Deduplicated: the business hears about a booking once, when it is placed (after payment for paid services).
 * A booking awaiting payment is not confirmed yet, so nothing is sent until the payment arrives, and nothing is sent
 * when it expires or is cancelled before payment (WooCommerce informs about the order itself).
 */
final class BookingNotifier implements Module {

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
		add_action( BookingService::EVENT_CREATED, array( $this, 'booking_created' ), 30 );
		add_action( BookingService::EVENT_STATUS_CHANGED, array( $this, 'booking_status_changed' ), 30, 2 );
	}

	/**
	 * `trmz_booking_created`.
	 *
	 * @param mixed $booking Booking.
	 */
	public function booking_created( $booking ): void {
		if ( ! $booking instanceof Booking ) {
			return;
		}

		if ( BookingStatus::Pending === $booking->status ) {
			$this->notify( MessageType::CustomerPending, $booking );
			$this->notify( MessageType::AdminNew, $booking );
		} elseif ( BookingStatus::Confirmed === $booking->status ) {
			$this->notify( MessageType::CustomerConfirmed, $booking );
			$this->notify( MessageType::AdminNew, $booking );
		}
	}

	/**
	 * `trmz_booking_status_changed`.
	 *
	 * @param mixed $booking  Booking (new state).
	 * @param mixed $previous Previous status.
	 */
	public function booking_status_changed( $booking, $previous = null ): void {
		if ( ! $booking instanceof Booking || ! $previous instanceof BookingStatus ) {
			return;
		}

		if ( BookingStatus::Confirmed === $booking->status && in_array( $previous, array( BookingStatus::Pending, BookingStatus::PendingPayment ), true ) ) {
			$this->notify( MessageType::CustomerConfirmed, $booking );
			$this->notify( MessageType::AdminNew, $booking );
		} elseif ( BookingStatus::Pending === $booking->status && BookingStatus::PendingPayment === $previous ) {
			$this->notify( MessageType::CustomerPending, $booking );
			$this->notify( MessageType::AdminNew, $booking );
		} elseif ( BookingStatus::Cancelled === $booking->status && in_array( $previous, array( BookingStatus::Pending, BookingStatus::Confirmed ), true ) ) {
			$this->notify( MessageType::CustomerCancelled, $booking );
			$this->notify( MessageType::AdminCancelled, $booking );
		}
	}

	/**
	 * Sends a message about a booking once (claimed in the notification log first; released when sending fails).
	 * Disabled messages are neither sent nor recorded. Never throws: a notification problem must not break a booking.
	 *
	 * @param MessageType           $type    Message.
	 * @param Booking               $booking Booking.
	 * @param string                $key     Deduplication key; '' = the message type.
	 * @param array<string, string> $extra   Additional placeholder values.
	 * @return bool Whether the e-mail was sent now.
	 */
	public function notify( MessageType $type, Booking $booking, string $key = '', array $extra = array() ): bool {
		if ( null === $booking->id ) {
			return false;
		}
		$services = $this->services();
		if ( ! $services->mailer()->templates()->get( $type )->enabled ) {
			return false;
		}

		$key = '' === $key ? $type->value : $key;
		$log = $services->notification_log();
		try {
			if ( ! $log->claim( $booking->id, $key, $services->clock()->now() ) ) {
				return false;
			}
			$sent = $services->mailer()->send_for_booking( $type, $booking, $extra );
			if ( ! $sent ) {
				$log->release( $booking->id, $key );
			}
		} catch ( DatabaseError $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostics.
				error_log( 'Terminarz: notification failed: ' . $e->getMessage() );
			}
			return false;
		}

		return $sent;
	}

	/**
	 * Composition root.
	 */
	private function services(): Services {
		return $this->services ?? Services::instance();
	}
}
