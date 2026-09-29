<?php
/**
 * Customer contact data attached to a booking.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Model;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Exception\InvalidValue;

/**
 * Contact data of the person who booked. Values are expected to be sanitised by the adapter
 * (REST/admin); the domain only enforces structural rules.
 */
final class Customer {

	/**
	 * Creates the customer data.
	 *
	 * @param string   $name    Full name (non-empty).
	 * @param string   $email   E-mail address (valid).
	 * @param string   $phone   Phone number, may be empty.
	 * @param string   $note    Note from the customer, may be empty.
	 * @param int|null $user_id Account of the customer (logged-in booking), null = guest.
	 *
	 * @throws InvalidValue On invalid data.
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $email,
		public readonly string $phone = '',
		public readonly string $note = '',
		public readonly ?int $user_id = null
	) {
		if ( '' === trim( $name ) ) {
			throw new InvalidValue( 'Customer name must not be empty.' );
		}
		if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			throw new InvalidValue( 'Customer e-mail address is not valid.' );
		}
		if ( null !== $user_id && $user_id <= 0 ) {
			throw new InvalidValue( 'Customer user id must be a positive integer.' );
		}
	}
}
