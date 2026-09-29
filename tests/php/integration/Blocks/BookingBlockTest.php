<?php
/**
 * Integration tests of the booking block (registration, rendering, configuration).
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Blocks;

use Terminarz\Blocks\BookingBlock;
use Terminarz\Infrastructure\Services;
use Terminarz\Infrastructure\Settings;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * Requires built assets (`npm run build`) — the block is registered from build/booking/block.json.
 *
 * @covers \Terminarz\Blocks\BookingBlock
 */
final class BookingBlockTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		wp_set_current_user( 0 );
		Services::reset();
	}

	public function tear_down(): void {
		Services::reset();
		parent::tear_down();
	}

	/**
	 * Decodes the configuration embedded in the rendered markup.
	 *
	 * @param string $html Rendered block.
	 * @return array<string, mixed>
	 */
	private static function config_of( string $html ): array {
		self::assertMatchesRegularExpression( '#^<div [^>]*class="[^"]*wp-block-terminarz-booking#', $html );
		self::assertStringNotContainsString( 'data-trmz-config', $html );
		self::assertSame( 1, preg_match( '#<script type="application/json" class="trmz-booking__config">(.*?)</script>#s', $html, $m ) );
		$config = json_decode( $m[1], true );
		self::assertIsArray( $config );
		return $config;
	}

	public function test_configuration_json_cannot_break_out_of_the_script_element(): void {
		add_filter(
			'trmz_booking_block_config',
			static function ( array $config ): array {
				$config['extra'] = '</script><script>alert(1)</script> & "quotes"';
				return $config;
			}
		);

		$html = do_blocks( '<!-- wp:terminarz/booking /-->' );

		$this->assertSame( 1, substr_count( $html, '</script>' ), 'Only the closing tag of the configuration element.' );
		$this->assertSame( '</script><script>alert(1)</script> & "quotes"', self::config_of( $html )['extra'] );
	}

	public function test_block_is_registered_from_build_with_scripts_and_translations(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( BookingBlock::NAME );

		$this->assertNotNull( $type, 'Run `npm run build` before the integration tests.' );
		$this->assertTrue( $type->is_dynamic() );
		$this->assertSame( 3, $type->api_version );
		$this->assertNotEmpty( $type->editor_script_handles );
		$this->assertNotEmpty( $type->view_script_handles );
		$this->assertArrayHasKey( 'serviceIds', $type->attributes );
		$this->assertArrayHasKey( 'defaultServiceId', $type->attributes );
		$this->assertArrayHasKey( 'showResourcePicker', $type->attributes );
		$this->assertArrayHasKey( 'firstDayOfWeek', $type->attributes );

		foreach ( array_merge( $type->editor_script_handles, $type->view_script_handles ) as $handle ) {
			$this->assertSame( 'terminarz', wp_scripts()->registered[ $handle ]->textdomain, $handle );
		}
	}

	public function test_rendered_block_contains_container_config_and_enqueues_view_script(): void {
		update_option( 'start_of_week', 0 );
		Services::reset();

		$html   = do_blocks( '<!-- wp:terminarz/booking {"serviceIds":[3,5],"defaultServiceId":5,"showResourcePicker":false} /-->' );
		$config = self::config_of( $html );

		$this->assertSame( array( 3, 5 ), $config['serviceIds'] );
		$this->assertSame( 5, $config['defaultServiceId'] );
		$this->assertFalse( $config['showResourcePicker'] );
		$this->assertSame( 0, $config['firstDayOfWeek'] );
		$this->assertSame( rest_url(), $config['restRoot'] );
		$this->assertSame( '', $config['nonce'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $config['today'] );
		$this->assertSame( wp_date( 'Y-m-d' ), $config['today'] );
		$this->assertStringContainsString( '<noscript>', $html );

		$type = WP_Block_Type_Registry::get_instance()->get_registered( BookingBlock::NAME );
		$this->assertNotNull( $type );
		foreach ( $type->view_script_handles as $handle ) {
			$this->assertTrue( wp_script_is( $handle, 'enqueued' ), $handle );
		}
	}

	public function test_horizon_limits_the_last_bookable_date(): void {
		update_option( Settings::OPTION, array_merge( Settings::DEFAULTS, array( 'max_horizon_days' => 10 ) ) );
		Services::reset();

		$config = ( new BookingBlock() )->config( array() );

		$expected = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+10 days' )->format( 'Y-m-d' );
		$this->assertSame( $expected, $config['lastDate'] );
	}

	public function test_nonce_is_passed_only_to_logged_in_users(): void {
		$this->assertSame( '', ( new BookingBlock() )->config( array() )['nonce'] );

		wp_set_current_user( self::factory()->user->create() );
		$nonce = ( new BookingBlock() )->config( array() )['nonce'];

		$this->assertNotSame( '', $nonce );
		$this->assertSame( 1, wp_verify_nonce( $nonce, 'wp_rest' ) );
	}

	public function test_attributes_are_sanitized(): void {
		$attributes = BookingBlock::sanitize_attributes(
			array(
				'serviceIds'         => array( '4', 'x', -2, 4, 0, 9 ),
				'defaultServiceId'   => 'abc',
				'showResourcePicker' => 0,
				'firstDayOfWeek'     => 9,
			)
		);

		$this->assertSame(
			array(
				'serviceIds'         => array( 4, 2, 9 ),
				'defaultServiceId'   => 0,
				'showResourcePicker' => false,
				'firstDayOfWeek'     => -1,
			),
			$attributes
		);
		$this->assertSame(
			array(
				'serviceIds'         => array(),
				'defaultServiceId'   => 0,
				'showResourcePicker' => true,
				'firstDayOfWeek'     => -1,
			),
			BookingBlock::sanitize_attributes( array( 'serviceIds' => 'nope' ) )
		);
	}

	public function test_consent_text_is_filtered_and_markup_is_escaped(): void {
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::DEFAULTS,
				array( 'consent_text' => 'I accept the <a href="https://example.org/terms" onclick="evil()">terms</a> "quoted" <script>alert(1)</script><div>block</div>' )
			)
		);

		Services::reset();

		$html   = do_blocks( '<!-- wp:terminarz/booking /-->' );
		$config = self::config_of( $html );

		$this->assertSame( 1, substr_count( $html, '<script' ), 'Only the configuration element.' );
		$this->assertStringNotContainsString( '<a href', $html, 'HTML inside the JSON must be escaped.' );
		$this->assertStringContainsString( '<a href="https://example.org/terms">terms</a>', $config['consentHtml'] );
		$this->assertStringContainsString( '"quoted"', $config['consentHtml'] );
		$this->assertStringNotContainsString( 'onclick', $config['consentHtml'] );
		$this->assertStringNotContainsString( '<script', $config['consentHtml'] );
		$this->assertStringNotContainsString( '<div', $config['consentHtml'] );
	}

	public function test_email_notice_follows_the_customer_templates(): void {
		delete_option( \Terminarz\Notifications\Templates::OPTION );
		$this->assertTrue( ( new BookingBlock() )->config( array() )['emailNotice'] );

		$templates = new \Terminarz\Notifications\Templates();
		$templates->set_enabled( \Terminarz\Notifications\MessageType::CustomerPending, false );
		$templates->set_enabled( \Terminarz\Notifications\MessageType::CustomerConfirmed, false );
		Services::reset();

		$this->assertFalse( ( new BookingBlock() )->config( array() )['emailNotice'] );
	}

	public function test_time_format_of_the_site_is_passed(): void {
		update_option( 'time_format', 'g:i a' );
		$this->assertSame( 'g:i a', ( new BookingBlock() )->config( array() )['timeFormat'] );

		update_option( 'time_format', 'H:i' );
		$this->assertSame( 'H:i', ( new BookingBlock() )->config( array() )['timeFormat'] );
	}

	public function test_default_consent_text_when_not_configured(): void {
		$config = ( new BookingBlock() )->config( array() );

		$this->assertStringContainsString( 'personal data', $config['consentHtml'] );
	}

	public function test_config_filter(): void {
		$filter = static function ( array $config ): array {
			$config['extra'] = 'yes';
			return $config;
		};
		add_filter( 'trmz_booking_block_config', $filter );

		$config = ( new BookingBlock() )->config( array() );

		remove_filter( 'trmz_booking_block_config', $filter );
		$this->assertSame( 'yes', $config['extra'] );
	}

	public function test_missing_build_is_ignored_without_errors(): void {
		$block = new BookingBlock( '/nonexistent' );
		$block->register_block();

		$this->assertNotNull( WP_Block_Type_Registry::get_instance()->get_registered( BookingBlock::NAME ) );
	}
}
