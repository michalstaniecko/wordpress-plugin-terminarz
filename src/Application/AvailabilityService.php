<?php
/**
 * Availability use cases.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

use DateTimeImmutable;
use Terminarz\Domain\Availability\AvailabilityEngine;
use Terminarz\Domain\Availability\AvailabilityQuery;
use Terminarz\Domain\Availability\ResourceCalendar;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\ScheduleException;
use Terminarz\Domain\Model\Service;
use Terminarz\Domain\Model\Slot;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Domain\Repository\BookingRepository;
use Terminarz\Domain\Repository\ResourceRepository;
use Terminarz\Domain\Repository\ScheduleExceptionRepository;
use Terminarz\Domain\Repository\ScheduleRepository;
use Terminarz\Domain\Repository\ServiceRepository;

/**
 * Free slots of a service in a date range — for one resource or for "any" resource of the service.
 *
 * Loads everything with a constant number of queries (no N+1, independent of the number of resources and days):
 * service, its resource IDs, the resources, their weekly schedules, schedule exceptions, busy ranges.
 * Then delegates to the pure AvailabilityEngine (ADR-016).
 */
final class AvailabilityService {

	/**
	 * Constructor.
	 *
	 * @param ServiceRepository           $services   Services.
	 * @param ResourceRepository          $resources  Resources.
	 * @param ScheduleRepository          $schedules  Weekly schedules.
	 * @param ScheduleExceptionRepository $exceptions Schedule exceptions.
	 * @param BookingRepository           $bookings   Bookings.
	 * @param AvailabilityEngine          $engine     Engine.
	 * @param Clock                       $clock      Clock.
	 * @param AvailabilitySettings        $settings   Settings.
	 */
	public function __construct(
		private readonly ServiceRepository $services,
		private readonly ResourceRepository $resources,
		private readonly ScheduleRepository $schedules,
		private readonly ScheduleExceptionRepository $exceptions,
		private readonly BookingRepository $bookings,
		private readonly AvailabilityEngine $engine,
		private readonly Clock $clock,
		private readonly AvailabilitySettings $settings
	) {
	}

	/**
	 * Settings in use.
	 */
	public function settings(): AvailabilitySettings {
		return $this->settings;
	}

	/**
	 * Free slots of every active resource of the service (a slot per resource and start),
	 * sorted by start, then resource ID. With `$resource_id` only that resource is searched.
	 *
	 * @param int      $service_id  Service ID.
	 * @param string   $from_date   First local date (Y-m-d, site time zone).
	 * @param string   $to_date     Last local date (inclusive).
	 * @param int|null $resource_id Only this resource (must perform the service), null = all.
	 * @param int|null $exclude_id  Booking whose time does not count as busy (rescheduling).
	 * @return list<Slot>
	 * @throws EntityNotFound When the service does not exist.
	 * @throws InvalidValue   When the service is inactive, the resource does not perform it, or dates are invalid.
	 */
	public function slots( int $service_id, string $from_date, string $to_date, ?int $resource_id = null, ?int $exclude_id = null ): array {
		return $this->search( $service_id, $from_date, $to_date, $resource_id, $exclude_id )['slots'];
	}

	/**
	 * Free starts for "any resource": one slot per distinct start, with the resource picked by the configured
	 * strategy (the assignment is provisional — reserve_any() re-checks at booking time).
	 *
	 * @param int    $service_id Service ID.
	 * @param string $from_date  First local date.
	 * @param string $to_date    Last local date (inclusive).
	 * @return list<Slot>
	 * @throws EntityNotFound When the service does not exist.
	 * @throws InvalidValue   When the service is inactive or dates are invalid.
	 */
	public function any_resource_slots( int $service_id, string $from_date, string $to_date ): array {
		$result = $this->search( $service_id, $from_date, $to_date, null, null );
		$picked = array();
		foreach ( $this->group_by_start( $result['slots'] ) as $candidates ) {
			$picked[] = $this->rank( $candidates, $result['order'], $result['load'] )[0];
		}
		return $picked;
	}

