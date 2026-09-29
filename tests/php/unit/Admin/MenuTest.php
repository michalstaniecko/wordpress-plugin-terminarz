<?php
/**
 * Unit tests for the admin menu registry.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Terminarz\Admin\AdminPage;
use Terminarz\Admin\Menu;

/**
 * @covers \Terminarz\Admin\Menu
 */
final class MenuTest extends TestCase {

	public function test_empty_menu_has_no_parent(): void {
		$this->assertNull( ( new Menu() )->parent_slug() );
		$this->assertSame( array(), ( new Menu() )->pages() );
	}

	public function test_pages_are_ordered_by_position_then_slug_and_first_is_parent(): void {
		$settings = new FakePage( 'trmz-settings', 90 );
		$bookings = new FakePage( 'trmz-bookings', 10 );
		$services = new FakePage( 'trmz-services', 30 );
		$another  = new FakePage( 'trmz-alpha', 30 );

		$menu = ( new Menu( array( $settings ) ) )->add_page( $bookings )->add_page( $services )->add_page( $another );

		$this->assertSame( array( $bookings, $another, $services, $settings ), $menu->pages() );
		$this->assertSame( 'trmz-bookings', $menu->parent_slug() );
	}

	public function test_page_with_same_slug_replaces_previous(): void {
		$first  = new FakePage( 'trmz-settings', 90 );
		$second = new FakePage( 'trmz-settings', 5 );

		$menu = new Menu( array( $first, $second ) );

		$this->assertSame( array( $second ), $menu->pages() );
	}
}

/**
 * Minimal admin page double.
 */
final class FakePage implements AdminPage {

	/**
	 * Constructor.
	 *
	 * @param string $slug     Slug.
	 * @param int    $position Position.
	 */
	public function __construct( private string $slug, private int $position ) {
	}

	public function slug(): string {
		return $this->slug;
	}

	public function menu_title(): string {
		return $this->slug;
	}

	public function page_title(): string {
		return $this->slug;
	}

	public function position(): int {
		return $this->position;
	}

	public function register(): void {
	}

	public function render(): void {
	}
}
