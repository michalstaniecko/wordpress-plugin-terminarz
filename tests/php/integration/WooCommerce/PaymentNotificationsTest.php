<?php
/**
 * Integration tests: e-mails of paid bookings.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\WooCommerce;

use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Infrastructure\HoldExpiryScheduler;
use Terminarz\Infrastructure\Settings;
use Terminarz\Integrations\WooCommerce\OrderLink;
use Terminarz\Notifications\Templates;
use Terminarz\Tests\Integration\Support\CapturedMails;

/**
 * The plugin's e-mails are told apart from WooCommerce's own by their subjects.
 *
 * @group woocommerce
 * @covers \Terminarz\Notifications\BookingNotifier
 */
final class PaymentNotificationsTest extends WooCommerceTestCase {

	use CapturedMails;

	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'Europe/Warsaw' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		delete_option( Templates::OPTION );
		$this->use_payment_settings(
			array(
				'payment_mode'       => Settings::PAYMENT_FULL,
				'notification_email' => 'office@example.org',
			)
		);
		reset_phpmailer_instance();
	}

	/**
	 * Subjects of the plugin's e-mails (prefix "[Test Blog]" + our wording).
	 *
	 * @return string[]
	 */
	private static function ours(): array {
		return array_values(
			array_map(
				static fn( array $mail ): string => $mail['to'] . ': ' . $mail['subject'],
				array_filter(
					self::mails(),
					static fn( array $mail ): bool => 1 === preg_match( '/booking|Booking/', $mail['subject'] ) && ! str_contains( $mail['subject'], 'order' )
				)
			)
		);
	}

	public function test_nothing_is_sent_while_awaiting_payment_and_confirmation_follows_the_payment(): void {
		$booking = $this->book_paid();
		$this->assertSame( BookingStatus::PendingPayment, $booking->status );
		$this->assertSame( array(), self::ours() );

		self::order( $booking->order_id )->payment_complete( 'txn-1' );

		$this->assertSame(
			array(
				'jan@example.org: [Test Blog] Your booking is confirmed: 2030-01-07 10:00',
				'office@example.org: [Test Blog] New booking: Service, 2030-01-07 10:00',
			),
			self::ours()
		);

		// Payment notification repeated by the gateway: nothing new.
		self::order( $booking->order_id )->update_status( 'completed' );
		$this->assertCount( 2, self::ours() );
	}

	public function test_expired_hold_sends_nothing(): void {
		$this->book_paid();
		$this->clock->set( '2030-01-07 06:20' );

		( new HoldExpiryScheduler() )->run();

		$this->assertSame( array(), self::ours() );
	}

	public function test_late_payment_confirms_the_new_booking(): void {
		$booking = $this->book_paid();
		$this->clock->set( '2030-01-07 06:20' );
		( new HoldExpiryScheduler() )->run();

		self::order( $booking->order_id )->payment_complete( 'txn-late' );

		$new = OrderLink::booking( self::order( $booking->order_id ), $this->container );
		$this->assertNotNull( $new );
		$this->assertNotSame( $booking->id, $new->id );
		$this->assertCount( 2, self::ours() );
		$this->assertStringContainsString( (string) $new->public_id, self::mails_with_subject( 'Your booking is confirmed' )[0]['body'] );
	}
}
