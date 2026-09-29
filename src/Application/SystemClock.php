<?php
/**
 * System clock.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

defined( 'ABSPATH' ) || exit; // No direct access.

use DateTimeImmutable;
use DateTimeZone;

/**
 * Real time, in UTC.
 */
final class SystemClock implements Clock {

	/**
	 * {@inheritDoc}
	 */
	public function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}
}
