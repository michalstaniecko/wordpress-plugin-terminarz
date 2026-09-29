<?php
/**
 * Integration tests for multisite support (run with `composer test:integration:multisite`).
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Multisite;

use Terminarz\Infrastructure\Capabilities;
use Terminarz\Infrastructure\Database\Schema;
use Terminarz\Infrastructure\Lifecycle;
use Terminarz\Infrastructure\Multisite;
use WP_UnitTestCase;

/**
 * @group multisite
 * @covers \Terminarz\Infrastructure\Multisite
 * @covers \Terminarz\Infrastructure\Lifecycle
 */
final class MultisiteTest extends WP_UnitTestCase {

	/**
	 * Sites created by a test (deleted in tear_down, as creating tables commits the test transaction).
	 *
	 * @var int[]
	 */
	private array $sites = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only (WP_TESTS_MULTISITE=1).' );
		}
	}

	public function tear_down(): void {
		$this->set_network_active( false );
		foreach ( $this->sites as $site_id ) {
			if ( get_site( $site_id ) ) {
				wp_delete_site( $site_id );
			}
		}
		$this->sites = array();
		parent::tear_down();
	}

	public function test_network_activation_installs_every_existing_site(): void {
		$site_id = $this->create_site();
		$this->assertFalse( $this->site_has_tables( $site_id ), 'Plugin not network-active: no tables for a new site.' );

		Lifecycle::activate( true );

		foreach ( array( get_main_site_id(), $site_id ) as $id ) {
			$this->assertTrue( $this->site_has_tables( $id ), "Tables of site {$id}" );
			$this->assertTrue( $this->site_admin_has_capability( $id ), "Capability in site {$id}" );
		}
	}

	public function test_site_created_while_network_active_gets_schema_and_capability(): void {
		$this->set_network_active( true );

		$site_id = $this->create_site();

		$this->assertTrue( $this->site_has_tables( $site_id ) );
		$this->assertTrue( $this->site_admin_has_capability( $site_id ) );
		switch_to_blog( $site_id );
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
		restore_current_blog();
	}

	public function test_site_created_without_network_activation_is_left_alone(): void {
		$site_id = $this->create_site();

		$this->assertFalse( $this->site_has_tables( $site_id ) );
		$this->assertFalse( $this->site_admin_has_capability( $site_id ) );
	}

	public function test_deleting_a_site_drops_its_tables_only(): void {
		$this->set_network_active( true );
		$site_id = $this->create_site();
		Lifecycle::activate( true );
		$this->assertTrue( $this->site_has_tables( $site_id ) );

		wp_delete_site( $site_id );

		$this->assertFalse( $this->site_has_tables( $site_id ) );
		$this->assertTrue( $this->site_has_tables( get_main_site_id() ), 'Main site tables are kept.' );
	}

	public function test_drop_tables_filter_lists_plugin_tables_of_the_site(): void {
		global $wpdb;
		$tables = ( new Multisite() )->drop_tables( array( 'x' ), 7 );

		$this->assertContains( 'x', $tables );
		$this->assertContains( $wpdb->get_blog_prefix( 7 ) . Schema::BOOKINGS, $tables );
		$this->assertCount( 1 + count( Schema::logical_tables() ), $tables );
	}

	public function test_plugin_tables_are_per_site(): void {
		global $wpdb;
		$this->set_network_active( true );
		$site_id = $this->create_site();

		switch_to_blog( $site_id );
		$table = Schema::from_globals()->table( Schema::BOOKINGS );
		restore_current_blog();

		$this->assertSame( $wpdb->get_blog_prefix( $site_id ) . Schema::BOOKINGS, $table );
		$this->assertNotSame( Schema::from_globals()->table( Schema::BOOKINGS ), $table );
	}

	private function create_site(): int {
		$site_id       = (int) self::factory()->blog->create();
		$this->sites[] = $site_id;
		return $site_id;
	}

	private function set_network_active( bool $active ): void {
		$plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
		$file    = plugin_basename( TRMZ_FILE );
		if ( $active ) {
			$plugins[ $file ] = time();
		} else {
			unset( $plugins[ $file ] );
		}
		update_site_option( 'active_sitewide_plugins', $plugins );
		$this->assertSame( $active, Multisite::is_network_active() );
	}

	private function site_has_tables( int $site_id ): bool {
		global $wpdb;
		$schema = new Schema( $wpdb, $wpdb->get_blog_prefix( $site_id ) );
		foreach ( $schema->tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test.
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				return false;
			}
		}
		return true;
	}

	private function site_admin_has_capability( int $site_id ): bool {
		switch_to_blog( $site_id );
		$role = get_role( 'administrator' );
		$has  = null !== $role && $role->has_cap( Capabilities::MANAGE_BOOKINGS );
		restore_current_blog();
		return $has;
	}
}
