<?php
/**
 * Administrative booking endpoints.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Rest;

defined( 'ABSPATH' ) || exit; // No direct access.

use DateTimeImmutable;
use Terminarz\Domain\Exception\DomainError;
use Terminarz\Domain\Exception\InvalidStatusTransition;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Domain\Repository\BookingCriteria;
use Terminarz\Infrastructure\Database\DatabaseError;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Booking management for users with `trmz_manage_bookings`:
 *
 * - `GET /terminarz/v1/bookings` — filters (status, service, resource, from/to local dates, search), ordering, paging
 *   (`X-WP-Total`, `X-WP-TotalPages`);
 * - `GET /terminarz/v1/bookings/{id}`;
 * - `POST /terminarz/v1/bookings/{id}/confirm|cancel` — status changes (state machine, 422 when not allowed);
 * - `POST /terminarz/v1/bookings/{id}/reschedule` — atomic move with availability re-check (409 on conflict).
 *
 * `{id}` is the internal booking ID (admin only); the public ID is part of the representation.
 */
final class AdminBookingsController extends Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->rest_base = 'bookings';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$permission = array( $this, 'manage_permissions_check' );
		$id_arg     = array(
			'id' => array(
				'description' => __( 'Booking ID.', 'terminarz' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
		);

		// Same route as the public POST /bookings; no `schema` here so the public schema of that route stays.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => $permission,
					'args'                => $this->get_collection_params(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => $permission,
					'args'                => $id_arg,
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		foreach ( array( 'confirm', 'cancel' ) as $action ) {
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>\d+)/' . $action,
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, $action . '_item' ),
						'permission_callback' => $permission,
						'args'                => $id_arg,
					),
					'schema' => array( $this, 'get_public_item_schema' ),
				)
			);
		}

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/reschedule',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reschedule_item' ),
					'permission_callback' => $permission,
					'args'                => $id_arg + array(
						'start'    => array(
							'description' => __( 'New start, ISO 8601 with a time zone offset.', 'terminarz' ),
							'type'        => 'string',
							'pattern'     => BookingsController::START_PATTERN,
							'required'    => true,
						),
						'resource' => array(
							'description' => __( 'New resource (must provide the service); omitted = keep.', 'terminarz' ),
							'type'        => 'integer',
							'minimum'     => 1,
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Lists bookings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$range = $this->date_range( $request['from'], $request['to'] );
		if ( $range instanceof WP_Error ) {
			return $range;
		}

		$per_page = (int) $request['per_page'];
		$page     = (int) $request['page'];

		try {
			$criteria   = new BookingCriteria(
				statuses: array_map( static fn( $value ): BookingStatus => BookingStatus::from( (string) $value ), (array) ( $request['status'] ?? array() ) ),
				service_id: isset( $request['service'] ) ? (int) $request['service'] : null,
				resource_id: isset( $request['resource'] ) ? (int) $request['resource'] : null,
				starts_in: $range,
				search: (string) $request['search'],
				order_by: (string) $request['orderby'],
				descending: 'desc' === $request['order'],
				limit: $per_page,
				offset: ( $page - 1 ) * $per_page
			);
			$repository = $this->services()->bookings();
			$total      = $repository->count( $criteria );
			$bookings   = $repository->search( $criteria );
		} catch ( DomainError | DatabaseError $e ) {
			return ErrorMapper::to_wp_error( $e );
		}

		$items = array();
		foreach ( $bookings as $booking ) {
			$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $booking, $request ) );
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * Returns one booking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$booking = $this->services()->bookings()->get( (int) $request['id'] );
		if ( null === $booking ) {
			return self::not_found();
		}
		return $this->prepare_item_for_response( $booking, $request );
	}

	/**
	 * Confirms a booking (pending / pending_payment → confirmed).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function confirm_item( $request ) {
		return $this->change_status( $request, BookingStatus::Confirmed );
	}

	/**
	 * Cancels a booking (releases the slot).
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_item( $request ) {
		return $this->change_status( $request, BookingStatus::Cancelled );
	}

	/**
	 * Moves a booking to another start (and optionally resource) atomically.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function reschedule_item( $request ) {
		$services = $this->services();
		$booking  = $services->bookings()->get( (int) $request['id'] );
		if ( null === $booking ) {
			return self::not_found();
		}
		if ( ! $booking->status->is_active() ) {
			return ErrorMapper::to_wp_error( new InvalidStatusTransition( 'Only active bookings can be rescheduled.' ) );
		}

		$start = BookingsController::parse_start( (string) $request['start'] );
		if ( null === $start ) {
			return new WP_Error( 'trmz_invalid_date', __( 'Please provide a valid date and time.', 'terminarz' ), array( 'status' => 400 ) );
		}

		$resource_id = isset( $request['resource'] ) ? (int) $request['resource'] : null;
		if ( null !== $resource_id && $resource_id !== $booking->resource_id && ! in_array( $resource_id, $services->services()->resource_ids( $booking->service_id ), true ) ) {
			return AvailabilityController::resource_error();
		}

		try {
			$moved = $services->booking_service()->reschedule( $booking->id ?? 0, $start, $resource_id );
		} catch ( InvalidValue $e ) {
			return ErrorMapper::to_wp_error( $e, array( InvalidValue::class => __( 'The booking cannot be moved to this time.', 'terminarz' ) ) );
		} catch ( DomainError | DatabaseError $e ) {
			return ErrorMapper::to_wp_error( $e );
		}

		return $this->prepare_item_for_response( $moved, $request );
	}

	/**
	 * Applies a status change.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param BookingStatus   $target  Target status.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	private function change_status( WP_REST_Request $request, BookingStatus $target ) {
		$id = (int) $request['id'];
		if ( null === $this->services()->bookings()->get( $id ) ) {
			return self::not_found();
		}

		try {
			$changed = $this->services()->booking_service()->change_status( $id, $target );
		} catch ( DomainError | DatabaseError $e ) {
			return ErrorMapper::to_wp_error( $e );
		}

		return $this->prepare_item_for_response( $changed, $request );
	}

	/**
	 * Converts optional local dates into a UTC range of starts ([from 00:00, day after `to` 00:00) in the site time zone).
	 *
	 * @param mixed $from First local date or null.
	 * @param mixed $to   Last local date or null.
	 * @return TimeRange|WP_Error|null
	 */
	private function date_range( mixed $from, mixed $to ): TimeRange|WP_Error|null {
		if ( null === $from && null === $to ) {
			return null;
		}
		$tz    = $this->services()->availability_settings()->timezone;
		$start = null === $from ? DateTimeImmutable::createFromFormat( '!Y-m-d', '1970-01-01', $tz ) : DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $from, $tz );
		$end   = null === $to ? DateTimeImmutable::createFromFormat( '!Y-m-d', '9999-12-30', $tz ) : DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $to, $tz );

		if ( false === $start || false === $end
			|| ( null !== $from && $start->format( 'Y-m-d' ) !== $from )
			|| ( null !== $to && $end->format( 'Y-m-d' ) !== $to ) ) {
			return new WP_Error( 'trmz_invalid_date', __( 'Please provide valid dates.', 'terminarz' ), array( 'status' => 400 ) );
		}
		$end = $end->modify( '+1 day' );
		if ( $end <= $start ) {
			return new WP_Error( 'trmz_invalid_range', __( 'The end date must not be earlier than the start date.', 'terminarz' ), array( 'status' => 400 ) );
		}
		return new TimeRange( $start, $end );
	}

	/**
	 * Error for a missing booking.
	 */
	private static function not_found(): WP_Error {
		return new WP_Error( 'trmz_booking_not_found', __( 'The booking does not exist.', 'terminarz' ), array( 'status' => 404 ) );
	}

	/**
	 * Admin representation of a booking.
	 *
	 * @param Booking         $item    Booking.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response {
		$data = array(
			'id'                   => (int) $item->id,
			'public_id'            => (string) $item->public_id,
			'status'               => $item->status->value,
			'allowed_transitions'  => array_map( static fn( BookingStatus $s ): string => $s->value, $item->status->allowed_transitions() ),
			'service'              => $item->service_id,
			'resource'             => $item->resource_id,
			'start'                => $this->local_iso( $item->range->start ),
			'end'                  => $this->local_iso( $item->range->end ),
			'start_utc'            => self::utc_iso( $item->range->start ),
			'end_utc'              => self::utc_iso( $item->range->end ),
			'buffer_after_minutes' => $item->buffer_after_minutes,
			'customer'             => array(
				'name'    => $item->customer->name,
				'email'   => $item->customer->email,
				'phone'   => $item->customer->phone,
				'note'    => $item->customer->note,
				'user_id' => $item->customer->user_id,
			),
			'order_id'             => $item->order_id,
			'hold_expires_at'      => null === $item->hold_expires_at ? null : self::utc_iso( $item->hold_expires_at ),
			'created_at'           => null === $item->created_at ? null : self::utc_iso( $item->created_at ),
		);

		$data = $this->add_additional_fields_to_object( $data, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Collection parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_collection_params(): array {
		return array(
			'page'     => array(
				'description' => __( 'Page of the listing.', 'terminarz' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
			'per_page' => array(
				'description' => __( 'Bookings per page.', 'terminarz' ),
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => BookingCriteria::MAX_LIMIT,
			),
			'status'   => array(
				'description' => __( 'Only these statuses.', 'terminarz' ),
				'type'        => 'array',
				'items'       => array(
					'type' => 'string',
					'enum' => array_map( static fn( BookingStatus $s ): string => $s->value, BookingStatus::cases() ),
				),
			),
			'service'  => array(
				'description' => __( 'Only this service.', 'terminarz' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'resource' => array(
				'description' => __( 'Only this resource.', 'terminarz' ),
				'type'        => 'integer',
				'minimum'     => 1,
			),
			'from'     => array(
				'description' => __( 'Only bookings starting on or after this day (site time zone), Y-m-d.', 'terminarz' ),
				'type'        => 'string',
				'pattern'     => '^\d{4}-\d{2}-\d{2}$',
			),
			'to'       => array(
				'description' => __( 'Only bookings starting on or before this day (site time zone), Y-m-d.', 'terminarz' ),
				'type'        => 'string',
				'pattern'     => '^\d{4}-\d{2}-\d{2}$',
			),
			'search'   => array(
				'description'       => __( 'Part of the customer name or e-mail, or the public booking ID.', 'terminarz' ),
				'type'              => 'string',
				'default'           => '',
				'maxLength'         => 191,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => static fn( $value ): string => sanitize_text_field( (string) $value ),
			),
			'orderby'  => array(
				'description' => __( 'Sort by start time or creation time.', 'terminarz' ),
				'type'        => 'string',
				'enum'        => array( BookingCriteria::ORDER_START, BookingCriteria::ORDER_CREATED ),
				'default'     => BookingCriteria::ORDER_START,
			),
			'order'    => array(
				'description' => __( 'Sort direction.', 'terminarz' ),
				'type'        => 'string',
				'enum'        => array( 'asc', 'desc' ),
				'default'     => 'asc',
			),
		);
	}

	/**
	 * Schema of the admin booking representation.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$statuses     = array_map( static fn( BookingStatus $s ): string => $s->value, BookingStatus::cases() );
		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'trmz-booking',
			'type'       => 'object',
			'properties' => array(
				'id'                   => array(
					'description' => __( 'Booking ID.', 'terminarz' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'public_id'            => array(
					'description' => __( 'Public booking identifier.', 'terminarz' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'status'               => array(
					'type'     => 'string',
					'enum'     => $statuses,
					'readonly' => true,
				),
				'allowed_transitions'  => array(
					'description' => __( 'Statuses the booking can be moved to.', 'terminarz' ),
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => $statuses,
					),
					'readonly'    => true,
				),
				'service'              => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'resource'             => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'start'                => array(
					'type'     => 'string',
					'format'   => 'date-time',
					'readonly' => true,
				),
				'end'                  => array(
					'type'     => 'string',
					'format'   => 'date-time',
					'readonly' => true,
				),
				'start_utc'            => array(
					'type'     => 'string',
					'format'   => 'date-time',
					'readonly' => true,
				),
				'end_utc'              => array(
					'type'     => 'string',
					'format'   => 'date-time',
					'readonly' => true,
				),
				'buffer_after_minutes' => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'customer'             => array(
					'type'       => 'object',
					'readonly'   => true,
					'properties' => array(
						'name'    => array( 'type' => 'string' ),
						'email'   => array( 'type' => 'string' ),
						'phone'   => array( 'type' => 'string' ),
						'note'    => array( 'type' => 'string' ),
						'user_id' => array( 'type' => array( 'integer', 'null' ) ),
					),
				),
				'order_id'             => array(
					'type'     => array( 'integer', 'null' ),
					'readonly' => true,
				),
				'hold_expires_at'      => array(
					'type'     => array( 'string', 'null' ),
					'format'   => 'date-time',
					'readonly' => true,
				),
				'created_at'           => array(
					'type'     => array( 'string', 'null' ),
					'format'   => 'date-time',
					'readonly' => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
