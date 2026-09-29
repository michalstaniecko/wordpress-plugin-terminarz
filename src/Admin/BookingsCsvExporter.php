<?php
/**
 * CSV export of bookings.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Repository\BookingCriteria;
use Terminarz\Infrastructure\Services;

/**
 * Writes the bookings matching the list filters as CSV to a stream, in batches (never the whole result in memory):
 * UTF-8 with BOM (Excel), separator from the `trmz_csv_separator` filter (`,` by default), dates in the site time zone
 * plus the UTC start. Cells starting with `=`, `+`, `-`, `@`, tab or carriage return get a leading apostrophe
 * (CSV/formula injection). Rows are paged by ID (keyset) in ascending ID order, so bookings created or changed during
 * the export are neither skipped nor repeated.
 */
final class BookingsCsvExporter {

	/**
	 * Rows fetched per query.
	 */
	public const BATCH = BookingCriteria::MAX_LIMIT;

	/**
	 * Separators accepted from the filter.
	 */
	private const SEPARATORS = array( ',', ';', "\t", '|' );

	/**
	 * Constructor.
	 *
	 * @param Services $services Composition root.
	 */
	public function __construct( private readonly Services $services ) {
	}

	/**
	 * Writes the CSV document.
	 *
	 * @param resource       $stream  Writable stream (e.g. php://output).
	 * @param BookingFilters $filters List filters.
	 * @return int Number of bookings written.
	 */
	public function write( $stream, BookingFilters $filters ): int {
		$separator = self::separator();
		$timezone  = $this->services->availability_settings()->timezone;
		$services  = array();
		$resources = array();
		foreach ( $this->services->services()->all() as $service ) {
			$services[ (int) $service->id ] = $service->name;
		}
		foreach ( $this->services->resources()->all() as $resource ) {
			$resources[ (int) $resource->id ] = $resource->name;
		}

		fwrite( $stream, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to the response.
		$this->put( $stream, $this->header(), $separator );

		$written  = 0;
		$after_id = 0;
		$criteria = $filters->criteria( $timezone, self::BATCH );
		do {
			$batch = $this->services->bookings()->search_after_id( $criteria, $after_id );
			foreach ( $batch as $booking ) {
				$this->put( $stream, $this->row( $booking, $services, $resources ), $separator );
				$after_id = (int) $booking->id;
				++$written;
			}
			$full = count( $batch ) === self::BATCH;
			fflush( $stream );
		} while ( $full );

		return $written;
	}

	/**
	 * Neutralises a cell that a spreadsheet would treat as a formula.
	 *
	 * @param string $value Cell value.
	 */
	public static function safe_cell( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Column separator (filterable, one of `,` `;` tab `|`).
	 */
	public static function separator(): string {
		/**
		 * Filters the CSV column separator of the booking export.
		 *
		 * @param string $separator One of ",", ";", "\t", "|". Default ",".
		 */
		$separator = (string) apply_filters( 'trmz_csv_separator', ',' );
		return in_array( $separator, self::SEPARATORS, true ) ? $separator : ',';
	}

	/**
	 * File name for a download, e.g. `bookings-2030-01-07.csv`.
	 */
	public function filename(): string {
		return 'bookings-' . $this->services->clock()->now()->setTimezone( $this->services->availability_settings()->timezone )->format( 'Y-m-d' ) . '.csv';
	}

	/**
	 * Header row.
	 *
	 * @return string[]
	 */
	private function header(): array {
		return array(
			__( 'Booking ID', 'terminarz' ),
			__( 'Status', 'terminarz' ),
			__( 'Start', 'terminarz' ),
			__( 'End', 'terminarz' ),
			__( 'Start (UTC)', 'terminarz' ),
			__( 'Service', 'terminarz' ),
			__( 'Resource', 'terminarz' ),
			__( 'Name', 'terminarz' ),
			__( 'E-mail', 'terminarz' ),
			__( 'Phone', 'terminarz' ),
			__( 'Note', 'terminarz' ),
			__( 'Booked on', 'terminarz' ),
			__( 'Order', 'terminarz' ),
		);
	}

	/**
	 * One booking as cells.
	 *
	 * @param Booking            $booking   Booking.
	 * @param array<int, string> $services  Service names.
	 * @param array<int, string> $resources Resource names.
	 * @return string[]
	 */
	private function row( Booking $booking, array $services, array $resources ): array {
		$local = static fn( \DateTimeImmutable $time ): string => (string) wp_date( 'Y-m-d H:i', $time->getTimestamp() );
		return array(
			(string) $booking->public_id,
			Labels::status( $booking->status ),
			$local( $booking->range->start ),
			$local( $booking->range->end ),
			$booking->range->start->format( 'Y-m-d\TH:i:s\Z' ),
			$services[ $booking->service_id ] ?? '#' . $booking->service_id,
			$resources[ $booking->resource_id ] ?? '#' . $booking->resource_id,
			$booking->customer->name,
			$booking->customer->email,
			$booking->customer->phone,
			$booking->customer->note,
			null === $booking->created_at ? '' : $local( $booking->created_at ),
			null === $booking->order_id ? '' : (string) $booking->order_id,
		);
	}

	/**
	 * Writes one CSV line.
	 *
	 * @param resource $stream    Stream.
	 * @param string[] $cells     Cells.
	 * @param string   $separator Separator.
	 */
	private function put( $stream, array $cells, string $separator ): void {
		fputcsv( $stream, array_map( array( self::class, 'safe_cell' ), $cells ), $separator, '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv -- Streaming to the response.
	}
}
