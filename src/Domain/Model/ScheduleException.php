<?php
/**
 * One-off change of the schedule on a given local date.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Model;

defined( 'ABSPATH' ) || exit; // No direct access.

use DateTimeImmutable;
use Terminarz\Domain\Exception\InvalidValue;

/**
 * Overrides the weekly schedule on a single local date: the day is closed (no windows)
 * or has different hours (the given windows replace working hours *and* breaks of that day).
 *
 * A null resource id makes the exception global (e.g. a public holiday). When both a
 * resource exception and a global exception exist for a date, the resource one wins.
 * This is not a Throwable — "exception" as in "exception to the schedule".
 */
final class ScheduleException {

	/**
	 * Local date, "Y-m-d".
	 *
	 * @var string
	 */
	public readonly string $date;

	/**
	 * Bookable windows on that date, sorted; empty = closed.
	 *
	 * @var list<TimeWindow>
	 */
	public readonly array $windows;

	/**
	 * Creates the exception.
	 *
	 * @param ?int         $resource_id Resource id, or null for a global exception.
	 * @param string       $date        Local date "Y-m-d".
	 * @param TimeWindow[] $windows     Replacement windows; empty = closed.
	 * @param ?int         $id          Persistence id, null when not stored yet.
	 *
	 * @throws InvalidValue On an invalid date, resource id or overlapping windows.
	 */
	public function __construct(
		public readonly ?int $resource_id,
		string $date,
		array $windows = array(),
		public readonly ?int $id = null
	) {
		if ( null !== $resource_id && $resource_id <= 0 ) {
			throw new InvalidValue( 'Resource id must be a positive integer.' );
		}
		if ( null !== $id && $id <= 0 ) {
			throw new InvalidValue( 'Id must be a positive integer.' );
		}

		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $date ) {
			throw new InvalidValue( 'Exception date must be a valid Y-m-d date.' );
		}

		foreach ( $windows as $window ) {
			if ( ! $window instanceof TimeWindow ) {
				throw new InvalidValue( 'Exception windows must be TimeWindow instances.' );
			}
		}
		$windows = array_values( $windows );
		usort( $windows, static fn ( TimeWindow $a, TimeWindow $b ): int => $a->start->minutes <=> $b->start->minutes );
		$count = count( $windows );
		for ( $i = 1; $i < $count; $i++ ) {
			if ( $windows[ $i - 1 ]->overlaps( $windows[ $i ] ) ) {
				throw new InvalidValue( 'Exception windows must not overlap.' );
			}
		}

		$this->date    = $date;
		$this->windows = $windows;
	}

	/**
	 * Closed day.
	 *
	 * @param ?int   $resource_id Resource id, or null for global.
	 * @param string $date        Local date "Y-m-d".
	 */
	public static function closed( ?int $resource_id, string $date ): self {
		return new self( $resource_id, $date );
	}

	/**
	 * Day with different hours.
	 *
	 * @param ?int         $resource_id Resource id, or null for global.
	 * @param string       $date        Local date "Y-m-d".
	 * @param TimeWindow[] $windows     Replacement windows (at least one).
	 *
	 * @throws InvalidValue When no windows are given.
	 */
	public static function custom_hours( ?int $resource_id, string $date, array $windows ): self {
		if ( array() === $windows ) {
			throw new InvalidValue( 'Custom hours need at least one window; use closed() for a closed day.' );
		}

		return new self( $resource_id, $date, $windows );
	}

	/**
	 * Whether the day is closed.
	 */
	public function is_closed(): bool {
		return array() === $this->windows;
	}

	/**
	 * Whether the exception applies to all resources.
	 */
	public function is_global(): bool {
		return null === $this->resource_id;
	}

	/**
	 * Returns a copy with the persistence id set.
	 *
	 * @param int $id Persistence id.
	 */
	public function with_id( int $id ): self {
		return new self( $this->resource_id, $this->date, $this->windows, $id );
	}
}
