<?php
/**
 * Bookable resource (person, room, device).
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Model;

use Terminarz\Domain\Exception\InvalidValue;

/**
 * Something that can be booked for one appointment at a time: a specialist, a room, a device.
 *
 * Named `BookableResource` rather than `Resource`, because `resource` is a soft-reserved word in PHP.
 */
final class BookableResource {

	/**
	 * Creates the resource.
	 *
	 * @param ?int   $id        Persistence id, null when not stored yet.
	 * @param string $name      Display name (non-empty).
	 * @param bool   $is_active Inactive resources are not offered to customers.
	 *
	 * @throws InvalidValue On invalid data.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $name,
		public readonly bool $is_active = true
	) {
		if ( null !== $id && $id <= 0 ) {
			throw new InvalidValue( 'Id must be a positive integer.' );
		}
		if ( '' === trim( $name ) ) {
			throw new InvalidValue( 'Resource name must not be empty.' );
		}
	}

	/**
	 * Returns a copy with the persistence id set.
	 *
	 * @param int $id Persistence id.
	 */
	public function with_id( int $id ): self {
		return new self( $id, $this->name, $this->is_active );
	}
}
