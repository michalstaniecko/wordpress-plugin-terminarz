<?php
/**
 * Bookable service.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Model;

use Terminarz\Domain\Exception\InvalidValue;

/**
 * A service customers book, e.g. "Consultation, 45 min".
 *
 * The price is an integer amount in minor units (e.g. grosze); the currency is the shop's currency
 * (WooCommerce) and is not part of the domain model. The buffer after the service (cleanup, travel)
 * blocks the resource but is not shown to the customer.
 */
final class Service {

	/**
	 * Upper bound for duration and buffer: one day.
	 */
	public const MAX_MINUTES = 1440;

	/**
	 * Creates the service.
	 *
	 * @param ?int   $id                   Persistence id, null when not stored yet.
	 * @param string $name                 Display name (non-empty).
	 * @param int    $duration_minutes     Duration, 1–1440 minutes.
	 * @param int    $price_minor          Price in minor currency units, >= 0 (0 = free).
	 * @param int    $buffer_after_minutes Buffer after the service, 0–1440 minutes.
	 * @param bool   $is_active            Inactive services are not offered to customers.
	 *
	 * @throws InvalidValue On invalid data.
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $name,
		public readonly int $duration_minutes,
		public readonly int $price_minor = 0,
		public readonly int $buffer_after_minutes = 0,
		public readonly bool $is_active = true
	) {
		if ( null !== $id && $id <= 0 ) {
			throw new InvalidValue( 'Id must be a positive integer.' );
		}
		if ( '' === trim( $name ) ) {
			throw new InvalidValue( 'Service name must not be empty.' );
		}
		if ( $duration_minutes <= 0 || $duration_minutes > self::MAX_MINUTES ) {
			throw new InvalidValue( 'Service duration must be between 1 and 1440 minutes.' );
		}
		if ( $price_minor < 0 ) {
			throw new InvalidValue( 'Service price must not be negative.' );
		}
		if ( $buffer_after_minutes < 0 || $buffer_after_minutes > self::MAX_MINUTES ) {
			throw new InvalidValue( 'Service buffer must be between 0 and 1440 minutes.' );
		}
	}

	/**
	 * Minutes the resource is blocked by one booking: duration plus buffer.
	 */
	public function blocked_minutes(): int {
		return $this->duration_minutes + $this->buffer_after_minutes;
	}

	/**
	 * Whether the service is free of charge.
	 */
	public function is_free(): bool {
		return 0 === $this->price_minor;
	}

	/**
	 * Returns a copy with the persistence id set.
	 *
	 * @param int $id Persistence id.
	 */
	public function with_id( int $id ): self {
		return new self( $id, $this->name, $this->duration_minutes, $this->price_minor, $this->buffer_after_minutes, $this->is_active );
	}
}
