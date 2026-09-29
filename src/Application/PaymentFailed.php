<?php
/**
 * Payment creation failure.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

use RuntimeException;

/**
 * The payment of a booking could not be created. The message is technical (English, not shown to customers).
 */
final class PaymentFailed extends RuntimeException {
}
