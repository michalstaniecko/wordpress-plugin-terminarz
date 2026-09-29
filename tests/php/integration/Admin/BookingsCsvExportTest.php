<?php
/**
 * Integration tests for the CSV export of bookings.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Admin;

use Terminarz\Admin\BookingFilters;
use Terminarz\Admin\BookingsCsvExporter;
use Terminarz\Admin\BookingsPage;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Domain\Model\TimeRange;

/**
 * @covers \Terminarz\Admin\BookingsCsvExporter
 * @covers \Terminarz\Admin\BookingsPage::authorize_export
 */
final class BookingsCsvExportTest extends AdminTestCase {

	/**
	 * Resource.
	 *
	 * @var int
	 */
	private int $resource;

	/**
	 * Service.
	 *
	 * @var int
	 */
	private int $service;

	public function set_up(): void {
		parent::set_up();
		$this->resource = $this->make_resource( 'Room "A"' );
		$this->service  = $this->make_service( 60, 0, array( $this->resource ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'trmz_csv_separator' );
		parent::tear_down();
	}

	public function test_writes_bom_header_and_rows_in_site_time(): void {
		$this->store( '2030-01-08 08:00', new Customer( 'Anna Nowak', 'anna@example.org', '600 100 200', "Line 1\nLine 2, with comma" ) );

		$csv = $this->export( new BookingFilters() );

		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );
		$rows = $this->parse( $csv );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'Booking ID', $rows[0][0] );
		$row = array_combine( $rows[0], $rows[1] );
		$this->assertSame( '2030-01-08 09:00', $row['Start'] );
		$this->assertSame( '2030-01-08 10:00', $row['End'] );
		$this->assertSame( '2030-01-08T08:00:00Z', $row['Start (UTC)'] );
		$this->assertSame( 'Room "A"', $row['Resource'] );
		$this->assertSame( "Line 1\nLine 2, with comma", $row['Note'] );
		$this->assertSame( 'Confirmed', $row['Status'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $row['Booking ID'] );
	}

	public function test_neutralises_formula_injection(): void {
		$this->store( '2030-01-08 08:00', new Customer( '=HYPERLINK("http://evil")', 'a@example.org', '+48 600', '@SUM(A1)' ) );
		$this->store( '2030-01-08 10:00', new Customer( '-2+3', 'b@example.org', '', "\tTab" ) );

		$rows = $this->parse( $this->export( new BookingFilters() ) );

		$this->assertSame( "'=HYPERLINK(\"http://evil\")", $rows[1][7] );
		$this->assertSame( "'+48 600", $rows[1][9] );
		$this->assertSame( "'@SUM(A1)", $rows[1][10] );
		$this->assertSame( "'-2+3", $rows[2][7] );
		$this->assertSame( "'\tTab", $rows[2][10] );
		$this->assertSame( 'plain', BookingsCsvExporter::safe_cell( 'plain' ) );
		$this->assertSame( "'\rx", BookingsCsvExporter::safe_cell( "\rx" ) );
	}

	public function test_respects_list_filters(): void {
		$this->store( '2030-01-08 08:00', new Customer( 'Anna', 'anna@example.org' ) );
		$this->store( '2030-01-09 08:00', new Customer( 'Bob', 'bob@example.org' ) );
		$cancelled = $this->store( '2030-01-10 08:00', new Customer( 'Cecil', 'cecil@example.org' ) );
		$this->container->bookings()->change_status( (int) $cancelled->id, BookingStatus::Cancelled );

		$names = static fn( array $rows ): array => array_column( array_slice( $rows, 1 ), 7 );

		$this->assertSame( array( 'Anna', 'Bob', 'Cecil' ), $names( $this->parse( $this->export( new BookingFilters() ) ) ) );
		$this->assertSame(
			array( 'Bob' ),
			$names(
				$this->parse(
					$this->export(
						BookingFilters::from_query(
							array(
								'from' => '2030-01-09',
								'to'   => '2030-01-09',
							)
						)
					)
				)
			)
		);
		$this->assertSame( array( 'Cecil' ), $names( $this->parse( $this->export( BookingFilters::from_query( array( 'status' => 'cancelled' ) ) ) ) ) );
		$this->assertSame( array( 'Anna' ), $names( $this->parse( $this->export( BookingFilters::from_query( array( 's' => 'anna@' ) ) ) ) ) );
	}

