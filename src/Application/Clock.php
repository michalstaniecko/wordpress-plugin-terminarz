<?php
/**
 * Clock abstraction.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

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
