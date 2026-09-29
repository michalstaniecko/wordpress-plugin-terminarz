<?php
/**
 * $wpdb implementation of ScheduleExceptionRepository.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure\Persistence;

defined( 'ABSPATH' ) || exit; // No direct access.

use DateTimeImmutable;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Model\ScheduleException;
use Terminarz\Domain\Model\ScheduleExceptionPeriod;
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

	private const COLUMNS = 'id, resource_id, start_date, end_date, kind, intervals, note';

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
			'intervals'   => $exception->is_closed() ? null : self::encode_windows( $exception->windows ),
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
	 * {@inheritDoc}
	 *
	 * @param int $id Exception ID.
	 */
	public function get_period( int $id ): ?ScheduleExceptionPeriod {
		$table = $this->table( Schema::EXCEPTIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$rows = $this->rows( $this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE id = %d", $id ) );
		return array() === $rows ? null : self::hydrate_period( $rows[0] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ScheduleExceptionPeriod $period Period.
	 * @throws EntityNotFound When the period does not exist.
	 */
	public function save_period( ScheduleExceptionPeriod $period ): ScheduleExceptionPeriod {
		$table = $this->table( Schema::EXCEPTIONS );
		$data  = array(
			'resource_id' => $period->resource_id,
			'start_date'  => $period->start_date,
			'end_date'    => $period->end_date,
			'kind'        => $period->is_closed() ? self::KIND_CLOSED : self::KIND_CUSTOM_HOURS,
			'intervals'   => $period->is_closed() ? null : self::encode_windows( $period->windows ),
			'note'        => mb_substr( $period->note, 0, 191 ),
			'updated_at'  => $this->now(),
		);

		if ( null === $period->id ) {
			$data['created_at'] = $data['updated_at'];
			return $period->with_id( $this->insert( $table, $data ) );
		}

		if ( ! $this->exists( $table, $period->id ) ) {
			throw EntityNotFound::with_id( 'schedule exception', $period->id );
		}
		$this->update( $table, $data, array( 'id' => $period->id ) );
		return $period;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string|null $ending_from Only periods ending on or after this date.
	 * @return ScheduleExceptionPeriod[]
	 */
	public function periods( ?string $ending_from = null ): array {
		$table = $this->table( Schema::EXCEPTIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$sql = $this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE end_date >= %s ORDER BY start_date ASC, resource_id IS NOT NULL, resource_id ASC, id ASC", $ending_from ?? '0000-01-01' );

		return array_map( array( self::class, 'hydrate_period' ), $this->rows( $sql ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ScheduleExceptionPeriod $period Period.
	 * @return ScheduleExceptionPeriod[]
	 */
	public function conflicting_periods( ScheduleExceptionPeriod $period ): array {
		$table = $this->table( Schema::EXCEPTIONS );
		$where = 'start_date <= %s AND end_date >= %s AND id <> %d';
		$args  = array( $period->end_date, $period->start_date, $period->id ?? 0 );
		if ( null === $period->resource_id ) {
			$where .= ' AND resource_id IS NULL';
		} else {
			$where .= ' AND resource_id = %d';
			$args[] = $period->resource_id;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema, fixed conditions.
		$rows = $this->rows( $this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE {$where} ORDER BY start_date ASC", $args ) );

		return array_map( array( self::class, 'hydrate_period' ), $rows );
	}

	/**
	 * Row → period.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function hydrate_period( array $row ): ScheduleExceptionPeriod {
		$day = self::hydrate( $row, (string) $row['start_date'] );
		return new ScheduleExceptionPeriod(
			$day->resource_id,
			(string) $row['start_date'],
			(string) $row['end_date'],
			$day->windows,
			(string) ( $row['note'] ?? '' ),
			(int) $row['id']
		);
	}

	/**
	 * Windows → JSON `[["09:00","12:00"], …]`.
	 *
	 * @param TimeWindow[] $windows Windows.
	 */
	private static function encode_windows( array $windows ): string {
		return (string) wp_json_encode(
			array_map(
				static fn( TimeWindow $w ): array => array( $w->start->to_string(), $w->end->to_string() ),
				$windows
			)
		);
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
