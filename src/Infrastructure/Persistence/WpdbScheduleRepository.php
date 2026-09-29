<?php
/**
 * $wpdb implementation of ScheduleRepository.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure\Persistence;

use Terminarz\Domain\Model\LocalTime;
use Terminarz\Domain\Model\TimeWindow;
use Terminarz\Domain\Model\WeeklySchedule;
use Terminarz\Domain\Repository\ScheduleRepository;
use Terminarz\Infrastructure\Database\Schema;

/**
 * One row per window in `{prefix}trmz_schedules` (`kind` = work|break, site-local TIME values).
 */
final class WpdbScheduleRepository extends WpdbRepository implements ScheduleRepository {

	public const KIND_WORK  = 'work';
	public const KIND_BREAK = 'break';

	/**
	 * {@inheritDoc}
	 *
	 * @param int $resource_id Resource ID.
	 */
	public function for_resource( int $resource_id ): WeeklySchedule {
		return $this->for_resources( array( $resource_id ) )[ $resource_id ] ?? WeeklySchedule::closed();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int[] $resource_ids Resource IDs.
	 * @return array<int, WeeklySchedule>
	 */
	public function for_resources( array $resource_ids ): array {
		$resource_ids = self::ids( $resource_ids );
		if ( array() === $resource_ids ) {
			return array();
		}

		$table = $this->table( Schema::SCHEDULES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table from Schema, placeholders generated.
		$sql  = $this->db->prepare( "SELECT resource_id, weekday, kind, start_time, end_time FROM {$table} WHERE resource_id IN (" . self::int_placeholders( $resource_ids ) . ') ORDER BY resource_id, weekday, start_time', $resource_ids );
		$rows = $this->rows( $sql );

		// Resource ID => kind => weekday => windows.
		$grouped = array();
		foreach ( $rows as $row ) {
			$kind = self::KIND_BREAK === $row['kind'] ? 'break' : 'work';
			$grouped[ (int) $row['resource_id'] ][ $kind ][ (int) $row['weekday'] ][] = new TimeWindow(
				self::local_time( (string) $row['start_time'] ),
				self::local_time( (string) $row['end_time'] )
			);
		}

		$result = array();
		foreach ( $resource_ids as $resource_id ) {
			$result[ $resource_id ] = isset( $grouped[ $resource_id ] )
				? new WeeklySchedule( $grouped[ $resource_id ]['work'] ?? array(), $grouped[ $resource_id ]['break'] ?? array() )
				: WeeklySchedule::closed();
		}
		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int            $resource_id Resource ID.
	 * @param WeeklySchedule $schedule    New schedule.
	 */
	public function save( int $resource_id, WeeklySchedule $schedule ): void {
		$table = $this->table( Schema::SCHEDULES );

		$this->transaction->run(
			function () use ( $resource_id, $schedule, $table ): void {
				$this->delete_where( $table, array( 'resource_id' => $resource_id ) );
				for ( $weekday = 1; $weekday <= 7; $weekday++ ) {
					foreach ( array(
						self::KIND_WORK  => $schedule->working_hours( $weekday ),
						self::KIND_BREAK => $schedule->breaks( $weekday ),
					) as $kind => $windows ) {
						foreach ( $windows as $window ) {
							$this->insert(
								$table,
								array(
									'resource_id' => $resource_id,
									'weekday'     => $weekday,
									'kind'        => $kind,
									'start_time'  => $window->start->to_string() . ':00',
									'end_time'    => $window->end->to_string() . ':00',
								)
							);
						}
					}
				}
			}
		);
	}

	/**
	 * MySQL TIME ("HH:MM:SS") → LocalTime.
	 *
	 * @param string $value TIME value.
	 */
	private static function local_time( string $value ): LocalTime {
		return LocalTime::from_string( substr( $value, 0, 5 ) );
	}
}
