<?php
/**
 * Unit tests for ScheduleExceptionPeriod.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\ScheduleExceptionPeriod;
use Terminarz\Domain\Model\TimeWindow;

/**
 * @covers \Terminarz\Domain\Model\ScheduleExceptionPeriod
 */
final class ScheduleExceptionPeriodTest extends TestCase {

	public function test_closed_period_expands_to_days_across_dst(): void {
		$period = new ScheduleExceptionPeriod( 3, '2030-03-30', '2030-04-01', array(), 'Leave' );

		$this->assertTrue( $period->is_closed() );
		$this->assertFalse( $period->is_global() );
		$this->assertSame( 3, $period->length_in_days() );
		$days = $period->days();
		$this->assertSame( array( '2030-03-30', '2030-03-31', '2030-04-01' ), array_map( static fn( $d ) => $d->date, $days ) );
		$this->assertTrue( $days[1]->is_closed() );
		$this->assertSame( 3, $days[2]->resource_id );
	}

	public function test_custom_hours_are_sorted_and_copied_to_days(): void {
		$period = new ScheduleExceptionPeriod( null, '2030-12-24', '2030-12-24', array( TimeWindow::from_strings( '13:00', '15:00' ), TimeWindow::from_strings( '08:00', '12:00' ) ) );

		$this->assertTrue( $period->is_global() );
		$this->assertSame( '08:00-12:00', $period->windows[0]->to_string() );
		$this->assertSame( '13:00-15:00', $period->days()[0]->windows[1]->to_string() );
		$this->assertSame( 7, $period->with_id( 7 )->id );
		$this->assertSame( 7, $period->with_id( 7 )->days()[0]->id );
	}

	public function test_conflicts_only_within_the_same_scope(): void {
		$global = new ScheduleExceptionPeriod( null, '2030-01-01', '2030-01-05' );

		$this->assertTrue( $global->conflicts_with( new ScheduleExceptionPeriod( null, '2030-01-05', '2030-01-07' ) ) );
		$this->assertFalse( $global->conflicts_with( new ScheduleExceptionPeriod( null, '2030-01-06', '2030-01-07' ) ) );
		$this->assertFalse( $global->conflicts_with( new ScheduleExceptionPeriod( 1, '2030-01-02', '2030-01-03' ) ), 'Resource exceptions may override global ones.' );
		$this->assertTrue( ( new ScheduleExceptionPeriod( 1, '2030-01-01', '2030-01-01' ) )->conflicts_with( new ScheduleExceptionPeriod( 1, '2029-12-31', '2030-01-02' ) ) );
	}

	/**
	 * Invalid periods.
	 *
	 * @return iterable<string, array{0: callable}>
	 */
	public static function invalid(): iterable {
		yield 'end before start' => array( static fn () => new ScheduleExceptionPeriod( null, '2030-01-02', '2030-01-01' ) );
		yield 'bad date' => array( static fn () => new ScheduleExceptionPeriod( null, '2030-02-30', '2030-03-01' ) );
		yield 'too long' => array( static fn () => new ScheduleExceptionPeriod( null, '2030-01-01', '2031-01-02' ) );
		yield 'overlapping windows' => array( static fn () => new ScheduleExceptionPeriod( null, '2030-01-01', '2030-01-01', array( TimeWindow::from_strings( '08:00', '12:00' ), TimeWindow::from_strings( '11:00', '13:00' ) ) ) );
		yield 'zero resource' => array( static fn () => new ScheduleExceptionPeriod( 0, '2030-01-01', '2030-01-01' ) );
	}

	/**
	 * Validation.
	 *
	 * @dataProvider invalid
	 *
	 * @param callable $factory Factory.
	 */
	public function test_invalid_periods_are_rejected( callable $factory ): void {
		$this->expectException( InvalidValue::class );
		$factory();
	}
}
