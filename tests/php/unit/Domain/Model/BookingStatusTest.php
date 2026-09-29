<?php
/**
 * Unit tests for the booking status state machine.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Terminarz\Domain\Exception\InvalidStatusTransition;
use Terminarz\Domain\Model\BookingStatus;

/**
 * @covers \Terminarz\Domain\Model\BookingStatus
 * @covers \Terminarz\Domain\Exception\InvalidStatusTransition
 */
final class BookingStatusTest extends TestCase {

	/**
	 * Expected transition matrix: from => list of allowed targets.
	 *
	 * @var array<string, list<string>>
	 */
	private const ALLOWED = array(
		'pending_payment' => array( 'pending', 'confirmed', 'cancelled', 'expired' ),
		'pending'         => array( 'confirmed', 'cancelled' ),
		'confirmed'       => array( 'cancelled', 'completed' ),
		'cancelled'       => array(),
		'expired'         => array(),
		'completed'       => array(),
	);

	public function test_backed_values_are_stable(): void {
		$this->assertSame(
			array( 'pending', 'pending_payment', 'confirmed', 'cancelled', 'expired', 'completed' ),
			array_map( static fn ( BookingStatus $s ): string => $s->value, BookingStatus::cases() )
		);
	}

	/**
	 * @return iterable<string, array{BookingStatus, BookingStatus, bool}>
	 */
	public static function transitions(): iterable {
		foreach ( BookingStatus::cases() as $from ) {
			foreach ( BookingStatus::cases() as $to ) {
				yield $from->value . ' -> ' . $to->value => array( $from, $to, in_array( $to->value, self::ALLOWED[ $from->value ], true ) );
			}
		}
	}

	/**
	 * @dataProvider transitions
	 *
	 * @param BookingStatus $from    Source.
	 * @param BookingStatus $to      Target.
	 * @param bool          $allowed Expected.
	 */
	public function test_transition_matrix( BookingStatus $from, BookingStatus $to, bool $allowed ): void {
		$this->assertSame( $allowed, $from->can_transition_to( $to ) );

		if ( ! $allowed ) {
			$this->expectException( InvalidStatusTransition::class );
		}
		$this->assertSame( $to, $from->transition_to( $to ) );
	}

	public function test_active_statuses_block_the_slot(): void {
		$this->assertTrue( BookingStatus::Pending->is_active() );
		$this->assertTrue( BookingStatus::PendingPayment->is_active() );
		$this->assertTrue( BookingStatus::Confirmed->is_active() );
		$this->assertFalse( BookingStatus::Cancelled->is_active() );
		$this->assertFalse( BookingStatus::Expired->is_active() );
		$this->assertFalse( BookingStatus::Completed->is_active() );

		$this->assertSame(
			array( BookingStatus::Pending, BookingStatus::PendingPayment, BookingStatus::Confirmed ),
			BookingStatus::active()
		);
	}

	public function test_terminal_statuses(): void {
		$this->assertTrue( BookingStatus::Cancelled->is_terminal() );
		$this->assertTrue( BookingStatus::Expired->is_terminal() );
		$this->assertTrue( BookingStatus::Completed->is_terminal() );
		$this->assertFalse( BookingStatus::Confirmed->is_terminal() );
	}

	public function test_same_status_is_not_a_transition(): void {
		$this->expectException( InvalidStatusTransition::class );
		$this->expectExceptionMessage( 'from "confirmed" to "confirmed"' );

		BookingStatus::Confirmed->transition_to( BookingStatus::Confirmed );
	}
}
