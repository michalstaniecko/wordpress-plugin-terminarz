<?php
/**
 * $wpdb implementation of ScheduleExceptionRepository.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure\Persistence;

use DateTimeImmutable;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Model\ScheduleException;
use Terminarz\Domain\Model\TimeWindow;
use Terminarz\Domain\Repository\ScheduleExceptionRepository;
use Terminarz\Infrastructure\Database\DatabaseError;
use Terminarz\Infrastructure\Database\Schema;

/**
 * Stores exceptions in `{prefix}trmz_schedule_exceptions`.
 *
 * The table supports date ranges (`start_date`–`end_date`, inclusive) so a multi-day holiday can be one row
 * in the future; the domain model is one day per exception, so rows written here have start = end, and ranges
 * are expanded into one exception per day when reading. Windows are stored as JSON `[["09:00","12:00"], …]`.
 */
final class WpdbScheduleExceptionRepository extends WpdbRepository implements ScheduleExceptionRepository {

	public const KIND_CLOSED       = 'closed';
	public const KIND_CUSTOM_HOURS = 'custom_hours';

	private const COLUMNS = 'id, resource_id, start_date, end_date, kind, intervals';

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Exception ID.
	 */
	public function get( int $id ): ?ScheduleException {
		$table = $this->table( Schema::EXCEPTIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$rows = $this->rows( $this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE id = %d", $id ) );
		if ( array() === $rows ) {
			return null;
		}
		return self::hydrate( $rows[0], (string) $rows[0]['start_date'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ScheduleException $exception Exception.
	 * @throws EntityNotFound When the exception does not exist.
	 */
	public function save( ScheduleException $exception ): ScheduleException {
		$table = $this->table( Schema::EXCEPTIONS );
		$data  = array(
			'resource_id' => $exception->resource_id,
			'start_date'  => $exception->date,
			'end_date'    => $exception->date,
			'kind'        => $exception->is_closed() ? self::KIND_CLOSED : self::KIND_CUSTOM_HOURS,
			'intervals'   => $exception->is_closed() ? null : (string) wp_json_encode(
				array_map(
					static fn( TimeWindow $w ): array => array( $w->start->to_string(), $w->end->to_string() ),
					$exception->windows
				)
			),
			'updated_at'  => $this->now(),
		);

		if ( null === $exception->id ) {
			$data['created_at'] = $data['updated_at'];
			return $exception->with_id( $this->insert( $table, $data ) );
		}

		if ( ! $this->exists( $table, $exception->id ) ) {
			throw EntityNotFound::with_id( 'schedule exception', $exception->id );
		}
		$this->update( $table, $data, array( 'id' => $exception->id ) );
		return $exception;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Exception ID.
	 */
	public function delete( int $id ): void {
		$this->delete_where( $this->table( Schema::EXCEPTIONS ), array( 'id' => $id ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string     $from         First local date (Y-m-d).
	 * @param string     $to           Last local date (Y-m-d).
	 * @param int[]|null $resource_ids Resources to include; null = all.
	 * @return ScheduleException[]
	 */
	public function in_range( string $from, string $to, ?array $resource_ids = null ): array {
		$table = $this->table( Schema::EXCEPTIONS );
		$args  = array( $to, $from );
		$where = 'start_date <= %s AND end_date >= %s';

		if ( null !== $resource_ids ) {
			$resource_ids = self::ids( $resource_ids );
			if ( array() === $resource_ids ) {
				$where .= ' AND resource_id IS NULL';
			} else {
				$where .= ' AND (resource_id IS NULL OR resource_id IN (' . self::int_placeholders( $resource_ids ) . '))';
				$args   = array_merge( $args, $resource_ids );
			}
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table from Schema, placeholders generated.
		$rows = $this->rows( $this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE {$where}", $args ) );

		$result = array();
		foreach ( $rows as $row ) {
			$day  = max( $from, (string) $row['start_date'] );
			$last = min( $to, (string) $row['end_date'] );
			while ( $day <= $last ) {
				$result[] = self::hydrate( $row, $day );
				$day      = ( new DateTimeImmutable( $day ) )->modify( '+1 day' )->format( 'Y-m-d' );
			}
		}

		usort(
			$result,
			static fn( ScheduleException $a, ScheduleException $b ): int => array( $a->date, null !== $a->resource_id, $a->resource_id, $a->id ) <=> array( $b->date, null !== $b->resource_id, $b->resource_id, $b->id )
		);
		return $result;
	}

	/**
	 * Row → entity for one day.
	 *
	 * @param array<string, mixed> $row  Row.
	 * @param string               $date Local date (Y-m-d).
	 * @throws DatabaseError When the stored windows are corrupt.
	 */
	private static function hydrate( array $row, string $date ): ScheduleException {
		$resource_id = null === $row['resource_id'] ? null : (int) $row['resource_id'];
		$windows     = array();

		if ( self::KIND_CLOSED !== $row['kind'] ) {
			$decoded = json_decode( (string) $row['intervals'], true );
			if ( ! is_array( $decoded ) || array() === $decoded ) {
				throw new DatabaseError( sprintf( 'Schedule exception #%d has invalid intervals.', (int) $row['id'] ) );
			}
			foreach ( $decoded as $pair ) {
				if ( ! is_array( $pair ) || ! isset( $pair[0], $pair[1] ) ) {
					throw new DatabaseError( sprintf( 'Schedule exception #%d has invalid intervals.', (int) $row['id'] ) );
				}
				$windows[] = TimeWindow::from_strings( (string) $pair[0], (string) $pair[1] );
			}
		}

		return new ScheduleException( $resource_id, $date, $windows, (int) $row['id'] );
	}
}
