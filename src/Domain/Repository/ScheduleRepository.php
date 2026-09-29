<?php
/**
 * Weekly schedule repository contract.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Repository;

use Terminarz\Domain\Model\WeeklySchedule;

/**
 * Stores the recurring weekly schedule (working hours and breaks, site-local time) of each resource.
 */
interface ScheduleRepository {

	/**
	 * Weekly schedule of a resource (closed every day when nothing is stored).
	 *
	 * @param int $resource_id Resource ID.
	 */
	public function for_resource( int $resource_id ): WeeklySchedule;

	/**
	 * Weekly schedules of several resources in one query.
	 *
	 * @param int[] $resource_ids Resource IDs.
	 * @return array<int, WeeklySchedule> Keyed by resource ID; every requested ID is present.
	 */
	public function for_resources( array $resource_ids ): array;

	/**
	 * Replaces the weekly schedule of a resource (atomically).
	 *
	 * @param int            $resource_id Resource ID.
	 * @param WeeklySchedule $schedule    New schedule.
	 */
	public function save( int $resource_id, WeeklySchedule $schedule ): void;
}
