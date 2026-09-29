<?php
/**
 * Access to e-mails captured by the MockPHPMailer of the WordPress test suite.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Support;

/**
 * Decodes captured e-mails (PHPMailer switches to quoted-printable for long lines).
 */
trait CapturedMails {

	/**
	 * Captured e-mails: recipient, subject, decoded body and raw headers.
	 *
	 * @return array<int, array{to: string, subject: string, body: string, header: string}>
	 */
	protected static function mails(): array {
		$mails = array();
		foreach ( tests_retrieve_phpmailer_instance()->mock_sent as $mail ) {
			$header  = (string) $mail['header'];
			$body    = (string) $mail['body'];
			$mails[] = array(
				'to'      => (string) ( $mail['to'][0][0] ?? '' ),
				'subject' => (string) $mail['subject'],
				'body'    => str_contains( $header, 'quoted-printable' ) ? quoted_printable_decode( $body ) : $body,
				'header'  => $header,
			);
		}
		return $mails;
	}

	/**
	 * Captured e-mails whose subject contains a text.
	 *
	 * @param string $needle Text.
	 * @return array<int, array{to: string, subject: string, body: string, header: string}>
	 */
	protected static function mails_with_subject( string $needle ): array {
		return array_values( array_filter( self::mails(), static fn( array $mail ): bool => str_contains( $mail['subject'], $needle ) ) );
	}
}