	/**
	 * Resources of the service that are free at `$start`, best first according to the strategy.
	 *
	 * @param int               $service_id Service ID.
	 * @param DateTimeImmutable $start      Slot start.
	 * @param int|null          $exclude_id Booking being rescheduled.
	 * @return int[]
	 * @throws EntityNotFound When the service does not exist.
	 * @throws InvalidValue   When the service is inactive.
	 */
	public function free_resources_at( int $service_id, DateTimeImmutable $start, ?int $exclude_id = null ): array {
		$date   = $start->setTimezone( $this->settings->timezone )->format( 'Y-m-d' );
		$result = $this->search( $service_id, $date, $date, null, $exclude_id );

		$candidates = array_values(
			array_filter( $result['slots'], static fn( Slot $slot ): bool => $slot->start()->getTimestamp() === $start->getTimestamp() )
		);
		if ( array() === $candidates ) {
			return array();
		}
		return array_map( static fn( Slot $slot ): int => $slot->resource_id, $this->rank( $candidates, $result['order'], $result['load'] ) );
	}

	/**
	 * Whether `$start` is a free slot of the resource for the service (schedule, exceptions, lead time, horizon,
	 * grid, bookings). Used as the slot policy of BookingService.
	 *
	 * @param int               $service_id  Service ID.
	 * @param int               $resource_id Resource ID.
	 * @param DateTimeImmutable $start       Slot start.
	 * @param int|null          $exclude_id  Booking being rescheduled.
	 */
	public function is_available( int $service_id, int $resource_id, DateTimeImmutable $start, ?int $exclude_id = null ): bool {
		$date = $start->setTimezone( $this->settings->timezone )->format( 'Y-m-d' );
		try {
			$slots = $this->slots( $service_id, $date, $date, $resource_id, $exclude_id );
		} catch ( InvalidValue | EntityNotFound $e ) {
			return false;
		}
		foreach ( $slots as $slot ) {
			if ( $slot->start()->getTimestamp() === $start->getTimestamp() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Loads data and runs the engine.
	 *
	 * @param int      $service_id  Service ID.
	 * @param string   $from_date   First local date.
	 * @param string   $to_date     Last local date.
	 * @param int|null $resource_id Only this resource.
	 * @param int|null $exclude_id  Booking to ignore.
	 * @return array{slots: list<Slot>, order: array<int, int>, load: array<int, array<string, int>>}
	 * @throws EntityNotFound When the service does not exist.
	 * @throws InvalidValue   When the input is invalid.
	 */
	private function search( int $service_id, string $from_date, string $to_date, ?int $resource_id, ?int $exclude_id ): array {
		$service = $this->active_service( $service_id );
		$now     = $this->clock->now();

		// Validates the dates (format, order, maximum range) before touching the database.
		AvailabilityQuery::for_service( $service, array(), $this->settings->timezone, $from_date, $to_date, $now );

		$ids = $this->services->resource_ids( $service_id );
		if ( null !== $resource_id ) {
			if ( ! in_array( $resource_id, $ids, true ) ) {
				throw new InvalidValue( 'The resource does not perform this service.' );
			}
			$ids = array( $resource_id );
		}
		$active = $this->resources->get_many( $ids );
		$ids    = array_values( array_filter( $ids, static fn( int $id ): bool => isset( $active[ $id ] ) && $active[ $id ]->is_active ) );

		$empty = array(
			'slots' => array(),
			'order' => array(),
			'load'  => array(),
		);
		if ( array() === $ids ) {
			return $empty;
		}

		$schedules  = $this->schedules->for_resources( $ids );
		$exceptions = $this->exceptions->in_range( $from_date, $to_date, $ids );
		$busy       = $this->bookings->busy_ranges( $ids, $this->utc_span( $from_date, $to_date ), $now, $exclude_id );

		$calendars = array();
		foreach ( $ids as $id ) {
			$calendars[] = new ResourceCalendar(
				$id,
				$schedules[ $id ],
				array_values( array_filter( $exceptions, static fn( ScheduleException $e ): bool => $e->is_global() || $e->resource_id === $id ) ),
				$busy[ $id ] ?? array()
			);
		}

		$slots = $this->engine->find_slots(
			AvailabilityQuery::for_service(
				$service,
				$calendars,
				$this->settings->timezone,
				$from_date,
				$to_date,
				$now,
				$this->settings->min_lead_minutes,
				$this->settings->max_horizon_days,
				$this->settings->slot_step_minutes
			)
		);

		return array(
			'slots' => $slots,
			'order' => array_flip( $ids ),
			'load'  => AvailabilitySettings::STRATEGY_LEAST_BUSY === $this->settings->strategy ? $this->daily_load( $busy ) : array(),
		);
	}

	/**
	 * Orders candidate slots (same start, different resources) by the strategy.
	 *
	 * @param Slot[]                         $candidates Candidates.
	 * @param array<int, int>                $order      Resource ID => preference position.
	 * @param array<int, array<string, int>> $load       Resource ID => local date => busy minutes.
	 * @return Slot[]
	 */
	private function rank( array $candidates, array $order, array $load ): array {
		$tz = $this->settings->timezone;
		usort(
			$candidates,
			static function ( Slot $a, Slot $b ) use ( $order, $load, $tz ): int {
				$date   = $a->start()->setTimezone( $tz )->format( 'Y-m-d' );
				$load_a = $load[ $a->resource_id ][ $date ] ?? 0;
				$load_b = $load[ $b->resource_id ][ $date ] ?? 0;
				return array( $load_a, $order[ $a->resource_id ] ?? PHP_INT_MAX ) <=> array( $load_b, $order[ $b->resource_id ] ?? PHP_INT_MAX );
			}
		);
		return $candidates;
	}

	/**
	 * Groups slots (sorted by start) by start timestamp.
	 *
	 * @param Slot[] $slots Slots.
	 * @return array<int, Slot[]>
	 */
	private function group_by_start( array $slots ): array {
		$groups = array();
		foreach ( $slots as $slot ) {
			$groups[ $slot->start()->getTimestamp() ][] = $slot;
		}
		return $groups;
	}

	/**
	 * Busy minutes per resource and local start date.
	 *
	 * @param array<int, TimeRange[]> $busy Busy ranges.
	 * @return array<int, array<string, int>>
	 */
	private function daily_load( array $busy ): array {
		$load = array();
		foreach ( $busy as $resource_id => $ranges ) {
			foreach ( $ranges as $range ) {
				$date                          = $range->start->setTimezone( $this->settings->timezone )->format( 'Y-m-d' );
				$load[ $resource_id ][ $date ] = ( $load[ $resource_id ][ $date ] ?? 0 ) + $range->duration_minutes();
			}
		}
		return $load;
	}

	/**
	 * UTC span safely covering the local dates (±1 day for time zone offsets and DST).
	 *
	 * @param string $from_date First local date.
	 * @param string $to_date   Last local date.
	 */
	private function utc_span( string $from_date, string $to_date ): TimeRange {
		$tz = $this->settings->timezone;
		return new TimeRange(
			( new DateTimeImmutable( $from_date . ' 00:00:00', $tz ) )->modify( '-1 day' ),
			( new DateTimeImmutable( $to_date . ' 00:00:00', $tz ) )->modify( '+2 days' )
		);
	}

	/**
	 * Loads an active service.
	 *
	 * @param int $service_id Service ID.
	 * @throws EntityNotFound When missing.
	 * @throws InvalidValue   When inactive.
	 */
	private function active_service( int $service_id ): Service {
		$service = $this->services->get( $service_id );
		if ( null === $service ) {
			throw EntityNotFound::with_id( 'service', $service_id );
		}
		if ( ! $service->is_active ) {
			throw new InvalidValue( 'The service is not active.' );
		}
		return $service;
	}
}
