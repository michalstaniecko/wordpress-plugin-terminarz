<?php
/**
 * WordPress event dispatcher.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure;

use Terminarz\Application\EventDispatcher;

/**
 * Maps application events to WordPress actions.
 */
final class WpEventDispatcher implements EventDispatcher {

	/**
	 * {@inheritDoc}
	 *
	 * @param string $event   Hook name.
	 * @param mixed  ...$args Arguments.
	 */
	public function dispatch( string $event, mixed ...$args ): void {
		do_action( $event, ...$args ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- hook names are the documented trmz_* event names.
	}
}
