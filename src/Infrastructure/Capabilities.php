<?php
/**
 * Plugin capabilities.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure;

/**
 * Grants and revokes the custom capability used to guard every admin action.
 */
final class Capabilities {

	/**
	 * Capability required to manage resources, services and bookings.
	 */
	public const MANAGE_BOOKINGS = 'trmz_manage_bookings';

	/**
	 * Roles that receive the capability on activation.
	 *
	 * @var string[]
	 */
	public const DEFAULT_ROLES = array( 'administrator' );

	/**
	 * Adds the capability to the default roles of the current site.
	 */
	public static function grant(): void {
		foreach ( self::DEFAULT_ROLES as $role_name ) {
			$role = get_role( $role_name );
			if ( null !== $role ) {
				$role->add_cap( self::MANAGE_BOOKINGS );
			}
		}
	}

	/**
	 * Removes the capability from every role of the current site (used on uninstall).
	 */
	public static function revoke(): void {
		foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
			$role = get_role( (string) $role_name );
			if ( null !== $role ) {
				$role->remove_cap( self::MANAGE_BOOKINGS );
			}
		}
	}
}
