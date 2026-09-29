<?php
/**
 * Unit tests for domain entities.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Unit\Domain\Model;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Terminarz\Domain\Exception\InvalidStatusTransition;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\BookableResource;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Domain\Model\Service;
use Terminarz\Domain\Model\Slot;
use Terminarz\Domain\Model\TimeRange;

/**
 * @covers \Terminarz\Domain\Model\BookableResource
 * @covers \Terminarz\Domain\Model\Service
 * @covers \Terminarz\Domain\Model\Customer
 * @covers \Terminarz\Domain\Model\Booking
 * @covers \Terminarz\Domain\Model\Slot
 */
final class EntitiesTest extends TestCase {

	private static function range(): TimeRange {
		return new TimeRange( new DateTimeImmutable( '2026-10-01 08:00 UTC' ), new DateTimeImmutable( '2026-10-01 09:00 UTC' ) );
	}

	private static function booking( BookingStatus $status = BookingStatus::Confirmed, ?DateTimeImmutable $hold = null ): Booking {
		return new Booking(
			resource_id: 1,
			service_id: 2,
			range: self::range(),
			status: $status,
			customer: new Customer( 'Jan Kowalski', 'jan@example.com' ),
			buffer_after_minutes: 15,
			hold_expires_at: $hold
		);
	}

	public function test_resource(): void {
		$resource = new BookableResource( null, 'Gabinet 1' );
		$this->assertNull( $resource->id );
		$this->assertTrue( $resource->is_active );
		$this->assertSame( 5, $resource->with_id( 5 )->id );
		$this->assertNull( $resource->id, 'with_id() must not mutate the original.' );
	}

	public function test_service(): void {
		$service = new Service( null, 'Konsultacja', 45, 15000, 15 );
		$this->assertSame( 60, $service->blocked_minutes() );
		$this->assertFalse( $service->is_free() );
		$this->assertTrue( ( new Service( 1, 'Darmowa', 30 ) )->is_free() );
		$this->assertSame( 7, $service->with_id( 7 )->id );
		$this->assertSame( 15000, $service->with_id( 7 )->price_minor );
	}

	/**
	 * @return iterable<string, array{callable(): mixed}>
	 */
	public static function invalid_entities(): iterable {
		yield 'resource: empty name' => array( static fn () => new BookableResource( null, '  ' ) );
		yield 'resource: zero id' => array( static fn () => new BookableResource( 0, 'A' ) );
		yield 'resource: unknown type' => array( static fn () => new BookableResource( null, 'A', true, 'car' ) );
		yield 'service: zero duration' => array( static fn () => new Service( null, 'A', 0 ) );
		yield 'service: too long' => array( static fn () => new Service( null, 'A', 1441 ) );
		yield 'service: negative price' => array( static fn () => new Service( null, 'A', 30, -1 ) );
		yield 'service: negative buffer' => array( static fn () => new Service( null, 'A', 30, 0, -5 ) );
		yield 'service: empty name' => array( static fn () => new Service( null, '', 30 ) );
		yield 'customer: empty name' => array( static fn () => new Customer( '', 'a@example.com' ) );
		yield 'customer: bad email' => array( static fn () => new Customer( 'A', 'not-an-email' ) );
		yield 'customer: zero user id' => array( static fn () => new Customer( 'A', 'a@example.com', '', '', 0 ) );
		yield 'slot: zero resource' => array( static fn () => new Slot( 0, self::range() ) );
		yield 'booking: pending_payment without hold' => array( static fn () => self::booking( BookingStatus::PendingPayment ) );
		yield 'booking: zero resource' => array(
			static fn () => new Booking( 0, 1, self::range(), BookingStatus::Pending, new Customer( 'A', 'a@example.com' ) ),
		);
		yield 'booking: negative buffer' => array(
			static fn () => new Booking( 1, 1, self::range(), BookingStatus::Pending, new Customer( 'A', 'a@example.com' ), -1 ),
		);
		yield 'booking: empty public id' => array(
			static fn () => new Booking( 1, 1, self::range(), BookingStatus::Pending, new Customer( 'A', 'a@example.com' ), public_id: '' ),
		);
		yield 'booking: empty token hash' => array(
			static fn () => new Booking( 1, 1, self::range(), BookingStatus::Pending, new Customer( 'A', 'a@example.com' ), cancel_secret: '' ),
		);
	}

