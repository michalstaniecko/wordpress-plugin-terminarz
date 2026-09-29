<?php
/**
 * E-mail templates screen.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use Terminarz\Notifications\MessageType;
use Terminarz\Notifications\Placeholders;
use Terminarz\Notifications\Renderer;
use Terminarz\Notifications\Template;
use Terminarz\Notifications\Templates;

/**
 * "Terminarz → E-mails" (`admin.php?page=trmz-emails`): list of messages, edit form (on/off, subject, HTML body with
 * placeholders), preview with example data, restoring the default text and a test e-mail to the current user.
 */
final class EmailsPage extends Screen {

	public const SLUG = 'trmz-emails';

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function menu_title(): string {
		return __( 'E-mails', 'terminarz' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function page_title(): string {
		return __( 'E-mails', 'terminarz' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function position(): int {
		return 80;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function actions(): array {
		return array(
			'save_email'  => 'save',
			'reset_email' => 'reset',
			'test_email'  => 'test',
		);
	}

	/**
	 * Handler: save a template.
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function save( array $request ): string {
		$type = $this->type_from( $request );
		if ( null === $type ) {
			Notices::error( __( 'Unknown e-mail.', 'terminarz' ) );
			return $this->url();
		}

		$subject = Templates::sanitize_subject( Input::text( $request, 'subject' ) );
		$raw     = $request['body'] ?? '';
		$body    = Templates::sanitize_body( is_string( $raw ) ? $raw : '' );
		$enabled = Input::flag( $request, 'enabled' );
		$back    = $this->edit_url( $type );

		$errors = array();
		if ( '' === $subject ) {
			$errors[] = __( 'Enter a subject.', 'terminarz' );
		}
		if ( '' === $body ) {
			$errors[] = __( 'Enter the message text.', 'terminarz' );
		}
		if ( array() !== $errors ) {
			array_map( array( Notices::class, 'error' ), $errors );
			Notices::keep_input(
				array(
					'enabled' => $enabled,
					'subject' => $subject,
					'body'    => $body,
				)
			);
			return $back;
		}

		$this->templates()->save( $type, new Template( $enabled, $subject, $body ) );
		Notices::success( __( 'E-mail saved.', 'terminarz' ) );
		return $back;
	}

	/**
	 * Handler: restore the default subject and text.
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function reset( array $request ): string {
		$type = $this->type_from( $request );
		if ( null === $type ) {
			Notices::error( __( 'Unknown e-mail.', 'terminarz' ) );
			return $this->url();
		}

		$this->templates()->reset( $type );
		Notices::success( __( 'The default subject and text were restored.', 'terminarz' ) );
		return $this->edit_url( $type );
	}

	/**
	 * Handler: send the saved template with example data to the current user.
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function test( array $request ): string {
		$type = $this->type_from( $request );
		if ( null === $type ) {
			Notices::error( __( 'Unknown e-mail.', 'terminarz' ) );
			return $this->url();
		}

		$user = wp_get_current_user();
		$sent = $this->services()->mailer()->send( $type, (string) $user->user_email, Placeholders::sample_values(), '', null, true );
		if ( $sent ) {
			Notices::success(
				sprintf(
					/* translators: %s: e-mail address. */
					__( 'Test e-mail sent to %s.', 'terminarz' ),
					(string) $user->user_email
				)
			);
		} else {
			Notices::error( __( 'The test e-mail could not be sent. Check the e-mail configuration of the site.', 'terminarz' ) );
		}
		return $this->edit_url( $type );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function render_view(): void {
		if ( 'edit' === $this->current_view() ) {
			$this->render_form();
			return;
		}
		$this->render_list();
	}

	/**
	 * URL of the edit form of a message.
	 *
	 * @param MessageType $type Message.
	 */
	public function edit_url( MessageType $type ): string {
		return $this->url(
			array(
				'view' => 'edit',
				'type' => $type->value,
			)
		);
	}

	/**
	 * List of messages.
	 */
	private function render_list(): void {
		$this->heading( __( 'E-mails', 'terminarz' ) );
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: e-mail address. */
					__( 'E-mails for the business are sent to %s (Settings → Notification e-mail).', 'terminarz' ),
					$this->services()->settings()->notification_email()
				)
			)
		);

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'E-mail', 'terminarz' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Subject', 'terminarz' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Status', 'terminarz' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Text', 'terminarz' ) );
		echo '</tr></thead><tbody>';

