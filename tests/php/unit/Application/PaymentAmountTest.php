<?php
/**
 * Unit tests of PaymentAmount.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use Terminarz\Application\PaymentAmount;
use Terminarz\Domain\Exception\InvalidValue;

/**
 * @covers \Terminarz\Application\PaymentAmount
 */
final class PaymentAmountTest extends TestCase {

	public function test_full_price(): void {
		$this->assertSame( 15000, PaymentAmount::due( 15000 ) );
		$this->assertSame( 0, PaymentAmount::due( 0 ) );
	}

	/**
	 * @return array<string, array{int, int, int}>
	 */
	public static function deposits(): array {
		return array(
			'30% of 150.00'        => array( 15000, 30, 4500 ),
			'100%'                 => array( 15000, 100, 15000 ),
			'rounds half up'       => array( 1005, 10, 101 ),
			'rounds down below .5' => array( 1004, 10, 100 ),
			'at least one unit'    => array( 1, 1, 1 ),
			'free stays free'      => array( 0, 30, 0 ),
		);
	}

	/**
	 * @dataProvider deposits
	 *
	 * @param int $price    Price.
	 * @param int $percent  Percent.
	 * @param int $expected Expected.
	 */
	public function test_deposit( int $price, int $percent, int $expected ): void {
		$this->assertSame( $expected, PaymentAmount::due( $price, $percent ) );
	}

	public function test_rejects_invalid_input(): void {
		$this->expectException( InvalidValue::class );
		PaymentAmount::due( 1000, 0 );
	}

	public function test_rejects_negative_price(): void {
		$this->expectException( InvalidValue::class );
		PaymentAmount::due( -1 );
	}
}
