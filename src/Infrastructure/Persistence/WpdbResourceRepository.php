<?php
/**
 * $wpdb implementation of ResourceRepository.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure\Persistence;

use Terminarz\Domain\Exception\EntityInUse;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Model\BookableResource;
use Terminarz\Domain\Repository\ResourceRepository;
use Terminarz\Infrastructure\Database\Schema;

/**
 * Stores resources in `{prefix}trmz_resources`.
 */
final class WpdbResourceRepository extends WpdbRepository implements ResourceRepository {

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Resource ID.
	 */
	public function get( int $id ): ?BookableResource {
		return $this->get_many( array( $id ) )[ $id ] ?? null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int[] $ids Resource IDs.
	 * @return array<int, BookableResource>
	 */
	public function get_many( array $ids ): array {
		$ids = self::ids( $ids );
		if ( array() === $ids ) {
			return array();
		}
		$table = $this->table( Schema::RESOURCES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table from Schema, placeholders generated.
		$rows = $this->rows( $this->db->prepare( "SELECT id, name, is_active, type, description, sort_order FROM {$table} WHERE id IN (" . self::int_placeholders( $ids ) . ')', $ids ) );

		$result = array();
		foreach ( $rows as $row ) {
			$result[ (int) $row['id'] ] = self::hydrate( $row );
		}
		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param bool $only_active Skip inactive resources.
	 * @return BookableResource[]
	 */
	public function all( bool $only_active = false ): array {
		$table = $this->table( Schema::RESOURCES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
		$sql = $this->db->prepare( "SELECT id, name, is_active, type, description, sort_order FROM {$table} WHERE is_active >= %d ORDER BY sort_order ASC, id ASC", $only_active ? 1 : 0 );

		return array_map( array( self::class, 'hydrate' ), $this->rows( $sql ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param BookableResource $bookable Resource.
	 * @throws EntityNotFound When the resource does not exist.
	 */
	public function save( BookableResource $bookable ): BookableResource {
		$table = $this->table( Schema::RESOURCES );
		$data  = array(
			'name'        => $bookable->name,
			'type'        => $bookable->type,
			'description' => $bookable->description,
			'sort_order'  => $bookable->sort_order,
			'is_active'   => $bookable->is_active ? 1 : 0,
			'updated_at'  => $this->now(),
		);

		if ( null === $bookable->id ) {
			$data['created_at'] = $data['updated_at'];
			return $bookable->with_id( $this->insert( $table, $data ) );
		}

		if ( ! $this->exists( $table, $bookable->id ) ) {
			throw EntityNotFound::with_id( 'resource', $bookable->id );
		}
		$this->update( $table, $data, array( 'id' => $bookable->id ) );
		return $bookable;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Resource ID.
	 * @throws EntityInUse When the resource has bookings.
	 */
	public function delete( int $id ): void {
		$this->transaction->run(
			function () use ( $id ): void {
				$bookings = $this->table( Schema::BOOKINGS );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Schema.
				if ( null !== $this->db->get_var( $this->db->prepare( "SELECT id FROM {$bookings} WHERE resource_id = %d LIMIT 1", $id ) ) ) {
					throw EntityInUse::referenced_by_bookings( 'resource', $id );
				}
				$this->delete_where( $this->table( Schema::SERVICE_RESOURCES ), array( 'resource_id' => $id ) );
				$this->delete_where( $this->table( Schema::SCHEDULES ), array( 'resource_id' => $id ) );
				$this->delete_where( $this->table( Schema::EXCEPTIONS ), array( 'resource_id' => $id ) );
				$this->delete_where( $this->table( Schema::RESOURCES ), array( 'id' => $id ) );
			}
		);
	}

	/**
	 * Row → entity.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private static function hydrate( array $row ): BookableResource {
		$type = (string) ( $row['type'] ?? '' );
		return new BookableResource(
			(int) $row['id'],
			(string) $row['name'],
			1 === (int) $row['is_active'],
			in_array( $type, BookableResource::TYPES, true ) ? $type : BookableResource::TYPE_PERSON,
			(string) ( $row['description'] ?? '' ),
			(int) ( $row['sort_order'] ?? 0 )
		);
	}
}
