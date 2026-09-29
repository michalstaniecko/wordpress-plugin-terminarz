<?php
/**
 * Integration tests for the transaction helper (savepoint mode used inside the WP test suite).
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Database;

use RuntimeException;
use Terminarz\Infrastructure\Database\Schema;
use Terminarz\Infrastructure\Database\Transaction;
use WP_UnitTestCase;

/**
 * @covers \Terminarz\Infrastructure\Database\Transaction
 */
final class TransactionTest extends WP_UnitTestCase {

	public function test_commits_result_and_rolls_back_on_exception(): void {
		global $wpdb;
		$tx    = new Transaction( $wpdb );
		$table = Schema::from_globals()->table( Schema::RESOURCES );

		$id = $tx->run( fn() => $this->insert_resource( 'kept' ) );
		$this->assertGreaterThan( 0, $id );

		try {
			$tx->run(
				function (): void {
					$this->insert_resource( 'dropped' );
					throw new RuntimeException( 'boom' );
				}
			);
			$this->fail( 'Exception was swallowed.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$names = $wpdb->get_col( "SELECT name FROM {$table} ORDER BY id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( array( 'kept' ), $names );
		$this->assertFalse( $tx->active() );
	}

	public function test_inner_failure_rolls_back_only_inner_work(): void {
		global $wpdb;
		$tx    = new Transaction( $wpdb );
		$table = Schema::from_globals()->table( Schema::RESOURCES );

		$tx->run(
			function () use ( $tx ): void {
				$this->insert_resource( 'outer' );
				$this->assertTrue( $tx->active() );
				try {
					$tx->run(
						function (): void {
							$this->insert_resource( 'inner' );
							throw new RuntimeException( 'inner' );
						}
					);
				} catch ( RuntimeException $e ) {
					unset( $e );
				}
			}
		);

		$names = $wpdb->get_col( "SELECT name FROM {$table} ORDER BY id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( array( 'outer' ), $names );
	}

	private function insert_resource( string $name ): int {
		global $wpdb;
		$wpdb->insert(
			Schema::from_globals()->table( Schema::RESOURCES ),
			array(
				'name'       => $name,
				'created_at' => '2030-01-01 00:00:00',
				'updated_at' => '2030-01-01 00:00:00',
			)
		);
		return (int) $wpdb->insert_id;
	}
}
