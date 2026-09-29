<?php
/**
 * Sends template-based e-mails.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Notifications;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Model\Booking;
use Terminarz\Infrastructure\Services;

/**
 * Renders a message from its template and sends it with `wp_mail()` as HTML.
 *
 * The HTML content type is passed in the headers of each call (never through the global `wp_mail_content_type`
 * filter), so other plugins' e-mails are not affected. Disabled messages are skipped.
 */
final class Mailer {

	/**
	 * Templates.
	 *
	 * @var Templates
	 */
	private readonly Templates $templates;

	/**
	 * Renderer.
	 *
	 * @var Renderer
	 */
	private readonly Renderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param Services       $services  Composition root.
	 * @param Templates|null $templates Templates; null = the stored ones.
	 * @param Renderer|null  $renderer  Renderer; null = the default one.
	 */
	public function __construct( private readonly Services $services, ?Templates $templates = null, ?Renderer $renderer = null ) {
		$this->templates = $templates ?? new Templates();
		$this->renderer  = $renderer ?? new Renderer();
	}

	/**
	 * Templates.
	 */
	public function templates(): Templates {
		return $this->templates;
	}

	/**
	 * Sends a booking message to its recipient: the customer or the notification e-mail of the business.
	 *
	 * @param MessageType           $type    Message.
	 * @param Booking               $booking Booking.
	 * @param array<string, string> $extra   Additional placeholder values.
	 * @return bool Whether `wp_mail()` accepted the message (false when disabled, no recipient or failure).
	 */
	public function send_for_booking( MessageType $type, Booking $booking, array $extra = array() ): bool {
		$values = ( new BookingPlaceholders( $this->services ) )->for_booking( $booking, $extra );

		if ( $type->is_for_admin() ) {
			$to       = $this->services->settings()->notification_email();
			$reply_to = $booking->customer->email;
		} else {
			$to       = $booking->customer->email;
			$reply_to = $this->services->settings()->notification_email();
		}

		return $this->send( $type, $to, $values, $reply_to, $booking );
	}

	/**
	 * Renders and sends a message.
	 *
	 * @param MessageType           $type     Message.
	 * @param string                $to       Recipient.
	 * @param array<string, string> $values   Placeholder values.
	 * @param string                $reply_to Reply-To address ('' = none).
	 * @param Booking|null          $booking  Booking the message is about (passed to filters).
	 * @param bool                  $force    Send even when the message is disabled (test e-mail).
	 */
	public function send( MessageType $type, string $to, array $values, string $reply_to = '', ?Booking $booking = null, bool $force = false ): bool {
		$template = $this->templates->get( $type );
		if ( ! $template->enabled && ! $force ) {
			return false;
		}
		$to = sanitize_email( $to );
		if ( '' === $to || false === is_email( $to ) ) {
			return false;
		}

		$message = $this->renderer->render( $template, $values );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$reply   = sanitize_email( $reply_to );
		if ( '' !== $reply && false !== is_email( $reply ) && $reply !== $to ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		$mail = array(
			'to'      => $to,
			'subject' => $message['subject'],
			'message' => $message['html'],
			'headers' => $headers,
		);

		/**
		 * Filters a Terminarz e-mail before it is sent. Return false to skip it.
		 *
		 * @param array{to: string, subject: string, message: string, headers: string[]}|false $mail    E-mail arguments of wp_mail().
		 * @param string                                                                      $type    Message type, e.g. "customer_confirmed".
		 * @param Booking|null                                                                $booking Booking, if any.
		 */
		$mail = apply_filters( 'trmz_email', $mail, $type->value, $booking );
		if ( ! is_array( $mail ) || ! isset( $mail['to'], $mail['subject'], $mail['message'] ) ) {
			return false;
		}

		return (bool) wp_mail( $mail['to'], (string) $mail['subject'], (string) $mail['message'], $mail['headers'] ?? array() );
	}
}
