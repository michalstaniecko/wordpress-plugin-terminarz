<?php
/**
 * Runs schema migrations when the stored version differs from the code.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure\Database;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Infrastructure\Module;

/**
 * Hooks the schema upgrade into `plugins_loaded` (activation calls {@see Migrator::migrate()} directly).
 *
 * The check costs one autoloaded option read per request. Concurrent requests are serialised with a
 * short-lived lock option (add_option() is atomic thanks to the unique `option_name` index).
 */
final class Migrator implements Module {

	/**
	 * Lock option name.
	 */
	public const LOCK_OPTION = 'trmz_db_migration_lock';

	/**
	 * A lock older than this (seconds) is considered stale (e.g. the request died mid-migration).
	 */
	private const LOCK_TTL = 300;

	/**
	 * Schema factory (resolved lazily, so the current site prefix is used on multisite).
	 *
	 * @var callable(): Schema
	 */
	private $schema_factory;

	/**
	 * Constructor.
	 *
	 * @param callable(): Schema|null $schema_factory Returns the schema to migrate; defaults to the global `$wpdb`.
	 */
	public function __construct( ?callable $schema_factory = null ) {
		$this->schema_factory = $schema_factory ?? array( Schema::class, 'from_globals' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ), 5 );
	}

	/**
	 * `plugins_loaded` callback.
	 */
	public function on_plugins_loaded(): void {
		$this->maybe_migrate();
	}

	/**
	 * Migrates when the stored schema version differs from {@see Schema::VERSION}.
	 *
	 * @return bool Whether a migration ran.
	 */
	public function maybe_migrate(): bool {
		$schema = ( $this->schema_factory )();
		if ( ! $schema->needs_upgrade() ) {
			return false;
		}

		return self::migrate( $schema );
	}

	/**
	 * Installs/upgrades the schema under a lock. Used on activation and by maybe_migrate().
	 *
	 * @param Schema|null $schema Schema to install; defaults to the global `$wpdb`.
	 * @return bool Whether the migration ran (false when another request holds the lock).
	 */
	public static function migrate( ?Schema $schema = null ): bool {
		$schema = $schema ?? Schema::from_globals();

		if ( ! self::acquire_lock() ) {
			return false;
		}

		try {
			$schema->install();

			/**
			 * Fires after the Terminarz database schema was installed or upgraded.
			 *
			 * @param string $version Installed schema version.
			 */
			do_action( 'trmz_schema_migrated', Schema::VERSION );
		} finally {
			delete_option( self::LOCK_OPTION );
		}

		return true;
	}

	/**
	 * Tries to take the migration lock; breaks a stale one.
	 */
	private static function acquire_lock(): bool {
		if ( add_option( self::LOCK_OPTION, (string) time(), '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $locked_at > 0 && time() - $locked_at < self::LOCK_TTL ) {
			return false;
		}

		delete_option( self::LOCK_OPTION );
		return (bool) add_option( self::LOCK_OPTION, (string) time(), '', false );
	}
}
