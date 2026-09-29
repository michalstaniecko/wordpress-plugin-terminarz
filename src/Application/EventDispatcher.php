<?php
/**
 * Application event dispatcher.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

defined( 'ABSPATH' ) || exit; // No direct access.

/**
 * Publishes application events. The WordPress implementation maps them 1:1 to actions (do_action).
 */
interface EventDispatcher {

	/**
	 * Dispatches an event.
	 *
	 * @param string $event   Event (hook) name, e.g. `trmz_booking_created`.
	 * @param mixed  ...$args Event arguments.
	 */
	public function dispatch( string $event, mixed ...$args ): void;
}
