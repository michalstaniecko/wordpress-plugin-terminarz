<?php
/**
 * Unit tests for BookingCriteria.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Unit\Domain\Repository;

use PHPUnit\Framework\TestCase;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Repository\BookingCriteria;

/**
 * @covers \Terminarz\Domain\Repository\BookingCriteria
 */
final class BookingCriteriaTest extends TestCase {

	public function test_defaults(): void {
		$criteria = new BookingCriteria();

		$this->assertSame( array(), $criteria->statuses );
		$this->assertSame( BookingCriteria::ORDER_START, $criteria->order_by );
		$this->assertSame( 20, $criteria->limit );
		$this->assertSame( 0, $criteria->offset );
	}

	/**
	 * @dataProvider invalid
	 *
	 * @param callable(): BookingCriteria $factory Factory.
	 */
	public function test_rejects_invalid_values( callable $factory ): void {
		$this->expectException( InvalidValue::class );
		$factory();
	}

	/**
	 * @return iterable<string, array{0: callable(): BookingCriteria}>
	 */
	public static function invalid(): iterable {
		yield 'status not an enum' => array( static fn () => new BookingCriteria( array( 'pending' ) ) ); // @phpstan-ignore argument.type (invalid input on purpose)
		yield 'zero service' => array( static fn () => new BookingCriteria( service_id: 0 ) );
		yield 'negative resource' => array( static fn () => new BookingCriteria( resource_id: -1 ) );
		yield 'unknown order' => array( static fn () => new BookingCriteria( order_by: 'email' ) );
		yield 'zero limit' => array( static fn () => new BookingCriteria( limit: 0 ) );
		yield 'limit too high' => array( static fn () => new BookingCriteria( limit: 101 ) );
		yield 'negative offset' => array( static fn () => new BookingCriteria( offset: -1 ) );
	}

	public function test_accepts_statuses(): void {
		$criteria = new BookingCriteria( array( BookingStatus::Pending, BookingStatus::Confirmed ) );

		$this->assertCount( 2, $criteria->statuses );
	}
}
