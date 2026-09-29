<?php
/**
 * Integration tests for uninstallation.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Infrastructure;

use Terminarz\Infrastructure\Capabilities;
use Terminarz\Infrastructure\Database\Schema;
use Terminarz\Infrastructure\HoldExpiryScheduler;
use Terminarz\Infrastructure\Lifecycle;
use Terminarz\Infrastructure\RateLimiter;
use Terminarz\Infrastructure\Settings;
use Terminarz\Infrastructure\Uninstaller;
use Terminarz\Notifications\ReminderScheduler;
use Terminarz\Notifications\Templates;
use WP_UnitTestCase;

/**
 * The WordPress test case turns CREATE/DROP TABLE into their TEMPORARY variants; tests that delete data run real DDL
 * ({@see self::real_ddl()}), which commits the test transaction, so tear_down() restores the schema, the options and
 * the capability itself.
 *
 * @covers \Terminarz\Infrastructure\Uninstaller
 */
final class UninstallerTest extends WP_UnitTestCase {

	/**
	 * Sites created by a test (multisite).
	 *
	 * @var int[]
	 */
	private array $sites = array();

	public function tear_down(): void {
		HoldExpiryScheduler::unschedule();
		ReminderScheduler::unschedule_all();
		foreach ( $this->sites as $site_id ) {
			if ( get_site( $site_id ) ) {
				wp_delete_site( $site_id );
			}
		}
		$this->sites = array();
		if ( is_multisite() ) {
			update_site_option( 'active_sitewide_plugins', array() );
		}

		// DML first, DDL last: the CREATE TABLE statements commit the restored state.
		delete_option( Settings::OPTION );
		Capabilities::grant();
		$this->real_ddl();
		Schema::from_globals()->install();

		parent::tear_down();
	}

