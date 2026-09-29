<?php
/**
 * Integration tests for the personal data exporter/eraser.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Admin;

use DateTimeZone;
use Terminarz\Admin\Privacy;
use Terminarz\Application\AvailabilitySettings;
use Terminarz\Application\FixedClock;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Infrastructure\Services;
use Terminarz\Tests\Integration\Support\BookingFixtures;
use WP_UnitTestCase;

/**
 * @covers \Terminarz\Admin\Privacy
 * @covers \Terminarz\Infrastructure\Persistence\WpdbBookingRepository::find_by_customer_email
 * @covers \Terminarz\Infrastructure\Persistence\WpdbBookingRepository::replace_customer
 */
final class PrivacyTest extends WP_UnitTestCase {

	use BookingFixtures;

	/**
	 * Composition root.
	 *
	 * @var Services
	 */
	private Services $container;

	/**
	 * Module under test.
	 *
	 * @var Privacy
	 */
	private Privacy $privacy;

	/**
	 * Resource.
	 *
	 * @var int
	 */
	private int $resource;

	/**
	 * Service.
	 *
	 * @var int
	 */
	private int $service;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// "Now" = 2030-01-10 12:00 UTC.
		$this->container = new Services( $wpdb, new FixedClock( '2030-01-10 12:00' ), new AvailabilitySettings( new DateTimeZone( 'Europe/Warsaw' ) ) );
		Services::set_instance( $this->container );
		$this->privacy = new Privacy();
		update_option( 'timezone_string', 'Europe/Warsaw' );
		update_option( 'time_format', 'H:i' );
		$this->resource = $this->make_resource( 'Room A' );
		$this->service  = $this->make_service( 60, 0, array( $this->resource ) );
	}

	public function tear_down(): void {
		Services::reset();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_registers_exporter_and_eraser(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$this->assertArrayHasKey( Privacy::EXPORTER_ID, $exporters );
		$this->assertArrayHasKey( Privacy::ERASER_ID, $erasers );
		$this->assertIsCallable( $exporters[ Privacy::EXPORTER_ID ]['callback'] );
		$this->assertIsCallable( $erasers[ Privacy::ERASER_ID ]['callback'] );
	}

	public function test_export_contains_all_bookings_of_the_customer_with_contact_data(): void {
		$this->store( '2030-01-02 09:00', 'anna@example.org', BookingStatus::Completed, 'Anna Nowak', '+48 500 100 200', 'Window seat' );
		$this->store( '2030-01-20 09:00', 'Anna@Example.org', BookingStatus::Confirmed, 'Anna Nowak' );
		$this->store( '2030-01-21 09:00', 'other@example.org', BookingStatus::Confirmed );

		$result = $this->privacy->export( 'anna@example.org', 1 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 2, $result['data'] );
		$item   = $result['data'][0];
		$values = array_column( $item['data'], 'value', 'name' );
		$this->assertSame( 'terminarz-bookings', $item['group_id'] );
		$this->assertStringStartsWith( 'terminarz-booking-', $item['item_id'] );
		$this->assertSame( 'Anna Nowak', $values['Name'] );
		$this->assertSame( 'anna@example.org', $values['E-mail'] );
		$this->assertSame( '+48 500 100 200', $values['Phone'] );
		$this->assertSame( 'Window seat', $values['Note'] );
		$this->assertSame( 'Room A', $values['Resource'] );
		$this->assertSame( 'Service', $values['Service'] );
		$this->assertSame( 'Completed', $values['Status'] );
		$this->assertStringContainsString( '10:00', $values['Start'], 'Start is shown in the site time zone.' );
	}

	public function test_export_is_paginated(): void {
		for ( $i = 0; $i < Privacy::PAGE_SIZE + 3; $i++ ) {
			$this->store( gmdate( 'Y-m-d H:i', strtotime( '2029-06-01 08:00 UTC' ) + $i * 3600 ), 'many@example.org', BookingStatus::Completed );
		}

		$first  = $this->privacy->export( 'many@example.org', 1 );
		$second = $this->privacy->export( 'many@example.org', 2 );

		$this->assertFalse( $first['done'] );
		$this->assertCount( Privacy::PAGE_SIZE, $first['data'] );
		$this->assertTrue( $second['done'] );
		$this->assertCount( 3, $second['data'] );
		$ids = array_merge( array_column( $first['data'], 'item_id' ), array_column( $second['data'], 'item_id' ) );
		$this->assertCount( Privacy::PAGE_SIZE + 3, array_unique( $ids ) );
	}

	public function test_export_of_unknown_address_is_empty(): void {
		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$this->privacy->export( 'nobody@example.org', 1 )
		);
	}

	public function test_erase_anonymises_past_and_inactive_bookings_and_keeps_the_records(): void {
		$past      = $this->store( '2030-01-02 09:00', 'anna@example.org', BookingStatus::Completed, 'Anna Nowak', '+48 500 100 200', 'Note' );
		$cancelled = $this->store( '2030-01-25 09:00', 'anna@example.org', BookingStatus::Pending, 'Anna Nowak' );
		$this->container->bookings()->change_status( (int) $cancelled->id, BookingStatus::Cancelled );
		$other = $this->store( '2030-01-03 09:00', 'other@example.org', BookingStatus::Completed );

		$result = $this->privacy->erase( 'anna@example.org', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );

		foreach ( array( $past, $cancelled ) as $booking ) {
			$stored = $this->container->bookings()->get( (int) $booking->id );
			$this->assertNotNull( $stored, 'The booking record is kept.' );
			$this->assertSame( '[deleted]', $stored->customer->name );
			$this->assertSame( 'deleted@site.invalid', $stored->customer->email );
			$this->assertSame( '', $stored->customer->phone );
			$this->assertSame( '', $stored->customer->note );
			$this->assertNull( $stored->customer->user_id );
			$this->assertSame( $booking->range->start->getTimestamp(), $stored->range->start->getTimestamp() );
			$this->assertSame( $booking->public_id, $stored->public_id );
		}
		$this->assertSame( 'other@example.org', $this->container->bookings()->get( (int) $other->id )?->customer->email );
		$this->assertSame( array(), $this->privacy->export( 'anna@example.org', 1 )['data'] );
	}

	public function test_erase_retains_upcoming_active_bookings(): void {
		$this->store( '2030-01-02 09:00', 'anna@example.org', BookingStatus::Completed );
		$upcoming = $this->store( '2030-01-20 09:00', 'anna@example.org', BookingStatus::Confirmed, 'Anna Nowak' );

		$result = $this->privacy->erase( 'anna@example.org', 1 );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['messages'] );
		$this->assertStringContainsString( (string) $upcoming->public_id, $result['messages'][0] );
		$this->assertSame( 'Anna Nowak', $this->container->bookings()->get( (int) $upcoming->id )?->customer->name );
	}

	public function test_erase_is_paginated_and_terminates(): void {
		for ( $i = 0; $i < Privacy::PAGE_SIZE + 5; $i++ ) {
			$this->store( gmdate( 'Y-m-d H:i', strtotime( '2029-06-01 08:00 UTC' ) + $i * 3600 ), 'many@example.org', BookingStatus::Completed );
		}
		$this->store( '2030-02-01 09:00', 'many@example.org', BookingStatus::Pending );

		$calls = 0;
		$page  = 1;
		do {
			$result = $this->privacy->erase( 'many@example.org', $page++ );
			++$calls;
		} while ( ! $result['done'] && $calls < 10 );

		$this->assertSame( 2, $calls );
		$this->assertTrue( $result['items_retained'] );
		$this->assertCount( 1, $this->privacy->export( 'many@example.org', 1 )['data'] );
	}

	public function test_erase_of_unknown_address_reports_nothing(): void {
		$result = $this->privacy->erase( 'nobody@example.org', 1 );

		$this->assertFalse( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_policy_content_is_suggested(): void {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';

		set_current_screen( 'options-privacy' );
		global $wp_current_filter;
		$wp_current_filter[] = 'admin_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates running inside admin_init.
		$this->privacy->add_policy_content();
		array_pop( $wp_current_filter );

		$texts = array_column( \WP_Privacy_Policy_Content::get_suggested_policy_text(), 'policy_text', 'plugin_name' );
		$this->assertArrayHasKey( 'Terminarz', $texts );
		$this->assertStringContainsString( 'e-mail address', $texts['Terminarz'] );
	}

	/**
	 * Stores a booking for the given customer.
	 *
	 * @param string        $start  Start (UTC).
	 * @param string        $email  E-mail.
	 * @param BookingStatus $status Status (inactive statuses are created as confirmed and moved on).
	 * @param string        $name   Name.
	 * @param string        $phone  Phone.
	 * @param string        $note   Note.
	 */
	private function store( string $start, string $email, BookingStatus $status, string $name = 'Jan Kowalski', string $phone = '', string $note = '' ): Booking {
		$from    = self::utc( $start );
		$booking = new Booking(
			resource_id: $this->resource,
			service_id: $this->service,
			range: new TimeRange( $from, $from->modify( '+60 minutes' ) ),
			status: $status->is_active() ? $status : BookingStatus::Confirmed,
			customer: new Customer( $name, $email, $phone, $note, 7 )
		);
		$stored  = $this->container->bookings()->create( $booking, self::utc( '2029-01-01 00:00' ) );
		if ( ! $status->is_active() ) {
			$stored = $this->container->bookings()->change_status( (int) $stored->id, $status );
		}
		return $stored;
	}
}
