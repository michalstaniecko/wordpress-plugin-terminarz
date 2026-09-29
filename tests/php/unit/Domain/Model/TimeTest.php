<?php
/**
 * Unit tests for time value objects.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Unit\Domain\Model;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\LocalTime;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Domain\Model\TimeWindow;

/**
 * @covers \Terminarz\Domain\Model\TimeRange
 * @covers \Terminarz\Domain\Model\LocalTime
 * @covers \Terminarz\Domain\Model\TimeWindow
 */
final class TimeTest extends TestCase {

	private static function range( string $start, string $end ): TimeRange {
		return new TimeRange( new DateTimeImmutable( $start ), new DateTimeImmutable( $end ) );
	}

	public function test_time_range_is_normalised_to_utc(): void {
		$range = self::range( '2026-10-01 10:00 Europe/Warsaw', '2026-10-01 11:30 Europe/Warsaw' );

		$this->assertSame( 'UTC', $range->start->getTimezone()->getName() );
		$this->assertSame( '2026-10-01 08:00', $range->start->format( 'Y-m-d H:i' ) );
		$this->assertSame( 90, $range->duration_minutes() );
		$this->assertTrue( $range->equals( self::range( '2026-10-01 08:00 UTC', '2026-10-01 09:30 UTC' ) ) );
	}

	/**
	 * @return iterable<string, array{string, string}>
	 */
	public static function invalid_ranges(): iterable {
		yield 'empty' => array( '2026-10-01 10:00 UTC', '2026-10-01 10:00 UTC' );
		yield 'reversed' => array( '2026-10-01 10:00 UTC', '2026-10-01 09:59 UTC' );
		yield 'reversed across zones' => array( '2026-10-01 10:00 UTC', '2026-10-01 11:00 Europe/Warsaw' );
	}

	/**
	 * @dataProvider invalid_ranges
	 *
	 * @param string $start Start.
	 * @param string $end   End.
	 */
	public function test_time_range_rejects_non_positive_length( string $start, string $end ): void {
		$this->expectException( InvalidValue::class );
		self::range( $start, $end );
	}

	public function test_time_range_is_half_open(): void {
		$a = self::range( '2026-10-01 09:00 UTC', '2026-10-01 10:00 UTC' );
		$b = self::range( '2026-10-01 10:00 UTC', '2026-10-01 11:00 UTC' );
		$c = self::range( '2026-10-01 09:59 UTC', '2026-10-01 10:01 UTC' );

		$this->assertFalse( $a->overlaps( $b ) );
		$this->assertFalse( $b->overlaps( $a ) );
		$this->assertTrue( $a->overlaps( $c ) );
		$this->assertTrue( $c->overlaps( $b ) );
		$this->assertTrue( $a->contains_instant( $a->start ) );
		$this->assertFalse( $a->contains_instant( $a->end ) );
		$this->assertTrue( $a->contains( $a ) );
		$this->assertFalse( $a->contains( $c ) );
	}

	public function test_time_range_extend_end(): void {
		$range = self::range( '2026-10-01 09:00 UTC', '2026-10-01 10:00 UTC' )->extend_end_by_minutes( 15 );

		$this->assertSame( '10:15', $range->end->format( 'H:i' ) );
		$this->expectException( InvalidValue::class );
		$range->extend_end_by_minutes( -1 );
	}

	public function test_local_time_parsing(): void {
		$this->assertSame( 0, LocalTime::from_string( '00:00' )->minutes );
		$this->assertSame( 570, LocalTime::from_string( '09:30' )->minutes );
		$this->assertSame( 1440, LocalTime::from_string( '24:00' )->minutes );
		$this->assertSame( '09:05', ( new LocalTime( 545 ) )->to_string() );
		$this->assertSame( 9, ( new LocalTime( 545 ) )->hour() );
		$this->assertSame( 5, ( new LocalTime( 545 ) )->minute() );
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function invalid_local_times(): iterable {
		foreach ( array( '9:00', '24:01', '25:00', '12:60', '12-00', '', ' 12:00' ) as $value ) {
			yield "'{$value}'" => array( $value );
		}
	}

	/**
	 * @dataProvider invalid_local_times
	 *
	 * @param string $value Malformed time.
	 */
	public function test_local_time_rejects_malformed_values( string $value ): void {
		$this->expectException( InvalidValue::class );
		LocalTime::from_string( $value );
	}

	public function test_local_time_rejects_out_of_range_minutes(): void {
		$this->expectException( InvalidValue::class );
		new LocalTime( 1441 );
	}

	public function test_time_window_validation(): void {
		$this->assertSame( 480, TimeWindow::from_strings( '09:00', '17:00' )->duration_minutes() );
		$this->assertSame( '00:00-24:00', TimeWindow::from_strings( '00:00', '24:00' )->to_string() );

		$this->expectException( InvalidValue::class );
		TimeWindow::from_strings( '22:00', '02:00' );
	}

	public function test_time_window_subtract(): void {
		$day   = TimeWindow::from_strings( '09:00', '17:00' );
		$parts = $day->subtract(
			array(
				TimeWindow::from_strings( '15:00', '15:30' ),
				TimeWindow::from_strings( '12:00', '13:00' ),
				TimeWindow::from_strings( '12:30', '13:15' ),
				TimeWindow::from_strings( '06:00', '09:30' ),
				TimeWindow::from_strings( '17:00', '18:00' ),
			)
		);

		$this->assertSame(
			array( '09:30-12:00', '13:15-15:00', '15:30-17:00' ),
			array_map( static fn ( TimeWindow $w ): string => $w->to_string(), $parts )
		);
		$this->assertSame( array(), $day->subtract( array( TimeWindow::from_strings( '08:00', '18:00' ) ) ) );
	}
}
