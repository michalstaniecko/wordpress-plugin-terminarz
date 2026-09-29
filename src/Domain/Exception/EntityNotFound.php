<?php
/**
 * Thrown when an entity expected to exist is missing.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Exception;

defined( 'ABSPATH' ) || exit; // No direct access.

/**
 * E.g. updating a resource that was deleted in the meantime.
 */
final class EntityNotFound extends \DomainException implements DomainError {

	/**
	 * Named constructor.
	 *
	 * @param string     $entity Entity kind (e.g. "resource").
	 * @param int|string $id     Entity ID.
	 */
	public static function with_id( string $entity, int|string $id ): self {
		return new self( sprintf( 'The %s "%s" does not exist.', $entity, (string) $id ) );
	}
}
