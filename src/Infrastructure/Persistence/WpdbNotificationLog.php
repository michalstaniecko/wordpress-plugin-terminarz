<?php
/**
 * Log of e-mails sent about bookings.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Terminarz\Infrastructure\Database\DatabaseError;
use Terminarz\Infrastructure\Database\Schema;

/**
 * `{prefix}trmz_notification_log`: one row per (booking, message key). Claiming a key is a single
 * `INSERT IGNORE` on the primary key, so two concurrent requests can never both send the same e-mail.
 */
final class WpdbNotificationLog extends WpdbRepository {

	/**
	 * Maximum length of a message key (column size).
	 */
	public const KEY_MAX = 64;

	/**
	 * Records that a message is being sent; false when it was already recorded (do not send again).
	 *
	 * @param int               $booking_id Booking ID.
	 * @param string            $key        Message key, e.g. "customer_confirmed".
	 * @param DateTimeImmutable $now        Current time.
	 * @throws DatabaseError On failure.
	 */
	public function claim( int $booking_id, string $key, DateTimeImmutable $now ): bool {
		$table = $this->table( Schema::NOTIFICATIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$sql = $this->db->prepare( "INSERT IGNORE INTO {$table} (booking_id, message, sent_at) VALUES (%d, %s, %s)", $booking_id, substr( $key, 0, self::KEY_MAX ), $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$result = $this->db->query( $sql );
		if ( false === $result ) {
			throw DatabaseError::from_wpdb( $this->db, 'Recording a notification failed' );
		}
		return 1 === (int) $result;
	}

	/**
	 * Forgets a claim (the e-mail could not be sent).
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $key        Message key.
	 */
	public function release( int $booking_id, string $key ): void {
		$this->db->delete(
			$this->table( Schema::NOTIFICATIONS ),
			array(
				'booking_id' => $booking_id,
				'message'    => substr( $key, 0, self::KEY_MAX ),
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Whether a message was recorded.
	 *
	 * @param int    $booking_id Booking ID.
	 * @param string $key        Message key.
	 */
	public function has( int $booking_id, string $key ): bool {
		$table = $this->table( Schema::NOTIFICATIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$sql = $this->db->prepare( "SELECT 1 FROM {$table} WHERE booking_id = %d AND message = %s", $booking_id, substr( $key, 0, self::KEY_MAX ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; custom table.
		return null !== $this->db->get_var( $sql );
	}

	/**
	 * Message keys recorded for a booking.
	 *
	 * @param int $booking_id Booking ID.
	 * @return string[]
	 */
	public function keys( int $booking_id ): array {
		$table = $this->table( Schema::NOTIFICATIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$sql = $this->db->prepare( "SELECT message FROM {$table} WHERE booking_id = %d ORDER BY message", $booking_id );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; custom table.
		return array_map( 'strval', $this->db->get_col( $sql ) );
	}
}
