<?php
/**
 * Online payment port.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\Service;

/**
 * Starts the payment of a booking awaiting payment (implemented by the WooCommerce integration).
 */
interface PaymentProvider {

	/**
	 * Creates the payment (e.g. an order) for a stored `pending_payment` booking, links it to the booking
	 * and returns the URL where the customer pays.
	 *
	 * @param Booking $booking Stored booking awaiting payment.
	 * @param Service $service Booked service.
	 * @return string Absolute payment URL.
	 * @throws PaymentFailed When the payment cannot be created.
	 */
	public function start_payment( Booking $booking, Service $service ): string;
}
