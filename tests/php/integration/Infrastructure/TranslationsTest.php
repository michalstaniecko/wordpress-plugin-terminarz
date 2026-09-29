<?php
/**
 * Integration tests of the bundled Polish translation (PHP and JavaScript).
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Infrastructure;

use Terminarz\Blocks\BookingBlock;
use Terminarz\Infrastructure\I18n;
use Terminarz\Notifications\MessageType;
use Terminarz\Notifications\Templates;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * Requires built assets (`npm run build`) and compiled translations (`npm run i18n:compile`, committed).
 *
 * @covers \Terminarz\Infrastructure\I18n
 * @covers \Terminarz\Blocks\BookingBlock
 */
final class TranslationsTest extends WP_UnitTestCase {

	/**
	 * Locale of the translation controller before the test.
	 *
	 * @var string
	 */
	private string $previous_locale;

	public function set_up(): void {
		parent::set_up();
		$this->previous_locale = \WP_Translation_Controller::get_instance()->get_locale();
		add_filter( 'locale', array( $this, 'polish' ) );
		\WP_Translation_Controller::get_instance()->set_locale( 'pl_PL' );
		// Reloadable: the next __() call loads the catalogue just in time (WordPress 6.7+ loads plugin translations lazily).
		unload_textdomain( I18n::TEXT_DOMAIN, true );
	}

	public function tear_down(): void {
		unload_textdomain( I18n::TEXT_DOMAIN, true );
		remove_filter( 'locale', array( $this, 'polish' ) );
		\WP_Translation_Controller::get_instance()->set_locale( $this->previous_locale );
		parent::tear_down();
	}

	/**
	 * `locale` filter.
	 */
	public function polish(): string {
		return 'pl_PL';
	}

	public function test_php_strings_are_translated(): void {
		$this->assertSame( 'pl_PL', determine_locale() );
		$this->assertSame( 'Rezerwacje', __( 'Bookings', 'terminarz' ) );
		$this->assertSame( 'Potwierdzona', _x( 'Confirmed', 'booking status', 'terminarz' ) );
	}

	public function test_plural_forms_follow_polish_rules(): void {
		$forms = array(
			1  => '1 minuta',
			2  => '2 minuty',
			5  => '5 minut',
			12 => '12 minut',
			22 => '22 minuty',
		);
		foreach ( $forms as $n => $expected ) {
			/* translators: %d: number of minutes. */
			$this->assertSame( $expected, sprintf( _n( '%d minute', '%d minutes', $n, 'terminarz' ), $n ) );
		}
	}

	public function test_default_e_mails_are_translated(): void {
		$template = ( new Templates() )->get( MessageType::CustomerConfirmed );

		$this->assertStringContainsString( 'Twoja rezerwacja jest potwierdzona', $template->subject );
		$this->assertStringContainsString( 'Dzień dobry', $template->body );
	}

	public function test_block_scripts_load_the_polish_json_translations(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( BookingBlock::NAME );
		$this->assertNotNull( $type, 'Run `npm run build` before the integration tests.' );
		$path = dirname( TRMZ_FILE ) . '/languages';

		$expected = array(
			'view'   => 'Wybierz usługę',
			'editor' => 'Pierwszy dzień tygodnia',
		);
		$handles  = array(
			'view'   => $type->view_script_handles,
			'editor' => $type->editor_script_handles,
		);
		foreach ( $handles as $kind => $list ) {
			$this->assertNotEmpty( $list );
			foreach ( $list as $handle ) {
				$json = load_script_textdomain( $handle, I18n::TEXT_DOMAIN, $path );
				$this->assertIsString( $json, "JSON translations of {$handle} (file name must match the built script path)." );
				$data = json_decode( $json, true );
				$this->assertSame( 'pl_PL', $data['locale_data']['messages']['']['lang'] ?? null, $handle );
				$this->assertStringContainsString( $expected[ $kind ], (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE ), $handle );
			}
		}
	}
}
