<?php
/**
 * Unit tests for weekly schedules and schedule exceptions.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\ScheduleException;
use Terminarz\Domain\Model\TimeWindow;
use Terminarz\Domain\Model\WeeklySchedule;

/**
 * @covers \Terminarz\Domain\Model\WeeklySchedule
 * @covers \Terminarz\Domain\Model\ScheduleException
 */
final class ScheduleTest extends TestCase {

	/**
	 * @param TimeWindow[] $windows Windows.
	 *
	 * @return list<string>
	 */
	private static function strings( array $windows ): array {
		return array_map( static fn ( TimeWindow $w ): string => $w->to_string(), $windows );
	}

	public function test_windows_are_working_hours_minus_breaks(): void {
		$schedule = new WeeklySchedule(
			array(
				1 => array( TimeWindow::from_strings( '14:00', '18:00' ), TimeWindow::from_strings( '08:00', '12:00' ) ),
				5 => array( TimeWindow::from_strings( '09:00', '13:00' ) ),
			),
			array(
				1 => array( TimeWindow::from_strings( '10:00', '10:15' ) ),
			)
		);

		$this->assertSame( array( '08:00-10:00', '10:15-12:00', '14:00-18:00' ), self::strings( $schedule->windows_for( 1 ) ) );
		$this->assertSame( array( '08:00-12:00', '14:00-18:00' ), self::strings( $schedule->working_hours( 1 ) ) );
		$this->assertSame( array( '10:00-10:15' ), self::strings( $schedule->breaks( 1 ) ) );
		$this->assertSame( array( '09:00-13:00' ), self::strings( $schedule->windows_for( 5 ) ) );
		$this->assertSame( array(), $schedule->windows_for( 7 ) );
		$this->assertSame( array(), WeeklySchedule::closed()->windows_for( 3 ) );
	}

	public function test_overlapping_working_hours_are_rejected(): void {
		$this->expectException( InvalidValue::class );
		new WeeklySchedule(
			array( 2 => array( TimeWindow::from_strings( '08:00', '12:00' ), TimeWindow::from_strings( '11:00', '13:00' ) ) )
		);
	}

	public function test_touching_working_hours_are_allowed(): void {
		$schedule = new WeeklySchedule(
			array( 2 => array( TimeWindow::from_strings( '08:00', '12:00' ), TimeWindow::from_strings( '12:00', '13:00' ) ) )
		);

		$this->assertCount( 2, $schedule->windows_for( 2 ) );
	}

	/**
	 * @return iterable<string, array{int}>
	 */
	public static function invalid_weekdays(): iterable {
		yield 'zero' => array( 0 );
		yield 'eight' => array( 8 );
	}

	/**
	 * @dataProvider invalid_weekdays
	 *
	 * @param int $weekday Invalid weekday.
	 */
	public function test_invalid_weekday_in_schedule_is_rejected( int $weekday ): void {
		$this->expectException( InvalidValue::class );
		new WeeklySchedule( array( $weekday => array( TimeWindow::from_strings( '08:00', '12:00' ) ) ) );
	}

	/**
	 * @dataProvider invalid_weekdays
	 *
	 * @param int $weekday Invalid weekday.
	 */
	public function test_invalid_weekday_in_query_is_rejected( int $weekday ): void {
		$this->expectException( InvalidValue::class );
		WeeklySchedule::closed()->windows_for( $weekday );
	}

	public function test_schedule_exception_factories(): void {
		$closed = ScheduleException::closed( null, '2026-12-25' );
		$this->assertTrue( $closed->is_closed() );
		$this->assertTrue( $closed->is_global() );
		$this->assertNull( $closed->id );

		$custom = ScheduleException::custom_hours(
			3,
			'2026-12-24',
			array( TimeWindow::from_strings( '12:00', '14:00' ), TimeWindow::from_strings( '08:00', '10:00' ) )
		)->with_id( 9 );
		$this->assertFalse( $custom->is_closed() );
		$this->assertFalse( $custom->is_global() );
		$this->assertSame( 3, $custom->resource_id );
		$this->assertSame( 9, $custom->id );
		$this->assertSame( array( '08:00-10:00', '12:00-14:00' ), self::strings( $custom->windows ) );
	}

	/**
	 * @return iterable<string, array{?int, string, list<TimeWindow>}>
	 */
	public static function invalid_exceptions(): iterable {
		yield 'bad date format' => array( null, '24.12.2026', array() );
		yield 'impossible date' => array( null, '2026-02-30', array() );
		yield 'non-positive resource' => array( 0, '2026-12-24', array() );
		yield 'overlapping windows' => array(
			1,
			'2026-12-24',
			array( TimeWindow::from_strings( '08:00', '10:00' ), TimeWindow::from_strings( '09:00', '11:00' ) ),
		);
	}

	/**
	 * @dataProvider invalid_exceptions
	 *
	 * @param ?int         $resource_id Resource.
	 * @param string       $date        Date.
	 * @param TimeWindow[] $windows     Windows.
	 */
	public function test_invalid_schedule_exception_is_rejected( ?int $resource_id, string $date, array $windows ): void {
		$this->expectException( InvalidValue::class );
		new ScheduleException( $resource_id, $date, $windows );
	}

	public function test_custom_hours_need_a_window(): void {
		$this->expectException( InvalidValue::class );
		ScheduleException::custom_hours( null, '2026-12-24', array() );
	}
}
