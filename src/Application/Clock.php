<?php
/**
 * Clock abstraction.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

defined( 'ABSPATH' ) || exit; // No direct access.

use DateTimeImmutable;

/**
 * Source of the current time (injected, so services are deterministic in tests).
 */
interface Clock {

	/**
	 * Current time in UTC.
	 */
	public function now(): DateTimeImmutable;
}
