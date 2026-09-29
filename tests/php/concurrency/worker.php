<?php
/**
 * Worker for the concurrency test (#14).
 *
 * Runs in its own process with its own database connection:
 * `wp eval-file tests/php/concurrency/worker.php <action> [args…]`.
 *
 * Actions (each prints one JSON line):
 * - setup <resources> <services>                    → {"resources":[ids],"services":[ids]}
 * - reserve <resource> <service> <start> <minutes> <buffer> <barrier>
 *                                                   → {"ok":bool,"error":?string,"started_at":float}
 *   Waits until the Unix time <barrier> (float) and then tries to book (UTC start "Y-m-d H:i").
 * - count <resource ids csv>                        → {"active":int}
 * - cleanup <resource ids csv> <service ids csv>    → {"ok":true}
 *
 * Operates on the real tables of the site (not the PHPUnit `wptests_` tables), outside any test transaction.
 *
 * @package Terminarz\Tests
 */

// No declare(strict_types=1): WP-CLI eval-file runs this code through eval().

use Terminarz\Domain\Exception\SlotUnavailable;
use Terminarz\Domain\Model\BookableResource;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Domain\Model\Service;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Infrastructure\Database\Schema;
use Terminarz\Infrastructure\Services;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals -- WP-CLI eval-file scope.

/**
 * Prints a JSON result line.
 *
 * @param array<string, mixed> $data Result.
 */
$trmz_out = static function ( array $data ): void {
	echo wp_json_encode( $data ), "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI JSON output.
};

/**
 * Parses a CSV list of IDs.
 *
 * @param string $csv IDs.
 * @return int[]
 */
$trmz_ids = static fn( string $csv ): array => array_values( array_filter( array_map( 'intval', explode( ',', $csv ) ) ) );

$trmz_args      = isset( $args ) && is_array( $args ) ? array_values( $args ) : array();
$trmz_action    = (string) ( $trmz_args[0] ?? '' );
$trmz_container = Services::instance();
$trmz_utc       = new DateTimeZone( 'UTC' );

switch ( $trmz_action ) {
	case 'setup':
		$trmz_resources = array();
		$trmz_services  = array();
		for ( $trmz_i = 0; $trmz_i < (int) ( $trmz_args[1] ?? 1 ); $trmz_i++ ) {
			$trmz_resources[] = $trmz_container->resources()->save( new BookableResource( null, 'trmz-concurrency-' . $trmz_i ) )->id;
		}
		for ( $trmz_i = 0; $trmz_i < (int) ( $trmz_args[2] ?? 1 ); $trmz_i++ ) {
			$trmz_services[] = $trmz_container->services()->save( new Service( null, 'trmz-concurrency-' . $trmz_i, 30 + 15 * $trmz_i, 0, 5 * $trmz_i ) )->id;
		}
		$trmz_out(
			array(
				'resources' => $trmz_resources,
				'services'  => $trmz_services,
			)
		);
		break;

	case 'reserve':
		$trmz_start = new DateTimeImmutable( (string) $trmz_args[3], $trmz_utc );
		$trmz_min   = (int) $trmz_args[4];
		$trmz_book  = new Booking(
			resource_id: (int) $trmz_args[1],
			service_id: (int) $trmz_args[2],
			range: new TimeRange( $trmz_start, $trmz_start->modify( "+{$trmz_min} minutes" ) ),
			status: BookingStatus::Confirmed,
			customer: new Customer( 'Concurrency Test', 'concurrency@example.org' ),
			buffer_after_minutes: (int) $trmz_args[5]
		);
		$trmz_repo  = $trmz_container->bookings();
		$trmz_now   = new DateTimeImmutable( 'now', $trmz_utc );

		// Barrier: every process has bootstrapped WordPress by now; start at the same instant.
		$trmz_barrier = (float) $trmz_args[6];
		$trmz_late    = microtime( true ) > $trmz_barrier;
		while ( microtime( true ) < $trmz_barrier ) {
			usleep( 200 );
		}
		$trmz_started = microtime( true );

		try {
			$trmz_repo->create( $trmz_book, $trmz_now );
			$trmz_out(
				array(
					'ok'         => true,
					'error'      => null,
					'started_at' => $trmz_started,
					'late'       => $trmz_late,
				)
			);
		} catch ( SlotUnavailable $trmz_e ) {
			$trmz_out(
				array(
					'ok'         => false,
					'error'      => 'SlotUnavailable',
					'started_at' => $trmz_started,
					'late'       => $trmz_late,
				)
			);
		} catch ( Throwable $trmz_e ) {
			$trmz_out(
				array(
					'ok'         => false,
					'error'      => get_class( $trmz_e ) . ': ' . $trmz_e->getMessage(),
					'started_at' => $trmz_started,
					'late'       => $trmz_late,
				)
			);
		}
		break;

	case 'count':
		global $wpdb;
		$trmz_list  = $trmz_ids( (string) ( $trmz_args[1] ?? '' ) );
		$trmz_table = Schema::from_globals()->table( Schema::BOOKINGS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery -- test helper.
		$trmz_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$trmz_table} WHERE active_start_utc IS NOT NULL AND resource_id IN (" . implode( ',', array_fill( 0, count( $trmz_list ), '%d' ) ) . ')', $trmz_list ) );
		$trmz_out( array( 'active' => (int) $trmz_count ) );
		break;

	case 'cleanup':
		global $wpdb;
		$trmz_table = Schema::from_globals()->table( Schema::BOOKINGS );
		foreach ( $trmz_ids( (string) ( $trmz_args[1] ?? '' ) ) as $trmz_id ) {
			$wpdb->delete( $trmz_table, array( 'resource_id' => $trmz_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test helper.
			$trmz_container->resources()->delete( $trmz_id );
		}
		foreach ( $trmz_ids( (string) ( $trmz_args[2] ?? '' ) ) as $trmz_id ) {
			$trmz_container->services()->delete( $trmz_id );
		}
		$trmz_out( array( 'ok' => true ) );
		break;

	default:
		$trmz_out( array( 'error' => 'Unknown action.' ) );
}
