<?php
/**
 * Plugin container and module registrar.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz;

use Terminarz\Admin\BookingsPage;
use Terminarz\Admin\EmailsPage;
use Terminarz\Admin\ExceptionsPage;
use Terminarz\Admin\Menu;
use Terminarz\Admin\Privacy;
use Terminarz\Admin\ResourcesPage;
use Terminarz\Admin\SchedulePage;
use Terminarz\Admin\ServicesPage;
use Terminarz\Admin\SettingsPage;
use Terminarz\Blocks\BookingBlock;
use Terminarz\Infrastructure\Database\Migrator;
use Terminarz\Infrastructure\HoldExpiryScheduler;
use Terminarz\Infrastructure\I18n;
use Terminarz\Infrastructure\Module;
use Terminarz\Integrations\WooCommerce\WooCommerceModule;
use Terminarz\Notifications\BookingNotifier;
use Terminarz\Rest\RestModule;

/**
 * Holds the list of plugin modules and registers their WordPress hooks once.
 */
final class Plugin {

	/**
	 * Shared instance used by the bootstrap file.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Registered modules.
	 *
	 * @var Module[]
	 */
	private array $modules;

	/**
	 * Constructor.
	 *
	 * @param Module[] $modules Modules to register on boot.
	 */
	public function __construct( array $modules ) {
		$this->modules = $modules;
	}

	/**
	 * Returns the shared, default-configured instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self( self::default_modules() );
		}

		return self::$instance;
	}

	/**
	 * Default set of modules. New features add their modules here.
	 *
	 * @return Module[]
	 */
	private static function default_modules(): array {
		return array(
			new I18n(),
			new Migrator(),
			new Menu(
				array(
					new BookingsPage(),
					new ResourcesPage(),
					new SchedulePage(),
					new ExceptionsPage(),
					new ServicesPage(),
					new EmailsPage(),
					new SettingsPage(),
				)
			),
			new RestModule(),
			new BookingBlock(),
			new Privacy(),
			new HoldExpiryScheduler(),
			new BookingNotifier(),
			new WooCommerceModule(),
		);
	}

	/**
	 * Registers hooks of all modules. Safe to call more than once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		foreach ( $this->modules as $module ) {
			$module->register();
		}

		$this->booted = true;
	}

	/**
	 * Returns registered modules.
	 *
	 * @return Module[]
	 */
	public function modules(): array {
		return $this->modules;
	}

	/**
	 * Whether boot() has already run.
	 */
	public function is_booted(): bool {
		return $this->booted;
	}
}
