<?php
/**
 * Public service catalogue.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Rest;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Model\Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET /terminarz/v1/services` and `GET /terminarz/v1/services/{id}` — active services, public fields only.
 */
final class ServicesController extends Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->rest_base = 'services';
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'public_read_permissions_check' ),
					'args'                => array(
						'id'      => array(
							'description' => __( 'Service ID.', 'terminarz' ),
							'type'        => 'integer',
							'minimum'     => 1,
						),
						'context' => $this->get_context_param( array( 'default' => 'view' ) ),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Lists active services.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function get_items( $request ): WP_REST_Response {
		$items = array();
		foreach ( $this->services()->services()->all( true ) as $service ) {
			$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $service, $request ) );
		}
		return rest_ensure_response( $items );
	}

	/**
	 * Returns one active service.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$service = $this->services()->services()->get( (int) $request['id'] );
		if ( null === $service || ! $service->is_active ) {
			return self::not_found();
		}
		return $this->prepare_item_for_response( $service, $request );
	}

	/**
	 * Error returned for missing and inactive services (inactive ones are not public).
	 */
	public static function not_found(): WP_Error {
		return new WP_Error( 'trmz_service_not_found', __( 'The service does not exist or is not available.', 'terminarz' ), array( 'status' => 404 ) );
	}

	/**
	 * Public representation of a service.
	 *
	 * @param Service         $item    Service.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response {
		$data = array(
			'id'               => (int) $item->id,
			'name'             => $item->name,
			'duration_minutes' => $item->duration_minutes,
			'price_minor'      => $item->price_minor,
			'is_free'          => $item->is_free(),
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
		);
	}

	/**
	 * Schema of a service.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'trmz-service',
			'type'       => 'object',
			'properties' => array(
				'id'               => array(
					'description' => __( 'Service ID.', 'terminarz' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'embed' ),
					'readonly'    => true,
				),
				'name'             => array(
					'description' => __( 'Service name.', 'terminarz' ),
					'type'        => 'string',
					'context'     => array( 'view', 'embed' ),
					'readonly'    => true,
				),
				'duration_minutes' => array(
					'description' => __( 'Duration of the appointment in minutes.', 'terminarz' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'embed' ),
					'readonly'    => true,
				),
				'price_minor'      => array(
					'description' => __( 'Price in the smallest currency unit (e.g. cents).', 'terminarz' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'embed' ),
					'readonly'    => true,
				),
				'is_free'          => array(
					'description' => __( 'Whether the service is free of charge.', 'terminarz' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'embed' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
