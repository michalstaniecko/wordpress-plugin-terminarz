<?php
/**
 * "Terminarz" admin menu.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Infrastructure\Capabilities;
use Terminarz\Infrastructure\Module;

/**
 * Registry of admin pages: one top-level "Terminarz" item with a submenu entry per page,
 * all guarded by `trmz_manage_bookings`. Later screens (resources, services, bookings) are added with `add_page()`.
 */
final class Menu implements Module {

	/**
	 * Menu position in the admin sidebar (below "Comments").
	 */
	private const MENU_POSITION = 26;

	/**
	 * Registered pages keyed by slug.
	 *
	 * @var array<string, AdminPage>
	 */
	private array $pages = array();

	/**
	 * Constructor.
	 *
	 * @param AdminPage[] $pages Pages.
	 */
	public function __construct( array $pages = array() ) {
		foreach ( $pages as $page ) {
			$this->add_page( $page );
		}
	}

	/**
	 * Adds a page (call before `register()`).
	 *
	 * @param AdminPage $page Page.
	 */
	public function add_page( AdminPage $page ): self {
		$this->pages[ $page->slug() ] = $page;
		return $this;
	}

	/**
	 * Pages ordered by position (then slug).
	 *
	 * @return AdminPage[]
	 */
	public function pages(): array {
		$pages = array_values( $this->pages );
		usort(
			$pages,
			static fn( AdminPage $a, AdminPage $b ): int => array( $a->position(), $a->slug() ) <=> array( $b->position(), $b->slug() )
		);
		return $pages;
	}

	/**
	 * Slug of the top-level menu item (the first page), or null when no page is registered.
	 */
	public function parent_slug(): ?string {
		$pages = $this->pages();
		return array() === $pages ? null : $pages[0]->slug();
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		foreach ( $this->pages as $page ) {
			$page->register();
		}
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Adds the top-level item and the submenu (on `admin_menu`).
	 */
	public function add_menu(): void {
		$pages  = $this->pages();
		$parent = $this->parent_slug();
		if ( null === $parent ) {
			return;
		}

		add_menu_page(
			$pages[0]->page_title(),
			__( 'Terminarz', 'terminarz' ),
			Capabilities::MANAGE_BOOKINGS,
			$parent,
			array( $pages[0], 'render' ),
			'dashicons-calendar-alt',
			self::MENU_POSITION
		);

		foreach ( $pages as $page ) {
			add_submenu_page(
				$parent,
				$page->page_title(),
				$page->menu_title(),
				Capabilities::MANAGE_BOOKINGS,
				$page->slug(),
				array( $page, 'render' )
			);
		}
	}
}
