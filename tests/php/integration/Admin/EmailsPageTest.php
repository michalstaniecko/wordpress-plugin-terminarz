<?php
/**
 * Integration tests for the e-mail templates screen.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Admin;

use Terminarz\Admin\EmailsPage;
use Terminarz\Admin\Notices;
use Terminarz\Notifications\MessageType;
use Terminarz\Notifications\Templates;
use Terminarz\Tests\Integration\Support\CapturedMails;
use WPDieException;

/**
 * @covers \Terminarz\Admin\EmailsPage
 */
final class EmailsPageTest extends AdminTestCase {

	use CapturedMails;

	/**
	 * Screen.
	 *
	 * @var EmailsPage
	 */
	private EmailsPage $page;

	public function set_up(): void {
		parent::set_up();
		delete_option( Templates::OPTION );
		reset_phpmailer_instance();
		$this->page = new EmailsPage();
	}

	public function test_lists_every_message(): void {
		$html = $this->render( $this->page );

		foreach ( MessageType::cases() as $type ) {
			$this->assertStringContainsString( esc_html( $type->label() ), $html );
			$this->assertStringContainsString( 'type=' . $type->value, $html );
		}
	}

	public function test_edit_form_shows_placeholders_and_a_safe_preview(): void {
		( new Templates() )->save( MessageType::CustomerConfirmed, new \Terminarz\Notifications\Template( true, 'Hi {customer_name}', '<p>Dear {customer_name}</p>' ) );

		$html = $this->render(
			$this->page,
			array(
				'view' => 'edit',
				'type' => 'customer_confirmed',
			)
		);

		$this->assertStringContainsString( 'name="action" value="trmz_save_email"', $html );
		$this->assertStringContainsString( '{cancel_url}', $html );
		$this->assertStringContainsString( 'trmz-email-preview', $html );
		$this->assertStringContainsString( 'Dear Jane Doe', $html );
		$this->assertStringContainsString( 'trmz_test_email', $html );
		$this->assertStringContainsString( 'trmz_reset_email', $html );
	}

	public function test_saves_a_template_with_kses(): void {
		$url = $this->run_action(
			$this->page,
			'save_email',
			array(
				'type'    => 'admin_new',
				'subject' => 'New: {service_name}',
				'body'    => '<p>Booked {service_name}</p><script>alert(1)</script>',
			)
		);

		$template = ( new Templates() )->get( MessageType::AdminNew );
		$this->assertFalse( $template->enabled, 'Unchecked checkbox disables the e-mail.' );
		$this->assertSame( 'New: {service_name}', $template->subject );
		$this->assertSame( '<p>Booked {service_name}</p>alert(1)', $template->body );
		$this->assertStringContainsString( 'type=admin_new', $url );
		$this->assertSame( array( 'E-mail saved.' ), $this->notices( Notices::SUCCESS ) );
	}

	public function test_rejects_an_empty_subject_or_body(): void {
		$this->run_action(
			$this->page,
			'save_email',
			array(
				'type'    => 'admin_new',
				'enabled' => '1',
				'subject' => '  ',
				'body'    => '<script></script>',
			)
		);

		$this->assertCount( 2, $this->notices( Notices::ERROR ) );
		$this->assertFalse( ( new Templates() )->is_customized( MessageType::AdminNew ) );
	}

	public function test_rejects_unknown_messages(): void {
		$this->run_action(
			$this->page,
			'save_email',
			array(
				'type'    => 'nope',
				'subject' => 'x',
				'body'    => 'y',
			)
		);

		$this->assertSame( array( 'Unknown e-mail.' ), $this->notices( Notices::ERROR ) );
	}

	public function test_restores_the_default_text(): void {
		$this->run_action(
			$this->page,
			'save_email',
			array(
				'type'    => 'customer_pending',
				'enabled' => '1',
				'subject' => 'Custom',
				'body'    => '<p>Custom</p>',
			)
		);

		$this->run_action( $this->page, 'reset_email', array( 'type' => 'customer_pending' ) );

		$this->assertEquals( Templates::default_template( MessageType::CustomerPending ), ( new Templates() )->get( MessageType::CustomerPending ) );
	}

	public function test_sends_a_test_e_mail_to_the_current_user_even_when_disabled(): void {
		( new Templates() )->set_enabled( MessageType::CustomerReminder, false );
		$email = wp_get_current_user()->user_email;

		$this->run_action( $this->page, 'test_email', array( 'type' => 'customer_reminder' ) );

		$sent = self::mails();
		$this->assertCount( 1, $sent );
		$this->assertSame( $email, $sent[0]['to'] );
		$this->assertStringContainsString( 'Jane Doe', $sent[0]['body'] );
		$this->assertCount( 1, $this->notices( Notices::SUCCESS ) );
	}

	public function test_requires_the_capability_and_a_valid_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		try {
			$this->run_action( $this->page, 'reset_email', array( 'type' => 'customer_pending' ) );
			$this->fail( 'Expected wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'not allowed', $e->getMessage() );
		}

		wp_set_current_user( $this->manager );
		$this->expectException( WPDieException::class );
		$this->run_action( $this->page, 'reset_email', array( 'type' => 'customer_pending' ), 'invalid' );
	}
}
