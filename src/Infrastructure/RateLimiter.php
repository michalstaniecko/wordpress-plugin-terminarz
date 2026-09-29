<?php
/**
 * Fixed-window request limiter.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure;

use Terminarz\Application\Clock;

/**
 * Counts hits per (bucket, client) in fixed time windows, stored in transients (the object cache when one is installed).
 *
 * The client identifier (e.g. an IP address) is never stored: the key is an HMAC of bucket + client with a WordPress salt.
 * Increments are not atomic (read → write), so under heavy concurrency a few extra requests may slip through — acceptable
 * for anti-abuse limits; it never blocks legitimate users more than configured.
 */
final class RateLimiter {

	private const PREFIX = 'trmz_rl_';

	/**
	 * Constructor.
	 *
	 * @param Clock $clock Clock.
	 */
	public function __construct( private readonly Clock $clock ) {
	}

	/**
	 * Registers a hit and tells whether it is within the limit.
	 *
	 * @param string $bucket         Kind of action (e.g. "booking_create").
	 * @param string $client         Client identifier (e.g. IP address).
	 * @param int    $limit          Allowed hits per window (<= 0 = unlimited).
	 * @param int    $window_seconds Window length in seconds.
	 * @return array{allowed: bool, remaining: int, retry_after: int}
	 */
	public function hit( string $bucket, string $client, int $limit, int $window_seconds ): array {
		if ( $limit <= 0 || $window_seconds <= 0 ) {
			return array(
				'allowed'     => true,
				'remaining'   => PHP_INT_MAX,
				'retry_after' => 0,
			);
		}

		$now   = $this->clock->now()->getTimestamp();
		$key   = self::key( $bucket, $client );
		$state = get_transient( $key );

		if ( ! is_array( $state ) || ! isset( $state['count'], $state['reset'] ) || (int) $state['reset'] <= $now ) {
			$state = array(
				'count' => 0,
				'reset' => $now + $window_seconds,
			);
		}

		$reset = (int) $state['reset'];
		if ( (int) $state['count'] >= $limit ) {
			return array(
				'allowed'     => false,
				'remaining'   => 0,
				'retry_after' => max( 1, $reset - $now ),
			);
		}

		$count = (int) $state['count'] + 1;
		set_transient(
			$key,
			array(
				'count' => $count,
				'reset' => $reset,
			),
			max( 1, $reset - $now )
		);

		return array(
			'allowed'     => true,
			'remaining'   => $limit - $count,
			'retry_after' => 0,
		);
	}

	/**
	 * Forgets the hits of a client (e.g. tests, or after a successful verification step).
	 *
	 * @param string $bucket Bucket.
	 * @param string $client Client identifier.
	 */
	public function reset( string $bucket, string $client ): void {
		delete_transient( self::key( $bucket, $client ) );
	}

	/**
	 * Transient name: prefix + truncated HMAC-SHA256 (fits the 172-character transient name limit, hides the IP).
	 *
	 * @param string $bucket Bucket.
	 * @param string $client Client identifier.
	 */
	public static function key( string $bucket, string $client ): string {
		return self::PREFIX . substr( hash_hmac( 'sha256', $bucket . '|' . $client, wp_salt( 'nonce' ) ), 0, 40 );
	}
}
