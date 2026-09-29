<?php
/**
 * A customer cannot cancel a booking.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Exception\DomainError;
use Terminarz\Domain\Model\Booking;

/**
 * Thrown by `BookingService::check_customer_cancellation()` / `cancel_by_customer()`. The reason is one of the constants;
 * adapters show a translated message for it.
 */
final class CancellationRefused extends \RuntimeException implements DomainError {

	/**
	 * Unknown booking or wrong token (not distinguished, so links cannot be probed).
	 */
	public const INVALID_TOKEN = 'invalid_token';

	/**
	 * The booking is no longer active (cancelled, expired or completed).
	 */
	public const NOT_ACTIVE = 'not_active';

	/**
	 * The cancellation deadline (hours before the start) has passed.
	 */
	public const TOO_LATE = 'too_late';

	/**
	 * Constructor.
	 *
	 * @param string       $reason  One of the constants.
	 * @param Booking|null $booking The booking (null for INVALID_TOKEN).
	 */
	public function __construct( public readonly string $reason, public readonly ?Booking $booking = null ) {
		parent::__construct( 'Customer cancellation refused: ' . $reason );
	}
}
