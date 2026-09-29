<?php
/**
 * Unit tests for the plugin container.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Terminarz\Infrastructure\Module;
use Terminarz\Plugin;

/**
 * @covers \Terminarz\Plugin
 */
final class PluginTest extends TestCase {

	public function test_boot_registers_every_module_once(): void {
		$first  = new CountingModule();
		$second = new CountingModule();
		$plugin = new Plugin( array( $first, $second ) );

		$this->assertFalse( $plugin->is_booted() );

		$plugin->boot();
		$plugin->boot();

		$this->assertTrue( $plugin->is_booted() );
		$this->assertSame( 1, $first->registrations );
		$this->assertSame( 1, $second->registrations );
		$this->assertSame( array( $first, $second ), $plugin->modules() );
	}
}

/**
 * Test double counting register() calls.
 */
final class CountingModule implements Module {

	/**
	 * Number of register() calls.
	 *
	 * @var int
	 */
	public int $registrations = 0;

	public function register(): void {
		++$this->registrations;
	}
}
