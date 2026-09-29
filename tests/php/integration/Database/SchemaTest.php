<?php
/**
 * Integration tests for the database schema.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Database;

use Terminarz\Infrastructure\Database\Migrator;
use Terminarz\Infrastructure\Database\Schema;
use WP_UnitTestCase;

/**
 * The WordPress test suite turns CREATE/DROP TABLE issued inside a test into temporary tables, so the
 * "fresh install" cases use a separate prefix: they run against brand-new (temporary) tables and do not
 * touch the real plugin tables created while the suite bootstrapped (plugins_loaded).
 *
 * @covers \Terminarz\Infrastructure\Database\Schema
 * @covers \Terminarz\Infrastructure\Database\Migrator
 */
final class SchemaTest extends WP_UnitTestCase {

	private const FRESH_PREFIX = 'wptests_fresh_';

	public function test_tables_are_installed_on_plugins_loaded(): void {
		global $wpdb;

		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );

		foreach ( Schema::from_globals()->tables() as $table ) {
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				)
			);
			$this->assertSame( 'InnoDB', $engine, $table );
		}
	}

	public function test_install_on_clean_database_is_idempotent(): void {
		$schema = new Schema( $GLOBALS['wpdb'], self::FRESH_PREFIX );

		$first = $schema->install();
		$this->assertNotEmpty( $first );
		foreach ( $schema->tables() as $table ) {
			$this->assertTableExists( $table );
		}

		$this->assertSame( array(), $schema->install(), 'Second run must not change anything.' );
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
	}

	public function test_bookings_table_columns(): void {
		global $wpdb;
		$schema = new Schema( $wpdb, self::FRESH_PREFIX );
		$schema->install();

		$columns = $wpdb->get_col( 'DESCRIBE ' . $schema->table( Schema::BOOKINGS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( array( 'public_id', 'service_id', 'resource_id', 'start_utc', 'end_utc', 'buffer_end_utc', 'active_start_utc', 'status', 'customer_name', 'customer_email', 'cancel_token_hash', 'order_id', 'hold_expires_at', 'created_at', 'updated_at' ) as $column ) {
			$this->assertContains( $column, $columns );
		}
	}

	public function test_active_start_is_unique_per_resource_but_nulls_are_not(): void {
		global $wpdb;
		$schema = new Schema( $wpdb, self::FRESH_PREFIX );
		$schema->install();
		$table = $schema->table( Schema::BOOKINGS );

		$this->assertSame( 1, $wpdb->insert( $table, $this->booking_row( 'a', 1, '2030-01-01 10:00:00' ) ) );

		$suppress = $wpdb->suppress_errors();
		$result   = $wpdb->insert( $table, $this->booking_row( 'b', 1, '2030-01-01 10:00:00' ) );
		$wpdb->suppress_errors( $suppress );
		$this->assertFalse( $result, 'Same resource and active start must be rejected.' );
		$this->assertStringContainsString( 'Duplicate', $wpdb->last_error );

		$this->assertSame( 1, $wpdb->insert( $table, $this->booking_row( 'c', 2, '2030-01-01 10:00:00' ) ), 'Other resource.' );
		$this->assertSame( 1, $wpdb->insert( $table, $this->booking_row( 'd', 1, null ) ), 'Inactive booking (NULL).' );
		$this->assertSame( 1, $wpdb->insert( $table, $this->booking_row( 'e', 1, null ) ), 'Second inactive booking (NULL).' );
	}

	public function test_upgrade_from_version_1_adds_resource_type_and_keeps_rows(): void {
		global $wpdb;
		$schema = new Schema( $wpdb, self::FRESH_PREFIX );
		$table  = $schema->table( Schema::RESOURCES );
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test table name.
		// Version 1 of the resources table (before the `type` column).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test table name.
		$wpdb->query( "CREATE TABLE {$table} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, name varchar(191) NOT NULL, description text NULL, user_id bigint(20) unsigned NULL, sort_order int(11) NOT NULL DEFAULT 0, is_active tinyint(1) NOT NULL DEFAULT 1, created_at datetime NOT NULL, updated_at datetime NOT NULL, PRIMARY KEY  (id) ) ENGINE=InnoDB" );
		$wpdb->insert(
			$table,
			array(
				'name'       => 'Legacy',
				'created_at' => '2030-01-01 00:00:00',
				'updated_at' => '2030-01-01 00:00:00',
			)
		);
		// Note: ALTER TABLE commits the test transaction implicitly (even on temporary tables), so this test goes through
		// Schema::install() without the migration lock/version options, which would otherwise leak into later tests.
		$changes = $schema->install();

		$this->assertNotEmpty( preg_grep( '/type/', $changes ) );

		$this->assertContains( 'type', $wpdb->get_col( "DESCRIBE {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 'person', $wpdb->get_var( "SELECT type FROM {$table} WHERE name = 'Legacy'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
	}

	public function test_maybe_migrate_runs_only_when_version_differs(): void {
		$schema   = new Schema( $GLOBALS['wpdb'], self::FRESH_PREFIX );
		$migrator = new Migrator( static fn() => $schema );

		update_option( Schema::VERSION_OPTION, Schema::VERSION );
		$this->assertFalse( $migrator->maybe_migrate() );

		update_option( Schema::VERSION_OPTION, '0' );
		$fired = did_action( 'trmz_schema_migrated' );
		$this->assertTrue( $migrator->maybe_migrate() );
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
		$this->assertSame( $fired + 1, did_action( 'trmz_schema_migrated' ) );
		$this->assertFalse( get_option( Migrator::LOCK_OPTION ), 'Lock is released.' );
	}

	public function test_migration_is_skipped_while_locked_and_stale_lock_is_broken(): void {
		$schema = new Schema( $GLOBALS['wpdb'], self::FRESH_PREFIX );
		delete_option( Schema::VERSION_OPTION );

		add_option( Migrator::LOCK_OPTION, (string) time(), '', false );
		$this->assertFalse( Migrator::migrate( $schema ) );
		$this->assertFalse( get_option( Schema::VERSION_OPTION ) );

		update_option( Migrator::LOCK_OPTION, (string) ( time() - 3600 ) );
		$this->assertTrue( Migrator::migrate( $schema ) );
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
	}

	/**
	 * Minimal booking row.
	 *
	 * @param string      $public_id   Public ID.
	 * @param int         $resource_id Resource ID.
	 * @param string|null $active      Active start (UTC) or null.
	 * @return array<string, mixed>
	 */
	private function booking_row( string $public_id, int $resource_id, ?string $active ): array {
		return array(
			'public_id'        => $public_id,
			'service_id'       => 1,
			'resource_id'      => $resource_id,
			'start_utc'        => '2030-01-01 10:00:00',
			'end_utc'          => '2030-01-01 11:00:00',
			'buffer_end_utc'   => '2030-01-01 11:15:00',
			'active_start_utc' => $active,
			'status'           => null === $active ? 'cancelled' : 'confirmed',
			'created_at'       => '2029-12-01 00:00:00',
			'updated_at'       => '2029-12-01 00:00:00',
		);
	}

	/**
	 * Asserts that a (possibly temporary) table exists.
	 *
	 * @param string $table Table name.
	 */
	private function assertTableExists( string $table ): void {
		global $wpdb;
		$suppress = $wpdb->suppress_errors();
		$rows     = $wpdb->get_results( "DESCRIBE `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( $suppress );
		$this->assertNotEmpty( $rows, "Table {$table} exists." );
	}
}
