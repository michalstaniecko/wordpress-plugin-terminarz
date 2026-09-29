<?php
/**
 * Schedule exception repository contract.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Repository;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Model\ScheduleException;
use Terminarz\Domain\Model\ScheduleExceptionPeriod;

/**
 * Stores one-day deviations from the weekly schedule (days off, holidays, different hours).
 * Dates are site-local `Y-m-d` strings.
 */
interface ScheduleExceptionRepository {

	/**
	 * Finds an exception by ID.
	 *
	 * @param int $id Exception ID.
	 */
	public function get( int $id ): ?ScheduleException;

	/**
	 * Inserts (no ID) or updates (with ID) an exception.
	 *
	 * @param ScheduleException $exception Exception.
	 * @return ScheduleException The stored exception (with ID).
	 * @throws EntityNotFound When updating an exception that does not exist.
	 */
	public function save( ScheduleException $exception ): ScheduleException;

	/**
	 * Deletes an exception (no-op when missing).
	 *
	 * @param int $id Exception ID.
	 */
	public function delete( int $id ): void;

	/**
	 * Exceptions in a date range (inclusive), in one query: global ones plus those of the given resources.
	 * Sorted by date, global exceptions first.
	 *
	 * @param string     $from         First local date (Y-m-d).
	 * @param string     $to           Last local date (Y-m-d).
	 * @param int[]|null $resource_ids Resources to include; null = all resources, empty array = global only.
	 * @return ScheduleException[]
	 */
	public function in_range( string $from, string $to, ?array $resource_ids = null ): array;

	/**
	 * Finds a stored exception with its full date range.
	 *
	 * @param int $id Exception ID.
	 */
	public function get_period( int $id ): ?ScheduleExceptionPeriod;

	/**
	 * Inserts (no ID) or updates (with ID) an exception covering a date range (one row).
	 *
	 * @param ScheduleExceptionPeriod $period Period.
	 * @return ScheduleExceptionPeriod The stored period (with ID).
	 * @throws EntityNotFound When updating a period that does not exist.
	 */
	public function save_period( ScheduleExceptionPeriod $period ): ScheduleExceptionPeriod;

	/**
	 * All stored exceptions (global and of every resource) as periods, sorted by start date, global first.
	 *
	 * @param string|null $ending_from Only periods ending on or after this local date (Y-m-d); null = all.
	 * @return ScheduleExceptionPeriod[]
	 */
	public function periods( ?string $ending_from = null ): array;

	/**
	 * Stored periods of the same scope (same resource, or global) sharing at least one day with the given one,
	 * excluding the period itself.
	 *
	 * @param ScheduleExceptionPeriod $period Period.
	 * @return ScheduleExceptionPeriod[]
	 */
	public function conflicting_periods( ScheduleExceptionPeriod $period ): array;
}
