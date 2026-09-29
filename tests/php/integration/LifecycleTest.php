<?php
/**
 * Integration tests for activation/deactivation.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration;

use Terminarz\Infrastructure\Capabilities;
use Terminarz\Infrastructure\Lifecycle;
use Terminarz\Plugin;
use WP_UnitTestCase;

/**
 * @covers \Terminarz\Infrastructure\Lifecycle
 * @covers \Terminarz\Infrastructure\Capabilities
 */
final class LifecycleTest extends WP_UnitTestCase {

	public function test_plugin_is_booted(): void {
		$this->assertTrue( Plugin::instance()->is_booted() );
	}

	public function test_activation_grants_capability_to_administrators_only(): void {
		Capabilities::revoke();
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_BOOKINGS ) );

		Lifecycle::activate();

		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_BOOKINGS ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::MANAGE_BOOKINGS ) );

		$admin  = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$this->assertTrue( user_can( $admin, Capabilities::MANAGE_BOOKINGS ) );
		$this->assertFalse( user_can( $editor, Capabilities::MANAGE_BOOKINGS ) );
	}

	public function test_deactivation_keeps_capability(): void {
		Lifecycle::activate();
		Lifecycle::deactivate();

		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_BOOKINGS ) );
	}

	public function test_revoke_removes_capability_from_all_roles(): void {
		get_role( 'editor' )->add_cap( Capabilities::MANAGE_BOOKINGS );
		Lifecycle::activate();

		Capabilities::revoke();

		foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
			$this->assertFalse( get_role( (string) $role_name )->has_cap( Capabilities::MANAGE_BOOKINGS ), $role_name );
		}
	}
}
