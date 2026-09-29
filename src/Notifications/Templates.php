<?php
/**
 * Stored e-mail templates (the `trmz_email_templates` option).
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Notifications;

/**
 * Templates edited in "Terminarz → E-mails", falling back to translatable defaults.
 *
 * Stored value: `[type => ['enabled' => bool, 'subject' => string|null, 'body' => string|null]]`. A missing or null
 * subject/body means "default text" — it is translated at send time, so untouched templates follow the site language.
 * The option is not autoloaded (read only when an e-mail is sent or edited).
 */
final class Templates {

	public const OPTION = 'trmz_email_templates';

	/**
	 * Maximum subject length (characters).
	 */
	public const SUBJECT_MAX = 255;

	/**
	 * Template of a message: stored text or the default.
	 *
	 * @param MessageType $type Message.
	 */
	public function get( MessageType $type ): Template {
		$stored  = $this->stored()[ $type->value ] ?? array();
		$default = self::default_template( $type );

		return new Template(
			(bool) ( $stored['enabled'] ?? true ),
			isset( $stored['subject'] ) && is_string( $stored['subject'] ) ? $stored['subject'] : $default->subject,
			isset( $stored['body'] ) && is_string( $stored['body'] ) ? $stored['body'] : $default->body
		);
	}

	/**
	 * Whether the subject or body of a message differs from the default.
	 *
	 * @param MessageType $type Message.
	 */
	public function is_customized( MessageType $type ): bool {
		$stored = $this->stored()[ $type->value ] ?? array();
		return isset( $stored['subject'] ) || isset( $stored['body'] );
	}

	/**
	 * Saves a template. The subject becomes single-line plain text, the body is filtered by `wp_kses_post()`.
	 * Text equal to the default is stored as "default" (keeps following translations).
	 *
	 * @param MessageType $type     Message.
	 * @param Template    $template Template.
	 * @return Template Saved (sanitized) template.
	 */
	public function save( MessageType $type, Template $template ): Template {
		$subject  = self::sanitize_subject( $template->subject );
		$body     = self::sanitize_body( $template->body );
		$default  = self::default_template( $type );
		$pristine = self::sanitize_body( $default->body );

		$all                 = $this->stored();
		$all[ $type->value ] = array(
			'enabled' => $template->enabled,
			'subject' => $default->subject === $subject ? null : $subject,
			'body'    => $pristine === $body ? null : $body,
		);
		update_option( self::OPTION, $all, false );

		return $this->get( $type );
	}

