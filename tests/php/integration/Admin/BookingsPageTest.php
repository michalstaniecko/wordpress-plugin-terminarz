<?php
/**
 * Integration tests for the bookings screen.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Admin;

use DateTimeZone;
use Terminarz\Admin\BookingFilters;
use Terminarz\Admin\BookingsPage;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Domain\Model\TimeWindow;
use Terminarz\Domain\Model\WeeklySchedule;

/**
 * "Now" = Monday 2030-01-07 06:00 UTC (07:00 Warsaw). Resources work Mon–Fri 09:00–17:00 Warsaw (08:00–16:00 UTC).
 *
 * @covers \Terminarz\Admin\BookingsPage
 * @covers \Terminarz\Admin\BookingsListTable
 * @covers \Terminarz\Admin\BookingFilters
 */
final class BookingsPageTest extends AdminTestCase {

	/**
	 * Screen under test.
	 *
	 * @var BookingsPage
	 */
	private BookingsPage $page;

	/**
	 * Resources.
	 *
	 * @var int
	 */
	private int $anna;

	/**
	 * Resources.
	 *
	 * @var int
	 */
	private int $bob;

	/**
	 * Service (60 min).
	 *
	 * @var int
	 */
	private int $service;

	public function set_up(): void {
		parent::set_up();
		$this->page    = new BookingsPage();
		$this->anna    = $this->make_resource( 'Anna' );
		$this->bob     = $this->make_resource( 'Bob' );
		$this->service = $this->make_service( 60, 0, array( $this->anna, $this->bob ) );
		$week          = new WeeklySchedule( array_fill_keys( array( 1, 2, 3, 4, 5 ), array( TimeWindow::from_strings( '09:00', '17:00' ) ) ) );
		$this->container->schedules()->save( $this->anna, $week );
		$this->container->schedules()->save( $this->bob, $week );
	}

	public function test_list_shows_local_times_filters_and_escapes(): void {
		$first  = $this->reserve( $this->anna, '2030-01-08 08:00', '<b>Ewa</b>', 'ewa@example.org' );
		$second = $this->reserve( $this->bob, '2030-01-09 10:00', 'Jan Nowak', 'jan@example.org' );
		$this->container->booking_service()->change_status( (int) $second->id, BookingStatus::Confirmed );

		$html = $this->render( $this->page, array( 'page' => BookingsPage::SLUG ) );
		$this->assertStringContainsString( '2030-01-08 09:00–10:00', $html, 'Start in the site time zone.' );
		$this->assertStringContainsString( '&lt;b&gt;Ewa&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>Ewa', $html );
		$this->assertStringContainsString( 'Europe/Warsaw', $html );
		$this->assertStringContainsString( 'action=trmz_confirm_booking', $html );

		$html = $this->render(
			$this->page,
			array(
				'page'   => BookingsPage::SLUG,
				'status' => 'confirmed',
			)
		);
		$this->assertStringContainsString( 'Jan Nowak', $html );
		$this->assertStringNotContainsString( 'Ewa', $html );

		$html = $this->render(
			$this->page,
			array(
				'page' => BookingsPage::SLUG,
				's'    => 'ewa@',
			)
		);
		$this->assertStringContainsString( 'Ewa', $html );
		$this->assertStringNotContainsString( 'Jan Nowak', $html );

		$html = $this->render(
			$this->page,
			array(
				'page'     => BookingsPage::SLUG,
				'resource' => $this->bob,
				'from'     => '2030-01-09',
				'to'       => '2030-01-09',
			)
		);
		$this->assertStringContainsString( 'Jan Nowak', $html );
		$this->assertStringNotContainsString( 'Ewa', $html );
		$this->assertNotNull( $first->id );
	}

	public function test_list_is_paged(): void {
		for ( $i = 0; $i < 25; $i++ ) {
			$day = 8 + intdiv( $i, 8 );
			$this->reserve( $this->anna, sprintf( '2030-01-%02d %02d:00', $day, 8 + $i % 8 ), 'Customer ' . $i, "c{$i}@example.org" );
		}

		$first = $this->render( $this->page, array( 'page' => BookingsPage::SLUG ) );
		$this->assertStringContainsString( '25 items', $first );
		$this->assertStringContainsString( 'Customer 19<', $first );
		$this->assertStringNotContainsString( 'Customer 20<', $first );

		$second = $this->render(
			$this->page,
			array(
				'page'  => BookingsPage::SLUG,
				'paged' => 2,
			)
		);
		$this->assertStringContainsString( 'Customer 24<', $second );
		$this->assertStringNotContainsString( 'Customer 3<', $second );
	}

