<?php
/**
 * Fixed clock (tests, simulations).
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Always returns the same instant (can be moved explicitly).
 */
final class FixedClock implements Clock {

	/**
	 * Current instant.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * Constructor.
	 *
	 * @param DateTimeImmutable|string $now Instant (string parsed as UTC).
	 */
	public function __construct( DateTimeImmutable|string $now ) {
		$this->set( $now );
	}

	/**
	 * Moves the clock.
	 *
	 * @param DateTimeImmutable|string $now Instant (string parsed as UTC).
	 */
	public function set( DateTimeImmutable|string $now ): void {
		$utc       = new DateTimeZone( 'UTC' );
		$this->now = is_string( $now ) ? new DateTimeImmutable( $now, $utc ) : $now->setTimezone( $utc );
	}

	/**
	 * {@inheritDoc}
	 */
	public function now(): DateTimeImmutable {
		return $this->now;
	}
}
