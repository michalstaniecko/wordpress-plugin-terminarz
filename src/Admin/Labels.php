<?php
/**
 * Translated labels and date formatting shared by admin screens and integrations.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use DateTimeImmutable;
use Terminarz\Domain\Model\BookableResource;
use Terminarz\Domain\Model\BookingStatus;

/**
 * Presentation helpers (translations live outside the domain, ADR-015).
 */
final class Labels {

	/**
	 * Translated label of a booking status.
	 *
	 * @param BookingStatus $status Status.
	 */
	public static function status( BookingStatus $status ): string {
		return match ( $status ) {
			BookingStatus::Pending => _x( 'Pending', 'booking status', 'terminarz' ),
			BookingStatus::PendingPayment => _x( 'Awaiting payment', 'booking status', 'terminarz' ),
			BookingStatus::Confirmed => _x( 'Confirmed', 'booking status', 'terminarz' ),
			BookingStatus::Cancelled => _x( 'Cancelled', 'booking status', 'terminarz' ),
			BookingStatus::Expired => _x( 'Expired', 'booking status', 'terminarz' ),
			BookingStatus::Completed => _x( 'Completed', 'booking status', 'terminarz' ),
		};
	}

	/**
	 * Labels of all statuses keyed by stored value.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		$labels = array();
		foreach ( BookingStatus::cases() as $status ) {
			$labels[ $status->value ] = self::status( $status );
		}
		return $labels;
	}

	/**
	 * Translated labels of resource types keyed by stored value.
	 *
	 * @return array<string, string>
	 */
	public static function resource_types(): array {
		return array(
			BookableResource::TYPE_PERSON => _x( 'Person', 'resource type', 'terminarz' ),
			BookableResource::TYPE_ROOM   => _x( 'Room', 'resource type', 'terminarz' ),
			BookableResource::TYPE_DEVICE => _x( 'Device', 'resource type', 'terminarz' ),
		);
	}

	/**
	 * Translated label of a resource type.
	 *
	 * @param string $type Stored type.
	 */
	public static function resource_type( string $type ): string {
		return self::resource_types()[ $type ] ?? $type;
	}

	/**
	 * Date and time in the site time zone, using the site's date and time formats.
	 *
	 * @param DateTimeImmutable $time Instant.
	 */
	public static function datetime( DateTimeImmutable $time ): string {
		$format = trim( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ) );
		return (string) wp_date( $format, $time->getTimestamp() );
	}

	/**
	 * Time of day in the site time zone.
	 *
	 * @param DateTimeImmutable $time Instant.
	 */
	public static function time( DateTimeImmutable $time ): string {
		return (string) wp_date( (string) get_option( 'time_format', 'H:i' ), $time->getTimestamp() );
	}
}
