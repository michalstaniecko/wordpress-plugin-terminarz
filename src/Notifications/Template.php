<?php
/**
 * E-mail template.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Notifications;

/**
 * Subject (plain text) and body (HTML allowed by `wp_kses_post`) with `{placeholders}`, plus the on/off switch.
 */
final class Template {

	/**
	 * Constructor.
	 *
	 * @param bool   $enabled Whether the message is sent.
	 * @param string $subject Subject with placeholders.
	 * @param string $body    HTML body with placeholders.
	 */
	public function __construct(
		public readonly bool $enabled,
		public readonly string $subject,
		public readonly string $body
	) {
	}
}
