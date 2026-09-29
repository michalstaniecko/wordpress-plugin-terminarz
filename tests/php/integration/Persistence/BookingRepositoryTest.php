<?php
/**
 * Integration tests for the booking repository.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Persistence;

use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Exception\InvalidStatusTransition;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Exception\SlotUnavailable;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Infrastructure\Database\Schema;
use Terminarz\Infrastructure\Persistence\WpdbBookingRepository;
use Terminarz\Tests\Integration\Support\BookingFixtures;
use WP_UnitTestCase;

/**
 * @covers \Terminarz\Infrastructure\Persistence\WpdbBookingRepository
 */
final class BookingRepositoryTest extends WP_UnitTestCase {

	use BookingFixtures;

	/**
	 * Repository under test.
	 *
	 * @var WpdbBookingRepository
	 */
	private WpdbBookingRepository $bookings;

	/**
	 * Resource used by most tests.
	 *
	 * @var int
	 */
	private int $resource;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->bookings = new WpdbBookingRepository( $wpdb );
		$this->resource = $this->make_resource();
	}

	public function test_create_and_read_back(): void {
		$now    = self::utc( '2030-01-01 08:00' );
		$stored = $this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00', 45, 15 ), $now );

		$this->assertNotNull( $stored->id );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $stored->public_id );
		$this->assertEquals( $now, $stored->created_at );
		$this->assertSame( 15, $stored->buffer_after_minutes );
		$this->assertSame( '2030-01-02 10:45', $stored->range->end->format( 'Y-m-d H:i' ) );
		$this->assertSame( 'jan@example.org', $stored->customer->email );
		$this->assertEquals( $stored, $this->bookings->get( (int) $stored->id ) );
		$this->assertEquals( $stored, $this->bookings->get_by_public_id( (string) $stored->public_id ) );
		$this->assertNull( $this->bookings->get_by_public_id( 'nope' ) );
		$this->assertSame( '2030-01-02 10:00:00', $this->active_start( (int) $stored->id ) );
	}

	public function test_same_slot_conflicts(): void {
		$now = self::utc( '2030-01-01 08:00' );
		$this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00' ), $now );

		$this->expectException( SlotUnavailable::class );
		$this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00' ), $now );
	}

	/**
	 * Existing booking 10:00–11:00 with a 15 min buffer blocks [10:00, 11:15).
	 *
	 * @dataProvider provide_overlaps
	 *
	 * @param string $start     New booking start.
	 * @param int    $minutes   New booking duration.
	 * @param int    $buffer    New booking buffer.
	 * @param bool   $available Expected outcome.
	 */
	public function test_overlap_with_buffers( string $start, int $minutes, int $buffer, bool $available ): void {
		$now = self::utc( '2030-01-01 08:00' );
		$this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00', 60, 15 ), $now );

		try {
			$this->bookings->create( $this->booking( $this->resource, "2030-01-02 {$start}", $minutes, $buffer ), $now );
			$this->assertTrue( $available, "{$start} should conflict." );
		} catch ( SlotUnavailable $e ) {
			$this->assertFalse( $available, "{$start} should be free." );
		}
	}

	/**
	 * @return array<string, array{string, int, int, bool}>
	 */
	public static function provide_overlaps(): array {
		return array(
			'inside existing buffer'       => array( '11:00', 30, 0, false ),
			'right after existing buffer'  => array( '11:15', 30, 0, true ),
			'ends when existing starts'    => array( '09:00', 60, 0, true ),
			'overlaps existing start'      => array( '09:15', 60, 0, false ),
			'own buffer overlaps existing' => array( '09:00', 60, 15, false ),
			'own buffer ends at existing'  => array( '08:45', 60, 15, true ),
			'contained in existing'        => array( '10:15', 15, 0, false ),
			'containing existing'          => array( '09:00', 180, 0, false ),
		);
	}

	public function test_other_resource_is_independent(): void {
		$now   = self::utc( '2030-01-01 08:00' );
		$other = $this->make_resource( 'Other' );
		$this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00' ), $now );

		$this->assertNotNull( $this->bookings->create( $this->booking( $other, '2030-01-02 10:00' ), $now )->id );
	}

	public function test_cancellation_releases_slot(): void {
		$now    = self::utc( '2030-01-01 08:00' );
		$stored = $this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00' ), $now );

		$cancelled = $this->bookings->change_status( (int) $stored->id, BookingStatus::Cancelled );

		$this->assertSame( BookingStatus::Cancelled, $cancelled->status );
		$this->assertNull( $this->active_start( (int) $stored->id ) );
		$this->assertNotNull( $this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00' ), $now )->id );
	}

	public function test_active_hold_blocks_and_expired_hold_is_released(): void {
		$now  = self::utc( '2030-01-01 08:00' );
		$hold = $this->bookings->create(
			$this->booking( $this->resource, '2030-01-02 10:00', 60, 0, BookingStatus::PendingPayment, self::utc( '2030-01-01 08:15' ) ),
			$now
		);

		try {
			$this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00' ), self::utc( '2030-01-01 08:14' ) );
			$this->fail( 'An active hold must block the slot.' );
		} catch ( SlotUnavailable $e ) {
			$this->assertSame( BookingStatus::PendingPayment, $this->bookings->get( (int) $hold->id )->status );
		}

		$later = self::utc( '2030-01-01 08:15' );
		$this->assertSame( array(), $this->bookings->busy_ranges( array( $this->resource ), $this->day(), $later )[ $this->resource ] );

		$new = $this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00' ), $later );
		$this->assertNotNull( $new->id );
		$this->assertSame( BookingStatus::Expired, $this->bookings->get( (int) $hold->id )->status );
		$this->assertNull( $this->active_start( (int) $hold->id ) );
	}

	public function test_unique_index_is_the_last_line_of_defence(): void {
		global $wpdb;
		// A row whose range does not overlap (so the overlap check passes) but which holds the same active start.
		$wpdb->insert(
			Schema::from_globals()->table( Schema::BOOKINGS ),
			array(
				'public_id'        => 'raw',
				'service_id'       => 1,
				'resource_id'      => $this->resource,
				'start_utc'        => '2030-06-01 10:00:00',
				'end_utc'          => '2030-06-01 11:00:00',
				'buffer_end_utc'   => '2030-06-01 11:00:00',
				'active_start_utc' => '2030-01-02 10:00:00',
				'status'           => 'confirmed',
				'created_at'       => '2030-01-01 00:00:00',
				'updated_at'       => '2030-01-01 00:00:00',
			)
		);

		$this->expectException( SlotUnavailable::class );
		$this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00' ), self::utc( '2030-01-01 08:00' ) );
	}

	public function test_invalid_transition_is_rejected(): void {
		$stored = $this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00' ), self::utc( '2030-01-01 08:00' ) );
		$this->bookings->change_status( (int) $stored->id, BookingStatus::Cancelled );

		$this->expectException( InvalidStatusTransition::class );
		$this->bookings->change_status( (int) $stored->id, BookingStatus::Confirmed );
	}

	public function test_pending_payment_can_be_confirmed_and_keeps_slot(): void {
		$stored = $this->bookings->create(
			$this->booking( $this->resource, '2030-01-02 10:00', 60, 0, BookingStatus::PendingPayment, self::utc( '2030-01-01 08:15' ) ),
			self::utc( '2030-01-01 08:00' )
		);

		$confirmed = $this->bookings->change_status( (int) $stored->id, BookingStatus::Confirmed );
		$this->assertSame( BookingStatus::Confirmed, $confirmed->status );
		$this->assertSame( '2030-01-02 10:00:00', $this->active_start( (int) $stored->id ) );
	}

	public function test_reschedule_moves_booking_atomically(): void {
		$now    = self::utc( '2030-01-01 08:00' );
		$stored = $this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00', 60, 15 ), $now );
		$this->bookings->create( $this->booking( $this->resource, '2030-01-02 14:00' ), $now );

		// Overlapping its own current slot is fine.
		$moved = $this->bookings->reschedule( (int) $stored->id, $this->range( '2030-01-02 10:30', 60 ), null, $now );
		$this->assertSame( '2030-01-02 10:30', $moved->range->start->format( 'Y-m-d H:i' ) );
		$this->assertSame( 15, $moved->buffer_after_minutes );
		$this->assertSame( '2030-01-02 10:30:00', $this->active_start( (int) $stored->id ) );

		// The freed start can be booked by someone else.
		$this->assertNotNull( $this->bookings->create( $this->booking( $this->resource, '2030-01-02 09:00', 60 ), $now )->id );

		try {
			$this->bookings->reschedule( (int) $stored->id, $this->range( '2030-01-02 13:00', 60 ), null, $now );
			$this->fail( 'Buffer overlapping the 14:00 booking must conflict.' );
		} catch ( SlotUnavailable $e ) {
			$this->assertSame( '2030-01-02 10:30', $this->bookings->get( (int) $stored->id )->range->start->format( 'Y-m-d H:i' ) );
		}
	}

	public function test_reschedule_to_another_resource(): void {
		$now    = self::utc( '2030-01-01 08:00' );
		$other  = $this->make_resource( 'Other' );
		$stored = $this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00' ), $now );

		$moved = $this->bookings->reschedule( (int) $stored->id, $this->range( '2030-01-02 10:00', 60 ), $other, $now );

		$this->assertSame( $other, $moved->resource_id );
		$this->assertNotNull( $this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00' ), $now )->id );
	}

	public function test_inactive_booking_cannot_be_rescheduled(): void {
		$now    = self::utc( '2030-01-01 08:00' );
		$stored = $this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00' ), $now );
		$this->bookings->change_status( (int) $stored->id, BookingStatus::Cancelled );

		$this->expectException( InvalidValue::class );
		$this->bookings->reschedule( (int) $stored->id, $this->range( '2030-01-02 12:00', 60 ), null, $now );
	}

	public function test_missing_resource_and_booking(): void {
		try {
			$this->bookings->create( $this->booking( 999999, '2030-01-02 10:00' ), self::utc( '2030-01-01 08:00' ) );
			$this->fail( 'Missing resource accepted.' );
		} catch ( EntityNotFound $e ) {
			$this->assertStringContainsString( 'resource', $e->getMessage() );
		}

		$this->expectException( EntityNotFound::class );
		$this->bookings->change_status( 999999, BookingStatus::Cancelled );
	}

	public function test_busy_ranges_include_buffers_and_skip_inactive(): void {
		$now   = self::utc( '2030-01-01 08:00' );
		$other = $this->make_resource( 'Other' );
		$this->bookings->create( $this->booking( $this->resource, '2030-01-02 12:00', 60, 15 ), $now );
		$this->bookings->create( $this->booking( $this->resource, '2030-01-02 09:00', 30 ), $now );
		$cancelled = $this->bookings->create( $this->booking( $other, '2030-01-02 09:00', 30 ), $now );
		$this->bookings->change_status( (int) $cancelled->id, BookingStatus::Cancelled );
		$this->bookings->create( $this->booking( $other, '2030-01-03 09:00', 30 ), $now );

		$busy = $this->bookings->busy_ranges( array( $this->resource, $other, 424242 ), $this->day(), $now );

		$this->assertSame( array( $this->resource, $other, 424242 ), array_keys( $busy ) );
		$this->assertSame(
			array( '09:00-09:30', '12:00-13:15' ),
			array_map( static fn( TimeRange $r ): string => $r->start->format( 'H:i' ) . '-' . $r->end->format( 'H:i' ), $busy[ $this->resource ] )
		);
		$this->assertSame( array(), $busy[ $other ] );
		$this->assertSame( array(), $busy[424242] );
	}

	public function test_in_range_lists_all_statuses(): void {
		$now = self::utc( '2030-01-01 08:00' );
		$a   = $this->bookings->create( $this->booking( $this->resource, '2030-01-02 12:00' ), $now );
		$b   = $this->bookings->create( $this->booking( $this->resource, '2030-01-02 09:00' ), $now );
		$this->bookings->change_status( (int) $a->id, BookingStatus::Cancelled );

		$ids = array_map( static fn( $booking ) => $booking->id, $this->bookings->in_range( $this->day() ) );
		$this->assertSame( array( $b->id, $a->id ), $ids );
		$this->assertSame( array(), $this->bookings->in_range( $this->day(), array() ) );
	}

	public function test_expire_holds(): void {
		$now  = self::utc( '2030-01-01 08:00' );
		$hold = $this->bookings->create(
			$this->booking( $this->resource, '2030-01-02 10:00', 60, 0, BookingStatus::PendingPayment, self::utc( '2030-01-01 08:15' ) ),
			$now
		);
		$this->bookings->create(
			$this->booking( $this->resource, '2030-01-02 12:00', 60, 0, BookingStatus::PendingPayment, self::utc( '2030-01-01 09:00' ) ),
			$now
		);

		$this->assertSame( array(), $this->bookings->expire_holds( self::utc( '2030-01-01 08:10' ) ) );
		$this->assertSame( array( $hold->id ), $this->bookings->expire_holds( self::utc( '2030-01-01 08:30' ) ) );
		$this->assertSame( BookingStatus::Expired, $this->bookings->get( (int) $hold->id )->status );
	}

	public function test_attach_order(): void {
		$stored = $this->bookings->create( $this->booking( $this->resource, '2030-01-02 10:00' ), self::utc( '2030-01-01 08:00' ) );
		$this->assertSame( 77, $this->bookings->attach_order( (int) $stored->id, 77 )->order_id );
	}

	private function day(): TimeRange {
		return new TimeRange( self::utc( '2030-01-02 00:00' ), self::utc( '2030-01-03 00:00' ) );
	}

	private function range( string $start, int $minutes ): TimeRange {
		$from = self::utc( $start );
		return new TimeRange( $from, $from->modify( "+{$minutes} minutes" ) );
	}

	private function active_start( int $id ): ?string {
		global $wpdb;
		$table = Schema::from_globals()->table( Schema::BOOKINGS );
		return $wpdb->get_var( $wpdb->prepare( "SELECT active_start_utc FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
