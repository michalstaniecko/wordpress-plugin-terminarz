<?php
/**
 * Integration tests for the public catalogue endpoints.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Rest;

use Terminarz\Domain\Model\BookableResource;
use Terminarz\Domain\Model\Service;

/**
 * @covers \Terminarz\Rest\ServicesController
 * @covers \Terminarz\Rest\ResourcesController
 * @covers \Terminarz\Rest\Controller
 * @covers \Terminarz\Rest\RestModule
 */
final class CatalogControllersTest extends RestTestCase {

	public function test_routes_are_registered_with_explicit_permission_callbacks(): void {
		$routes = rest_get_server()->get_routes( 'terminarz/v1' );

		foreach ( array( '/terminarz/v1/services', '/terminarz/v1/services/(?P<id>\d+)', '/terminarz/v1/resources' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );
			foreach ( $routes[ $route ] as $handler ) {
				$this->assertIsCallable( $handler['permission_callback'] );
				$this->assertNotSame( '__return_true', $handler['permission_callback'] );
			}
		}
	}

	public function test_services_lists_only_active_services_with_public_fields(): void {
		$active   = $this->make_service( 45, 15 );
		$inactive = $this->make_service();
		$repo     = $this->container->services();
		$service  = $repo->get( $inactive );
		$this->assertNotNull( $service );
		$repo->save( new Service( $inactive, $service->name, 60, 0, 0, false ) );

		$response = $this->request( 'GET', '/services' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'id'               => $active,
					'name'             => 'Service',
					'duration_minutes' => 45,
					'price_minor'      => 10000,
					'is_free'          => false,
				),
			),
			$response->get_data()
		);
	}

	public function test_single_service_and_404_for_missing_or_inactive(): void {
		$active   = $this->make_service( 30 );
		$inactive = $this->container->services()->save( new Service( null, 'Hidden', 30, 0, 0, false ) )->id;

		$response = $this->request( 'GET', '/services/' . $active );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $active, $response->get_data()['id'] );
		$this->assertArrayNotHasKey( 'buffer_after_minutes', $response->get_data() );

		foreach ( array( $inactive, 999999 ) as $id ) {
			$missing = $this->request( 'GET', '/services/' . $id );
			$this->assertSame( 404, $missing->get_status() );
			$this->assertSame( 'trmz_service_not_found', self::error_code( $missing ) );
		}
	}

	public function test_resources_of_a_service_in_preference_order_without_inactive_ones(): void {
		$anna    = $this->make_resource( 'Anna' );
		$bartek  = $this->make_resource( 'Bartek' );
		$hidden  = $this->container->resources()->save( new BookableResource( null, 'Hidden', false ) )->id;
		$other   = $this->make_resource( 'Other' );
		$service = $this->make_service( 60, 0, array( $bartek, (int) $hidden, $anna ) );

		$response = $this->request( 'GET', '/resources', array( 'service' => $service ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				array(
					'id'   => $bartek,
					'name' => 'Bartek',
				),
				array(
					'id'   => $anna,
					'name' => 'Anna',
				),
			),
			$response->get_data()
		);

		$all = wp_list_pluck( $this->request( 'GET', '/resources' )->get_data(), 'id' );
		$this->assertSame( array( $anna, $bartek, $other ), $all );
	}

	public function test_resources_validate_the_service_argument(): void {
		$this->assertSame( 400, $this->request( 'GET', '/resources', array( 'service' => 'abc' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/resources', array( 'service' => 0 ) )->get_status() );

		$missing = $this->request( 'GET', '/resources', array( 'service' => 999999 ) );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'trmz_service_not_found', self::error_code( $missing ) );
	}

	public function test_no_internal_data_leaks(): void {
		global $wpdb;
		$resource = $this->make_resource( 'Anna' );
		$user     = self::factory()->user->create( array( 'user_email' => 'anna-secret@example.org' ) );
		$wpdb->update( $wpdb->prefix . 'trmz_resources', array( 'user_id' => $user ), array( 'id' => $resource ) );
		$service = $this->make_service( 60, 0, array( $resource ) );

		$body = (string) wp_json_encode(
			array(
				$this->request( 'GET', '/resources', array( 'service' => $service ) )->get_data(),
				$this->request( 'GET', '/services' )->get_data(),
			)
		);

		$this->assertStringNotContainsString( 'anna-secret', $body );
		$this->assertStringNotContainsString( 'user_id', $body );
		$this->assertStringNotContainsString( 'sort_order', $body );
	}

	public function test_schema_is_published(): void {
		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'OPTIONS', '/terminarz/v1/services' ) );
		$schema   = $response->get_data()['schema'];

		$this->assertSame( 'trmz-service', $schema['title'] );
		$this->assertSame( array( 'id', 'name', 'duration_minutes', 'price_minor', 'is_free' ), array_keys( $schema['properties'] ) );
	}
}
