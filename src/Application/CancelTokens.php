<?php
/**
 * Customer cancellation tokens.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Model\Booking;

/**
 * Derives the token of a booking's cancellation link: `HMAC-SHA256(public_id . "|" . secret, key)`.
 *
 * - `secret` is random per booking (`Booking::$cancel_secret`, 64 hex characters, stored in the database);
 * - `key` is a site secret outside the database (WordPress: `wp_salt( 'auth' )` from wp-config.php).
 *
 * The link can be rebuilt for every e-mail (confirmation after payment, reminder), a leaked database alone is not
 * enough to forge it, and changing the secret (or the salt) revokes it. Verification recomputes the token and compares
 * with `hash_equals()` (docs/ARCHITECTURE.md, ADR-042).
 */
final class CancelTokens {

	/**
	 * Constructor.
	 *
	 * @param string $key Site secret (non-empty).
	 * @throws \InvalidArgumentException When the key is empty.
	 */
	public function __construct( private readonly string $key ) {
		if ( '' === $key ) {
			throw new \InvalidArgumentException( 'The cancellation token key must not be empty.' );
		}
	}

	/**
	 * New random per-booking secret.
	 */
	public static function new_secret(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Token of a booking ('' when the booking has no public ID or secret).
	 *
	 * @param Booking $booking Booking.
	 */
	public function token( Booking $booking ): string {
		if ( null === $booking->public_id || null === $booking->cancel_secret ) {
			return '';
		}
		return hash_hmac( 'sha256', $booking->public_id . '|' . $booking->cancel_secret, $this->key );
	}

	/**
	 * Whether a token matches the booking (constant-time comparison).
	 *
	 * @param Booking $booking Booking.
	 * @param string  $token   Token from the link.
	 */
	public function verify( Booking $booking, string $token ): bool {
		$expected = $this->token( $booking );
		return '' !== $expected && 1 === preg_match( '/^[0-9a-f]{64}$/', $token ) && hash_equals( $expected, $token );
	}
}
