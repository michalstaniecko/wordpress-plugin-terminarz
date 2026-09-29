<?php
/**
 * Base class of REST integration tests.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Rest;

use DateTimeImmutable;
use DateTimeZone;
use Terminarz\Application\AvailabilitySettings;
use Terminarz\Application\FixedClock;
use Terminarz\Domain\Model\TimeWindow;
use Terminarz\Domain\Model\WeeklySchedule;
use Terminarz\Infrastructure\Services;
use Terminarz\Tests\Integration\Support\BookingFixtures;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Fresh REST server per test, a fixed clock ("now" = Monday 2030-01-07 06:00 UTC = 07:00 Europe/Warsaw)
 * and a composition root bound to it.
 */
abstract class RestTestCase extends WP_UnitTestCase {

	use BookingFixtures;

	protected const MONDAY = '2030-01-07';

	/**
	 * Clock.
	 *
	 * @var FixedClock
	 */
	protected FixedClock $clock;

	/**
	 * Composition root used by the controllers.
	 *
	 * @var Services
	 */
	protected Services $container;

	public function set_up(): void {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Fresh REST server per test.

		$this->clock = new FixedClock( '2030-01-07 06:00' );
		$this->use_settings( new AvailabilitySettings( new DateTimeZone( 'Europe/Warsaw' ), 0, null, 30 ) );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Fresh REST server per test.
		Services::reset();
		parent::tear_down();
	}

	/**
	 * Rebuilds the composition root with the given settings.
	 *
	 * @param AvailabilitySettings $settings Settings.
	 */
	protected function use_settings( AvailabilitySettings $settings ): void {
		global $wpdb;
		$this->container = new Services( $wpdb, $this->clock, $settings );
		Services::set_instance( $this->container );
	}

	/**
	 * Dispatches a request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route without the namespace (e.g. "/services").
	 * @param array<string, mixed> $params Query (GET) or body (other methods) parameters.
	 */
	protected function request( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/terminarz/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Error code of an error response.
	 *
	 * @param WP_REST_Response $response Response.
	 */
	protected static function error_code( WP_REST_Response $response ): string {
		$data = $response->get_data();
		return is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
	}

	/**
	 * Gives a resource working hours (site time) every day of the week.
	 *
	 * @param int    $resource_id Resource.
	 * @param string $from        Start (HH:MM).
	 * @param string $to          End (HH:MM).
	 */
	protected function open_daily( int $resource_id, string $from = '09:00', string $to = '17:00' ): void {
		$window = TimeWindow::from_strings( $from, $to );
		$this->container->schedules()->save( $resource_id, new WeeklySchedule( array_fill_keys( range( 1, 7 ), array( $window ) ) ) );
	}

	/**
	 * Moment given in Warsaw time.
	 *
	 * @param string $time Local time "Y-m-d H:i".
	 */
	protected static function warsaw( string $time ): DateTimeImmutable {
		return new DateTimeImmutable( $time, new DateTimeZone( 'Europe/Warsaw' ) );
	}
}
