<?php
/**
 * Maps domain and persistence exceptions to REST errors.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Rest;

use Terminarz\Domain\Exception\DomainError;
use Terminarz\Domain\Exception\EntityInUse;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Exception\InvalidStatusTransition;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Exception\SlotUnavailable;
use Terminarz\Infrastructure\Database\DatabaseError;
use WP_Error;

/**
 * Domain exception messages are English and meant for developers (ADR-015); REST clients get a stable error code,
 * an HTTP status and a translated, generic message instead. Internal details (IDs, SQL errors) never leak.
 *
 * | Exception                 | Code                              | Status |
 * |---------------------------|-----------------------------------|--------|
 * | SlotUnavailable           | trmz_slot_unavailable             | 409    |
 * | EntityInUse               | trmz_entity_in_use                | 409    |
 * | EntityNotFound            | trmz_not_found                    | 404    |
 * | InvalidStatusTransition   | trmz_invalid_status_transition    | 422    |
 * | InvalidValue              | trmz_invalid_value                | 400    |
 * | DatabaseError / other     | trmz_server_error                 | 500    |
 */
final class ErrorMapper {

	/**
	 * Converts an exception into a WP_Error.
	 *
	 * @param DomainError|DatabaseError $error    Exception.
	 * @param array<string, string>     $messages Optional message overrides keyed by exception class (already translated).
	 */
	public static function to_wp_error( DomainError|DatabaseError $error, array $messages = array() ): WP_Error {
		[ $code, $status, $message ] = match ( true ) {
			$error instanceof SlotUnavailable => array(
				'trmz_slot_unavailable',
				409,
				__( 'The selected time is no longer available. Please choose another one.', 'terminarz' ),
			),
			$error instanceof EntityInUse => array(
				'trmz_entity_in_use',
				409,
				__( 'This item is in use and cannot be changed this way.', 'terminarz' ),
			),
			$error instanceof EntityNotFound => array(
				'trmz_not_found',
				404,
				__( 'The requested item does not exist.', 'terminarz' ),
			),
			$error instanceof InvalidStatusTransition => array(
				'trmz_invalid_status_transition',
				422,
				__( 'This action is not possible for the booking in its current status.', 'terminarz' ),
			),
			$error instanceof InvalidValue => array(
				'trmz_invalid_value',
				400,
				__( 'The request contains invalid data.', 'terminarz' ),
			),
			default => array(
				'trmz_server_error',
				500,
				__( 'Something went wrong. Please try again later.', 'terminarz' ),
			),
		};

		return new WP_Error( $code, $messages[ $error::class ] ?? $message, array( 'status' => $status ) );
	}
}
