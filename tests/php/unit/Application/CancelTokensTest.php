<?php
/**
 * Unit tests for cancellation tokens.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Unit\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Terminarz\Application\CancelTokens;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Domain\Model\TimeRange;

/**
 * @covers \Terminarz\Application\CancelTokens
 */
final class CancelTokensTest extends TestCase {

	private static function booking( ?string $secret = 'secret-1', ?string $public_id = '0123456789abcdef0123456789abcdef' ): Booking {
		$start = new DateTimeImmutable( '2030-01-01 10:00', new DateTimeZone( 'UTC' ) );
		return new Booking(
			resource_id: 1,
			service_id: 1,
			range: new TimeRange( $start, $start->modify( '+1 hour' ) ),
			status: BookingStatus::Confirmed,
			customer: new Customer( 'A', 'a@example.com' ),
			cancel_secret: $secret,
			public_id: $public_id
		);
	}

	public function test_token_is_a_keyed_hmac_of_public_id_and_secret(): void {
		$tokens = new CancelTokens( 'key' );
		$token  = $tokens->token( self::booking() );

		$this->assertSame( hash_hmac( 'sha256', '0123456789abcdef0123456789abcdef|secret-1', 'key' ), $token );
		$this->assertSame( $token, $tokens->token( self::booking() ), 'Deterministic: the link can be rebuilt.' );
		$this->assertTrue( $tokens->verify( self::booking(), $token ) );
	}

	public function test_token_depends_on_key_secret_and_public_id(): void {
		$token = ( new CancelTokens( 'key' ) )->token( self::booking() );

		$this->assertFalse( ( new CancelTokens( 'other key' ) )->verify( self::booking(), $token ), 'The database alone is not enough.' );
		$this->assertFalse( ( new CancelTokens( 'key' ) )->verify( self::booking( 'secret-2' ), $token ), 'A new secret revokes the link.' );
		$this->assertFalse( ( new CancelTokens( 'key' ) )->verify( self::booking( 'secret-1', str_repeat( 'f', 32 ) ), $token ) );
	}

	public function test_rejects_malformed_tokens_and_bookings_without_secret(): void {
		$tokens = new CancelTokens( 'key' );
		$token  = $tokens->token( self::booking() );

		$this->assertFalse( $tokens->verify( self::booking(), '' ) );
		$this->assertFalse( $tokens->verify( self::booking(), strtoupper( $token ) ) );
		$this->assertFalse( $tokens->verify( self::booking(), $token . 'a' ) );
		$this->assertSame( '', $tokens->token( self::booking( null ) ) );
		$this->assertFalse( $tokens->verify( self::booking( null ), $token ) );
	}

	public function test_new_secrets_are_random_hex(): void {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', CancelTokens::new_secret() );
		$this->assertNotSame( CancelTokens::new_secret(), CancelTokens::new_secret() );
	}

	public function test_empty_key_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new CancelTokens( '' );
	}
}
