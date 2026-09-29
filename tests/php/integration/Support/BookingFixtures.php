<?php
/**
 * Fixture helpers for booking tests.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Support;

use DateTimeImmutable;
use DateTimeZone;
use Terminarz\Domain\Model\BookableResource;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Domain\Model\Service;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Infrastructure\Persistence\WpdbResourceRepository;
use Terminarz\Infrastructure\Persistence\WpdbServiceRepository;

/**
 * Creates resources/services and builds bookings.
 */
trait BookingFixtures {

	/**
	 * Creates a resource and returns its ID.
	 *
	 * @param string $name Name.
	 */
	protected function make_resource( string $name = 'Anna' ): int {
		global $wpdb;
		return (int) ( new WpdbResourceRepository( $wpdb ) )->save( new BookableResource( null, $name ) )->id;
	}

	/**
	 * Creates a service assigned to the given resources and returns its ID.
	 *
	 * @param int   $duration     Duration (minutes).
	 * @param int   $buffer       Buffer after (minutes).
	 * @param int[] $resource_ids Resources.
	 */
	protected function make_service( int $duration = 60, int $buffer = 0, array $resource_ids = array() ): int {
		global $wpdb;
		$repo = new WpdbServiceRepository( $wpdb );
		$id   = (int) $repo->save( new Service( null, 'Service', $duration, 10000, $buffer ) )->id;
		$repo->assign_resources( $id, $resource_ids );
		return $id;
	}

	/**
	 * Builds an unsaved booking.
	 *
	 * @param int                    $resource_id Resource.
	 * @param string                 $start       Start (UTC, "Y-m-d H:i").
	 * @param int                    $minutes     Duration.
	 * @param int                    $buffer      Buffer after.
	 * @param BookingStatus          $status      Status.
	 * @param DateTimeImmutable|null $hold        Hold expiry (pending_payment).
	 * @param int                    $service_id  Service.
	 */
	protected function booking( int $resource_id, string $start, int $minutes = 60, int $buffer = 0, BookingStatus $status = BookingStatus::Confirmed, ?DateTimeImmutable $hold = null, int $service_id = 1 ): Booking {
		$from = self::utc( $start );
		return new Booking(
			resource_id: $resource_id,
			service_id: $service_id,
			range: new TimeRange( $from, $from->modify( "+{$minutes} minutes" ) ),
			status: $status,
			customer: new Customer( 'Jan Kowalski', 'jan@example.org', '+48 600 000 000' ),
			buffer_after_minutes: $buffer,
			hold_expires_at: $hold
		);
	}

	/**
	 * Parses a UTC time.
	 *
	 * @param string $time Time.
	 */
	protected static function utc( string $time ): DateTimeImmutable {
		return new DateTimeImmutable( $time, new DateTimeZone( 'UTC' ) );
	}
}
