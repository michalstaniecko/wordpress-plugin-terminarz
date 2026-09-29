<?php
/**
 * Integration with the WordPress personal data tools (export, erasure, privacy policy guide).
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Exception\DomainError;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\Customer;
use Terminarz\Infrastructure\Database\DatabaseError;
use Terminarz\Infrastructure\Module;
use Terminarz\Infrastructure\Services;

/**
 * Registers a personal data exporter and eraser for bookings, matched by the customer's e-mail address, and
 * suggests a privacy policy paragraph.
 *
 * Erasure anonymises the customer data and keeps the booking row (statistics, resource history). Upcoming active
 * bookings (pending, awaiting payment, confirmed, starting in the future) are retained untouched — the business still
 * needs to contact the customer; the eraser reports them so the administrator can cancel them and run the erasure again.
 */
final class Privacy implements Module {

	public const EXPORTER_ID = 'terminarz-bookings';
	public const ERASER_ID   = 'terminarz-bookings';

	/**
	 * Items processed per exporter/eraser call.
	 */
	public const PAGE_SIZE = 50;

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * `wp_privacy_personal_data_exporters` filter.
	 *
	 * @param array<string, mixed> $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public function register_exporter( $exporters ): array {
		$exporters                      = is_array( $exporters ) ? $exporters : array();
		$exporters[ self::EXPORTER_ID ] = array(
			'exporter_friendly_name' => __( 'Terminarz bookings', 'terminarz' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * `wp_privacy_personal_data_erasers` filter.
	 *
	 * @param array<string, mixed> $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public function register_eraser( $erasers ): array {
		$erasers                    = is_array( $erasers ) ? $erasers : array();
		$erasers[ self::ERASER_ID ] = array(
			'eraser_friendly_name' => __( 'Terminarz bookings', 'terminarz' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exporter callback: one page of the customer's bookings.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page, starting at 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( $email, $page = 1 ): array {
		$email    = sanitize_email( (string) $email );
		$page     = max( 1, (int) $page );
		$services = Services::instance();
		$bookings = $services->bookings()->find_by_customer_email( $email, self::PAGE_SIZE, ( $page - 1 ) * self::PAGE_SIZE );

		$data = array();
		foreach ( $bookings as $booking ) {
			$data[] = array(
				'group_id'          => 'terminarz-bookings',
				'group_label'       => __( 'Bookings', 'terminarz' ),
				'group_description' => __( 'Appointments booked with this e-mail address.', 'terminarz' ),
				'item_id'           => 'terminarz-booking-' . (string) $booking->public_id,
				'data'              => $this->export_fields( $booking ),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $bookings ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Eraser callback: anonymises one page of the customer's past or inactive bookings.
	 *
	 * Anonymised rows no longer match the address and retained (upcoming active) rows are excluded from the query,
	 * so every call reads from offset 0 — the page number is irrelevant.
	 *
	 * @param string $email E-mail address.
	 * @param int    $page  Page, starting at 1 (unused, see above).
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( $email, $page = 1 ): array {
		unset( $page );
		$email    = sanitize_email( (string) $email );
		$services = Services::instance();
		$repo     = $services->bookings();
		$now      = $services->clock()->now();
		$result   = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		if ( '' === $email ) {
			return $result;
		}

		$bookings = $repo->find_by_customer_email( $email, self::PAGE_SIZE, 0, $now );
		foreach ( $bookings as $booking ) {
			try {
				$repo->replace_customer( (int) $booking->id, $this->anonymous_customer() );
				$result['items_removed'] = true;
			} catch ( DomainError | DatabaseError $e ) {
				$result['items_retained'] = true;
				$result['messages'][]     = sprintf(
					/* translators: %s: public booking ID. */
					__( 'Booking %s could not be anonymised.', 'terminarz' ),
					(string) $booking->public_id
				);
			}
		}

		if ( count( $bookings ) >= self::PAGE_SIZE && $result['items_removed'] ) {
			$result['done'] = false;
			return $result;
		}

		$retained = $repo->find_by_customer_email( $email, self::PAGE_SIZE );
		if ( array() !== $retained ) {
			$result['items_retained'] = true;
			foreach ( $retained as $booking ) {
				if ( null === $booking->public_id ) {
					continue;
				}
				$result['messages'][] = sprintf(
					/* translators: 1: public booking ID, 2: date and time of the appointment. */
					__( 'Upcoming booking %1$s (%2$s) was retained. Cancel it first if the customer data must be erased.', 'terminarz' ),
					$booking->public_id,
					Labels::datetime( $booking->range->start )
				);
			}
		}

		return $result;
	}

	/**
	 * Suggests a paragraph for the privacy policy (Settings → Privacy → Policy guide).
	 */
	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p class="privacy-policy-tutorial">' . esc_html__( 'Suggested text for the section about appointment bookings. Adjust it to how you use the data.', 'terminarz' ) . '</p>'
			. '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'terminarz' ) . '</strong> '
			. esc_html__( 'When you book an appointment on this website, we collect your name, e-mail address, optional phone number and the note you enter, together with the booked service, date and time. We use this data to manage the appointment and to contact you about it. If you book while logged in, the booking is linked to your account.', 'terminarz' )
			. '</p><p>'
			. esc_html__( 'Booking data is kept for as long as needed to provide the service and for our records. You can request an export or erasure of your personal data. On erasure, your contact details are removed from past bookings; the anonymous booking record (service, date and time) is kept for statistics. Upcoming bookings are retained until they are cancelled.', 'terminarz' )
			. '</p>';

		wp_add_privacy_policy_content( __( 'Terminarz', 'terminarz' ), wp_kses_post( $content ) );
	}

	/**
	 * Exported name/value pairs of a booking.
	 *
	 * @param Booking $booking Booking.
	 * @return array<int, array{name: string, value: string}>
	 */
	private function export_fields( Booking $booking ): array {
		$services = Services::instance();
		$service  = $services->services()->get( $booking->service_id );
		$resource = $services->resources()->get( $booking->resource_id );

		$fields = array(
			__( 'Booking ID', 'terminarz' ) => (string) $booking->public_id,
			__( 'Service', 'terminarz' )    => null === $service ? '' : $service->name,
			__( 'Resource', 'terminarz' )   => null === $resource ? '' : $resource->name,
			__( 'Start', 'terminarz' )      => Labels::datetime( $booking->range->start ),
			__( 'End', 'terminarz' )        => Labels::datetime( $booking->range->end ),
			__( 'Status', 'terminarz' )     => Labels::status( $booking->status ),
			__( 'Name', 'terminarz' )       => $booking->customer->name,
			__( 'E-mail', 'terminarz' )     => $booking->customer->email,
			__( 'Phone', 'terminarz' )      => $booking->customer->phone,
			__( 'Note', 'terminarz' )       => $booking->customer->note,
			__( 'Booked on', 'terminarz' )  => null === $booking->created_at ? '' : Labels::datetime( $booking->created_at ),
		);

		$result = array();
		foreach ( $fields as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$result[] = array(
				'name'  => $name,
				'value' => $value,
			);
		}
		return $result;
	}

	/**
	 * Customer data that replaces the personal data on erasure.
	 */
	private function anonymous_customer(): Customer {
		$name  = (string) wp_privacy_anonymize_data( 'text' );
		$email = (string) wp_privacy_anonymize_data( 'email' );

		if ( '' === trim( $name ) ) {
			$name = '[deleted]';
		}
		if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$email = 'deleted@site.invalid';
		}
		return new Customer( $name, $email );
	}
}
