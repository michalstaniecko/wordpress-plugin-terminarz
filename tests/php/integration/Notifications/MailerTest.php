<?php
/**
 * Integration tests: e-mail templates, rendering and sending.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Notifications;

use DateTimeZone;
use Terminarz\Application\AvailabilitySettings;
use Terminarz\Application\FixedClock;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Infrastructure\Services;
use Terminarz\Infrastructure\Settings;
use Terminarz\Notifications\MessageType;
use Terminarz\Notifications\Placeholders;
use Terminarz\Notifications\Renderer;
use Terminarz\Notifications\Template;
use Terminarz\Notifications\Templates;
use Terminarz\Tests\Integration\Support\BookingFixtures;
use Terminarz\Tests\Integration\Support\CapturedMails;
use WP_UnitTestCase;

/**
 * E-mails are captured by the MockPHPMailer of the WordPress test suite.
 *
 * @covers \Terminarz\Notifications\Mailer
 * @covers \Terminarz\Notifications\Templates
 * @covers \Terminarz\Notifications\Renderer
 * @covers \Terminarz\Notifications\BookingPlaceholders
 * @covers \Terminarz\Notifications\Placeholders
 * @covers \Terminarz\Notifications\MessageType
 */
final class MailerTest extends WP_UnitTestCase {

	use BookingFixtures;
	use CapturedMails;

