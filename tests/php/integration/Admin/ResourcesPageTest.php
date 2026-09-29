<?php
/**
 * Integration tests for the resources screen.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Admin;

use Terminarz\Admin\ResourcesPage;
use Terminarz\Domain\Model\BookableResource;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Service;

/**
 * @covers \Terminarz\Admin\ResourcesPage
 * @covers \Terminarz\Admin\ResourcesListTable
 * @covers \Terminarz\Admin\Screen
 * @covers \Terminarz\Admin\Notices
 */
final class ResourcesPageTest extends AdminTestCase {

	/**
	 * Screen under test.
	 *
	 * @var ResourcesPage
	 */
	private ResourcesPage $page;

	public function set_up(): void {
		parent::set_up();
		$this->page = new ResourcesPage();
	}

	public function test_creates_a_resource_with_sanitized_fields(): void {
		$url = $this->run_action(
			$this->page,
			'save_resource',
			array(
				'name'        => ' <b>Room</b> 1 ',
				'type'        => 'room',
				'description' => "First floor\n<script>alert(1)</script>",
				'sort_order'  => '3',
				'is_active'   => '1',
			)
		);

		$resources = $this->container->resources()->all();
		$this->assertCount( 1, $resources );
		$resource = $resources[0];
		$this->assertSame( 'Room 1', $resource->name );
		$this->assertSame( BookableResource::TYPE_ROOM, $resource->type );
		$this->assertStringNotContainsString( '<script>', $resource->description );
		$this->assertStringContainsString( 'First floor', $resource->description );
		$this->assertSame( 3, $resource->sort_order );
		$this->assertTrue( $resource->is_active );
		$this->assertStringContainsString( 'page=trmz-resources', $url );
		$this->assertStringContainsString( 'id=' . $resource->id, $url );
		$this->assertSame( array( 'Resource added.' ), $this->notices( 'success' ) );
	}

	public function test_updates_a_resource_and_unchecked_box_deactivates(): void {
		$id = $this->make_resource( 'Anna' );

		$this->run_action(
			$this->page,
			'save_resource',
			array(
				'id'   => (string) $id,
				'name' => 'Anna K.',
				'type' => 'person',
			)
		);

		$resource = $this->container->resources()->get( $id );
		$this->assertSame( 'Anna K.', $resource->name );
		$this->assertFalse( $resource->is_active );
	}

	public function test_validation_errors_keep_input_and_do_not_save(): void {
		$url = $this->run_action(
			$this->page,
			'save_resource',
			array(
				'name' => '   ',
				'type' => 'spaceship',
			)
		);

		$this->assertSame( array(), $this->container->resources()->all() );
		$this->assertCount( 2, $this->notices( 'error' ) );
		$this->assertStringContainsString( 'view=edit', $url );

		$html = $this->render(
			$this->page,
			array(
				'page' => ResourcesPage::SLUG,
				'view' => 'edit',
			)
		);
		$this->assertStringContainsString( 'Enter a name.', $html );
	}

	public function test_toggle_deactivates_and_warns_about_upcoming_bookings(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$this->container->bookings()->create( $this->booking( $resource, '2030-01-10 09:00', 60, 0, BookingStatus::Confirmed, null, $service ), $this->clock->now() );

		$this->run_action(
			$this->page,
			'toggle_resource',
			array(
				'id'     => (string) $resource,
				'active' => '0',
			)
		);

		$this->assertFalse( $this->container->resources()->get( $resource )->is_active );
		$this->assertCount( 1, $this->notices( 'warning' ) );

		$this->run_action(
			$this->page,
			'toggle_resource',
			array(
				'id'     => (string) $resource,
				'active' => '1',
			)
		);
		$this->assertTrue( $this->container->resources()->get( $resource )->is_active );
	}

	public function test_delete_without_bookings(): void {
		$id = $this->make_resource();

		$this->run_action( $this->page, 'delete_resource', array( 'id' => (string) $id ) );

		$this->assertNull( $this->container->resources()->get( $id ) );
		$this->assertSame( array( 'Resource deleted.' ), $this->notices( 'success' ) );
	}

