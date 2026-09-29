<?php
/**
 * Integration tests for the admin menu and the settings screen.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Admin;

use Terminarz\Admin\Menu;
use Terminarz\Admin\SettingsPage;
use Terminarz\Infrastructure\Capabilities;
use Terminarz\Infrastructure\Lifecycle;
use Terminarz\Infrastructure\Settings;
use Terminarz\Plugin;
use WP_UnitTestCase;
use WPDieException;

/**
 * @covers \Terminarz\Admin\Menu
 * @covers \Terminarz\Admin\SettingsPage
 */
final class SettingsPageTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		Lifecycle::activate();
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- resetting admin globals between tests.
		$GLOBALS['menu']               = array();
		$GLOBALS['submenu']            = array();
		$GLOBALS['wp_settings_errors'] = array();
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		delete_option( Settings::OPTION );
	}

	public function tear_down(): void {
		unset( $GLOBALS['wp_settings_sections']['trmz-settings'], $GLOBALS['wp_settings_fields']['trmz-settings'] );
		parent::tear_down();
	}

	private function login( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	public function test_menu_is_registered_for_managers(): void {
		$this->login( 'administrator' );

		( new Menu( array( new SettingsPage() ) ) )->add_menu();

		$top = array_values( array_filter( $GLOBALS['menu'], static fn( $item ): bool => SettingsPage::SLUG === $item[2] ) );
		$this->assertCount( 1, $top );
		$this->assertSame( 'Terminarz', $top[0][0] );
		$this->assertSame( Capabilities::MANAGE_BOOKINGS, $top[0][1] );
		$this->assertSame( array( SettingsPage::SLUG ), array_column( $GLOBALS['submenu'][ SettingsPage::SLUG ], 2 ) );
		$this->assertStringContainsString( 'admin.php?page=trmz-settings', menu_page_url( SettingsPage::SLUG, false ) );
	}

	public function test_menu_is_hidden_without_capability(): void {
		$this->login( 'editor' );

		( new Menu( array( new SettingsPage() ) ) )->add_menu();

		// Top-level items are filtered by capability later (wp-admin/includes/menu.php); submenu entries are refused here.
		$this->assertArrayNotHasKey( SettingsPage::SLUG, $GLOBALS['submenu'] );
		foreach ( $GLOBALS['menu'] as $item ) {
			if ( SettingsPage::SLUG === $item[2] ) {
				$this->assertSame( Capabilities::MANAGE_BOOKINGS, $item[1] );
			}
		}
		$this->assertFalse( user_can( get_current_user_id(), Capabilities::MANAGE_BOOKINGS ) );
	}

	public function test_plugin_registers_menu_on_admin_menu(): void {
		$menus = array_values( array_filter( Plugin::instance()->modules(), static fn( $module ): bool => $module instanceof Menu ) );

		$this->assertCount( 1, $menus );
		$this->assertNotFalse( has_action( 'admin_menu', array( $menus[0], 'add_menu' ) ) );
		$slugs = array_map( static fn( $page ): string => $page->slug(), $menus[0]->pages() );
		$this->assertContains( SettingsPage::SLUG, $slugs );
		$this->assertSame( $slugs[0], $menus[0]->parent_slug() );
	}

	public function test_options_page_capability_is_the_plugin_capability(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		$this->assertSame( Capabilities::MANAGE_BOOKINGS, apply_filters( 'option_page_capability_' . SettingsPage::GROUP, 'manage_options' ) );
	}

	public function test_setting_is_registered_with_sanitize_callback(): void {
		$registered = get_registered_settings();

		$this->assertArrayHasKey( Settings::OPTION, $registered );
		$this->assertSame( SettingsPage::GROUP, $registered[ Settings::OPTION ]['group'] );
		$this->assertSame( array( Settings::class, 'sanitize' ), $registered[ Settings::OPTION ]['sanitize_callback'] );
	}

	public function test_render_prints_form_with_escaped_values(): void {
		$this->login( 'administrator' );
		update_option(
			Settings::OPTION,
			array(
				'consent_text'       => 'Terms "quoted" <a href="https://example.org">link</a>',
				'notification_email' => 'shop@example.org',
			)
		);
		$page = new SettingsPage();
		$page->register_fields();

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( "name='option_page' value='trmz_settings'", $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		foreach ( array_keys( Settings::DEFAULTS ) as $key ) {
			$this->assertStringContainsString( 'name="trmz_settings[' . $key . ']"', $html, $key );
		}
		$this->assertStringContainsString( 'value="shop@example.org"', $html );
		$this->assertStringContainsString( 'Terms &quot;quoted&quot; &lt;a href=&quot;https://example.org&quot;&gt;link&lt;/a&gt;</textarea>', $html );
		$this->assertStringContainsString( 'min="5" max="240"', $html );
	}

	public function test_render_shows_woocommerce_notice_when_inactive(): void {
		$this->login( 'administrator' );
		$page = new SettingsPage();

		ob_start();
		$page->payments_intro();
		$html = (string) ob_get_clean();

		if ( class_exists( 'WooCommerce' ) ) {
			$this->assertStringContainsString( 'processed by WooCommerce', $html );
		} else {
			$this->assertStringContainsString( 'notice-warning', $html );
			$this->assertStringContainsString( 'WooCommerce is not active', $html );
		}
	}

	public function test_render_refuses_users_without_capability(): void {
		$this->login( 'editor' );

		$this->expectException( WPDieException::class );
		( new SettingsPage() )->render();
	}
}
