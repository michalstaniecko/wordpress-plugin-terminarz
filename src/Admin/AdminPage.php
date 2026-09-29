<?php
/**
 * Admin page contract.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

defined( 'ABSPATH' ) || exit; // No direct access.

/**
 * A screen under the "Terminarz" admin menu. Registered in `Admin\Menu`.
 */
interface AdminPage {

	/**
	 * Page slug (`admin.php?page=<slug>`), prefixed with `trmz-`.
	 */
	public function slug(): string;

	/**
	 * Translated menu label.
	 */
	public function menu_title(): string;

	/**
	 * Translated page (document) title.
	 */
	public function page_title(): string;

	/**
	 * Position in the submenu (lower first). The lowest one is also the target of the top-level menu item.
	 */
	public function position(): int;

	/**
	 * Registers hooks the page needs outside rendering (e.g. `admin_init`). Called once, on boot.
	 */
	public function register(): void;

	/**
	 * Prints the page. The menu has already checked the capability.
	 */
	public function render(): void;
}