	public function test_delete_with_bookings_is_refused(): void {
		$resource = $this->make_resource();
		$service  = $this->make_service( 60, 0, array( $resource ) );
		$this->container->bookings()->create( $this->booking( $resource, '2030-01-10 09:00', 60, 0, BookingStatus::Confirmed, null, $service ), $this->clock->now() );

		$this->run_action( $this->page, 'delete_resource', array( 'id' => (string) $resource ) );

		$this->assertNotNull( $this->container->resources()->get( $resource ) );
		$this->assertStringContainsString( 'Deactivate it instead', $this->notices( 'error' )[0] );
	}

	public function test_actions_require_the_capability(): void {
		$id = $this->make_resource();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertDies( fn() => $this->run_action( $this->page, 'delete_resource', array( 'id' => (string) $id ) ) );
		$this->assertNotNull( $this->container->resources()->get( $id ) );
	}

	public function test_actions_require_a_valid_nonce(): void {
		$id = $this->make_resource();

		$this->assertDies( fn() => $this->run_action( $this->page, 'delete_resource', array( 'id' => (string) $id ), 'invalid' ) );
		$this->assertDies( fn() => $this->run_action( $this->page, 'delete_resource', array( 'id' => (string) $id ), wp_create_nonce( 'trmz_save_resource' ) ) );
		$this->assertNotNull( $this->container->resources()->get( $id ) );
	}

	public function test_list_escapes_output(): void {
		$this->container->resources()->save( new BookableResource( null, '<img src=x onerror=alert(1)>', true, 'device', '"quoted" <em>' ) );

		$html = $this->render( $this->page, array( 'page' => ResourcesPage::SLUG ) );

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringContainsString( 'Device', $html );
		$this->assertStringContainsString( 'action=trmz_delete_resource', $html );
		$this->assertStringContainsString( '_wpnonce=', $html );
	}

	public function test_services_column_without_a_query_per_row(): void {
		$anna    = (int) $this->container->resources()->save( new BookableResource( null, 'Anna', true, 'person' ) )->id;
		$bob     = (int) $this->container->resources()->save( new BookableResource( null, 'Bob', true, 'person' ) )->id;
		$massage = (int) $this->container->services()->save( new Service( null, 'Massage', 60, 0 ) )->id;
		$physio  = (int) $this->container->services()->save( new Service( null, 'Physio', 30, 0 ) )->id;
		$this->container->services()->assign_resources( $massage, array( $anna, $bob ) );
		$this->container->services()->assign_resources( $physio, array( $anna ) );
		$this->container->resources()->save( new BookableResource( null, 'Idle', true, 'person' ) );

		global $wpdb;
		$before = $wpdb->num_queries;
		$html   = $this->render( $this->page, array( 'page' => ResourcesPage::SLUG ) );
		$few    = $wpdb->num_queries - $before;

		$this->assertStringContainsString( 'Massage, Physio', $html );
		$this->assertStringContainsString( '&mdash;', $html, 'Resource without services.' );

		$extra = array( $anna, $bob );
		for ( $i = 0; $i < 4; $i++ ) {
			$extra[] = (int) $this->container->resources()->save( new BookableResource( null, 'Extra ' . $i, true, 'person' ) )->id;
		}
		$this->container->services()->assign_resources( $massage, $extra );

		$before = $wpdb->num_queries;
		$this->render( $this->page, array( 'page' => ResourcesPage::SLUG ) );
		$this->assertLessThanOrEqual( $few, $wpdb->num_queries - $before, 'Query count does not grow with the number of resources.' );
	}

	public function test_render_requires_the_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertDies( fn() => $this->render( $this->page, array( 'page' => ResourcesPage::SLUG ) ) );
	}

	public function test_edit_form_is_prefilled(): void {
		$resource = $this->container->resources()->save( new BookableResource( null, 'Anna', true, 'person', 'Physio', 2 ) );

		$html = $this->render(
			$this->page,
			array(
				'page' => ResourcesPage::SLUG,
				'view' => 'edit',
				'id'   => (int) $resource->id,
			)
		);

		$this->assertStringContainsString( 'value="Anna"', $html );
		$this->assertStringContainsString( 'Physio</textarea>', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'value="trmz_save_resource"', $html );
	}
}
