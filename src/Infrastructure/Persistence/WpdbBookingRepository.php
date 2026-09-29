<?php
/**
 * $wpdb implementation of BookingRepository with atomic slot booking.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Exception\SlotUnavailable;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Domain\Repository\BookingCriteria;
use Terminarz\Domain\Repository\BookingRepository;
use Terminarz\Infrastructure\Database\DatabaseError;
use Terminarz\Infrastructure\Database\Schema;

/**
 * Stores bookings in `{prefix}trmz_bookings`.
 *
 * Atomicity (docs/ARCHITECTURE.md, ADR-014): every write that occupies a slot runs in an InnoDB transaction that
 * first locks the resource row (`SELECT … FOR UPDATE`), which serialises bookings per resource; then it checks
 * overlapping blocking bookings (appointment + buffer), and finally writes. The UNIQUE index
 * (resource_id, active_start_utc) is the last line of defence: a duplicate-key error becomes SlotUnavailable.
 */
final class WpdbBookingRepository extends WpdbRepository implements BookingRepository {

	private const COLUMNS = 'id, public_id, service_id, resource_id, start_utc, end_utc, buffer_end_utc, status, customer_name, customer_email, customer_phone, customer_note, customer_user_id, cancel_token_hash, order_id, hold_expires_at, created_at';

	private const DATETIME = 'Y-m-d H:i:s';

