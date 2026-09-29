<?php
/**
 * InnoDB transaction helper.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure\Database;

use Throwable;
use wpdb;

/**
 * Runs a callback in a transaction: commit on success, rollback on any exception (rethrown).
 *
 * Nested calls, and calls made while someone else already opened a transaction on the connection
 * (the WordPress test suite wraps every test in one), use SAVEPOINTs instead, because a second
 * START TRANSACTION would silently commit the outer one. The latter case is signalled by the
 * `trmz_db_inside_external_transaction` filter.
 *
 * Deadlocks and lock wait timeouts in an outermost transaction are retried a few times.
 */
final class Transaction {

	/**
	 * Current nesting depth per connection (spl_object_id of wpdb).
	 *
	 * @var array<int, int>
	 */
	private static array $depth = array();

	/**
	 * MySQL/MariaDB error codes worth retrying: deadlock and lock wait timeout.
	 */
	private const RETRYABLE = array( 'Deadlock found', 'Lock wait timeout' );

	/**
	 * Connection.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Constructor.
	 *
	 * @param wpdb $db Connection.
	 */
	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Runs the callback inside a transaction (or savepoint) and returns its result.
	 *
	 * @template T
	 * @param callable(): T $callback Work to do.
	 * @param int           $attempts Attempts for retryable lock errors (outermost transaction only).
	 * @return T
	 * @throws Throwable Whatever the callback throws (after rollback).
	 */
	public function run( callable $callback, int $attempts = 3 ): mixed {
		$key   = spl_object_id( $this->db );
		$depth = self::$depth[ $key ] ?? 0;

		/**
		 * Whether plugin transactions run inside a transaction opened by someone else on the same connection
		 * (e.g. the WordPress PHPUnit suite). When true, savepoints are used even at the outermost level.
		 *
		 * @param bool $inside Default false.
		 */
		$use_savepoint = $depth > 0 || (bool) apply_filters( 'trmz_db_inside_external_transaction', false );

		if ( $use_savepoint ) {
			return $this->run_in_savepoint( $callback, $key, $depth );
		}

		for ( $attempt = 1; ; $attempt++ ) {
			$this->exec( 'START TRANSACTION' );
			self::$depth[ $key ] = 1;
			try {
				$result = $callback();
				$this->exec( 'COMMIT' );
				return $result;
			} catch ( Throwable $e ) {
				$this->exec( 'ROLLBACK', false );
				if ( $attempt >= $attempts || ! $this->is_retryable( $e ) ) {
					throw $e;
				}
				usleep( 20000 * $attempt );
			} finally {
				self::$depth[ $key ] = 0;
			}
		}
	}

	/**
	 * Whether the current connection is inside a transaction opened by this helper.
	 */
	public function active(): bool {
		return ( self::$depth[ spl_object_id( $this->db ) ] ?? 0 ) > 0;
	}

	/**
	 * Savepoint variant.
	 *
	 * @template T
	 * @param callable(): T $callback Work to do.
	 * @param int           $key      Connection key.
	 * @param int           $depth    Current depth.
	 * @return T
	 * @throws Throwable Whatever the callback throws (after rolling back to the savepoint).
	 */
	private function run_in_savepoint( callable $callback, int $key, int $depth ): mixed {
		$name = 'trmz_sp_' . ( $depth + 1 );
		$this->exec( 'SAVEPOINT ' . $name );
		self::$depth[ $key ] = $depth + 1;
		try {
			$result = $callback();
			$this->exec( 'RELEASE SAVEPOINT ' . $name );
			return $result;
		} catch ( Throwable $e ) {
			$this->exec( 'ROLLBACK TO SAVEPOINT ' . $name, false );
			throw $e;
		} finally {
			self::$depth[ $key ] = $depth;
		}
	}

	/**
	 * Whether an exception was caused by a deadlock/lock timeout.
	 *
	 * @param Throwable $e Exception.
	 */
	private function is_retryable( Throwable $e ): bool {
		$message = $e->getMessage() . ' ' . $this->db->last_error;
		foreach ( self::RETRYABLE as $needle ) {
			if ( str_contains( $message, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Executes a transaction-control statement.
	 *
	 * @param string $sql          Statement (no user input).
	 * @param bool   $must_succeed Throw on failure.
	 * @throws DatabaseError When the statement fails and $must_succeed is true.
	 */
	private function exec( string $sql, bool $must_succeed = true ): void {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed transaction-control statements.
		if ( false === $this->db->query( $sql ) && $must_succeed ) {
			throw DatabaseError::from_wpdb( $this->db, $sql );
		}
	}
}