		foreach ( MessageType::cases() as $type ) {
			$template = $this->templates()->get( $type );
			echo '<tr>';
			printf(
				'<td><strong><a href="%1$s">%2$s</a></strong><p class="description">%3$s</p></td>',
				esc_url( $this->edit_url( $type ) ),
				esc_html( $type->label() ),
				esc_html( $type->description() )
			);
			printf( '<td>%s</td>', esc_html( $template->subject ) );
			printf( '<td>%s</td>', esc_html( $template->enabled ? __( 'Enabled', 'terminarz' ) : __( 'Disabled', 'terminarz' ) ) );
			printf( '<td>%s</td>', esc_html( $this->templates()->is_customized( $type ) ? __( 'Customized', 'terminarz' ) : __( 'Default', 'terminarz' ) ) );
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Edit form, placeholders, preview and extra actions.
	 */
	private function render_form(): void {
		$type = MessageType::tryFrom( $this->query_text( 'type' ) );
		if ( null === $type ) {
			$this->heading( __( 'Edit e-mail', 'terminarz' ) );
			echo '<p>' . esc_html__( 'Unknown e-mail.', 'terminarz' ) . '</p>';
			return;
		}

		$saved  = $this->templates()->get( $type );
		$old    = Notices::take_input() ?? array();
		$values = array(
			'enabled' => (bool) ( $old['enabled'] ?? $saved->enabled ),
			'subject' => (string) ( $old['subject'] ?? $saved->subject ),
			'body'    => (string) ( $old['body'] ?? $saved->body ),
		);

		$this->heading( $type->label() );
		echo '<p><a href="' . esc_url( $this->url() ) . '">' . esc_html__( '&larr; Back to e-mails', 'terminarz' ) . '</a></p>';
		echo '<p>' . esc_html( $type->description() ) . '</p>';

		$this->form_open( 'save_email', 'trmz-email-form' );
		printf( '<input type="hidden" name="type" value="%s" />', esc_attr( $type->value ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" id="trmz-email-enabled" name="enabled" value="1" %2$s /> %3$s</label></td></tr>',
			esc_html__( 'Sending', 'terminarz' ),
			checked( $values['enabled'], true, false ),
			esc_html__( 'Send this e-mail', 'terminarz' )
		);
		printf(
			'<tr><th scope="row"><label for="trmz-email-subject">%1$s</label></th><td><input type="text" class="large-text" id="trmz-email-subject" name="subject" value="%2$s" maxlength="%3$d" required /></td></tr>',
			esc_html__( 'Subject', 'terminarz' ),
			esc_attr( $values['subject'] ),
			(int) Templates::SUBJECT_MAX
		);
		printf(
			'<tr><th scope="row"><label for="trmz-email-body">%1$s</label></th><td><textarea class="large-text code" rows="14" id="trmz-email-body" name="body" aria-describedby="trmz-email-body-help" required>%2$s</textarea><p class="description" id="trmz-email-body-help">%3$s</p></td></tr>',
			esc_html__( 'Message', 'terminarz' ),
			esc_textarea( $values['body'] ),
			esc_html__( 'HTML allowed in posts (paragraphs, links, bold…). Placeholders in braces are replaced with booking data.', 'terminarz' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save e-mail', 'terminarz' ) );
		echo '</form>';

		$this->render_placeholders();
		$this->render_preview( $saved );

		echo '<h2>' . esc_html__( 'More actions', 'terminarz' ) . '</h2>';
		echo '<div style="display:flex;gap:8px;flex-wrap:wrap;">';
		$this->form_open( 'test_email', 'trmz-email-test' );
		printf( '<input type="hidden" name="type" value="%s" />', esc_attr( $type->value ) );
		submit_button( __( 'Send a test e-mail to me', 'terminarz' ), 'secondary', 'submit', false );
		echo '</form>';
		$this->form_open( 'reset_email', 'trmz-email-reset' );
		printf( '<input type="hidden" name="type" value="%s" />', esc_attr( $type->value ) );
		submit_button( __( 'Restore default text', 'terminarz' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * Placeholder reference.
	 */
	private function render_placeholders(): void {
		echo '<h2>' . esc_html__( 'Placeholders', 'terminarz' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:800px"><tbody>';
		foreach ( Placeholders::descriptions() as $name => $description ) {
			printf( '<tr><td><code>{%1$s}</code></td><td>%2$s</td></tr>', esc_html( $name ), esc_html( $description ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Saved template rendered with example data.
	 *
	 * @param Template $template Saved template.
	 */
	private function render_preview( Template $template ): void {
		$values = Placeholders::sample_values();
		echo '<h2>' . esc_html__( 'Preview (saved version, example data)', 'terminarz' ) . '</h2>';
		printf( '<p><strong>%1$s</strong> %2$s</p>', esc_html__( 'Subject:', 'terminarz' ), esc_html( Renderer::subject( $template->subject, $values ) ) );
		printf(
			'<div id="trmz-email-preview" style="max-width:600px;background:#fff;border:1px solid #c3c4c7;padding:16px 24px;">%s</div>',
			wp_kses_post( Renderer::body( $template->body, $values ) )
		);
	}

	/**
	 * Message type from the request.
	 *
	 * @param array<string, mixed> $request Request.
	 */
	private function type_from( array $request ): ?MessageType {
		return MessageType::tryFrom( Input::key( $request, 'type' ) );
	}

	/**
	 * Templates.
	 */
	private function templates(): Templates {
		return $this->services()->mailer()->templates();
	}
}
