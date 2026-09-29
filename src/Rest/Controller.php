<?php
/**
 * Base REST controller.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Rest;

use Terminarz\Infrastructure\Capabilities;
use Terminarz\Infrastructure\Services;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;

/**
 * Shared helpers of the `terminarz/v1` controllers: namespace, composition root, permission checks.
 */
abstract class Controller extends WP_REST_Controller {

	public const NAMESPACE = 'terminarz/v1';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = self::NAMESPACE;
	}

	/**
	 * Composition root (resolved per request, so tests can swap it).
	 */
	protected function services(): Services {
		return Services::instance();
	}

	/**
	 * Permission callback of public, read-only endpoints: anyone may read the public catalogue and availability.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function public_read_permissions_check( $request ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature required by the REST API.
		return true;
	}

	/**
	 * Permission callback of administrative endpoints: requires the `trmz_manage_bookings` capability.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function manage_permissions_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Signature required by the REST API.
		if ( current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to manage bookings.', 'terminarz' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Formats a moment as ISO 8601 in the site time zone (e.g. `2030-01-07T09:00:00+01:00`).
	 *
	 * @param \DateTimeImmutable $time Moment.
	 */
	protected function local_iso( \DateTimeImmutable $time ): string {
		return $time->setTimezone( $this->services()->availability_settings()->timezone )->format( DATE_RFC3339 );
	}

	/**
	 * Formats a moment as ISO 8601 in UTC (e.g. `2030-01-07T08:00:00Z`).
	 *
	 * @param \DateTimeImmutable $time Moment.
	 */
	protected static function utc_iso( \DateTimeImmutable $time ): string {
		return $time->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}
}
