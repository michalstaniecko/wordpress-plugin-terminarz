<?php
/**
 * Turns a template and placeholder values into an e-mail.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Notifications;

defined( 'ABSPATH' ) || exit; // No direct access.

/**
 * Replaces `{placeholders}` and wraps the body in a simple HTML layout.
 *
 * - Subject: raw values, then reduced to single-line plain text (no header injection).
 * - Body: values escaped with `esc_html()` (URL placeholders with `esc_url()`), the result filtered by `wp_kses_post()`
 *   again, so neither the template nor a value can inject scripts.
 */
final class Renderer {

	/**
	 * Renders a message.
	 *
	 * @param Template              $template Template.
	 * @param array<string, string> $values   Placeholder values keyed by name (without braces), plain text.
	 * @return array{subject: string, html: string}
	 */
	public function render( Template $template, array $values ): array {
		$subject = self::subject( $template->subject, $values );
		$content = self::body( $template->body, $values );

		return array(
			'subject' => $subject,
			'html'    => self::layout( $content, $subject, $values['site_name'] ?? '' ),
		);
	}

	/**
	 * Subject with placeholders replaced (single-line plain text).
	 *
	 * @param string                $subject Subject template.
	 * @param array<string, string> $values  Values.
	 */
	public static function subject( string $subject, array $values ): string {
		$replaced = strtr( $subject, self::pairs( $values, static fn( string $value ): string => $value ) );
		$replaced = wp_strip_all_tags( $replaced, true );
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $replaced ) );
	}

	/**
	 * Body HTML with placeholders replaced by escaped values.
	 *
	 * @param string                $body   Body template.
	 * @param array<string, string> $values Values.
	 */
	public static function body( string $body, array $values ): string {
		$pairs = array();
		foreach ( $values as $name => $value ) {
			if ( in_array( $name, Placeholders::HTML, true ) ) {
				$pairs[ '{' . $name . '}' ] = Placeholders::kses_html( $value );
			} elseif ( in_array( $name, Placeholders::URLS, true ) ) {
				$pairs[ '{' . $name . '}' ] = esc_url( $value );
			} else {
				$pairs[ '{' . $name . '}' ] = nl2br( esc_html( $value ), false );
			}
		}
		return wp_kses_post( strtr( $body, $pairs ) );
	}

	/**
	 * Wraps the content in the e-mail layout (filterable with `trmz_email_html`).
	 *
	 * @param string $content   Body HTML (already safe).
	 * @param string $subject   Subject (plain text).
	 * @param string $site_name Site name (plain text).
	 */
	public static function layout( string $content, string $subject, string $site_name ): string {
		// Short lines: mail transports limit line length (RFC 5322).
		$html = implode(
			"\n",
			array(
				'<!DOCTYPE html>',
				'<html><head><meta charset="UTF-8" />',
				'<meta name="viewport" content="width=device-width, initial-scale=1" />',
				'<title>' . esc_html( $subject ) . '</title></head>',
				'<body style="margin:0;padding:0;background:#f4f4f5;">',
				'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;">',
				'<tr><td align="center" style="padding:24px 12px;">',
				'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:6px;">',
				'<tr><td style="padding:20px 24px;border-bottom:1px solid #e4e4e7;font-family:Arial,Helvetica,sans-serif;font-size:18px;font-weight:bold;color:#18181b;">',
				esc_html( $site_name ),
				'</td></tr>',
				'<tr><td style="padding:24px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#27272a;">',
				str_replace( '</p>', "</p>\n", $content ),
				'</td></tr>',
				'</table>',
				'</td></tr></table>',
				'</body></html>',
			)
		);

		/**
		 * Filters the complete HTML of a Terminarz e-mail (layout around the template body).
		 *
		 * @param string $html      Complete HTML document.
		 * @param string $content   Rendered template body (safe HTML).
		 * @param string $subject   Subject.
		 */
		return (string) apply_filters( 'trmz_email_html', $html, $content, $subject );
	}

	/**
	 * `{name}` => transformed value pairs for strtr().
	 *
	 * @param array<string, string>    $values    Values.
	 * @param callable(string): string $transform Value transformation.
	 * @return array<string, string>
	 */
	private static function pairs( array $values, callable $transform ): array {
		$pairs = array();
		foreach ( $values as $name => $value ) {
			$pairs[ '{' . $name . '}' ] = $transform( $value );
		}
		return $pairs;
	}
}
