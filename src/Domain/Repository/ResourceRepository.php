<?php
/**
 * Resource repository contract.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Repository;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Exception\EntityInUse;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Model\BookableResource;

/**
 * Stores bookable resources (staff members, rooms, devices…).
 */
interface ResourceRepository {

	/**
	 * Finds a resource by ID.
	 *
	 * @param int $id Resource ID.
	 */
	public function get( int $id ): ?BookableResource;

	/**
	 * Finds several resources at once (one query).
	 *
	 * @param int[] $ids Resource IDs.
	 * @return array<int, BookableResource> Keyed by ID; missing IDs are skipped.
	 */
	public function get_many( array $ids ): array;

	/**
	 * Lists resources in their display order.
	 *
	 * @param bool $only_active Skip inactive resources.
	 * @return BookableResource[]
	 */
	public function all( bool $only_active = false ): array;

	/**
	 * Inserts (no ID) or updates (with ID) a resource.
	 *
	 * @param BookableResource $bookable Resource.
	 * @return BookableResource The stored resource (with ID).
	 * @throws EntityNotFound When updating a resource that does not exist.
	 */
	public function save( BookableResource $bookable ): BookableResource;

	/**
	 * Deletes a resource together with its schedule, schedule exceptions and service assignments.
	 *
	 * @param int $id Resource ID.
	 * @throws EntityInUse When the resource has bookings.
	 */
	public function delete( int $id ): void;
}
