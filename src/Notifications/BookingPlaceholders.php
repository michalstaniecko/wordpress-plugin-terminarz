<?php
/**
 * Placeholder values of a booking.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Notifications;

use Terminarz\Admin\BookingsPage;
use Terminarz\Admin\Labels;
use Terminarz\Admin\Money;
use Terminarz\Domain\Model\Booking;
use Terminarz\Infrastructure\Services;

/**
 * Builds the plain-text values of every placeholder for a booking. Dates and times are shown in the site time zone
 * (`wp_date()`) with the site's date/time formats.
 */
final class BookingPlaceholders {

	/**
	 * Constructor.
	 *
	 * @param Services $services Composition root.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Values for a booking.
	 *
	 * @param Booking               $booking Booking.
	 * @param array<string, string> $extra   Additional or overriding values (e.g. `reason`, `order_number`).
	 * @return array<string, string>
	 */
	public function for_booking( Booking $booking, array $extra = array() ): array {
		$service  = $this->services->services()->get( $booking->service_id );
		$resource = $this->services->resources()->get( $booking->resource_id );
		$date     = (string) get_option( 'date_format', 'Y-m-d' );
		$time     = (string) get_option( 'time_format', 'H:i' );
		$start    = $booking->range->start->getTimestamp();
		$end      = $booking->range->end->getTimestamp();

		$values = array_merge(
			Placeholders::empty_values(),
			array(
				'customer_name'     => $booking->customer->name,
				'customer_email'    => $booking->customer->email,
				'customer_phone'    => $booking->customer->phone,
				'customer_note'     => $booking->customer->note,
				'service_name'      => null === $service ? '' : $service->name,
				'resource_name'     => null === $resource ? '' : $resource->name,
				'start_date'        => (string) wp_date( $date, $start ),
				'start_time'        => (string) wp_date( $time, $start ),
				'end_time'          => (string) wp_date( $time, $end ),
				'price'             => null === $service ? '' : Money::format( $service->price_minor ),
				'status'            => Labels::status( $booking->status ),
				'booking_id'        => (string) $booking->public_id,
				'site_name'         => wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
				'site_url'          => home_url( '/' ),
				'admin_booking_url' => null === $booking->id ? '' : add_query_arg(
					array(
						'page' => BookingsPage::SLUG,
						'view' => 'view',
						'id'   => $booking->id,
					),
					admin_url( 'admin.php' )
				),
			),
			$extra
		);

		/**
		 * Filters the placeholder values of a booking e-mail (plain text; they are escaped when rendered).
		 *
		 * @param array<string, string> $values  Values keyed by placeholder name (without braces).
		 * @param Booking               $booking Booking.
		 */
		$filtered = apply_filters( 'trmz_email_placeholders', $values, $booking );

		return is_array( $filtered ) ? array_map( 'strval', array_filter( $filtered, 'is_scalar' ) ) : $values;
	}
}
