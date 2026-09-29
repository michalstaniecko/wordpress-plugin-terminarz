<?php
/**
 * Shared helpers for $wpdb repositories.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure\Persistence;

use Terminarz\Infrastructure\Database\DatabaseError;
use Terminarz\Infrastructure\Database\Schema;
use Terminarz\Infrastructure\Database\Transaction;
use wpdb;

/**
 * Base class: table names, UTC timestamps, error handling. Every query goes through $wpdb->prepare()
 * (or the $wpdb->insert/update/delete helpers, which prepare internally).
 */
abstract class WpdbRepository {

	/**
	 * Connection.
	 *
	 * @var wpdb
	 */
	protected wpdb $db;

	/**
	 * Schema (table names).
	 *
	 * @var Schema
	 */
	protected Schema $schema;

	/**
	 * Transaction helper.
	 *
	 * @var Transaction
	 */
	protected Transaction $transaction;

	/**
	 * Constructor.
	 *
	 * @param wpdb        $db     Connection.
	 * @param Schema|null $schema Schema; defaults to one bound to $db.
	 */
	public function __construct( wpdb $db, ?Schema $schema = null ) {
		$this->db          = $db;
		$this->schema      = $schema ?? new Schema( $db );
		$this->transaction = new Transaction( $db );
	}

	/**
	 * Full table name.
	 *
	 * @param string $table Schema table constant.
	 */
	protected function table( string $table ): string {
		return $this->schema->table( $table );
	}

	/**
	 * Current UTC time in MySQL DATETIME format.
	 */
	protected function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Inserts a row and returns its ID.
	 *
	 * @param string               $table Full table name.
	 * @param array<string, mixed> $data  Column => value (null allowed).
	 * @throws DatabaseError On failure.
	 */
	protected function insert( string $table, array $data ): int {
		if ( false === $this->db->insert( $table, $data ) ) {
			throw DatabaseError::from_wpdb( $this->db, "Insert into {$table} failed" );
		}
		return (int) $this->db->insert_id;
	}

	/**
	 * Updates rows and returns the number of affected rows.
	 *
	 * @param string               $table Full table name.
	 * @param array<string, mixed> $data  Column => value.
	 * @param array<string, mixed> $where Column => value.
	 * @throws DatabaseError On failure.
	 */
	protected function update( string $table, array $data, array $where ): int {
		$result = $this->db->update( $table, $data, $where );
		if ( false === $result ) {
			throw DatabaseError::from_wpdb( $this->db, "Update of {$table} failed" );
		}
		return (int) $result;
	}

	/**
	 * Deletes rows.
	 *
	 * @param string               $table Full table name.
	 * @param array<string, mixed> $where Column => value.
	 * @throws DatabaseError On failure.
	 */
	protected function delete_where( string $table, array $where ): int {
		$result = $this->db->delete( $table, $where );
		if ( false === $result ) {
			throw DatabaseError::from_wpdb( $this->db, "Delete from {$table} failed" );
		}
		return (int) $result;
	}

	/**
	 * Whether a row with the given ID exists.
	 *
	 * @param string $table Full table name.
	 * @param int    $id    Row ID.
	 */
	protected function exists( string $table, int $id ): bool {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Schema constants.
		return null !== $this->db->get_var( $this->db->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Runs a prepared SELECT and returns rows as associative arrays.
	 *
	 * @param string $sql Prepared SQL.
	 * @return array<int, array<string, mixed>>
	 * @throws DatabaseError On failure.
	 */
	protected function rows( string $sql ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- callers pass the result of $wpdb->prepare().
		$rows = $this->db->get_results( $sql, ARRAY_A );
		if ( '' !== $this->db->last_error ) {
			throw DatabaseError::from_wpdb( $this->db, 'Query failed' );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * `IN (...)` placeholder list for integers, e.g. "%d,%d,%d".
	 *
	 * @param int[] $ids IDs (non-empty).
	 */
	protected static function int_placeholders( array $ids ): string {
		return implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	}

	/**
	 * Normalises a list of IDs (ints > 0, unique, order kept).
	 *
	 * @param array<mixed> $ids Raw IDs.
	 * @return int[]
	 */
	protected static function ids( array $ids ): array {
		$result = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! in_array( $id, $result, true ) ) {
				$result[] = $id;
			}
		}
		return $result;
	}
}