	/**
	 * Composition root.
	 *
	 * @var Services
	 */
	private Services $container;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		update_option( 'timezone_string', 'Europe/Warsaw' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );
		update_option( 'blogname', 'Studio & Co' );
		update_option( Settings::OPTION, array( 'notification_email' => 'office@example.org' ) );
		delete_option( Templates::OPTION );
		$this->container = new Services( $wpdb, new FixedClock( '2030-01-07 06:00' ), new AvailabilitySettings( new DateTimeZone( 'Europe/Warsaw' ) ) );
		Services::set_instance( $this->container );
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		Services::reset();
		parent::tear_down();
	}

	/**
	 * Stored booking of a "Haircut" with "Anna" on 2030-01-07 09:00 UTC (10:00 Warsaw).
	 *
	 * @param Customer|null $customer Customer.
	 */
	private function stored_booking( ?Customer $customer = null ): Booking {
		$resource = $this->make_resource( 'Anna' );
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$from     = self::utc( '2030-01-07 09:00' );
		$booking  = new Booking(
			resource_id: $resource,
			service_id: $service,
			range: new TimeRange( $from, $from->modify( '+60 minutes' ) ),
			status: BookingStatus::Confirmed,
			customer: $customer ?? new Customer( 'Jan Kowalski', 'jan@example.org', '+48 600 000 000' )
		);
		return $this->container->bookings()->create( $booking, self::utc( '2030-01-07 06:00' ) );
	}

	public function test_every_message_has_a_translatable_default_with_known_placeholders(): void {
		$known = Placeholders::names();
		foreach ( MessageType::cases() as $type ) {
			$template = ( new Templates() )->get( $type );
			$this->assertTrue( $template->enabled, $type->value );
			$this->assertNotSame( '', $template->subject );
			$this->assertSame( Templates::sanitize_body( $template->body ), $template->body, 'Defaults pass wp_kses_post.' );
			preg_match_all( '/\{([a-z_]+)\}/', $template->subject . $template->body, $matches );
			$this->assertSame( array(), array_diff( $matches[1], $known ), $type->value );
			$this->assertNotSame( '', $type->label() );
			$this->assertNotSame( '', $type->description() );
		}
		$this->assertTrue( MessageType::AdminNew->is_for_admin() );
		$this->assertFalse( MessageType::CustomerConfirmed->is_for_admin() );
	}

	public function test_saving_sanitizes_and_keeps_defaults_following_translations(): void {
		$templates = new Templates();
		$default   = Templates::default_template( MessageType::CustomerConfirmed );

		$saved = $templates->save( MessageType::CustomerConfirmed, new Template( false, "Hi\n{customer_name}<b>", '<p onclick="x()">Hello</p><script>alert(1)</script>' ) );

		$this->assertFalse( $saved->enabled );
		$this->assertSame( 'Hi {customer_name}', $saved->subject );
		$this->assertSame( '<p>Hello</p>alert(1)', $saved->body );
		$this->assertTrue( $templates->is_customized( MessageType::CustomerConfirmed ) );

		$templates->reset( MessageType::CustomerConfirmed );
		$reset = $templates->get( MessageType::CustomerConfirmed );
		$this->assertSame( $default->subject, $reset->subject );
		$this->assertSame( $default->body, $reset->body );
		$this->assertFalse( $reset->enabled, 'Reset keeps the on/off switch.' );
		$this->assertFalse( $templates->is_customized( MessageType::CustomerConfirmed ) );

		$templates->save( MessageType::CustomerConfirmed, new Template( true, $default->subject, $default->body ) );
		$this->assertFalse( $templates->is_customized( MessageType::CustomerConfirmed ), 'Unchanged text is stored as the default.' );
		$stored = get_option( Templates::OPTION );
		$this->assertNull( $stored['customer_confirmed']['subject'] );

		$templates->set_enabled( MessageType::AdminNew, false );
		$this->assertFalse( $templates->get( MessageType::AdminNew )->enabled );
	}

	public function test_invalid_stored_option_falls_back_to_defaults(): void {
		update_option(
			Templates::OPTION,
			array(
				'unknown'            => array( 'subject' => 'x' ),
				'customer_confirmed' => 'broken',
			)
		);

		$this->assertEquals( Templates::default_template( MessageType::CustomerConfirmed ), ( new Templates() )->get( MessageType::CustomerConfirmed ) );
	}

	public function test_renderer_escapes_values_and_strips_header_injection(): void {
		$template = new Template( true, 'Hello {customer_name} {missing}', '<p>{customer_name}</p><p><a href="{cancel_url}">x</a></p><p>{customer_note}</p>' );
		$values   = array(
			'customer_name' => "<script>alert(1)</script>\r\nBcc: evil@example.org",
			'cancel_url'    => 'javascript:alert(1)',
			'customer_note' => "line 1\nline 2",
			'site_name'     => 'Studio & Co',
		);

		$message = ( new Renderer() )->render( $template, $values );

		$this->assertStringNotContainsString( "\n", $message['subject'] );
		$this->assertStringNotContainsString( "\r", $message['subject'] );
		$this->assertStringContainsString( '{missing}', $message['subject'], 'Unknown placeholders stay visible.' );
		$this->assertStringNotContainsString( '<script>', $message['html'] );
		$this->assertStringContainsString( '&lt;script&gt;', $message['html'] );
		$this->assertStringNotContainsString( 'javascript:', $message['html'] );
		$this->assertStringContainsString( 'line 1<br>', $message['html'] );
		$this->assertStringContainsString( 'Studio &amp; Co', $message['html'] );
		$this->assertStringStartsWith( '<!DOCTYPE html>', $message['html'] );
	}

	public function test_customer_message_is_sent_as_html_with_booking_data_in_the_site_time_zone(): void {
		$booking = $this->stored_booking( new Customer( 'Ola <b>Nowak</b>', 'ola@example.org' ) );

		$this->assertTrue( $this->container->mailer()->send_for_booking( MessageType::CustomerConfirmed, $booking ) );

		$sent = self::mails();
		$this->assertCount( 1, $sent );
		$this->assertSame( 'ola@example.org', $sent[0]['to'] );
		$this->assertSame( '[Studio & Co] Your booking is confirmed: 2030-01-07 10:00', $sent[0]['subject'] );
		$this->assertStringContainsString( 'Content-Type: text/html; charset=UTF-8', $sent[0]['header'] );
		$this->assertStringContainsString( 'Reply-To: office@example.org', $sent[0]['header'] );
		$body = $sent[0]['body'];
		$this->assertStringContainsString( 'Ola &lt;b&gt;Nowak&lt;/b&gt;', $body );
		$this->assertStringContainsString( '10:00–11:00', $body );
		$this->assertStringContainsString( 'Anna', $body );
		$this->assertStringContainsString( (string) $booking->public_id, $body );
	}

	public function test_admin_message_goes_to_the_notification_address_with_reply_to_the_customer(): void {
		$booking = $this->stored_booking();

		$this->assertTrue( $this->container->mailer()->send_for_booking( MessageType::AdminNew, $booking ) );

		$sent = self::mails();
		$this->assertSame( 'office@example.org', $sent[0]['to'] );
		$this->assertStringContainsString( 'Reply-To: jan@example.org', $sent[0]['header'] );
		$this->assertStringContainsString( 'page=trmz-bookings', $sent[0]['body'] );
		$this->assertStringContainsString( '+48 600 000 000', $sent[0]['body'] );
	}

	public function test_disabled_or_filtered_messages_are_not_sent(): void {
		$booking = $this->stored_booking();
		( new Templates() )->set_enabled( MessageType::CustomerConfirmed, false );

		$this->assertFalse( $this->container->mailer()->send_for_booking( MessageType::CustomerConfirmed, $booking ) );
		$this->assertTrue( $this->container->mailer()->send( MessageType::CustomerConfirmed, 'test@example.org', Placeholders::sample_values(), '', null, true ), 'Forced (test e-mail).' );

		add_filter( 'trmz_email', '__return_false' );
		$this->assertFalse( $this->container->mailer()->send_for_booking( MessageType::AdminNew, $booking ) );
		remove_filter( 'trmz_email', '__return_false' );

		$this->assertFalse( $this->container->mailer()->send( MessageType::AdminNew, 'not-an-email', array() ) );
		$this->assertCount( 1, self::mails() );
	}

	public function test_placeholder_values_can_be_filtered(): void {
		$booking = $this->stored_booking();
		add_filter(
			'trmz_email_placeholders',
			static function ( array $values ): array {
				$values['service_name'] = 'Filtered service';
				return $values;
			}
		);

		$this->container->mailer()->send_for_booking( MessageType::CustomerReminder, $booking );

		$this->assertStringContainsString( 'Filtered service', self::mails()[0]['subject'] );
	}
}
