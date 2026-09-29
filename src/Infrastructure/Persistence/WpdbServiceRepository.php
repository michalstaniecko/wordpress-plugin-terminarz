<?php
/**
 * $wpdb implementation of ServiceRepository.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure\Persistence;

use Terminarz\Domain\Exception\EntityInUse;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Model\Service;
use Terminarz\Domain\Repository\ServiceRepository;
use Terminarz\Infrastructure\Database\Schema;

/**
 * Stores services in `{prefix}trmz_services` and assignments in `{prefix}trmz_service_resources`.
 */
final class WpdbServiceRepository extends WpdbRepository implements ServiceRepository {

	private const COLUMNS = 'id, name, duration_minutes, price_minor, buffer_after_minutes, is_active';

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Service ID.
	 */
	public function get( int $id ): ?Service {
		$table = $this->table( Schema::SERVICES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$rows = $this->rows( $this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE id = %d", $id ) );

		return array() === $rows ? null : self::hydrate( $rows[0] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param bool $only_active Skip inactive services.
	 * @return Service[]
	 */
	public function all( bool $only_active = false ): array {
		$table = $this->table( Schema::SERVICES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$sql = $this->db->prepare( 'SELECT ' . self::COLUMNS . " FROM {$table} WHERE is_active >= %d ORDER BY sort_order ASC, id ASC", $only_active ? 1 : 0 );

		return array_map( array( self::class, 'hydrate' ), $this->rows( $sql ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Service $service Service.
	 * @throws EntityNotFound When the service does not exist.
	 */
	public function save( Service $service ): Service {
		$table = $this->table( Schema::SERVICES );
		$data  = array(
			'name'                 => $service->name,
			'duration_minutes'     => $service->duration_minutes,
			'price_minor'          => $service->price_minor,
			'buffer_after_minutes' => $service->buffer_after_minutes,
			'is_active'            => $service->is_active ? 1 : 0,
			'updated_at'           => $this->now(),
		);

		if ( null === $service->id ) {
			$data['created_at'] = $data['updated_at'];
			return $service->with_id( $this->insert( $table, $data ) );
		}

		if ( ! $this->exists( $table, $service->id ) ) {
			throw EntityNotFound::with_id( 'service', $service->id );
		}
		$this->update( $table, $data, array( 'id' => $service->id ) );
		return $service;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Service ID.
	 * @throws EntityInUse When the service has bookings.
	 */
	public function delete( int $id ): void {
		$this->transaction->run(
			function () use ( $id ): void {
				$bookings = $this->table( Schema::BOOKINGS );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
				if ( null !== $this->db->get_var( $this->db->prepare( "SELECT id FROM {$bookings} WHERE service_id = %d LIMIT 1", $id ) ) ) {
					throw EntityInUse::referenced_by_bookings( 'service', $id );
				}
				$this->delete_where( $this->table( Schema::SERVICE_RESOURCES ), array( 'service_id' => $id ) );
				$this->delete_where( $this->table( Schema::SERVICES ), array( 'id' => $id ) );
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int   $service_id   Service ID.
	 * @param int[] $resource_ids Resource IDs in preference order.
	 * @throws EntityNotFound When the service does not exist.
	 */
	public function assign_resources( int $service_id, array $resource_ids ): void {
		if ( ! $this->exists( $this->table( Schema::SERVICES ), $service_id ) ) {
			throw EntityNotFound::with_id( 'service', $service_id );
		}
		$resource_ids = self::ids( $resource_ids );
		$pivot        = $this->table( Schema::SERVICE_RESOURCES );

		$this->transaction->run(
			function () use ( $service_id, $resource_ids, $pivot ): void {
				$this->delete_where( $pivot, array( 'service_id' => $service_id ) );
				foreach ( $resource_ids as $position => $resource_id ) {
					$this->insert(
						$pivot,
						array(
							'service_id'  => $service_id,
							'resource_id' => $resource_id,
							'sort_order'  => $position,
						)
					);
				}
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $service_id Service ID.
	 * @return int[]
	 */
	public function resource_ids( int $service_id ): array {
		$pivot = $this->table( Schema::SERVICE_RESOURCES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$rows = $this->rows( $this->db->prepare( "SELECT resource_id FROM {$pivot} WHERE service_id = %d ORDER BY sort_order ASC, resource_id ASC", $service_id ) );

		return array_map( static fn( array $row ): int => (int) $row['resource_id'], $rows );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $resource_id Resource ID.
	 * @return int[]
	 */
	public function service_ids_for_resource( int $resource_id ): array {
		$pivot = $this->table( Schema::SERVICE_RESOURCES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$rows = $this->rows( $this->db->prepare( "SELECT service_id FROM {$pivot} WHERE resource_id = %d ORDER BY service_id ASC", $resource_id ) );

		return array_map( static fn( array $row ): int => (int) $row['service_id'], $rows );
	}

	/**
	 * Row → entity.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function hydrate( array $row ): Service {
		return new Service(
			(int) $row['id'],
			(string) $row['name'],
			(int) $row['duration_minutes'],
			(int) $row['price_minor'],
			(int) $row['buffer_after_minutes'],
			1 === (int) $row['is_active']
		);
	}
}
