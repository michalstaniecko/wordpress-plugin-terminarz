<?php
/**
 * Integration tests for resource and service repositories.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Persistence;

use Terminarz\Domain\Exception\EntityInUse;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Model\BookableResource;
use Terminarz\Domain\Model\Service;
use Terminarz\Domain\Model\TimeWindow;
use Terminarz\Domain\Model\WeeklySchedule;
use Terminarz\Infrastructure\Database\Schema;
use Terminarz\Infrastructure\Persistence\WpdbResourceRepository;
use Terminarz\Infrastructure\Persistence\WpdbScheduleRepository;
use Terminarz\Infrastructure\Persistence\WpdbServiceRepository;
use WP_UnitTestCase;

/**
 * @covers \Terminarz\Infrastructure\Persistence\WpdbResourceRepository
 * @covers \Terminarz\Infrastructure\Persistence\WpdbServiceRepository
 * @covers \Terminarz\Infrastructure\Persistence\WpdbRepository
 * @covers \Terminarz\Infrastructure\Database\Transaction
 */
final class CatalogRepositoriesTest extends WP_UnitTestCase {

	/**
	 * Resource repository.
	 *
	 * @var WpdbResourceRepository
	 */
	private WpdbResourceRepository $resources;

	/**
	 * Service repository.
	 *
	 * @var WpdbServiceRepository
	 */
	private WpdbServiceRepository $services;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->resources = new WpdbResourceRepository( $wpdb );
		$this->services  = new WpdbServiceRepository( $wpdb );
	}

	public function test_resource_crud(): void {
		$saved = $this->resources->save( new BookableResource( null, 'Anna' ) );
		$this->assertNotNull( $saved->id );
		$this->assertEquals( $saved, $this->resources->get( $saved->id ) );

		$this->resources->save( new BookableResource( $saved->id, 'Anna K.', false ) );
		$reloaded = $this->resources->get( $saved->id );
		$this->assertSame( 'Anna K.', $reloaded->name );
		$this->assertFalse( $reloaded->is_active );

		$this->resources->delete( $saved->id );
		$this->assertNull( $this->resources->get( $saved->id ) );
	}

	public function test_resource_listing_and_get_many(): void {
		$a = $this->resources->save( new BookableResource( null, 'A' ) );
		$b = $this->resources->save( new BookableResource( null, 'B', false ) );
		$c = $this->resources->save( new BookableResource( null, 'C' ) );

		$this->assertSame( array( 'A', 'B', 'C' ), array_map( static fn( $r ) => $r->name, $this->resources->all() ) );
		$this->assertSame( array( 'A', 'C' ), array_map( static fn( $r ) => $r->name, $this->resources->all( true ) ) );

		$many = $this->resources->get_many( array( $c->id, $a->id, 999999 ) );
		$this->assertEqualsCanonicalizing( array( $a->id, $c->id ), array_keys( $many ) );
		$this->assertSame( array(), $this->resources->get_many( array() ) );
		$this->assertNotNull( $b->id );
	}

	public function test_updating_missing_resource_throws(): void {
		$this->expectException( EntityNotFound::class );
		$this->resources->save( new BookableResource( 999999, 'Ghost' ) );
	}

	public function test_service_crud(): void {
		$saved = $this->services->save( new Service( null, 'Haircut', 45, 8000, 15 ) );
		$this->assertNotNull( $saved->id );
		$this->assertEquals( $saved, $this->services->get( $saved->id ) );

		$this->services->save( new Service( $saved->id, 'Haircut long', 60, 9900, 0, false ) );
		$reloaded = $this->services->get( $saved->id );
		$this->assertSame( 60, $reloaded->duration_minutes );
		$this->assertSame( 9900, $reloaded->price_minor );
		$this->assertSame( 0, $reloaded->buffer_after_minutes );
		$this->assertFalse( $reloaded->is_active );
		$this->assertSame( array(), $this->services->all( true ) );

		$this->services->delete( $saved->id );
		$this->assertNull( $this->services->get( $saved->id ) );
	}

	public function test_service_resource_assignment_keeps_preference_order(): void {
		$service = $this->services->save( new Service( null, 'Massage', 60 ) );
		$a       = $this->resources->save( new BookableResource( null, 'A' ) );
		$b       = $this->resources->save( new BookableResource( null, 'B' ) );
		$c       = $this->resources->save( new BookableResource( null, 'C' ) );

		$this->services->assign_resources( $service->id, array( $c->id, $a->id, $c->id ) );
		$this->assertSame( array( $c->id, $a->id ), $this->services->resource_ids( $service->id ) );
		$this->assertSame( array( $service->id ), $this->services->service_ids_for_resource( $a->id ) );

		$this->services->assign_resources( $service->id, array( $b->id ) );
		$this->assertSame( array( $b->id ), $this->services->resource_ids( $service->id ) );
		$this->assertSame( array(), $this->services->service_ids_for_resource( $a->id ) );
	}

	public function test_deleting_resource_removes_its_schedule_and_assignments(): void {
		global $wpdb;
		$service  = $this->services->save( new Service( null, 'Massage', 60 ) );
		$resource = $this->resources->save( new BookableResource( null, 'A' ) );
		$this->services->assign_resources( $service->id, array( $resource->id ) );
		( new WpdbScheduleRepository( $wpdb ) )->save( $resource->id, new WeeklySchedule( array( 1 => array( TimeWindow::from_strings( '09:00', '17:00' ) ) ) ) );

		$this->resources->delete( $resource->id );

		$this->assertSame( array(), $this->services->resource_ids( $service->id ) );
		$schedules = Schema::from_globals()->table( Schema::SCHEDULES );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$schedules} WHERE resource_id = %d", $resource->id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_resource_and_service_with_bookings_cannot_be_deleted(): void {
		global $wpdb;
		$service  = $this->services->save( new Service( null, 'Massage', 60 ) );
		$resource = $this->resources->save( new BookableResource( null, 'A' ) );
		$wpdb->insert(
			Schema::from_globals()->table( Schema::BOOKINGS ),
			array(
				'public_id'      => 'x1',
				'service_id'     => $service->id,
				'resource_id'    => $resource->id,
				'start_utc'      => '2030-01-01 10:00:00',
				'end_utc'        => '2030-01-01 11:00:00',
				'buffer_end_utc' => '2030-01-01 11:00:00',
				'status'         => 'cancelled',
				'created_at'     => '2030-01-01 00:00:00',
				'updated_at'     => '2030-01-01 00:00:00',
			)
		);

		try {
			$this->resources->delete( $resource->id );
			$this->fail( 'Resource with bookings was deleted.' );
		} catch ( EntityInUse $e ) {
			$this->assertNotNull( $this->resources->get( $resource->id ) );
		}

		$this->expectException( EntityInUse::class );
		$this->services->delete( $service->id );
	}
}
