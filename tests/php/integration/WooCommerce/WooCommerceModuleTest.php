<?php
/**
 * Integration tests of the WooCommerce integration layer (WooCommerce loaded).
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Terminarz\Admin\SettingsPage;
use Terminarz\Infrastructure\Module;
use Terminarz\Infrastructure\Settings;
use Terminarz\Integrations\WooCommerce\WooCommerce;
use Terminarz\Integrations\WooCommerce\WooCommerceModule;
use Terminarz\Plugin;
use WP_UnitTestCase;

/**
 * @group woocommerce
 * @covers \Terminarz\Integrations\WooCommerce\WooCommerce
 * @covers \Terminarz\Integrations\WooCommerce\WooCommerceModule
 */
final class WooCommerceModuleTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded (TRMZ_TESTS_WOOCOMMERCE=0).' );
		}
	}

	public function test_detects_supported_woocommerce(): void {
		$this->assertTrue( WooCommerce::is_loaded() );
		$this->assertTrue( WooCommerce::is_active() );
		$this->assertFalse( WooCommerce::is_outdated() );
		$this->assertTrue( version_compare( WooCommerce::version(), WooCommerce::MIN_VERSION, '>=' ) );
	}

	public function test_filter_can_disable_the_integration(): void {
		add_filter( 'trmz_woocommerce_active', '__return_false' );

		$this->assertFalse( WooCommerce::is_active() );
		$this->assertFalse( ( new Settings( array( 'payment_mode' => Settings::PAYMENT_FULL ) ) )->payments_enabled() );
	}

	public function test_payments_enabled_only_with_a_payment_mode(): void {
		$this->assertFalse( ( new Settings() )->payments_enabled() );
		$this->assertTrue( ( new Settings( array( 'payment_mode' => Settings::PAYMENT_FULL ) ) )->payments_enabled() );
		$this->assertTrue( ( new Settings( array( 'payment_mode' => Settings::PAYMENT_DEPOSIT ) ) )->payments_enabled() );
	}

	public function test_plugin_loads_the_integration(): void {
		$modules = array_values( array_filter( Plugin::instance()->modules(), static fn( $module ): bool => $module instanceof WooCommerceModule ) );

		$this->assertCount( 1, $modules );
		$this->assertTrue( $modules[0]->is_integration_loaded() );
		$this->assertSame( 1, did_action( WooCommerceModule::LOADED_ACTION ) );
	}

	public function test_declares_hpos_and_checkout_blocks_compatibility(): void {
		$basename = plugin_basename( TRMZ_FILE );

		foreach ( array( 'custom_order_tables', 'cart_checkout_blocks' ) as $feature ) {
			$plugins = FeaturesUtil::get_compatible_plugins_for_feature( $feature );
			$this->assertContains( $basename, $plugins['compatible'], $feature );
			$this->assertNotContains( $basename, $plugins['incompatible'], $feature );
		}
	}

	public function test_registers_components_once_when_active(): void {
		$component = new class() implements Module {
			/**
			 * Number of register() calls.
			 *
			 * @var int
			 */
			public int $calls = 0;

			public function register(): void {
				++$this->calls;
			}
		};
		$module    = new WooCommerceModule( array( $component ) );

		$module->register(); // plugins_loaded already fired: loads immediately.
		$module->load_integration();

		$this->assertSame( 1, $component->calls );
		$this->assertTrue( $module->is_integration_loaded() );
		$this->assertSame( 10, has_action( 'before_woocommerce_init', array( $module, 'declare_compatibility' ) ) );
	}

	public function test_does_not_register_components_when_disabled(): void {
		add_filter( 'trmz_woocommerce_active', '__return_false' );
		$component = new class() implements Module {
			/**
			 * Number of register() calls.
			 *
			 * @var int
			 */
			public int $calls = 0;

			public function register(): void {
				++$this->calls;
			}
		};
		$module    = new WooCommerceModule( array( $component ) );

		$module->load_integration();

		$this->assertSame( 0, $component->calls );
		$this->assertFalse( $module->is_integration_loaded() );
	}

	public function test_outdated_woocommerce_is_reported(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $user_id ); // Only network admins can update plugins on multisite.
		}
		wp_set_current_user( $user_id );

		ob_start();
		( new WooCommerceModule() )->outdated_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'require WooCommerce 8.0 or newer', $html );
	}

	public function test_settings_page_says_payments_are_processed_by_woocommerce(): void {
		ob_start();
		( new SettingsPage() )->payments_intro();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'processed by WooCommerce', $html );
	}
}