	/**
	 * Name of the UNIQUE index guarding active slot starts.
	 */
	private const ACTIVE_START_INDEX = 'resource_active_start';

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Booking ID.
	 */
	public function get( int $id ): ?Booking {
		return $this->find_one( 'id = %d', $id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $public_id Public ID.
	 */
	public function get_by_public_id( string $public_id ): ?Booking {
		if ( '' === $public_id ) {
			return null;
		}
		return $this->find_one( 'public_id = %s', $public_id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Booking           $booking New booking.
	 * @param DateTimeImmutable $now     Current time.
	 * @throws InvalidValue When the booking already has an ID or is not active.
	 */
	public function create( Booking $booking, DateTimeImmutable $now ): Booking {
		if ( null !== $booking->id ) {
			throw new InvalidValue( 'Only new bookings (without an ID) can be created.' );
		}
		if ( ! $booking->status->is_active() ) {
			throw new InvalidValue( 'New bookings must have an active status.' );
		}

		$public_id = $booking->public_id ?? self::random_public_id();
		$now_sql   = self::to_sql( $now );

		return $this->transaction->run(
			function () use ( $booking, $public_id, $now, $now_sql ): Booking {
				$this->lock_resource( $booking->resource_id );
				$this->assert_free( $booking->resource_id, $booking->blocked_range(), $now, null );

				$blocked = $booking->blocked_range();
				$data    = array(
					'public_id'         => $public_id,
					'service_id'        => $booking->service_id,
					'resource_id'       => $booking->resource_id,
					'start_utc'         => self::to_sql( $booking->range->start ),
					'end_utc'           => self::to_sql( $booking->range->end ),
					'buffer_end_utc'    => self::to_sql( $blocked->end ),
					'active_start_utc'  => self::to_sql( $booking->range->start ),
					'status'            => $booking->status->value,
					'customer_name'     => $booking->customer->name,
					'customer_email'    => $booking->customer->email,
					'customer_phone'    => $booking->customer->phone,
					'customer_note'     => $booking->customer->note,
					'customer_user_id'  => $booking->customer->user_id,
					'cancel_token_hash' => $booking->cancel_secret, // Column name predates ADR-042: it stores the link secret.
					'order_id'          => $booking->order_id,
					'hold_expires_at'   => null === $booking->hold_expires_at ? null : self::to_sql( $booking->hold_expires_at ),
					'created_at'        => $now_sql,
					'updated_at'        => $now_sql,
				);

				$suppress = $this->db->suppress_errors( true );
				$result   = $this->db->insert( $this->table( Schema::BOOKINGS ), $data );
				$this->db->suppress_errors( $suppress );

				if ( false === $result ) {
					$this->throw_write_error( $booking->resource_id, $booking->range->start, 'Insert booking' );
				}

				return $this->require_booking( (int) $this->db->insert_id );
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int               $id          Booking ID.
	 * @param TimeRange         $range       New appointment range.
	 * @param int|null          $resource_id New resource, null = keep.
	 * @param DateTimeImmutable $now         Current time.
	 * @throws EntityNotFound When the booking does not exist.
	 * @throws SlotUnavailable When the booking was moved to another resource concurrently.
	 */
	public function reschedule( int $id, TimeRange $range, ?int $resource_id, DateTimeImmutable $now ): Booking {
		$current = $this->get( $id );
		if ( null === $current ) {
			throw EntityNotFound::with_id( 'booking', $id );
		}
		$target_resource = $resource_id ?? $current->resource_id;

		return $this->transaction->run(
			function () use ( $id, $range, $target_resource, $resource_id, $now ): Booking {
				// Same lock order as create(): resource row first, then booking rows.
				$this->lock_resource( $target_resource );
				$booking = $this->find_one( 'id = %d', $id, true );
				if ( null === $booking ) {
					throw EntityNotFound::with_id( 'booking', $id );
				}
				if ( null === $resource_id && $booking->resource_id !== $target_resource ) {
					throw SlotUnavailable::at( $target_resource, $range->start );
				}

				$moved = $booking->rescheduled( $range, $target_resource );
				$this->assert_free( $target_resource, $moved->blocked_range(), $now, $id );

				$suppress = $this->db->suppress_errors( true );
				$result   = $this->db->update(
					$this->table( Schema::BOOKINGS ),
					array(
						'resource_id'      => $target_resource,
						'start_utc'        => self::to_sql( $moved->range->start ),
						'end_utc'          => self::to_sql( $moved->range->end ),
						'buffer_end_utc'   => self::to_sql( $moved->blocked_range()->end ),
						'active_start_utc' => self::to_sql( $moved->range->start ),
						'updated_at'       => self::to_sql( $now ),
					),
					array( 'id' => $id )
				);
				$this->db->suppress_errors( $suppress );

				if ( false === $result ) {
					$this->throw_write_error( $target_resource, $moved->range->start, 'Reschedule booking' );
				}

				return $this->require_booking( $id );
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int           $id     Booking ID.
	 * @param BookingStatus $target Target status.
	 * @throws EntityNotFound When the booking does not exist.
	 */
	public function change_status( int $id, BookingStatus $target ): Booking {
		return $this->transaction->run(
			function () use ( $id, $target ): Booking {
				$booking = $this->find_one( 'id = %d', $id, true );
				if ( null === $booking ) {
					throw EntityNotFound::with_id( 'booking', $id );
				}

				$changed = $booking->with_status( $target );
				$this->update(
					$this->table( Schema::BOOKINGS ),
					array(
						'status'           => $changed->status->value,
						'active_start_utc' => $changed->status->is_active() ? self::to_sql( $changed->range->start ) : null,
						'updated_at'       => $this->now(),
					),
					array( 'id' => $id )
				);

				return $this->require_booking( $id );
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id       Booking ID.
	 * @param int $order_id Order ID.
	 * @throws InvalidValue When the order ID is not positive.
	 */
	public function attach_order( int $id, int $order_id ): Booking {
		if ( $order_id <= 0 ) {
			throw new InvalidValue( 'Order id must be a positive integer.' );
		}
		$this->require_booking( $id );
		$this->update(
			$this->table( Schema::BOOKINGS ),
			array(
				'order_id'   => $order_id,
				'updated_at' => $this->now(),
			),
			array( 'id' => $id )
		);
		return $this->require_booking( $id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int[]             $resource_ids Resource IDs.
	 * @param TimeRange         $range        Range of interest.
	 * @param DateTimeImmutable $now          Current time.
	 * @param int|null          $exclude_id   Booking to ignore.
	 * @return array<int, TimeRange[]>
	 */
	public function busy_ranges( array $resource_ids, TimeRange $range, DateTimeImmutable $now, ?int $exclude_id = null ): array {
		$resource_ids = self::ids( $resource_ids );
		$result       = array_fill_keys( $resource_ids, array() );
		if ( array() === $resource_ids ) {
			return $result;
		}

		$table = $this->table( Schema::BOOKINGS );
		$args  = array_merge(
			$resource_ids,
			array( self::to_sql( $range->end ), self::to_sql( $range->start ), BookingStatus::PendingPayment->value, self::to_sql( $now ), $exclude_id ?? 0 )
		);
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table from Schema, placeholders generated.
		$sql = $this->db->prepare(
			"SELECT resource_id, start_utc, buffer_end_utc FROM {$table}
			WHERE resource_id IN (" . self::int_placeholders( $resource_ids ) . ')
			AND active_start_utc IS NOT NULL
			AND start_utc < %s AND buffer_end_utc > %s
			AND NOT ( status = %s AND hold_expires_at IS NOT NULL AND hold_expires_at <= %s )
			AND id <> %d
			ORDER BY resource_id, start_utc',
			$args
		);
		// phpcs:enable

		foreach ( $this->rows( $sql ) as $row ) {
			$result[ (int) $row['resource_id'] ][] = new TimeRange( self::from_sql( (string) $row['start_utc'] ), self::from_sql( (string) $row['buffer_end_utc'] ) );
		}
		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param TimeRange  $range        Range.
	 * @param int[]|null $resource_ids Resources; null = all.
	 * @return Booking[]
	 */
	public function in_range( TimeRange $range, ?array $resource_ids = null ): array {
		$table = $this->table( Schema::BOOKINGS );
		$where = 'start_utc < %s AND end_utc > %s';
		$args  = array( self::to_sql( $range->end ), self::to_sql( $range->start ) );

		if ( null !== $resource_ids ) {
			$resource_ids = self::ids( $resource_ids );
			if ( array() === $resource_ids ) {
				return array();
			}
			$where .= ' AND resource_id IN (' . self::int_placeholders( $resource_ids ) . ')';
			$args   = array_merge( $args, $resource_ids );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table from Schema, placeholders generated.
		$rows = $this->rows( $this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE {$where} ORDER BY start_utc, id", $args ) );

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param BookingCriteria $criteria Criteria.
	 * @return Booking[]
	 */
	public function search( BookingCriteria $criteria ): array {
		$table            = $this->table( Schema::BOOKINGS );
		[ $where, $args ] = $this->criteria_where( $criteria );
		$column           = BookingCriteria::ORDER_CREATED === $criteria->order_by ? 'created_at' : 'start_utc';
		$direction        = $criteria->descending ? 'DESC' : 'ASC';
		$args[]           = $criteria->limit;
		$args[]           = $criteria->offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table from Schema, order from a whitelist, placeholders generated.
		$rows = $this->rows( $this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE {$where} ORDER BY {$column} {$direction}, id {$direction} LIMIT %d OFFSET %d", $args ) );

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param BookingCriteria $criteria Criteria.
	 * @throws DatabaseError When the query fails.
	 */
	public function count( BookingCriteria $criteria ): int {
		$table            = $this->table( Schema::BOOKINGS );
		[ $where, $args ] = $this->criteria_where( $criteria );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table from Schema, placeholders generated.
		$sql   = array() === $args ? "SELECT COUNT(*) FROM {$table} WHERE {$where}" : $this->db->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args );
		$count = $this->db->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above (or no arguments).
		if ( null === $count && '' !== $this->db->last_error ) {
			throw DatabaseError::from_wpdb( $this->db, 'Count bookings' );
		}
		return (int) $count;
	}

	/**
	 * WHERE clause (with placeholders) and its arguments for search criteria.
	 *
	 * @param BookingCriteria $criteria Criteria.
	 * @return array{0: string, 1: array<int, int|string>}
	 */
	private function criteria_where( BookingCriteria $criteria ): array {
		$where = array( '1=1' );
		$args  = array();

		if ( array() !== $criteria->statuses ) {
			$where[] = 'status IN (' . implode( ', ', array_fill( 0, count( $criteria->statuses ), '%s' ) ) . ')';
			foreach ( $criteria->statuses as $status ) {
				$args[] = $status->value;
			}
		}
		if ( null !== $criteria->service_id ) {
			$where[] = 'service_id = %d';
			$args[]  = $criteria->service_id;
		}
		if ( null !== $criteria->resource_id ) {
			$where[] = 'resource_id = %d';
			$args[]  = $criteria->resource_id;
		}
		if ( null !== $criteria->starts_in ) {
			$where[] = 'start_utc >= %s AND start_utc < %s';
			$args[]  = self::to_sql( $criteria->starts_in->start );
			$args[]  = self::to_sql( $criteria->starts_in->end );
		}
		if ( '' !== $criteria->search ) {
			$like    = '%' . $this->db->esc_like( $criteria->search ) . '%';
			$where[] = '(customer_name LIKE %s OR customer_email LIKE %s OR public_id = %s)';
			array_push( $args, $like, $like, $criteria->search );
		}

		return array( implode( ' AND ', $where ), $args );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string                 $email            E-mail address.
	 * @param int                    $limit            Page size.
	 * @param int                    $offset           Offset.
	 * @param DateTimeImmutable|null $keep_active_from Skip active bookings starting at or after this time.
	 * @return Booking[]
	 */
	public function find_by_customer_email( string $email, int $limit, int $offset = 0, ?DateTimeImmutable $keep_active_from = null ): array {
		$email = trim( $email );
		if ( '' === $email ) {
			return array();
		}
		$table = $this->table( Schema::BOOKINGS );
		$where = 'customer_email = %s';
		$args  = array( $email );
		if ( null !== $keep_active_from ) {
			$where .= ' AND NOT (active_start_utc IS NOT NULL AND start_utc >= %s)';
			$args[] = self::to_sql( $keep_active_from );
		}
		$args[] = max( 1, $limit );
		$args[] = max( 0, $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table from Schema, fixed conditions.
		$rows = $this->rows( $this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", $args ) );

		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int      $id       Booking ID.
	 * @param Customer $customer New customer data.
	 */
	public function replace_customer( int $id, Customer $customer ): Booking {
		$this->require_booking( $id );
		$this->update(
			$this->table( Schema::BOOKINGS ),
			array(
				'customer_name'    => $customer->name,
				'customer_email'   => $customer->email,
				'customer_phone'   => $customer->phone,
				'customer_note'    => $customer->note,
				'customer_user_id' => $customer->user_id,
				'updated_at'       => $this->now(),
			),
			array( 'id' => $id )
		);
		return $this->require_booking( $id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param DateTimeImmutable $now   Current time.
	 * @param int               $limit Maximum number of bookings.
	 * @return int[]
	 */
	public function expire_holds( DateTimeImmutable $now, int $limit = 100 ): array {
		$table = $this->table( Schema::BOOKINGS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$sql = $this->db->prepare( "SELECT id FROM {$table} WHERE status = %s AND hold_expires_at <= %s ORDER BY hold_expires_at LIMIT %d", BookingStatus::PendingPayment->value, self::to_sql( $now ), max( 1, $limit ) );

		$expired = array();
		foreach ( $this->rows( $sql ) as $row ) {
			if ( $this->expire_hold( (int) $row['id'], $now ) ) {
				$expired[] = (int) $row['id'];
			}
		}
		return $expired;
	}

	/**
	 * Locks the resource row for the rest of the transaction (serialises bookings of one resource).
	 *
	 * @param int $resource_id Resource ID.
	 * @throws EntityNotFound When the resource does not exist.
	 */
	private function lock_resource( int $resource_id ): void {
		$table = $this->table( Schema::RESOURCES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$locked = $this->db->get_var( $this->db->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $resource_id ) );
		if ( '' !== $this->db->last_error ) {
			throw DatabaseError::from_wpdb( $this->db, 'Locking resource failed' );
		}
		if ( null === $locked ) {
			throw EntityNotFound::with_id( 'resource', $resource_id );
		}
	}

	/**
	 * Throws SlotUnavailable when a blocking booking of the resource overlaps the blocked range; bookings awaiting
	 * payment whose hold already expired are marked `expired` instead (their slot is free).
	 *
	 * Must run after lock_resource() in the same transaction. The read is a plain consistent read: its snapshot is
	 * taken after the resource lock was granted, so it sees every booking committed by the previous lock holder.
	 *
	 * @param int               $resource_id Resource ID.
	 * @param TimeRange         $blocked     Blocked range (appointment + buffer).
	 * @param DateTimeImmutable $now         Current time.
	 * @param int|null          $exclude_id  Booking to ignore (the one being rescheduled).
	 * @throws SlotUnavailable When the range is taken.
	 */
	private function assert_free( int $resource_id, TimeRange $blocked, DateTimeImmutable $now, ?int $exclude_id ): void {
		$table = $this->table( Schema::BOOKINGS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$sql = $this->db->prepare(
			"SELECT id, status, hold_expires_at FROM {$table}
			WHERE resource_id = %d AND active_start_utc IS NOT NULL AND start_utc < %s AND buffer_end_utc > %s AND id <> %d",
			$resource_id,
			self::to_sql( $blocked->end ),
			self::to_sql( $blocked->start ),
			$exclude_id ?? 0
		);

		foreach ( $this->rows( $sql ) as $row ) {
			$stale_hold = BookingStatus::PendingPayment->value === $row['status']
				&& null !== $row['hold_expires_at']
				&& self::from_sql( (string) $row['hold_expires_at'] ) <= $now;

			if ( ! $stale_hold || ! $this->expire_hold( (int) $row['id'], $now ) ) {
				throw SlotUnavailable::at( $resource_id, $blocked->start );
			}
		}
	}

	/**
	 * Expires one booking awaiting payment whose hold has run out (no-op if it changed meanwhile).
	 *
	 * @param int               $id  Booking ID.
	 * @param DateTimeImmutable $now Current time.
	 * @return bool Whether the booking was expired.
	 * @throws DatabaseError On failure.
	 */
	private function expire_hold( int $id, DateTimeImmutable $now ): bool {
		$table = $this->table( Schema::BOOKINGS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$sql = $this->db->prepare(
			"UPDATE {$table} SET status = %s, active_start_utc = NULL, updated_at = %s WHERE id = %d AND status = %s AND hold_expires_at <= %s",
			BookingStatus::Expired->value,
			self::to_sql( $now ),
			$id,
			BookingStatus::PendingPayment->value,
			self::to_sql( $now )
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$result = $this->db->query( $sql );
		if ( false === $result ) {
			throw DatabaseError::from_wpdb( $this->db, 'Expiring booking hold failed' );
		}
		return 1 === (int) $result;
	}

	/**
	 * Turns a failed booking write into the right exception.
	 *
	 * @param int               $resource_id Resource ID.
	 * @param DateTimeImmutable $start       Slot start.
	 * @param string            $context     Operation.
	 * @return never
	 * @throws SlotUnavailable On a duplicate active start (lost race).
	 * @throws DatabaseError   On any other failure.
	 */
	private function throw_write_error( int $resource_id, DateTimeImmutable $start, string $context ): never {
		$error = $this->db->last_error;
		if ( str_contains( $error, 'Duplicate entry' ) && str_contains( $error, self::ACTIVE_START_INDEX ) ) {
			throw SlotUnavailable::at( $resource_id, $start );
		}
		throw DatabaseError::from_wpdb( $this->db, $context );
	}

	/**
	 * Loads a booking that must exist.
	 *
	 * @param int $id Booking ID.
	 * @throws EntityNotFound When missing.
	 */
	private function require_booking( int $id ): Booking {
		$booking = $this->get( $id );
		if ( null === $booking ) {
			throw EntityNotFound::with_id( 'booking', $id );
		}
		return $booking;
	}

	/**
	 * Loads one booking by a single-placeholder condition.
	 *
	 * @param string     $condition  SQL condition with one placeholder.
	 * @param int|string $value      Placeholder value.
	 * @param bool       $for_update Lock the row.
	 */
	private function find_one( string $condition, int|string $value, bool $for_update = false ): ?Booking {
		$table = $this->table( Schema::BOOKINGS );
		$lock  = $for_update ? ' FOR UPDATE' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema, condition is a fixed string from this class.
		$rows = $this->rows( $this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE {$condition} LIMIT 1{$lock}", $value ) );

		return array() === $rows ? null : self::hydrate( $rows[0] );
	}

	/**
	 * Row → entity.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function hydrate( array $row ): Booking {
		$start      = self::from_sql( (string) $row['start_utc'] );
		$end        = self::from_sql( (string) $row['end_utc'] );
		$buffer_end = self::from_sql( (string) $row['buffer_end_utc'] );

		return new Booking(
			resource_id: (int) $row['resource_id'],
			service_id: (int) $row['service_id'],
			range: new TimeRange( $start, $end ),
			status: BookingStatus::from( (string) $row['status'] ),
			customer: new Customer(
				(string) $row['customer_name'],
				(string) $row['customer_email'],
				(string) $row['customer_phone'],
				(string) ( $row['customer_note'] ?? '' ),
				null === $row['customer_user_id'] ? null : (int) $row['customer_user_id']
			),
			buffer_after_minutes: intdiv( $buffer_end->getTimestamp() - $end->getTimestamp(), 60 ),
			hold_expires_at: null === $row['hold_expires_at'] ? null : self::from_sql( (string) $row['hold_expires_at'] ),
			id: (int) $row['id'],
			order_id: null === $row['order_id'] ? null : (int) $row['order_id'],
			cancel_secret: null === $row['cancel_token_hash'] ? null : (string) $row['cancel_token_hash'],
			created_at: self::from_sql( (string) $row['created_at'] ),
			public_id: (string) $row['public_id']
		);
	}

	/**
	 * Random public identifier: 32 lowercase hex characters (128 bits).
	 */
	public static function random_public_id(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * DateTimeImmutable → MySQL DATETIME in UTC.
	 *
	 * @param DateTimeImmutable $time Time.
	 */
	private static function to_sql( DateTimeImmutable $time ): string {
		return $time->setTimezone( new DateTimeZone( 'UTC' ) )->format( self::DATETIME );
	}

	/**
	 * MySQL DATETIME (UTC) → DateTimeImmutable.
	 *
	 * @param string $value DATETIME value.
	 * @throws DatabaseError When the value cannot be parsed.
	 */
	private static function from_sql( string $value ): DateTimeImmutable {
		$time = DateTimeImmutable::createFromFormat( '!' . self::DATETIME, $value, new DateTimeZone( 'UTC' ) );
		if ( false === $time ) {
			throw new DatabaseError( sprintf( 'Invalid DATETIME value "%s".', $value ) );
		}
		return $time;
	}
}
