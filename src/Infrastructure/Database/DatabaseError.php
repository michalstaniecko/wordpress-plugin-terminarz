<?php
/**
 * Unexpected database failure.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure\Database;

use wpdb;

/**
 * Thrown by repositories when a query fails for a reason that is not a domain rule (connection lost, bad SQL…).
 */
final class DatabaseError extends \RuntimeException {

	/**
	 * Builds the exception from the last wpdb error.
	 *
	 * @param wpdb   $db      Connection.
	 * @param string $context What was being done.
	 */
	public static function from_wpdb( wpdb $db, string $context ): self {
		return new self( sprintf( '%s: %s', $context, '' !== $db->last_error ? $db->last_error : 'unknown database error' ) );
	}
}
