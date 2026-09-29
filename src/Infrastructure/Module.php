<?php
/**
 * Module contract.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure;

/**
 * A unit of the plugin that hooks itself into WordPress.
 */
interface Module {

	/**
	 * Registers WordPress hooks (actions/filters). Must not perform heavy work.
	 */
	public function register(): void;
}
