<?php
/**
 * Customer contact data attached to a booking.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Model;

use Terminarz\Domain\Exception\InvalidValue;

/**
 * Contact data of the person who booked. Values are expected to be sanitised by the adapter
 * (REST/admin); the domain only enforces structural rules.
 */
final class Customer {

	/**
	 * Creates the customer data.
	 *
	 * @param string $name  Full name (non-empty).
	 * @param string $email E-mail address (valid).
	 * @param string $phone Phone number, may be empty.
	 *
	 * @throws InvalidValue On invalid data.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $email,
		public readonly string $phone = ''
	) {
		if ( '' === trim( $name ) ) {
			throw new InvalidValue( 'Customer name must not be empty.' );
		}
		if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			throw new InvalidValue( 'Customer e-mail address is not valid.' );
		}
	}
}
