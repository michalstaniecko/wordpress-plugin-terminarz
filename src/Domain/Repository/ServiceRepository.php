<?php
/**
 * Service repository contract.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Repository;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Exception\EntityInUse;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Model\Service;

/**
 * Stores services and their assignment to resources.
 */
interface ServiceRepository {

	/**
	 * Finds a service by ID.
	 *
	 * @param int $id Service ID.
	 */
	public function get( int $id ): ?Service;

	/**
	 * Lists services in their display order.
	 *
	 * @param bool $only_active Skip inactive services.
	 * @return Service[]
	 */
	public function all( bool $only_active = false ): array;

	/**
	 * Inserts (no ID) or updates (with ID) a service.
	 *
	 * @param Service $service Service.
	 * @return Service The stored service (with ID).
	 * @throws EntityNotFound When updating a service that does not exist.
	 */
	public function save( Service $service ): Service;

	/**
	 * Deletes a service and its resource assignments.
	 *
	 * @param int $id Service ID.
	 * @throws EntityInUse When the service has bookings.
	 */
	public function delete( int $id ): void;

	/**
	 * Replaces the resources that perform a service. The array order is the preference order
	 * used when the customer picks "any resource".
	 *
	 * @param int   $service_id   Service ID.
	 * @param int[] $resource_ids Resource IDs in preference order.
	 * @throws EntityNotFound When the service does not exist.
	 */
	public function assign_resources( int $service_id, array $resource_ids ): void;

	/**
	 * IDs of resources assigned to a service, in preference order.
	 *
	 * @param int $service_id Service ID.
	 * @return int[]
	 */
	public function resource_ids( int $service_id ): array;

	/**
	 * IDs of services a resource performs.
	 *
	 * @param int $resource_id Resource ID.
	 * @return int[]
	 */
	public function service_ids_for_resource( int $resource_id ): array;

	/**
	 * Service IDs of every resource at once (one query for listings).
	 *
	 * @return array<int, int[]> Keyed by resource ID; resources without services are absent.
	 */
	public function service_ids_by_resource(): array;
}
