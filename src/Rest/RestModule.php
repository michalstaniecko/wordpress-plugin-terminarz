<?php
/**
 * REST API module.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Rest;

use Terminarz\Infrastructure\Module;
use WP_REST_Controller;

/**
 * Registers the `terminarz/v1` REST routes on `rest_api_init`.
 */
final class RestModule implements Module {

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes of every controller.
	 */
	public function register_routes(): void {
		foreach ( $this->controllers() as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Controllers of the plugin.
	 *
	 * @return WP_REST_Controller[]
	 */
	private function controllers(): array {
		return array(
			new ServicesController(),
			new ResourcesController(),
			new AvailabilityController(),
		);
	}
}