	public function test_filters_convert_local_dates_to_utc_range_and_keep_query_args(): void {
		$filters  = BookingFilters::from_query(
			array(
				'status'  => 'pending',
				'from'    => '2030-07-02',
				'to'      => '2030-07-01',
				's'       => ' anna ',
				'orderby' => 'created',
				'order'   => 'desc',
				'service' => 'x',
			)
		);
		$criteria = $filters->criteria( new DateTimeZone( 'Europe/Warsaw' ) );

		$this->assertSame( array( BookingStatus::Pending ), $criteria->statuses );
		$this->assertSame( '2030-06-30 22:00', $criteria->starts_in->start->format( 'Y-m-d H:i' ), 'Swapped dates, local midnight in UTC.' );
		$this->assertSame( '2030-07-02 22:00', $criteria->starts_in->end->format( 'Y-m-d H:i' ) );
		$this->assertSame( 'anna', $criteria->search );
		$this->assertTrue( $criteria->descending );
		$this->assertNull( $criteria->service_id );
		$this->assertSame(
			array(
				'status'  => 'pending',
				'from'    => '2030-07-01',
				'to'      => '2030-07-02',
				's'       => 'anna',
				'orderby' => 'created',
				'order'   => 'desc',
			),
			$filters->query_args()
		);
	}

	public function test_confirm_and_cancel_follow_the_state_machine(): void {
		$booking = $this->reserve( $this->anna, '2030-01-08 08:00' );
		$fired   = did_action( 'trmz_booking_status_changed' );

		$this->run_action( $this->page, 'confirm_booking', array( 'id' => (string) $booking->id ) );
		$this->assertSame( BookingStatus::Confirmed, $this->container->bookings()->get( (int) $booking->id )->status );
		$this->assertSame( $fired + 1, did_action( 'trmz_booking_status_changed' ) );
		$this->assertSame( array( 'Booking confirmed.' ), $this->notices( 'success' ) );

		$this->run_action( $this->page, 'confirm_booking', array( 'id' => (string) $booking->id ) );
		$this->assertStringContainsString( 'not possible', $this->notices( 'error' )[0] );

		$url = $this->run_action( $this->page, 'cancel_booking', array( 'id' => (string) $booking->id ) );
		$this->assertSame( BookingStatus::Cancelled, $this->container->bookings()->get( (int) $booking->id )->status );
		$this->assertStringContainsString( 'page=trmz-bookings', $url );

		// The slot is free again.
		$this->assertNotNull( $this->reserve( $this->anna, '2030-01-08 08:00' )->id );
	}

	public function test_action_returns_to_the_filtered_list(): void {
		$booking                 = $this->reserve( $this->anna, '2030-01-08 08:00' );
		$_SERVER['HTTP_REFERER'] = admin_url( 'admin.php?page=trmz-bookings&status=pending&paged=2' );

		$url = $this->run_action( $this->page, 'confirm_booking', array( 'id' => (string) $booking->id ) );
		unset( $_SERVER['HTTP_REFERER'] );

		$this->assertStringContainsString( 'status=pending', $url );
	}

	public function test_reschedule_lists_free_slots_and_moves_atomically(): void {
		$booking = $this->reserve( $this->anna, '2030-01-08 08:00' );
		$this->reserve( $this->anna, '2030-01-08 10:00' );

		$slots = $this->page->reschedule_slots( $booking, '2030-01-08' );
		$anna  = array_map( static fn( $s ) => $s->start()->format( 'H:i' ), array_values( array_filter( $slots, fn( $s ) => $s->resource_id === $this->anna ) ) );
		$this->assertContains( '08:00', $anna, 'Own time is free for the booking itself.' );
		$this->assertNotContains( '10:00', $anna, 'Taken by another booking.' );
		$this->assertNotContains( '09:30', $anna, 'Would overlap the other booking.' );

		$html = $this->render(
			$this->page,
			array(
				'page' => BookingsPage::SLUG,
				'view' => 'reschedule',
				'id'   => (int) $booking->id,
				'date' => '2030-01-08',
			)
		);
		$this->assertStringContainsString( 'value="trmz_reschedule_booking"', $html );
		$this->assertStringContainsString( '12:00–13:00 — Anna', $html, 'Slot labels in the site time zone (11:00 UTC).' );

		$target = self::utc( '2030-01-08 13:00' )->getTimestamp();
		$url    = $this->run_action(
			$this->page,
			'reschedule_booking',
			array(
				'id'   => (string) $booking->id,
				'date' => '2030-01-08',
				'slot' => $target . ':' . $this->bob,
			)
		);

		$moved = $this->container->bookings()->get( (int) $booking->id );
		$this->assertSame( $target, $moved->range->start->getTimestamp() );
		$this->assertSame( $this->bob, $moved->resource_id );
		$this->assertStringContainsString( 'view=view', $url );
		$this->assertStringContainsString( 'Booking moved to 2030-01-08 14:00', $this->notices( 'success' )[0] );
	}