	/**
	 * @dataProvider invalid_entities
	 *
	 * @param callable(): mixed $factory Creates the invalid object.
	 */
	public function test_invalid_entities_are_rejected( callable $factory ): void {
		$this->expectException( InvalidValue::class );
		$factory();
	}

	public function test_booking_blocked_range_includes_buffer(): void {
		$booking = self::booking();

		$this->assertSame( '09:00', $booking->range->end->format( 'H:i' ) );
		$this->assertSame( '09:15', $booking->blocked_range()->end->format( 'H:i' ) );
	}

	public function test_booking_status_changes_return_new_instances(): void {
		$booking   = self::booking( BookingStatus::Pending );
		$confirmed = $booking->with_status( BookingStatus::Confirmed );

		$this->assertSame( BookingStatus::Pending, $booking->status );
		$this->assertSame( BookingStatus::Confirmed, $confirmed->status );
		$this->assertSame( BookingStatus::Completed, $confirmed->with_status( BookingStatus::Completed )->status );

		$this->expectException( InvalidStatusTransition::class );
		$confirmed->with_status( BookingStatus::Pending );
	}

	public function test_pending_payment_blocks_slot_only_until_hold_expires(): void {
		$hold    = new DateTimeImmutable( '2026-09-30 12:15 Europe/Warsaw' );
		$booking = self::booking( BookingStatus::PendingPayment, $hold );

		$this->assertSame( 'UTC', $booking->hold_expires_at?->getTimezone()->getName() );
		$this->assertTrue( $booking->blocks_slot_at( new DateTimeImmutable( '2026-09-30 10:14:59 UTC' ) ) );
		$this->assertFalse( $booking->blocks_slot_at( new DateTimeImmutable( '2026-09-30 10:15:00 UTC' ) ) );
		$this->assertTrue( $booking->is_hold_expired( new DateTimeImmutable( '2026-09-30 10:15:00 UTC' ) ) );

		$confirmed = $booking->with_status( BookingStatus::Confirmed );
		$this->assertTrue( $confirmed->blocks_slot_at( new DateTimeImmutable( '2026-10-05 00:00 UTC' ) ), 'A confirmed booking ignores the old hold.' );
	}

	public function test_inactive_bookings_do_not_block(): void {
		$now = new DateTimeImmutable( '2026-09-30 00:00 UTC' );

		$this->assertTrue( self::booking( BookingStatus::Pending )->blocks_slot_at( $now ) );
		$this->assertFalse( self::booking( BookingStatus::Confirmed )->with_status( BookingStatus::Cancelled )->blocks_slot_at( $now ) );
		$this->assertFalse( self::booking( BookingStatus::Confirmed )->with_status( BookingStatus::Completed )->blocks_slot_at( $now ) );
	}

	public function test_booking_reschedule_and_ids(): void {
		$booking = ( new Booking( 1, 2, self::range(), BookingStatus::Confirmed, new Customer( 'A', 'a@example.com' ), public_id: 'abc123' ) )
			->with_id( 3 )
			->with_order_id( 99 );
		$moved   = $booking->rescheduled(
			new TimeRange( new DateTimeImmutable( '2026-10-02 08:00 UTC' ), new DateTimeImmutable( '2026-10-02 09:00 UTC' ) ),
			4
		);

		$this->assertSame( 3, $moved->id );
		$this->assertSame( 99, $moved->order_id );
		$this->assertSame( 'abc123', $moved->public_id );
		$this->assertSame( 4, $moved->resource_id );
		$this->assertSame( '2026-10-02', $moved->range->start->format( 'Y-m-d' ) );
		$this->assertSame( '2026-10-01', $booking->range->start->format( 'Y-m-d' ) );

		$this->expectException( InvalidValue::class );
		$booking->with_status( BookingStatus::Cancelled )->rescheduled( self::range() );
	}

	public function test_slot(): void {
		$slot = new Slot( 1, self::range() );

		$this->assertSame( '2026-10-01 08:00', $slot->start()->format( 'Y-m-d H:i' ) );
		$this->assertSame( '2026-10-01 09:00', $slot->end()->format( 'Y-m-d H:i' ) );
		$this->assertTrue( $slot->equals( new Slot( 1, self::range() ) ) );
		$this->assertFalse( $slot->equals( new Slot( 2, self::range() ) ) );
	}
}
