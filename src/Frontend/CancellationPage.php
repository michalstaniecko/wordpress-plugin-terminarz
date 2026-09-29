<?php
/**
 * Public page where customers cancel a booking with the link from their e-mail.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Frontend;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Admin\Labels;
use Terminarz\Application\BookingService;
use Terminarz\Application\CancellationRefused;
use Terminarz\Domain\Model\Booking;
use Terminarz\Infrastructure\Module;
use Terminarz\Infrastructure\Services;
use Terminarz\Notifications\BookingPlaceholders;
use Terminarz\Notifications\MessageType;
use Terminarz\Rest\RequestLimit;
use WP_Error;

/**
 * `https://site/?trmz_cancel=<public_id>&token=<token>` (no rewrite rules, works with any permalink setting):
 *
 * - GET shows the booking and a "Cancel booking" button — nothing changes on GET, so link previews and mail scanners
 *   that prefetch links cannot cancel anything;
 * - POST (token in a hidden field) cancels, respecting the customer cancellation limit from the settings.
 *
 * The page is minimal and self-contained (no theme), `noindex`, not cacheable and sent with
 * `Referrer-Policy: no-referrer` (the token never leaks through the Referer header). Every POST and every GET with
 * an invalid link counts against the `booking_cancel` request limit (`RequestLimit`, 429 + `Retry-After`).
 */
final class CancellationPage implements Module {

	/**
	 * Query argument carrying the public booking ID.
	 */
	public const QUERY_VAR = 'trmz_cancel';

