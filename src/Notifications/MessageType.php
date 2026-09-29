<?php
/**
 * Kinds of e-mail notifications.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Notifications;

/**
 * Every e-mail the plugin sends. The value is the key in the `trmz_email_templates` option.
 */
enum MessageType: string {

	case CustomerPending            = 'customer_pending';
	case CustomerConfirmed          = 'customer_confirmed';
	case CustomerCancelled          = 'customer_cancelled';
	case CustomerReminder           = 'customer_reminder';
	case AdminNew                   = 'admin_new';
	case AdminCancelled             = 'admin_cancelled';
	case AdminPaymentNeedsAttention = 'admin_payment_needs_attention';

	/**
	 * Whether the message goes to the business (notification e-mail) rather than to the customer.
	 */
	public function is_for_admin(): bool {
		return str_starts_with( $this->value, 'admin_' );
	}

	/**
	 * Translated name of the message (admin screen).
	 */
	public function label(): string {
		return match ( $this ) {
			self::CustomerPending => __( 'Booking request received (customer)', 'terminarz' ),
			self::CustomerConfirmed => __( 'Booking confirmed (customer)', 'terminarz' ),
			self::CustomerCancelled => __( 'Booking cancelled (customer)', 'terminarz' ),
			self::CustomerReminder => __( 'Appointment reminder (customer)', 'terminarz' ),
			self::AdminNew => __( 'New booking (business)', 'terminarz' ),
			self::AdminCancelled => __( 'Booking cancelled (business)', 'terminarz' ),
			self::AdminPaymentNeedsAttention => __( 'Payment needs attention (business)', 'terminarz' ),
		};
	}

	/**
	 * Translated description of when the message is sent (admin screen).
	 */
	public function description(): string {
		return match ( $this ) {
			self::CustomerPending => __( 'Sent to the customer when a booking waits for confirmation by staff.', 'terminarz' ),
			self::CustomerConfirmed => __( 'Sent to the customer when a booking is confirmed (automatically, by staff or after payment).', 'terminarz' ),
			self::CustomerCancelled => __( 'Sent to the customer when a confirmed or pending booking is cancelled.', 'terminarz' ),
			self::CustomerReminder => __( 'Sent to the customer before a confirmed appointment (see the reminder setting).', 'terminarz' ),
			self::AdminNew => __( 'Sent to the notification e-mail when a new booking is placed (after payment for paid services).', 'terminarz' ),
			self::AdminCancelled => __( 'Sent to the notification e-mail when a confirmed or pending booking is cancelled.', 'terminarz' ),
			self::AdminPaymentNeedsAttention => __( 'Sent to the notification e-mail when a payment arrives for a slot that is no longer available.', 'terminarz' ),
		};
	}
}