	public function test_uninstall_file_runs_the_uninstaller(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 4 ) . '/uninstall.php' );

		$this->assertStringContainsString( "defined( 'WP_UNINSTALL_PLUGIN' )", $source );
		$this->assertStringContainsString( '/vendor/autoload.php', $source );
		$this->assertStringContainsString( 'Uninstaller::run()', $source );
	}

	public function test_keeps_data_by_default_but_removes_scheduled_jobs(): void {
		update_option( Templates::OPTION, array( 'x' => 'y' ) );
		wp_schedule_event( time() + 60, 'hourly', HoldExpiryScheduler::HOOK );
		wp_schedule_single_event( time() + 3600, ReminderScheduler::HOOK, array( 12 ) );

		$this->assertFalse( Uninstaller::uninstall_site() );

		$this->assertFalse( wp_next_scheduled( HoldExpiryScheduler::HOOK ) );
		$this->assertFalse( wp_next_scheduled( ReminderScheduler::HOOK, array( 12 ) ) );
		$this->assertTrue( $this->has_tables() );
		$this->assertSame( array( 'x' => 'y' ), get_option( Templates::OPTION ) );
		$this->assertSame( Schema::VERSION, get_option( Schema::VERSION_OPTION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_BOOKINGS ) );
	}

	public function test_removes_every_action_scheduler_action_of_the_group(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || 0 === did_action( 'action_scheduler_init' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded (WooCommerce missing).' );
		}
		as_schedule_recurring_action( time() + 60, 300, HoldExpiryScheduler::HOOK, array(), HoldExpiryScheduler::GROUP );
		as_schedule_single_action( time() + 3600, ReminderScheduler::HOOK, array( 5 ), ReminderScheduler::GROUP );
		as_schedule_single_action( time() + 3600, 'trmz_future_job', array(), HoldExpiryScheduler::GROUP );
		as_schedule_single_action( time() + 3600, 'other_plugin_job', array(), 'other' );

		Uninstaller::unschedule_jobs();

		$this->assertFalse( as_has_scheduled_action( HoldExpiryScheduler::HOOK ) );
		$this->assertFalse( as_has_scheduled_action( ReminderScheduler::HOOK ) );
		$this->assertFalse( as_has_scheduled_action( 'trmz_future_job' ) );
		$this->assertTrue( as_has_scheduled_action( 'other_plugin_job' ), 'Actions of other plugins are kept.' );
		as_unschedule_all_actions( 'other_plugin_job' );
	}

	public function test_deletes_data_when_enabled_in_settings(): void {
		$limiter = new RateLimiter( new \Terminarz\Application\SystemClock() );
		$limiter->hit( 'booking', '203.0.113.5', 10, 60 );
		$transient = RateLimiter::key( 'booking', '203.0.113.5' );
		$this->assertNotFalse( get_transient( $transient ) );
		update_option( Templates::OPTION, array( 'x' => 'y' ) );
		update_option( 'trmz_unknown_future_option', 1 );
		update_option( 'unrelated_option', 1 );
		get_role( 'editor' )->add_cap( Capabilities::MANAGE_BOOKINGS );
		update_option( Settings::OPTION, array( 'delete_data_on_uninstall' => true ) );
		$this->real_ddl();

		$this->assertTrue( Uninstaller::uninstall_site() );

		$this->assertFalse( $this->has_tables(), 'Tables dropped' );
		foreach ( array( Settings::OPTION, Templates::OPTION, Schema::VERSION_OPTION, 'trmz_unknown_future_option' ) as $option ) {
			$this->assertFalse( $this->option_stored( $option ), $option );
		}
		$this->assertFalse( get_transient( $transient ), 'Rate limit transient' );
		$this->assertSame( '1', (string) get_option( 'unrelated_option' ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_BOOKINGS ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::MANAGE_BOOKINGS ) );
		get_role( 'editor' )->remove_cap( Capabilities::MANAGE_BOOKINGS );
		delete_option( 'unrelated_option' );
	}

	/**
	 * @group multisite
	 */
	public function test_multisite_uninstall_is_per_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only (WP_TESTS_MULTISITE=1).' );
		}
		$keep   = (int) self::factory()->blog->create();
		$delete = (int) self::factory()->blog->create();
		array_push( $this->sites, $keep, $delete );
		Lifecycle::activate( true );
		switch_to_blog( $delete );
		update_option( Settings::OPTION, array( 'delete_data_on_uninstall' => true ) );
		wp_schedule_event( time() + 60, 'hourly', HoldExpiryScheduler::HOOK );
		restore_current_blog();
		switch_to_blog( $keep );
		wp_schedule_event( time() + 60, 'hourly', HoldExpiryScheduler::HOOK );
		restore_current_blog();
		$this->real_ddl();

		Uninstaller::run();

		$this->assertFalse( $this->has_tables( $delete ), 'Site that opted in: tables dropped' );
		$this->assertTrue( $this->has_tables( $keep ), 'Site that did not opt in: tables kept' );
		$this->assertTrue( $this->has_tables( get_main_site_id() ) );
		switch_to_blog( $keep );
		$this->assertFalse( wp_next_scheduled( HoldExpiryScheduler::HOOK ), 'Jobs removed everywhere' );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_BOOKINGS ) );
		restore_current_blog();
		switch_to_blog( $delete );
		$this->assertFalse( wp_next_scheduled( HoldExpiryScheduler::HOOK ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_BOOKINGS ) );
		$this->assertFalse( $this->option_stored( Settings::OPTION ) );
		restore_current_blog();
	}

	/**
	 * Whether an option row exists in the current site (get_option() may return a registered default).
	 *
	 * @param string $option Option name.
	 */
	private function option_stored( string $option ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test.
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $option ) );
	}

	/**
	 * Stops turning CREATE/DROP TABLE into temporary-table statements for the rest of the test.
	 */
	private function real_ddl(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Whether every plugin table exists (regular or temporary) for a site.
	 *
	 * @param int $site_id Site ID (0 = current site).
	 */
	private function has_tables( int $site_id = 0 ): bool {
		global $wpdb;
		$prefix   = $site_id > 0 ? $wpdb->get_blog_prefix( $site_id ) : $wpdb->prefix;
		$suppress = $wpdb->suppress_errors( true );
		$exists   = true;
		foreach ( ( new Schema( $wpdb, $prefix ) )->tables() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test; identifiers cannot be prepared.
			if ( false === $wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 0" ) ) {
				$exists = false;
				break;
			}
		}
		$wpdb->suppress_errors( $suppress );
		return $exists;
	}
}
