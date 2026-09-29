<?php
/**
 * Thrown when an entity cannot be deleted because other data references it.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Exception;

/**
 * E.g. a resource or service that still has bookings.
 */
final class EntityInUse extends \DomainException implements DomainError {

	/**
	 * Named constructor.
	 *
	 * @param string $entity Entity kind (e.g. "resource").
	 * @param int    $id     Entity ID.
	 */
	public static function referenced_by_bookings( string $entity, int $id ): self {
		return new self( sprintf( 'The %s #%d cannot be deleted because it has bookings.', $entity, $id ) );
	}
}