	public function test_reschedule_to_a_taken_slot_is_refused(): void {
		$booking = $this->reserve( $this->anna, '2030-01-08 08:00' );
		$other   = $this->reserve( $this->anna, '2030-01-08 12:00' );

		$url = $this->run_action(
			$this->page,
			'reschedule_booking',
			array(
				'id'   => (string) $booking->id,
				'date' => '2030-01-08',
				'slot' => $other->range->start->getTimestamp() . ':' . $this->anna,
			)
		);

		$this->assertStringContainsString( 'no longer available', $this->notices( 'error' )[0] );
		$this->assertStringContainsString( 'view=reschedule', $url );
		$this->assertSame( '2030-01-08 08:00', $this->container->bookings()->get( (int) $booking->id )->range->start->format( 'Y-m-d H:i' ) );

		$this->run_action(
			$this->page,
			'reschedule_booking',
			array(
				'id'   => (string) $booking->id,
				'slot' => 'garbage',
			)
		);
		$this->assertStringContainsString( 'Choose a new time', implode( ' ', $this->notices( 'error' ) ) );
	}

	public function test_details_view(): void {
		$booking = $this->container->booking_service()->reserve(
			$this->service,
			$this->anna,
			self::utc( '2030-01-08 08:00' ),
			new Customer( 'Ewa', 'ewa@example.org', '+48 600', "Line 1\n<script>x</script>" )
		)->booking;

		$html = $this->render(
			$this->page,
			array(
				'page' => BookingsPage::SLUG,
				'view' => 'view',
				'id'   => (int) $booking->id,
			)
		);

		$this->assertStringContainsString( (string) $booking->public_id, $html );
		$this->assertStringContainsString( 'mailto:ewa@example.org', $html );
		$this->assertStringContainsString( 'Line 1<br />', $html );
		$this->assertStringNotContainsString( '<script>x', $html );
		$this->assertStringContainsString( 'value="trmz_confirm_booking"', $html );
		$this->assertStringContainsString( 'value="trmz_cancel_booking"', $html );
		$this->assertStringContainsString( 'Guest', $html );
	}

	public function test_actions_require_capability_and_nonce(): void {
		$booking = $this->reserve( $this->anna, '2030-01-08 08:00' );

		$this->assertDies( fn() => $this->run_action( $this->page, 'cancel_booking', array( 'id' => (string) $booking->id ), 'bad' ) );
		$this->assertDies( fn() => $this->run_action( $this->page, 'cancel_booking', array( 'id' => (string) $booking->id ), wp_create_nonce( 'trmz_confirm_booking' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertDies( fn() => $this->run_action( $this->page, 'cancel_booking', array( 'id' => (string) $booking->id ) ) );
		$this->assertDies( fn() => $this->render( $this->page, array( 'page' => BookingsPage::SLUG ) ) );

		$this->assertSame( BookingStatus::Pending, $this->container->bookings()->get( (int) $booking->id )->status );
	}

	/**
	 * Reserves through the booking service (pending).
	 *
	 * @param int    $resource_id Resource.
	 * @param string $start       Start (UTC).
	 * @param string $name        Customer name.
	 * @param string $email       Customer e-mail.
	 */
	private function reserve( int $resource_id, string $start, string $name = 'Jan Kowalski', string $email = 'jan@example.org' ): Booking {
		return $this->container->booking_service()->reserve( $this->service, $resource_id, self::utc( $start ), new Customer( $name, $email ) )->booking;
	}
}
