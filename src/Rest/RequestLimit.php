<?php
/**
 * Request limits of public write endpoints.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Rest;

use Terminarz\Infrastructure\Capabilities;
use Terminarz\Infrastructure\Services;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;

/**
 * Limits how often one client may call a public write endpoint (booking now, cancellation in M7).
 *
 * - Client = IP address from `REMOTE_ADDR` only. Proxy headers (`X-Forwarded-For`…) can be spoofed, so they are
 *   used only when a site opts in with the `trmz_client_ip` filter (e.g. behind a trusted reverse proxy).
 * - Limit per bucket: `trmz_rate_limit` filter, default 5 requests per 10 minutes; a limit <= 0 disables it.
 * - Every request that passes schema validation counts (successful or not), which also slows down slot probing.
 * - Users with `trmz_manage_bookings` are not limited.
 * - Over the limit: 429 `trmz_rate_limited` with a `Retry-After` header (seconds).
 */
final class RequestLimit {

	public const BOOKING_CREATE = 'booking_create';

	public const BOOKING_CANCEL = 'booking_cancel';

	public const DEFAULT_LIMIT = 5;

	public const DEFAULT_WINDOW = 600;

	public const ERROR_CODE = 'trmz_rate_limited';

	/**
	 * Registers a hit of the current client; returns an error when over the limit.
	 *
	 * @param string               $bucket  Kind of action.
	 * @param WP_REST_Request|null $request REST request; null outside the REST API (cancellation page).
	 * @phpstan-param WP_REST_Request<array<string, mixed>>|null $request
	 * @return true|WP_Error
	 */
	public static function check( string $bucket, ?WP_REST_Request $request = null ) {
		if ( current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			return true;
		}

		$defaults = array(
			'limit'  => self::DEFAULT_LIMIT,
			'window' => self::DEFAULT_WINDOW,
		);

		/**
		 * Filters the request limit of a public write endpoint.
		 *
		 * @param array{limit: int, window: int} $config  Allowed requests per window (seconds); limit <= 0 disables it.
		 * @param string                         $bucket  Kind of action: "booking_create" or "booking_cancel".
		 * @param WP_REST_Request|null           $request REST request (null for the cancellation page).
		 */
		$config = apply_filters( 'trmz_rate_limit', $defaults, $bucket, $request );
		$config = is_array( $config ) ? array_merge( $defaults, $config ) : $defaults;

		$result = Services::instance()->rate_limiter()->hit( $bucket, self::client_ip( $request ), (int) $config['limit'], (int) $config['window'] );
		if ( $result['allowed'] ) {
			return true;
		}

		return new WP_Error(
			self::ERROR_CODE,
			__( 'Too many attempts. Please wait a few minutes and try again.', 'terminarz' ),
			array(
				'status'      => 429,
				'retry_after' => $result['retry_after'],
			)
		);
	}

	/**
	 * IP address of the client: `REMOTE_ADDR`, filterable with `trmz_client_ip` (trusted proxies). Invalid → "unknown".
	 *
	 * @param WP_REST_Request|null $request REST request; null outside the REST API.
	 * @phpstan-param WP_REST_Request<array<string, mixed>>|null $request
	 */
	public static function client_ip( ?WP_REST_Request $request = null ): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP used for request limits. By default only `REMOTE_ADDR` is trusted; a site behind
		 * a reverse proxy may return the address from a header the proxy sets (and clients cannot spoof).
		 *
		 * @param string               $ip      IP address from REMOTE_ADDR.
		 * @param WP_REST_Request|null $request REST request (null for the cancellation page).
		 */
		$ip = apply_filters( 'trmz_client_ip', $remote, $request );

		return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}

	/**
	 * `rest_request_after_callbacks` filter: turns the 429 error into a response with a `Retry-After` header
	 * (permission callbacks cannot set headers).
	 *
	 * @param mixed           $response Response or error.
	 * @param mixed           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return mixed
	 */
	public static function add_retry_after( $response, $handler, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter signature.
		if ( ! $response instanceof WP_Error || self::ERROR_CODE !== $response->get_error_code() ) {
			return $response;
		}
		$data  = $response->get_error_data();
		$retry = is_array( $data ) ? (int) ( $data['retry_after'] ?? 0 ) : 0;

		$converted = rest_convert_error_to_response( $response );
		if ( $converted instanceof WP_HTTP_Response && $retry > 0 ) {
			$converted->header( 'Retry-After', (string) $retry );
		}
		return $converted;
	}
}