	/**
	 * Turns a message on or off, keeping its text.
	 *
	 * @param MessageType $type    Message.
	 * @param bool        $enabled Whether it is sent.
	 */
	public function set_enabled( MessageType $type, bool $enabled ): void {
		$all                            = $this->stored();
		$all[ $type->value ]            = $all[ $type->value ] ?? array();
		$all[ $type->value ]['enabled'] = $enabled;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Restores the default subject and body (the on/off switch is kept).
	 *
	 * @param MessageType $type Message.
	 */
	public function reset( MessageType $type ): void {
		$all = $this->stored();
		if ( ! isset( $all[ $type->value ] ) ) {
			return;
		}
		$all[ $type->value ]['subject'] = null;
		$all[ $type->value ]['body']    = null;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Single-line plain text subject.
	 *
	 * @param string $subject Raw subject.
	 */
	public static function sanitize_subject( string $subject ): string {
		$subject = sanitize_text_field( $subject );
		return mb_substr( $subject, 0, self::SUBJECT_MAX );
	}

	/**
	 * HTML body allowed in posts.
	 *
	 * @param string $body Raw body.
	 */
	public static function sanitize_body( string $body ): string {
		return trim( wp_kses_post( $body ) );
	}

	/**
	 * Translatable default template.
	 *
	 * @param MessageType $type Message.
	 */
	public static function default_template( MessageType $type ): Template {
		$details = self::details_block();

		return match ( $type ) {
			MessageType::CustomerPending => new Template(
				true,
				/* translators: Default e-mail subject. Keep the {placeholders} unchanged. */
				__( '[{site_name}] We have received your booking request', 'terminarz' ),
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				'<p>' . __( 'Hello {customer_name},', 'terminarz' ) . '</p>'
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				. '<p>' . __( 'Thank you for your booking request. We will confirm it by e-mail shortly.', 'terminarz' ) . '</p>'
				. $details
			),
			MessageType::CustomerConfirmed => new Template(
				true,
				/* translators: Default e-mail subject. Keep the {placeholders} unchanged. */
				__( '[{site_name}] Your booking is confirmed: {start_date} {start_time}', 'terminarz' ),
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				'<p>' . __( 'Hello {customer_name},', 'terminarz' ) . '</p>'
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				. '<p>' . __( 'Your booking is confirmed. We look forward to seeing you.', 'terminarz' ) . '</p>'
				. $details
			),
			MessageType::CustomerCancelled => new Template(
				true,
				/* translators: Default e-mail subject. Keep the {placeholders} unchanged. */
				__( '[{site_name}] Your booking has been cancelled', 'terminarz' ),
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				'<p>' . __( 'Hello {customer_name},', 'terminarz' ) . '</p>'
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				. '<p>' . __( 'Your booking has been cancelled. If this is a mistake, please make a new booking or contact us.', 'terminarz' ) . '</p>'
				. $details
			),
			MessageType::CustomerReminder => new Template(
				true,
				/* translators: Default e-mail subject. Keep the {placeholders} unchanged. */
				__( '[{site_name}] Reminder: {service_name} on {start_date} at {start_time}', 'terminarz' ),
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				'<p>' . __( 'Hello {customer_name},', 'terminarz' ) . '</p>'
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				. '<p>' . __( 'This is a reminder of your upcoming appointment.', 'terminarz' ) . '</p>'
				. $details
			),
			MessageType::AdminNew => new Template(
				true,
				/* translators: Default e-mail subject. Keep the {placeholders} unchanged. */
				__( '[{site_name}] New booking: {service_name}, {start_date} {start_time}', 'terminarz' ),
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				'<p>' . __( 'A new booking has been placed ({status}).', 'terminarz' ) . '</p>'
				. $details
				. self::customer_block()
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				. '<p><a href="{admin_booking_url}">' . __( 'View the booking in the admin panel', 'terminarz' ) . '</a></p>'
			),
			MessageType::AdminCancelled => new Template(
				true,
				/* translators: Default e-mail subject. Keep the {placeholders} unchanged. */
				__( '[{site_name}] Booking cancelled: {service_name}, {start_date} {start_time}', 'terminarz' ),
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				'<p>' . __( 'A booking has been cancelled and its slot is free again.', 'terminarz' ) . '</p>'
				. $details
				. self::customer_block()
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				. '<p><a href="{admin_booking_url}">' . __( 'View the booking in the admin panel', 'terminarz' ) . '</a></p>'
			),
			MessageType::AdminPaymentNeedsAttention => new Template(
				true,
				/* translators: Default e-mail subject. Keep the {placeholders} unchanged. */
				__( '[{site_name}] Order #{order_number} needs attention', 'terminarz' ),
				'<p>{reason}</p>'
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				. '<p>' . __( 'Order: #{order_number}', 'terminarz' ) . '<br />'
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				. __( 'Booking: {booking_id}', 'terminarz' ) . '</p>'
				. $details
				. self::customer_block()
				/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
				. '<p><a href="{order_url}">' . __( 'Open the order', 'terminarz' ) . '</a></p>'
			),
		};
	}

	/**
	 * Appointment details paragraph shared by the defaults.
	 */
	private static function details_block(): string {
		return '<p>'
			/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
			. __( 'Service: {service_name}', 'terminarz' ) . '<br />'
			/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
			. __( 'With: {resource_name}', 'terminarz' ) . '<br />'
			/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
			. __( 'Date: {start_date}, {start_time}–{end_time}', 'terminarz' ) . '<br />'
			/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
			. __( 'Booking number: {booking_id}', 'terminarz' )
			. '</p>';
	}

	/**
	 * Customer details paragraph of business e-mails.
	 */
	private static function customer_block(): string {
		return '<p>'
			/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
			. __( 'Customer: {customer_name}', 'terminarz' ) . '<br />'
			/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
			. __( 'E-mail: {customer_email}', 'terminarz' ) . '<br />'
			/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
			. __( 'Phone: {customer_phone}', 'terminarz' ) . '<br />'
			/* translators: Default e-mail text. Keep the {placeholders} unchanged. */
			. __( 'Note: {customer_note}', 'terminarz' )
			. '</p>';
	}

	/**
	 * Stored option value.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function stored(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$valid = array();
		foreach ( $stored as $key => $value ) {
			if ( is_string( $key ) && null !== MessageType::tryFrom( $key ) && is_array( $value ) ) {
				$valid[ $key ] = $value;
			}
		}
		return $valid;
	}
}
