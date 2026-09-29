<?php
/**
 * Input of the availability engine.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Availability;

defined( 'ABSPATH' ) || exit; // No direct access.

use DateTimeImmutable;
use DateTimeZone;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\Service;

/**
 * What to search for: a service (duration + buffer) on one or more resources,
 * within an inclusive range of local dates, as seen at `now`.
 */
final class AvailabilityQuery {

	/**
	 * Longest searchable date range (inclusive days) — protects against runaway requests.
	 */
	public const MAX_RANGE_DAYS = 366;

	/**
	 * Default slot grid step.
	 */
	public const DEFAULT_STEP_MINUTES = 15;

	/**
	 * Resources to search, keyed by nothing; ids are unique.
	 *
	 * @var list<ResourceCalendar>
	 */
	public readonly array $resources;

	/**
	 * Creates the query.
	 *
	 * @param ResourceCalendar[] $resources            Resources to search (unique ids).
	 * @param int                $duration_minutes     Service duration, 1–1440.
	 * @param int                $buffer_after_minutes Service buffer, 0–1440; must fit in the working window too.
	 * @param DateTimeZone       $timezone             Site time zone: local dates, schedules and exceptions use it.
	 * @param string             $from_date            First local date "Y-m-d" (inclusive).
	 * @param string             $to_date              Last local date "Y-m-d" (inclusive).
	 * @param DateTimeImmutable  $now                  Current time.
	 * @param int                $min_lead_minutes     Slots must start at least this many minutes after `now`.
	 * @param ?int               $max_horizon_days     Last bookable local date = local date of `now` + N days; null = no limit.
	 * @param int                $slot_step_minutes    Grid step between slot starts, 1–1440, counted from each window's start.
	 *
	 * @throws InvalidValue On invalid parameters.
	 */
	public function __construct(
		array $resources,
		public readonly int $duration_minutes,
		public readonly int $buffer_after_minutes,
		public readonly DateTimeZone $timezone,
		public readonly string $from_date,
		public readonly string $to_date,
		public readonly DateTimeImmutable $now,
		public readonly int $min_lead_minutes = 0,
		public readonly ?int $max_horizon_days = null,
		public readonly int $slot_step_minutes = self::DEFAULT_STEP_MINUTES
	) {
		$ids = array();
		foreach ( $resources as $calendar ) {
			if ( ! $calendar instanceof ResourceCalendar ) {
				throw new InvalidValue( 'Resources must be ResourceCalendar instances.' );
			}
			if ( isset( $ids[ $calendar->resource_id ] ) ) {
				throw new InvalidValue( 'Each resource may appear only once in a query.' );
			}
			$ids[ $calendar->resource_id ] = true;
		}
		$this->resources = array_values( $resources );

		if ( $duration_minutes <= 0 || $duration_minutes > Service::MAX_MINUTES ) {
			throw new InvalidValue( 'Duration must be between 1 and 1440 minutes.' );
		}
		if ( $buffer_after_minutes < 0 || $buffer_after_minutes > Service::MAX_MINUTES ) {
			throw new InvalidValue( 'Buffer must be between 0 and 1440 minutes.' );
		}
		if ( $min_lead_minutes < 0 ) {
			throw new InvalidValue( 'Minimum lead time must not be negative.' );
		}
		if ( null !== $max_horizon_days && $max_horizon_days < 0 ) {
			throw new InvalidValue( 'Maximum horizon must not be negative.' );
		}
		if ( $slot_step_minutes <= 0 || $slot_step_minutes > Service::MAX_MINUTES ) {
			throw new InvalidValue( 'Slot step must be between 1 and 1440 minutes.' );
		}

		$from = WallClock::naive_midnight( $from_date );
		$to   = WallClock::naive_midnight( $to_date );
		if ( $to < $from ) {
			throw new InvalidValue( 'The last date must not be before the first date.' );
		}
		if ( intdiv( $to - $from, 86400 ) + 1 > self::MAX_RANGE_DAYS ) {
			throw new InvalidValue( 'The date range is too long.' );
		}
	}

	/**
	 * Convenience constructor taking duration and buffer from a service.
	 *
	 * @param Service            $service   Service to book.
	 * @param ResourceCalendar[] $resources Resources to search.
	 * @param DateTimeZone       $timezone  Site time zone.
	 * @param string             $from_date First local date.
	 * @param string             $to_date   Last local date.
	 * @param DateTimeImmutable  $now       Current time.
	 * @param int                $min_lead_minutes  Minimum lead time.
	 * @param ?int               $max_horizon_days  Maximum horizon in days.
	 * @param int                $slot_step_minutes Grid step.
	 */
	public static function for_service(
		Service $service,
		array $resources,
		DateTimeZone $timezone,
		string $from_date,
		string $to_date,
		DateTimeImmutable $now,
		int $min_lead_minutes = 0,
		?int $max_horizon_days = null,
		int $slot_step_minutes = self::DEFAULT_STEP_MINUTES
	): self {
		return new self(
			$resources,
			$service->duration_minutes,
			$service->buffer_after_minutes,
			$timezone,
			$from_date,
			$to_date,
			$now,
			$min_lead_minutes,
			$max_horizon_days,
			$slot_step_minutes
		);
	}
}
