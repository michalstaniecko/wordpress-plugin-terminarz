<?php
/**
 * Integration tests for plugin settings (defaults, normalization, sanitization).
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Infrastructure;

use Terminarz\Admin\SettingsPage;
use Terminarz\Application\FixedClock;
use Terminarz\Infrastructure\Services;
use Terminarz\Infrastructure\Settings;
use WP_UnitTestCase;

/**
 * @covers \Terminarz\Infrastructure\Settings
 * @covers \Terminarz\Infrastructure\Services
 */
final class SettingsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reset between tests.
		delete_option( Settings::OPTION );
		( new SettingsPage() )->register_setting();
	}

	/**
	 * A complete, valid form submission.
	 *
	 * @return array<string, string>
	 */
	private static function form(): array {
		return array(
			'slot_step_minutes'           => '30',
			'min_lead_minutes'            => '120',
			'max_horizon_days'            => '45',
			'customer_cancel_limit_hours' => '48',
			'auto_confirm'                => '1',
			'hold_minutes'                => '20',
			'payment_mode'                => 'deposit',
			'deposit_percent'             => '50',
			'any_resource_strategy'       => 'least_busy',
			'notification_email'          => 'bookings@example.org',
			'consent_text'                => 'I accept the <a href="https://example.org/terms">terms</a>.',
			'delete_data_on_uninstall'    => '1',
		);
	}

	/**
	 * Setting errors codes reported for the option.
	 *
	 * @return string[]
	 */
	private static function error_codes(): array {
		return array_column( get_settings_errors( Settings::OPTION ), 'code' );
	}

	public function test_defaults_when_option_is_missing(): void {
		$settings = Settings::load();

		$this->assertSame( Settings::DEFAULTS, $settings->to_array() );
		$this->assertSame( 15, $settings->slot_step_minutes() );
		$this->assertSame( 60, $settings->min_lead_minutes() );
		$this->assertSame( 90, $settings->max_horizon_days() );
		$this->assertSame( 24, $settings->customer_cancel_limit_hours() );
		$this->assertFalse( $settings->auto_confirm() );
		$this->assertSame( 15, $settings->hold_minutes() );
		$this->assertSame( Settings::PAYMENT_NONE, $settings->payment_mode() );
		$this->assertFalse( $settings->payments_enabled() );
		$this->assertSame( 30, $settings->deposit_percent() );
		$this->assertSame( 'order', $settings->any_resource_strategy() );
		$this->assertSame( get_option( 'admin_email' ), $settings->notification_email() );
		$this->assertSame( '', $settings->consent_text() );
		$this->assertFalse( $settings->delete_data_on_uninstall() );
	}

	public function test_option_is_autoloaded_after_save(): void {
		update_option( Settings::OPTION, self::form() );

		$this->assertArrayHasKey( Settings::OPTION, wp_load_alloptions() );
	}

	public function test_valid_form_is_sanitized_to_typed_values(): void {
		update_option( Settings::OPTION, self::form() );

		$stored = get_option( Settings::OPTION );
		$this->assertSame( 30, $stored['slot_step_minutes'] );
		$this->assertSame( 120, $stored['min_lead_minutes'] );
		$this->assertSame( 45, $stored['max_horizon_days'] );
		$this->assertSame( 48, $stored['customer_cancel_limit_hours'] );
		$this->assertTrue( $stored['auto_confirm'] );
		$this->assertSame( 20, $stored['hold_minutes'] );
		$this->assertSame( 'deposit', $stored['payment_mode'] );
		$this->assertSame( 50, $stored['deposit_percent'] );
		$this->assertSame( 'least_busy', $stored['any_resource_strategy'] );
		$this->assertSame( 'bookings@example.org', $stored['notification_email'] );
		$this->assertSame( 'I accept the <a href="https://example.org/terms">terms</a>.', $stored['consent_text'] );
		$this->assertTrue( $stored['delete_data_on_uninstall'] );
		$this->assertSame( array(), self::error_codes() );

		$settings = Settings::load();
		$this->assertTrue( $settings->auto_confirm() );
		$this->assertSame( 'bookings@example.org', $settings->notification_email() );
	}

	public function test_unchecked_checkboxes_are_stored_as_false(): void {
		update_option( Settings::OPTION, self::form() );

		$form = self::form();
		unset( $form['auto_confirm'], $form['delete_data_on_uninstall'] );
		update_option( Settings::OPTION, $form );

		$this->assertFalse( Settings::load()->auto_confirm() );
		$this->assertFalse( Settings::load()->delete_data_on_uninstall() );
	}

	/**
	 * @dataProvider invalid_values
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Invalid submitted value.
	 */
	public function test_invalid_value_keeps_previous_and_reports_error( string $key, $value ): void {
		update_option( Settings::OPTION, self::form() );
		$before = get_option( Settings::OPTION );

		$form         = self::form();
		$form[ $key ] = $value;
		$sanitized    = Settings::sanitize( $form );

		$this->assertSame( $before[ $key ], $sanitized[ $key ] );
		$this->assertContains( 'trmz_invalid_' . $key, self::error_codes() );
	}

	/**
	 * @return iterable<string, array{0:string, 1:mixed}>
	 */
	public static function invalid_values(): iterable {
		yield 'step below minimum' => array( 'slot_step_minutes', '4' );
		yield 'step above maximum' => array( 'slot_step_minutes', '241' );
		yield 'step not a number' => array( 'slot_step_minutes', 'abc' );
		yield 'step decimal' => array( 'slot_step_minutes', '15.5' );
		yield 'negative lead' => array( 'min_lead_minutes', '-1' );
		yield 'lead above 30 days' => array( 'min_lead_minutes', '43201' );
		yield 'horizon above 730' => array( 'max_horizon_days', '731' );
		yield 'cancel limit negative' => array( 'customer_cancel_limit_hours', '-5' );
		yield 'hold zero' => array( 'hold_minutes', '0' );
		yield 'deposit zero' => array( 'deposit_percent', '0' );
		yield 'deposit above 100' => array( 'deposit_percent', '101' );
		yield 'unknown payment mode' => array( 'payment_mode', 'bitcoin' );
		yield 'unknown strategy' => array( 'any_resource_strategy', 'random' );
		yield 'array instead of string' => array( 'payment_mode', array( 'full' ) );
		yield 'invalid e-mail' => array( 'notification_email', 'not-an-email' );
	}

	public function test_boundaries_are_accepted(): void {
		$form = array_merge(
			self::form(),
			array(
				'slot_step_minutes' => ' 5 ',
				'min_lead_minutes'  => '0',
				'max_horizon_days'  => '0',
				'hold_minutes'      => '120',
				'deposit_percent'   => '100',
			)
		);

		$sanitized = Settings::sanitize( $form );

		$this->assertSame( 5, $sanitized['slot_step_minutes'] );
		$this->assertSame( 0, $sanitized['min_lead_minutes'] );
		$this->assertSame( 0, $sanitized['max_horizon_days'] );
		$this->assertSame( 120, $sanitized['hold_minutes'] );
		$this->assertSame( 100, $sanitized['deposit_percent'] );
		$this->assertSame( array(), self::error_codes() );
	}

	public function test_empty_email_means_admin_email(): void {
		update_option( Settings::OPTION, array_merge( self::form(), array( 'notification_email' => '  ' ) ) );

		$this->assertSame( '', get_option( Settings::OPTION )['notification_email'] );
		$this->assertSame( get_option( 'admin_email' ), Settings::load()->notification_email() );
	}

	public function test_consent_text_is_filtered_with_kses(): void {
		$form                 = self::form();
		$form['consent_text'] = '<script>alert(1)</script>I agree <a href="https://example.org" onclick="evil()">here</a>';

		$sanitized = Settings::sanitize( $form );

		$this->assertStringNotContainsString( '<script', $sanitized['consent_text'] );
		$this->assertStringNotContainsString( 'onclick', $sanitized['consent_text'] );
		$this->assertStringContainsString( '<a href="https://example.org">here</a>', $sanitized['consent_text'] );
	}

	public function test_unknown_keys_are_dropped_and_non_array_input_keeps_current(): void {
		update_option( Settings::OPTION, array_merge( self::form(), array( 'evil' => 'x' ) ) );

		$this->assertArrayNotHasKey( 'evil', get_option( Settings::OPTION ) );
		$this->assertSame( array_keys( Settings::DEFAULTS ), array_keys( get_option( Settings::OPTION ) ) );

		$current = get_option( Settings::OPTION );
		$this->assertSame( $current, Settings::sanitize( 'garbage' ) );
	}

	public function test_partial_update_keeps_other_values(): void {
		update_option( Settings::OPTION, self::form() );

		update_option( Settings::OPTION, array( 'slot_step_minutes' => '10' ) + array_intersect_key( self::form(), array_flip( Settings::FLAGS ) ) );

		$settings = Settings::load();
		$this->assertSame( 10, $settings->slot_step_minutes() );
		$this->assertSame( 120, $settings->min_lead_minutes() );
		$this->assertSame( 'deposit', $settings->payment_mode() );
	}

	public function test_corrupted_stored_values_fall_back_to_defaults(): void {
		$corrupt = static fn(): array => array(
			'slot_step_minutes' => 1000,
			'auto_confirm'      => 'yes',
			'payment_mode'      => 'free',
			'consent_text'      => array( 'x' ),
		);
		add_filter( 'pre_option_' . Settings::OPTION, $corrupt );

		$settings = Settings::load();

		remove_filter( 'pre_option_' . Settings::OPTION, $corrupt );
		$this->assertSame( 15, $settings->slot_step_minutes() );
		$this->assertTrue( $settings->auto_confirm() );
		$this->assertSame( Settings::PAYMENT_NONE, $settings->payment_mode() );
		$this->assertSame( '', $settings->consent_text() );
	}

	public function test_non_array_option_gives_defaults(): void {
		$corrupt = static fn(): string => 'broken';
		add_filter( 'pre_option_' . Settings::OPTION, $corrupt );

		$settings = Settings::load();

		remove_filter( 'pre_option_' . Settings::OPTION, $corrupt );
		$this->assertSame( Settings::DEFAULTS, $settings->to_array() );
	}

	public function test_services_expose_settings_and_feed_availability(): void {
		global $wpdb;
		update_option( Settings::OPTION, self::form() );

		$services = new Services( $wpdb, new FixedClock( '2030-01-07 06:00' ) );

		$this->assertTrue( $services->settings()->auto_confirm() );
		$availability = $services->availability_settings();
		$this->assertSame( 30, $availability->slot_step_minutes );
		$this->assertSame( 120, $availability->min_lead_minutes );
		$this->assertSame( 45, $availability->max_horizon_days );
		$this->assertSame( 'least_busy', $availability->strategy );
	}
}
