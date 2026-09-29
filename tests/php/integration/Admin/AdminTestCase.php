<?php
/**
 * Base class of admin screen integration tests.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Admin;

use DateTimeZone;
use Terminarz\Admin\Notices;
use Terminarz\Admin\Screen;
use Terminarz\Application\AvailabilitySettings;
use Terminarz\Application\FixedClock;
use Terminarz\Infrastructure\Capabilities;
use Terminarz\Infrastructure\Services;
use Terminarz\Tests\Integration\Support\BookingFixtures;
use WP_UnitTestCase;

/**
 * Logged-in manager, fixed clock ("now" = Monday 2030-01-07 06:00 UTC = 07:00 Europe/Warsaw), site time zone
 * Europe/Warsaw, and a helper that runs a write action like `admin-post.php` would (without the redirect/exit).
 */
abstract class AdminTestCase extends WP_UnitTestCase {

	use BookingFixtures;

	/**
	 * Composition root.
	 *
	 * @var Services
	 */
	protected Services $container;

	/**
	 * Clock.
	 *
	 * @var FixedClock
	 */
	protected FixedClock $clock;

	/**
	 * Manager user ID.
	 *
	 * @var int
	 */
	protected int $manager;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		update_option( 'timezone_string', 'Europe/Warsaw' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		$this->clock     = new FixedClock( '2030-01-07 06:00' );
		$this->container = new Services( $wpdb, $this->clock, new AvailabilitySettings( new DateTimeZone( 'Europe/Warsaw' ), 0, null, 30 ) );
		Services::set_instance( $this->container );

		$this->manager = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $this->manager )->add_cap( Capabilities::MANAGE_BOOKINGS );
		wp_set_current_user( $this->manager );
		set_current_screen( 'dashboard' );
		Notices::clear();
	}

	public function tear_down(): void {
		Notices::clear();
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		Services::reset();
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/**
	 * Runs a write action with a valid nonce and returns the redirect URL.
	 *
	 * @param Screen               $screen  Screen.
	 * @param string               $action  Action name (without `trmz_`).
	 * @param array<string, mixed> $request POST data.
	 * @param string|null          $nonce   Nonce to send (null = a valid one).
	 */
	protected function run_action( Screen $screen, string $action, array $request, ?string $nonce = null ): string {
		$request['_wpnonce'] = $nonce ?? wp_create_nonce( 'trmz_' . $action );
		$_POST               = wp_slash( $request );
		$_REQUEST            = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test request; the screen verifies the nonce.
		return $screen->handle( $action );
	}

	/**
	 * Messages of the given type waiting to be shown.
	 *
	 * @param string $type Notice type.
	 * @return string[]
	 */
	protected function notices( string $type ): array {
		return array_values(
			array_map(
				static fn( array $n ): string => $n['message'],
				array_filter( Notices::peek(), static fn( array $n ): bool => $n['type'] === $type )
			)
		);
	}

	/**
	 * Renders a screen view and returns the HTML.
	 *
	 * @param Screen                    $screen Screen.
	 * @param array<string, int|string> $query  Query arguments ($_GET).
	 */
	protected function render( Screen $screen, array $query = array() ): string {
		$_GET     = $query;
		$_REQUEST = $query; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- WP_List_Table reads paging from $_REQUEST.
		ob_start();
		try {
			$screen->render();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * Asserts that a callback dies (wp_die) — capability or nonce failure.
	 *
	 * @param callable $callback Callback.
	 */
	protected function assertDies( callable $callback ): void {
		try {
			$callback();
		} catch ( \WPDieException $e ) {
			$this->assertTrue( true );
			return;
		}
		$this->fail( 'Expected wp_die().' );
	}
}
