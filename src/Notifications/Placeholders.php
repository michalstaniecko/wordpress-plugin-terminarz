<?php
/**
 * Placeholders available in e-mail templates.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Notifications;

/**
 * Names and descriptions of the `{placeholders}` (admin help) and sample values (preview, test e-mail).
 */
final class Placeholders {

	/**
	 * Placeholders whose value is a URL (escaped with `esc_url()` in the HTML body).
	 */
	public const URLS = array( 'cancel_url', 'site_url', 'admin_booking_url', 'order_url' );

	/**
	 * Translated descriptions keyed by placeholder name (without braces).
	 *
	 * @return array<string, string>
	 */
	public static function descriptions(): array {
		return array(
			'customer_name'     => __( 'Customer name', 'terminarz' ),
			'customer_email'    => __( 'Customer e-mail', 'terminarz' ),
			'customer_phone'    => __( 'Customer phone', 'terminarz' ),
			'customer_note'     => __( 'Note from the customer', 'terminarz' ),
			'service_name'      => __( 'Service', 'terminarz' ),
			'resource_name'     => __( 'Resource (person, room, device)', 'terminarz' ),
			'start_date'        => __( 'Appointment date (site time zone)', 'terminarz' ),
			'start_time'        => __( 'Appointment start time', 'terminarz' ),
			'end_time'          => __( 'Appointment end time', 'terminarz' ),
			'price'             => __( 'Service price', 'terminarz' ),
			'status'            => __( 'Booking status', 'terminarz' ),
			'booking_id'        => __( 'Booking number (public ID)', 'terminarz' ),
			'cancel_url'        => __( 'Link the customer can use to cancel the booking', 'terminarz' ),
			'site_name'         => __( 'Site name', 'terminarz' ),
			'site_url'          => __( 'Site address', 'terminarz' ),
			'admin_booking_url' => __( 'Link to the booking in the admin panel (business e-mails)', 'terminarz' ),
			'order_number'      => __( 'WooCommerce order number (payment e-mails)', 'terminarz' ),
			'order_url'         => __( 'Link to the WooCommerce order (payment e-mails)', 'terminarz' ),
			'reason'            => __( 'Explanation of what needs attention (payment e-mails)', 'terminarz' ),
		);
	}

	/**
	 * Placeholder names.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array_keys( self::descriptions() );
	}

	/**
	 * Every placeholder set to '' (a message never shows a raw `{placeholder}` of a known name).
	 *
	 * @return array<string, string>
	 */
	public static function empty_values(): array {
		return array_fill_keys( self::names(), '' );
	}

	/**
	 * Example values for previews and test e-mails.
	 *
	 * @return array<string, string>
	 */
	public static function sample_values(): array {
		$start = ( new \DateTimeImmutable( 'tomorrow 10:00', wp_timezone() ) )->getTimestamp();
		$date  = (string) get_option( 'date_format', 'Y-m-d' );
		$time  = (string) get_option( 'time_format', 'H:i' );

		return array_merge(
			self::empty_values(),
			array(
				'customer_name'     => __( 'Jane Doe', 'terminarz' ),
				'customer_email'    => 'jane.doe@example.com',
				'customer_phone'    => '+48 600 000 000',
				'customer_note'     => __( 'This is an example note.', 'terminarz' ),
				'service_name'      => __( 'Example service', 'terminarz' ),
				'resource_name'     => __( 'Example resource', 'terminarz' ),
				'start_date'        => (string) wp_date( $date, $start ),
				'start_time'        => (string) wp_date( $time, $start ),
				'end_time'          => (string) wp_date( $time, $start + HOUR_IN_SECONDS ),
				'price'             => '150.00',
				'status'            => _x( 'Confirmed', 'booking status', 'terminarz' ),
				'booking_id'        => '0123456789abcdef0123456789abcdef',
				'cancel_url'        => home_url( '/' ),
				'site_name'         => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
				'site_url'          => home_url( '/' ),
				'admin_booking_url' => admin_url( 'admin.php?page=trmz-bookings' ),
				'order_number'      => '1234',
				'order_url'         => admin_url(),
				'reason'            => __( 'This is an example explanation.', 'terminarz' ),
			)
		);
	}
}