	/**
	 * Query argument / form field carrying the token.
	 */
	public const TOKEN_VAR = 'token';

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
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 1 );
	}

	/**
	 * Cancellation link of a booking.
	 *
	 * @param Booking $booking Booking.
	 * @param string  $token   Token (`BookingService::cancel_token()`).
	 */
	public static function url( Booking $booking, string $token ): string {
		return add_query_arg(
			array(
				self::QUERY_VAR => (string) $booking->public_id,
				self::TOKEN_VAR => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * `template_redirect`: serves the page when the query argument is present, then stops.
	 */
	public function template_redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public page authorised by the token.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		// phpcs:disable WordPress.Security.NonceVerification -- Public page authorised by the token (logged-out customers have no nonce).
		$query = wp_unslash( $_GET );
		$post  = wp_unslash( $_POST );
		// phpcs:enable WordPress.Security.NonceVerification
		$page = $this->respond( $method, is_array( $query ) ? $query : array(), is_array( $post ) ? $post : array() );

		status_header( $page['status'] );
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'X-Content-Type-Options: nosniff' );
		if ( $page['retry_after'] > 0 ) {
			header( 'Retry-After: ' . $page['retry_after'] );
		}
		echo $this->document( $page['title'], $page['body'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in this class.
		exit;
	}

	/**
	 * Handles a request and describes the page to show (testable without output).
	 *
	 * @param string               $method HTTP method.
	 * @param array<string, mixed> $query  Unslashed query arguments.
	 * @param array<string, mixed> $post   Unslashed form fields.
	 * @return array{status: int, title: string, body: string, retry_after: int, booking: Booking|null, cancelled: bool}
	 */
	public function respond( string $method, array $query, array $post ): array {
		$public_id = self::field( $query, self::QUERY_VAR );
		$limit     = $this->services()->settings()->customer_cancel_limit_hours();
		$service   = $this->services()->booking_service();

		if ( 'POST' === $method ) {
			$allowed = RequestLimit::check( RequestLimit::BOOKING_CANCEL );
			if ( $allowed instanceof WP_Error ) {
				return $this->rate_limited( $allowed );
			}
			try {
				$booking = $service->cancel_by_customer( $public_id, self::field( $post, self::TOKEN_VAR ), $limit );
			} catch ( CancellationRefused $e ) {
				return $this->refused( $e );
			}
			return $this->page(
				200,
				__( 'Booking cancelled', 'terminarz' ),
				'<p>' . esc_html__( 'Your booking has been cancelled. The time slot is now available to others.', 'terminarz' ) . '</p>'
				. $this->details( $booking )
				. ( $this->services()->mailer()->templates()->get( MessageType::CustomerCancelled )->enabled
					? '<p>' . esc_html__( 'A confirmation has been sent to your e-mail address.', 'terminarz' ) . '</p>'
					: '' ),
				$booking,
				true
			);
		}

		$token = self::field( $query, self::TOKEN_VAR );
		try {
			$booking = $service->check_customer_cancellation( $public_id, $token, $limit );
		} catch ( CancellationRefused $e ) {
			if ( CancellationRefused::INVALID_TOKEN === $e->reason ) {
				$allowed = RequestLimit::check( RequestLimit::BOOKING_CANCEL );
				if ( $allowed instanceof WP_Error ) {
					return $this->rate_limited( $allowed );
				}
			}
			return $this->refused( $e );
		}

		$deadline = BookingService::cancellation_deadline( $booking, $limit );
		$form     = sprintf(
			'<form method="post" action="%1$s"><input type="hidden" name="%2$s" value="%3$s" /><p><button type="submit" class="trmz-button">%4$s</button></p></form>',
			esc_url( add_query_arg( self::QUERY_VAR, $public_id, home_url( '/' ) ) ),
			esc_attr( self::TOKEN_VAR ),
			esc_attr( $token ),
			esc_html__( 'Cancel booking', 'terminarz' )
		);

		return $this->page(
			200,
			__( 'Cancel your booking', 'terminarz' ),
			'<p>' . esc_html__( 'Do you want to cancel this booking?', 'terminarz' ) . '</p>'
			. $this->details( $booking )
			. '<p>' . esc_html(
				sprintf(
					/* translators: %s: date and time. */
					__( 'You can cancel online until %s.', 'terminarz' ),
					Labels::datetime( $deadline )
				)
			) . '</p>'
			. $form
			. '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Keep my booking and go to the website', 'terminarz' ) . '</a></p>',
			$booking
		);
	}

	/**
	 * Page for a refused cancellation.
	 *
	 * @param CancellationRefused $e Reason.
	 * @return array{status: int, title: string, body: string, retry_after: int, booking: Booking|null, cancelled: bool}
	 */
	private function refused( CancellationRefused $e ): array {
		$contact = '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Go to the website', 'terminarz' ) . '</a></p>';

		switch ( $e->reason ) {
			case CancellationRefused::NOT_ACTIVE:
				return $this->page(
					200,
					__( 'Booking not active', 'terminarz' ),
					'<p>' . esc_html__( 'This booking is already cancelled or no longer active, so there is nothing to cancel.', 'terminarz' ) . '</p>'
					. ( null === $e->booking ? '' : $this->details( $e->booking ) ) . $contact,
					$e->booking
				);

			case CancellationRefused::TOO_LATE:
				return $this->page(
					200,
					__( 'Too late to cancel online', 'terminarz' ),
					'<p>' . esc_html__( 'The time for cancelling this booking online has passed. Please contact us directly.', 'terminarz' ) . '</p>'
					. ( null === $e->booking ? '' : $this->details( $e->booking ) ) . $contact,
					$e->booking
				);

			default:
				return $this->page(
					404,
					__( 'Invalid link', 'terminarz' ),
					'<p>' . esc_html__( 'This cancellation link is not valid. Please use the link from your latest e-mail exactly as it was sent, or contact us.', 'terminarz' ) . '</p>' . $contact
				);
		}
	}

	/**
	 * Page for a client over the request limit.
	 *
	 * @param WP_Error $error Limit error.
	 * @return array{status: int, title: string, body: string, retry_after: int, booking: Booking|null, cancelled: bool}
	 */
	private function rate_limited( WP_Error $error ): array {
		$data = $error->get_error_data();
		$page = $this->page( 429, __( 'Too many attempts', 'terminarz' ), '<p>' . esc_html( $error->get_error_message() ) . '</p>' );

		$page['retry_after'] = is_array( $data ) ? (int) ( $data['retry_after'] ?? 0 ) : 0;
		return $page;
	}

	/**
	 * Page description.
	 *
	 * @param int          $status    HTTP status.
	 * @param string       $title     Plain-text title.
	 * @param string       $body      Safe HTML.
	 * @param Booking|null $booking   Booking shown.
	 * @param bool         $cancelled Whether the request cancelled the booking.
	 * @return array{status: int, title: string, body: string, retry_after: int, booking: Booking|null, cancelled: bool}
	 */
	private function page( int $status, string $title, string $body, ?Booking $booking = null, bool $cancelled = false ): array {
		return array(
			'status'      => $status,
			'title'       => $title,
			'body'        => $body,
			'retry_after' => 0,
			'booking'     => $booking,
			'cancelled'   => $cancelled,
		);
	}

	/**
	 * Appointment details (no personal data beyond what the link holder already knows).
	 *
	 * @param Booking $booking Booking.
	 */
	private function details( Booking $booking ): string {
		$values = ( new BookingPlaceholders( $this->services() ) )->for_booking( $booking );
		$rows   = array(
			__( 'Service', 'terminarz' ) => $values['service_name'],
			__( 'With', 'terminarz' )    => $values['resource_name'],
			__( 'Date', 'terminarz' )    => $values['start_date'],
			__( 'Time', 'terminarz' )    => $values['start_time'] . '–' . $values['end_time'],
			__( 'Status', 'terminarz' )  => $values['status'],
		);

		$html = '<dl class="trmz-details">';
		foreach ( $rows as $label => $value ) {
			$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		return $html . '</dl>';
	}

	/**
	 * Complete HTML document (filterable with `trmz_cancel_page_html`).
	 *
	 * @param string $title Plain-text title.
	 * @param string $body  Safe HTML.
	 */
	public function document( string $title, string $body ): string {
		$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$html = '<!DOCTYPE html><html ' . get_language_attributes() . '><head>'
			. '<meta charset="' . esc_attr( (string) get_option( 'blog_charset', 'UTF-8' ) ) . '" />'
			. '<meta name="viewport" content="width=device-width, initial-scale=1" />'
			. '<meta name="robots" content="noindex, nofollow" />'
			. '<meta name="referrer" content="no-referrer" />'
			. '<title>' . esc_html( $title . ' – ' . $site ) . '</title>'
			. '<style>'
			. 'body{margin:0;background:#f4f4f5;color:#18181b;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}'
			. 'main{max-width:560px;margin:40px auto;padding:24px 28px;background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1)}'
			. '.trmz-site{margin:0 0 16px;font-size:14px;color:#52525b}h1{margin:0 0 16px;font-size:24px;line-height:1.3}'
			. '.trmz-details{display:grid;grid-template-columns:max-content 1fr;gap:4px 16px;margin:16px 0}.trmz-details dt{font-weight:600}.trmz-details dd{margin:0}'
			. '.trmz-button{padding:10px 20px;font-size:16px;border:0;border-radius:6px;background:#b91c1c;color:#fff;cursor:pointer}'
			. '.trmz-button:focus-visible,a:focus-visible{outline:3px solid #1d4ed8;outline-offset:2px}a{color:#1d4ed8}'
			. '@media (max-width:600px){main{margin:0;border-radius:0}}'
			. '</style></head><body><main>'
			. '<p class="trmz-site">' . esc_html( $site ) . '</p>'
			. '<h1>' . esc_html( $title ) . '</h1>'
			. $body
			. '</main></body></html>';

		/**
		 * Filters the HTML of the customer cancellation page.
		 *
		 * @param string $html  Complete HTML document.
		 * @param string $title Page title.
		 * @param string $body  Page content (safe HTML).
		 */
		return (string) apply_filters( 'trmz_cancel_page_html', $html, $title, $body );
	}

	/**
	 * Hex-only value of a request field ('' when missing or malformed).
	 *
	 * @param array<string, mixed> $source Request fields.
	 * @param string               $key    Field.
	 */
	private static function field( array $source, string $key ): string {
		$value = $source[ $key ] ?? '';
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return 1 === preg_match( '/^[0-9a-f]{1,64}$/', $value ) ? $value : '';
	}

	/**
	 * Composition root.
	 */
	private function services(): Services {
		return $this->services ?? Services::instance();
	}
}
