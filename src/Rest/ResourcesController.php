<?php
/**
 * Public resource catalogue.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Rest;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Model\BookableResource;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET /terminarz/v1/resources?service={id}` — active resources (of a service, in its preference order), public fields only.
 */
final class ResourcesController extends Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->rest_base = 'resources';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'public_read_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Lists active resources; with `service` only those performing the (active) service, in its preference order.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$services = $this->services();

		if ( isset( $request['service'] ) ) {
			$service_id = (int) $request['service'];
			$service    = $services->services()->get( $service_id );
			if ( null === $service || ! $service->is_active ) {
				return ServicesController::not_found();
			}
			$ids       = $services->services()->resource_ids( $service_id );
			$by_id     = $services->resources()->get_many( $ids );
			$resources = array();
			foreach ( $ids as $id ) {
				if ( isset( $by_id[ $id ] ) && $by_id[ $id ]->is_active ) {
					$resources[] = $by_id[ $id ];
				}
			}
		} else {
			$resources = $services->resources()->all( true );
		}

		$items = array();
		foreach ( $resources as $resource ) {
			$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $resource, $request ) );
		}
		return rest_ensure_response( $items );
	}

	/**
	 * Public representation of a resource (no e-mail, user or other internal data).
	 *
	 * @param BookableResource $item    Resource.
	 * @param WP_REST_Request  $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response {
		$data = array(
			'id'   => (int) $item->id,
			'name' => $item->name,
		);

		$context = ! empty( $request['context'] ) ? (string) $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );

		return rest_ensure_response( $data );
	}

	/**
	 * Collection parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_collection_params(): array {
		return array(
			'context' => $this->get_context_param( array( 'default' => 'view' ) ),
			'service' => array(
				'description' => __( 'Only resources performing this service.', 'terminarz' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
		);
	}

	/**
	 * Schema of a resource.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'trmz-resource',
			'type'       => 'object',
			'properties' => array(
				'id'   => array(
					'description' => __( 'Resource ID.', 'terminarz' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'embed' ),
					'readonly'    => true,
				),
				'name' => array(
					'description' => __( 'Resource name.', 'terminarz' ),
					'type'        => 'string',
					'context'     => array( 'view', 'embed' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
