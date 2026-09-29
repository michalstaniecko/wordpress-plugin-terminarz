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

	public const TYPE_PERSON = 'person';
	public const TYPE_ROOM   = 'room';
	public const TYPE_DEVICE = 'device';

	/**
	 * Allowed resource types.
	 *
	 * @var list<string>
	 */
	public const TYPES = array( self::TYPE_PERSON, self::TYPE_ROOM, self::TYPE_DEVICE );

	/**
	 * Creates the resource.
	 *
	 * @param ?int   $id          Persistence id, null when not stored yet.
	 * @param string $name        Display name (non-empty).
	 * @param bool   $is_active   Inactive resources are not offered to customers.
	 * @param string $type        Kind of resource: one of TYPES (informational; does not change availability rules).
	 * @param string $description Description, may be empty.
	 * @param int    $sort_order  Display order (lower first).
	 *
	 * @throws InvalidValue On invalid data.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $name,
		public readonly bool $is_active = true,
		public readonly string $type = self::TYPE_PERSON,
		public readonly string $description = '',
		public readonly int $sort_order = 0
	) {
		if ( null !== $id && $id <= 0 ) {
			throw new InvalidValue( 'Id must be a positive integer.' );
		}
		if ( '' === trim( $name ) ) {
			throw new InvalidValue( 'Resource name must not be empty.' );
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new InvalidValue( 'Unknown resource type.' );
		}
	}

	/**
	 * Returns a copy with the persistence id set.
	 *
	 * @param int $id Persistence id.
	 */
	public function with_id( int $id ): self {
		return new self( $id, $this->name, $this->is_active, $this->type, $this->description, $this->sort_order );
	}

	/**
	 * Returns an active or inactive copy.
	 *
	 * @param bool $is_active Whether the resource is offered to customers.
	 */
	public function with_active( bool $is_active ): self {
		return new self( $this->id, $this->name, $is_active, $this->type, $this->description, $this->sort_order );
	}
}
