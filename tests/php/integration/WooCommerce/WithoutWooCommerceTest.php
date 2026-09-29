<?php
/**
 * Integration tests of the plugin without WooCommerce (run with TRMZ_TESTS_WOOCOMMERCE=0 --group no-woocommerce).
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\WooCommerce;

use Terminarz\Admin\SettingsPage;
use Terminarz\Infrastructure\Settings;
use Terminarz\Integrations\WooCommerce\WooCommerce;
use Terminarz\Integrations\WooCommerce\WooCommerceModule;
use Terminarz\Plugin;
use Terminarz\Tests\Integration\Rest\RestTestCase;

/**
 * @group no-woocommerce
 * @covers \Terminarz\Integrations\WooCommerce\WooCommerce
 * @covers \Terminarz\Integrations\WooCommerce\WooCommerceModule
 */
final class WithoutWooCommerceTest extends RestTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is loaded; run with TRMZ_TESTS_WOOCOMMERCE=0.' );
		}
		update_option( Settings::OPTION, array( 'payment_mode' => Settings::PAYMENT_FULL ) );
	}

	public function test_integration_is_not_loaded(): void {
		$this->assertFalse( WooCommerce::is_loaded() );
		$this->assertFalse( WooCommerce::is_active() );
		$this->assertFalse( WooCommerce::is_outdated() );
		$this->assertSame( '', WooCommerce::version() );

		$modules = array_values( array_filter( Plugin::instance()->modules(), static fn( $module ): bool => $module instanceof WooCommerceModule ) );
		$this->assertCount( 1, $modules );
		$this->assertFalse( $modules[0]->is_integration_loaded() );
		$this->assertSame( 0, did_action( WooCommerceModule::LOADED_ACTION ) );
	}

	public function test_payment_mode_is_ignored(): void {
		$settings = Settings::load();

		$this->assertSame( Settings::PAYMENT_FULL, $settings->payment_mode() );
		$this->assertFalse( $settings->payments_enabled() );
	}

	public function test_settings_page_explains_that_woocommerce_is_missing(): void {
		ob_start();
		( new SettingsPage() )->payments_intro();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'WooCommerce is not active', $html );
	}

	public function test_booking_is_created_without_payment(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$this->open_daily( $resource );

		$response = $this->request(
			'POST',
			'/bookings',
			array(
				'service'  => $service,
				'resource' => (string) $resource,
				'start'    => self::MONDAY . 'T09:00:00+01:00',
				'name'     => 'Jan Kowalski',
				'email'    => 'jan@example.org',
				'consent'  => true,
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'pending', $data['status'] );
		$this->assertArrayNotHasKey( 'payment_url', $data );
	}
}
