<?php
/**
 * Integration tests for weekly schedule and schedule exception repositories.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Persistence;

use Terminarz\Domain\Model\ScheduleException;
use Terminarz\Domain\Model\TimeWindow;
use Terminarz\Domain\Model\WeeklySchedule;
use Terminarz\Infrastructure\Database\DatabaseError;
use Terminarz\Infrastructure\Database\Schema;
use Terminarz\Infrastructure\Persistence\WpdbScheduleExceptionRepository;
use Terminarz\Infrastructure\Persistence\WpdbScheduleRepository;
use WP_UnitTestCase;

/**
 * @covers \Terminarz\Infrastructure\Persistence\WpdbScheduleRepository
 * @covers \Terminarz\Infrastructure\Persistence\WpdbScheduleExceptionRepository
 */
final class ScheduleRepositoriesTest extends WP_UnitTestCase {

	/**
	 * Schedule repository.
	 *
	 * @var WpdbScheduleRepository
	 */
	private WpdbScheduleRepository $schedules;

	/**
	 * Exception repository.
	 *
	 * @var WpdbScheduleExceptionRepository
	 */
	private WpdbScheduleExceptionRepository $exceptions;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->schedules  = new WpdbScheduleRepository( $wpdb );
		$this->exceptions = new WpdbScheduleExceptionRepository( $wpdb );
	}

	public function test_weekly_schedule_round_trip_with_breaks(): void {
		$schedule = new WeeklySchedule(
			array(
				1 => array( TimeWindow::from_strings( '09:00', '17:00' ) ),
				3 => array( TimeWindow::from_strings( '14:00', '24:00' ), TimeWindow::from_strings( '08:00', '12:00' ) ),
			),
			array( 1 => array( TimeWindow::from_strings( '12:00', '12:30' ) ) )
		);

		$this->schedules->save( 7, $schedule );

		$this->assertEquals( $schedule, $this->schedules->for_resource( 7 ) );
		$this->assertSame( array( '09:00-12:00', '12:30-17:00' ), $this->windows( $this->schedules->for_resource( 7 )->windows_for( 1 ) ) );
		$this->assertSame( array( '08:00-12:00', '14:00-24:00' ), $this->windows( $this->schedules->for_resource( 7 )->windows_for( 3 ) ) );
	}

	public function test_saving_replaces_previous_schedule(): void {
		$this->schedules->save( 7, new WeeklySchedule( array( 1 => array( TimeWindow::from_strings( '09:00', '17:00' ) ) ) ) );
		$this->schedules->save( 7, new WeeklySchedule( array( 2 => array( TimeWindow::from_strings( '10:00', '11:00' ) ) ) ) );

		$schedule = $this->schedules->for_resource( 7 );
		$this->assertSame( array(), $schedule->working_hours( 1 ) );
		$this->assertSame( array( '10:00-11:00' ), $this->windows( $schedule->working_hours( 2 ) ) );
	}

	public function test_for_resources_loads_many_in_one_query_and_defaults_to_closed(): void {
		global $wpdb;
		$this->schedules->save( 1, new WeeklySchedule( array( 1 => array( TimeWindow::from_strings( '09:00', '17:00' ) ) ) ) );
		$this->schedules->save( 2, new WeeklySchedule( array( 2 => array( TimeWindow::from_strings( '09:00', '10:00' ) ) ) ) );

		$before = $wpdb->num_queries;
		$all    = $this->schedules->for_resources( array( 1, 2, 3 ) );
		$this->assertSame( 1, $wpdb->num_queries - $before );

		$this->assertSame( array( 1, 2, 3 ), array_keys( $all ) );
		$this->assertCount( 1, $all[1]->working_hours( 1 ) );
		$this->assertCount( 1, $all[2]->working_hours( 2 ) );
		$this->assertEquals( WeeklySchedule::closed(), $all[3] );
	}

	public function test_exception_crud(): void {
		$closed = $this->exceptions->save( ScheduleException::closed( 5, '2030-12-24' ) );
		$this->assertNotNull( $closed->id );
		$this->assertEquals( $closed, $this->exceptions->get( $closed->id ) );

		$custom = ScheduleException::custom_hours( 5, '2030-12-24', array( TimeWindow::from_strings( '13:00', '15:00' ), TimeWindow::from_strings( '08:00', '10:00' ) ) )->with_id( $closed->id );
		$this->exceptions->save( $custom );
		$reloaded = $this->exceptions->get( $closed->id );
		$this->assertFalse( $reloaded->is_closed() );
		$this->assertSame( array( '08:00-10:00', '13:00-15:00' ), $this->windows( $reloaded->windows ) );

		$this->exceptions->delete( $closed->id );
		$this->assertNull( $this->exceptions->get( $closed->id ) );
	}

	public function test_exceptions_in_range_include_globals_and_requested_resources(): void {
		$this->exceptions->save( ScheduleException::closed( null, '2030-01-01' ) );
		$this->exceptions->save( ScheduleException::closed( 1, '2030-01-02' ) );
		$this->exceptions->save( ScheduleException::custom_hours( 2, '2030-01-02', array( TimeWindow::from_strings( '10:00', '12:00' ) ) ) );
		$this->exceptions->save( ScheduleException::closed( 1, '2030-02-01' ) );

		$this->assertSame(
			array( '2030-01-01/global', '2030-01-02/1', '2030-01-02/2' ),
			$this->keys( $this->exceptions->in_range( '2030-01-01', '2030-01-31' ) )
		);
		$this->assertSame(
			array( '2030-01-01/global', '2030-01-02/1' ),
			$this->keys( $this->exceptions->in_range( '2030-01-01', '2030-01-31', array( 1 ) ) )
		);
		$this->assertSame(
			array( '2030-01-01/global' ),
			$this->keys( $this->exceptions->in_range( '2030-01-01', '2030-01-31', array() ) )
		);
		$this->assertSame( array(), $this->exceptions->in_range( '2030-03-01', '2030-03-31' ) );
	}

	public function test_multi_day_rows_are_expanded_and_clipped(): void {
		global $wpdb;
		$wpdb->insert(
			Schema::from_globals()->table( Schema::EXCEPTIONS ),
			array(
				'resource_id' => null,
				'start_date'  => '2030-07-30',
				'end_date'    => '2030-08-03',
				'kind'        => 'closed',
				'created_at'  => '2030-01-01 00:00:00',
				'updated_at'  => '2030-01-01 00:00:00',
			)
		);

		$this->assertSame(
			array( '2030-08-01/global', '2030-08-02/global', '2030-08-03/global' ),
			$this->keys( $this->exceptions->in_range( '2030-08-01', '2030-08-31' ) )
		);
	}

	public function test_corrupt_intervals_are_reported(): void {
		global $wpdb;
		$wpdb->insert(
			Schema::from_globals()->table( Schema::EXCEPTIONS ),
			array(
				'resource_id' => 1,
				'start_date'  => '2030-01-01',
				'end_date'    => '2030-01-01',
				'kind'        => 'custom_hours',
				'intervals'   => 'not json',
				'created_at'  => '2030-01-01 00:00:00',
				'updated_at'  => '2030-01-01 00:00:00',
			)
		);

		$this->expectException( DatabaseError::class );
		$this->exceptions->get( (int) $wpdb->insert_id );
	}

	/**
	 * @param TimeWindow[] $windows Windows.
	 * @return string[]
	 */
	private function windows( array $windows ): array {
		return array_map( static fn( TimeWindow $w ): string => $w->to_string(), $windows );
	}

	/**
	 * @param ScheduleException[] $exceptions Exceptions.
	 * @return string[]
	 */
	private function keys( array $exceptions ): array {
		return array_map( static fn( ScheduleException $e ): string => $e->date . '/' . ( $e->resource_id ?? 'global' ), $exceptions );
	}
}
