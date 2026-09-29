<?php
/**
 * Plugin container and module registrar.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz;

use Terminarz\Infrastructure\Database\Migrator;
use Terminarz\Infrastructure\I18n;
use Terminarz\Infrastructure\Module;

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