	public function test_streams_in_batches_beyond_one_page(): void {
		$count = BookingsCsvExporter::BATCH + 5;
		for ( $i = 0; $i < $count; $i++ ) {
			$this->store( gmdate( 'Y-m-d H:i', strtotime( '2030-02-01 08:00 UTC' ) + $i * 3600 ), new Customer( 'C' . $i, "c{$i}@example.org" ) );
		}

		$stream  = fopen( 'php://memory', 'w+' );
		$written = ( new BookingsCsvExporter( $this->container ) )->write( $stream, new BookingFilters() );
		rewind( $stream );
		$rows = $this->parse( (string) stream_get_contents( $stream ) );

		$this->assertSame( $count, $written );
		$this->assertCount( $count + 1, $rows );
		$this->assertCount( $count, array_unique( array_column( array_slice( $rows, 1 ), 0 ) ), 'No duplicates between batches.' );
	}

	public function test_separator_is_filterable_and_validated(): void {
		$this->store( '2030-01-08 08:00', new Customer( 'Anna', 'anna@example.org' ) );

		add_filter( 'trmz_csv_separator', static fn(): string => ';' );
		$csv = $this->export( new BookingFilters() );
		$this->assertStringContainsString( '"Booking ID";Status;Start;End', $csv );

		add_filter( 'trmz_csv_separator', static fn(): string => 'xx', 20 );
		$this->assertSame( ',', BookingsCsvExporter::separator() );
	}

	public function test_export_requires_capability_and_nonce(): void {
		$page = new BookingsPage();

		$_GET     = array(
			'status'   => 'pending',
			'_wpnonce' => wp_create_nonce( 'trmz_export_bookings' ),
		);
		$_REQUEST = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test request.
		$this->assertSame( BookingStatus::Pending, $page->authorize_export()->status );

		$_REQUEST['_wpnonce'] = 'bad';
		$this->assertDies( static fn() => $page->authorize_export() );

		$_REQUEST['_wpnonce'] = wp_create_nonce( 'trmz_export_bookings' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertDies( static fn() => $page->authorize_export() );
	}

	public function test_list_links_to_export_with_active_filters(): void {
		$html = $this->render(
			new BookingsPage(),
			array(
				'page'   => BookingsPage::SLUG,
				'status' => 'confirmed',
				'from'   => '2030-01-01',
			)
		);

		$this->assertMatchesRegularExpression( '/id="trmz-export-csv" href="[^"]*action=trmz_export_bookings[^"]*status=confirmed[^"]*from=2030-01-01[^"]*_wpnonce=/', $html );
	}

	/**
	 * Exports to a string.
	 *
	 * @param BookingFilters $filters Filters.
	 */
	private function export( BookingFilters $filters ): string {
		$stream = fopen( 'php://memory', 'w+' );
		( new BookingsCsvExporter( $this->container ) )->write( $stream, $filters );
		rewind( $stream );
		$csv = (string) stream_get_contents( $stream );
		fclose( $stream );
		return $csv;
	}

	/**
	 * Parses CSV (without the BOM).
	 *
	 * @param string $csv CSV.
	 * @return array<int, array<int, string>>
	 */
	private function parse( string $csv ): array {
		$stream = fopen( 'php://memory', 'w+' );
		fwrite( $stream, substr( $csv, 3 ) );
		rewind( $stream );
		$rows = array();
		while ( false !== ( $row = fgetcsv( $stream, 0, ',', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$rows[] = $row;
		}
		fclose( $stream );
		return $rows;
	}

	/**
	 * Stores a confirmed booking.
	 *
	 * @param string   $start    Start (UTC).
	 * @param Customer $customer Customer.
	 */
	private function store( string $start, Customer $customer ): Booking {
		$from = self::utc( $start );
		return $this->container->bookings()->create(
			new Booking( $this->resource, $this->service, new TimeRange( $from, $from->modify( '+60 minutes' ) ), BookingStatus::Confirmed, $customer ),
			$this->clock->now()
		);
	}
}
