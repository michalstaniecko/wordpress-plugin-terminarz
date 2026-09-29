<?php
/**
 * Database schema (custom tables) and its versioning.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure\Database;

use wpdb;

/**
 * Creates and upgrades the plugin tables with dbDelta().
 *
 * Every instant is stored as DATETIME in UTC. Weekly schedules and schedule exceptions are the only
 * exception: they describe recurring wall-clock hours, so they are stored as local TIME/DATE values
 * of the site time zone (see docs/ARCHITECTURE.md, ADR-012).
 *
 * All tables use InnoDB (transactions and row locks are required for atomic slot booking, ADR-014).
 */
final class Schema {

	/**
	 * Current schema version. Bump it whenever a CREATE TABLE statement below changes.
	 */
	public const VERSION = '2';

	/**
	 * Option that stores the installed schema version.
	 */
	public const VERSION_OPTION = 'trmz_db_version';

	/**
	 * Logical table names (without any prefix).
	 */
	public const RESOURCES         = 'trmz_resources';
	public const SERVICES          = 'trmz_services';
	public const SERVICE_RESOURCES = 'trmz_service_resources';
	public const SCHEDULES         = 'trmz_schedules';
	public const EXCEPTIONS        = 'trmz_schedule_exceptions';
	public const BOOKINGS          = 'trmz_bookings';

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	private wpdb $db;

	/**
	 * Table prefix (defaults to the current site prefix, so multisite gets per-site tables).
	 *
	 * @var string|null
	 */
	private ?string $prefix;

	/**
	 * Constructor.
	 *
	 * @param wpdb        $db     Database connection.
	 * @param string|null $prefix Table prefix; null means `$db->prefix` read at call time.
	 */
	public function __construct( wpdb $db, ?string $prefix = null ) {
		$this->db     = $db;
		$this->prefix = $prefix;
	}

	/**
	 * Builds an instance bound to the global `$wpdb`.
	 */
	public static function from_globals(): self {
		global $wpdb;
		return new self( $wpdb );
	}

	/**
	 * Full name of a plugin table for the current prefix.
	 *
	 * @param string $table One of the table constants of this class.
	 */
	public function table( string $table ): string {
		return $this->prefix() . $table;
	}

	/**
	 * Full names of all plugin tables, keyed by logical name.
	 *
	 * @return array<string, string>
	 */
	public function tables(): array {
		$tables = array();
		foreach ( self::logical_tables() as $table ) {
			$tables[ $table ] = $this->table( $table );
		}
		return $tables;
	}

	/**
	 * Logical names of all plugin tables.
	 *
	 * @return string[]
	 */
	public static function logical_tables(): array {
		return array(
			self::RESOURCES,
			self::SERVICES,
			self::SERVICE_RESOURCES,
			self::SCHEDULES,
			self::EXCEPTIONS,
			self::BOOKINGS,
		);
	}

	/**
	 * Creates missing tables and columns/indexes (idempotent) and stores the schema version.
	 *
	 * @return string[] Changes performed by dbDelta() (empty when the schema was already up to date).
	 */
	public function install(): array {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$changes = dbDelta( $this->create_statements() );

		update_option( self::VERSION_OPTION, self::VERSION, true );

		return array_values( array_map( 'strval', $changes ) );
	}

	/**
	 * Whether the installed schema version differs from the current one.
	 */
	public function needs_upgrade(): bool {
		return self::VERSION !== (string) get_option( self::VERSION_OPTION, '' );
	}

	/**
	 * Drops all plugin tables and the version option. Used only by uninstall (when the user opted in) and tests.
	 */
	public function drop(): void {
		foreach ( $this->tables() as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are built from constants; identifiers cannot be prepared.
			$this->db->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * CREATE TABLE statements in the format expected by dbDelta() (two spaces after PRIMARY KEY,
	 * one column per line, KEY instead of INDEX).
	 *
	 * @return string[]
	 */
	public function create_statements(): array {
		$options = 'ENGINE=InnoDB ' . $this->db->get_charset_collate();
		$t       = $this->tables();

		return array(
			"CREATE TABLE {$t[self::RESOURCES]} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  type varchar(20) NOT NULL DEFAULT 'person',
  description text NULL,
  user_id bigint(20) unsigned NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY active_order (is_active,sort_order),
  KEY user_id (user_id)
) {$options};",

			"CREATE TABLE {$t[self::SERVICES]} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(191) NOT NULL,
  description text NULL,
  duration_minutes smallint(5) unsigned NOT NULL,
  buffer_after_minutes smallint(5) unsigned NOT NULL DEFAULT 0,
  price_minor bigint(20) unsigned NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT '',
  sort_order int(11) NOT NULL DEFAULT 0,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY active_order (is_active,sort_order)
) {$options};",

			"CREATE TABLE {$t[self::SERVICE_RESOURCES]} (
  service_id bigint(20) unsigned NOT NULL,
  resource_id bigint(20) unsigned NOT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (service_id,resource_id),
  KEY resource_id (resource_id)
) {$options};",

			"CREATE TABLE {$t[self::SCHEDULES]} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  resource_id bigint(20) unsigned NOT NULL,
  weekday tinyint(1) unsigned NOT NULL,
  kind varchar(10) NOT NULL DEFAULT 'work',
  start_time time NOT NULL,
  end_time time NOT NULL,
  PRIMARY KEY  (id),
  KEY resource_weekday (resource_id,weekday)
) {$options};",

			"CREATE TABLE {$t[self::EXCEPTIONS]} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  resource_id bigint(20) unsigned NULL,
  start_date date NOT NULL,
  end_date date NOT NULL,
  kind varchar(20) NOT NULL DEFAULT 'closed',
  intervals text NULL,
  note varchar(191) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY resource_dates (resource_id,start_date,end_date),
  KEY dates (start_date,end_date)
) {$options};",

			"CREATE TABLE {$t[self::BOOKINGS]} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id varchar(32) NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  resource_id bigint(20) unsigned NOT NULL,
  start_utc datetime NOT NULL,
  end_utc datetime NOT NULL,
  buffer_end_utc datetime NOT NULL,
  active_start_utc datetime NULL,
  status varchar(20) NOT NULL,
  customer_name varchar(191) NOT NULL DEFAULT '',
  customer_email varchar(191) NOT NULL DEFAULT '',
  customer_phone varchar(50) NOT NULL DEFAULT '',
  customer_note text NULL,
  customer_user_id bigint(20) unsigned NULL,
  cancel_token_hash varchar(64) NULL,
  order_id bigint(20) unsigned NULL,
  hold_expires_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  UNIQUE KEY resource_active_start (resource_id,active_start_utc),
  KEY resource_range (resource_id,start_utc,buffer_end_utc,status),
  KEY status_hold (status,hold_expires_at),
  KEY start_utc (start_utc),
  KEY service_id (service_id),
  KEY order_id (order_id),
  KEY customer_email (customer_email)
) {$options};",
		);
	}

	/**
	 * Current table prefix.
	 */
	private function prefix(): string {
		return $this->prefix ?? $this->db->prefix;
	}
}
