<?php
/**
 * Public availability endpoint.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Rest;

defined( 'ABSPATH' ) || exit; // No direct access.

use DateTimeImmutable;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\Slot;
use Terminarz\Infrastructure\Database\DatabaseError;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET /terminarz/v1/availability?service=&resource=<id>|any&from=Y-m-d&to=Y-m-d` — free slots grouped by local day.
 *
 * Dates are local dates of the site time zone (inclusive, at most 31 days). Every slot carries its start/end as ISO 8601
 * with the site offset plus the start in UTC. With `resource=any` there is one slot per start (the resource is picked
 * at booking time), so `resource` of a slot is null.
 */
final class AvailabilityController extends Controller {

	public const MAX_DAYS = 31;

	private const DATE_PATTERN = '^\d{4}-\d{2}-\d{2}$';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->rest_base = 'availability';
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
	 * Free slots in the requested range.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$services   = $this->services();
		$service_id = (int) $request['service'];
		$resource   = (string) $request['resource'];
		$from       = (string) $request['from'];
		$to         = (string) $request['to'];

		$range_error = self::validate_range( $from, $to );
		if ( null !== $range_error ) {
			return $range_error;
		}

		$service = $services->services()->get( $service_id );
		if ( null === $service || ! $service->is_active ) {
			return ServicesController::not_found();
		}

		$resource_id = null;
		if ( 'any' !== $resource ) {
			$resource_id = (int) $resource;
			$bookable    = $services->resources()->get( $resource_id );
			if ( null === $bookable || ! $bookable->is_active || ! in_array( $resource_id, $services->services()->resource_ids( $service_id ), true ) ) {
				return self::resource_error();
			}
		}

		try {
			$slots = null === $resource_id
				? $services->availability_service()->any_resource_slots( $service_id, $from, $to )
				: $services->availability_service()->slots( $service_id, $from, $to, $resource_id );
		} catch ( EntityNotFound $e ) {
			return ServicesController::not_found();
		} catch ( InvalidValue | DatabaseError $e ) {
			return ErrorMapper::to_wp_error( $e );
		}

		$response = rest_ensure_response( $this->build( $service_id, null === $resource_id ? 'any' : $resource_id, $from, $to, $slots ) );
		$this->add_cache_headers( $response );

		return $response;
	}

	/**
	 * Error for a resource that does not perform the service (missing, inactive or not assigned).
	 */
	public static function resource_error(): WP_Error {
		return new WP_Error(
			'trmz_invalid_resource',
			__( 'The selected person or resource does not provide this service.', 'terminarz' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Validates the date range: real dates, `to` not before `from`, at most MAX_DAYS days.
	 *
	 * @param string $from First local date.
	 * @param string $to   Last local date.
	 */
	private static function validate_range( string $from, string $to ): ?WP_Error {
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $from );
		$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $to );
		if ( false === $start || false === $end || $start->format( 'Y-m-d' ) !== $from || $end->format( 'Y-m-d' ) !== $to ) {
			return new WP_Error( 'trmz_invalid_date', __( 'Please provide valid dates.', 'terminarz' ), array( 'status' => 400 ) );
		}
		if ( $end < $start ) {
			return new WP_Error( 'trmz_invalid_range', __( 'The end date must not be earlier than the start date.', 'terminarz' ), array( 'status' => 400 ) );
		}
		if ( (int) $start->diff( $end )->days + 1 > self::MAX_DAYS ) {
			return new WP_Error(
				'trmz_invalid_range',
				/* translators: %d: maximum number of days. */
				sprintf( __( 'The date range may cover at most %d days.', 'terminarz' ), self::MAX_DAYS ),
				array( 'status' => 400 )
			);
		}
		return null;
	}

	/**
	 * Builds the response body: every local day of the range, each with its slots.
	 *
	 * @param int        $service_id Service ID.
	 * @param int|string $target     Resource ID or "any".
	 * @param string     $from       First local date.
	 * @param string     $to         Last local date.
	 * @param Slot[]     $slots      Slots sorted by start.
	 * @return array<string, mixed>
	 */
	private function build( int $service_id, int|string $target, string $from, string $to, array $slots ): array {
		$tz   = $this->services()->availability_settings()->timezone;
		$days = array();
		$day  = new DateTimeImmutable( $from, $tz );
		while ( $day->format( 'Y-m-d' ) <= $to ) {
			$days[ $day->format( 'Y-m-d' ) ] = array();
			$day                             = $day->modify( '+1 day' );
		}

		foreach ( $slots as $slot ) {
			$date = $slot->start()->setTimezone( $tz )->format( 'Y-m-d' );
			if ( ! isset( $days[ $date ] ) ) {
				continue;
			}
			$days[ $date ][] = array(
				'start'     => $this->local_iso( $slot->start() ),
				'end'       => $this->local_iso( $slot->end() ),
				'start_utc' => self::utc_iso( $slot->start() ),
				'resource'  => 'any' === $target ? null : $slot->resource_id,
			);
		}

		$out = array();
		foreach ( $days as $date => $day_slots ) {
			$out[] = array(
				'date'  => $date,
				'slots' => $day_slots,
			);
		}

		return array(
			'service'  => $service_id,
			'resource' => $target,
			'timezone' => $tz->getName(),
			'from'     => $from,
			'to'       => $to,
			'days'     => $out,
		);
	}

	/**
	 * Availability changes with every booking: not cached by default (`no-store`). A site may allow a short
	 * shared cache with the `trmz_availability_cache_max_age` filter (seconds; the booking itself is re-checked anyway).
	 *
	 * @param WP_REST_Response $response Response.
	 */
	private function add_cache_headers( WP_REST_Response $response ): void {
		/**
		 * Filters the max-age (seconds) of availability responses. 0 (default) = `Cache-Control: no-store`.
		 *
		 * @param int $max_age Seconds.
		 */
		$max_age = (int) apply_filters( 'trmz_availability_cache_max_age', 0 );
		$response->header( 'Cache-Control', $max_age > 0 ? sprintf( 'public, max-age=%d', $max_age ) : 'no-store' );
	}

	/**
	 * Query parameters.
	 *
	 * @return array<string, mixed>
	 */
	public function get_collection_params(): array {
		return array(
			'service'  => array(
				'description' => __( 'Service ID.', 'terminarz' ),
				'type'        => 'integer',
				'minimum'     => 1,
				'required'    => true,
			),
			'resource' => array(
				'description' => __( 'Resource ID or "any".', 'terminarz' ),
				'type'        => 'string',
				'pattern'     => '^(any|[1-9][0-9]*)$',
				'default'     => 'any',
			),
			'from'     => array(
				'description' => __( 'First day (site time zone), Y-m-d.', 'terminarz' ),
				'type'        => 'string',
				'pattern'     => self::DATE_PATTERN,
				'required'    => true,
			),
			'to'       => array(
				'description' => __( 'Last day (site time zone, inclusive), Y-m-d. At most 31 days after the first one.', 'terminarz' ),
				'type'        => 'string',
				'pattern'     => self::DATE_PATTERN,
				'required'    => true,
			),
		);
	}

	/**
	 * Schema of the availability response.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'trmz-availability',
			'type'       => 'object',
			'properties' => array(
				'service'  => array(
					'description' => __( 'Service ID.', 'terminarz' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'resource' => array(
					'description' => __( 'Resource ID or "any".', 'terminarz' ),
					'type'        => array( 'integer', 'string' ),
					'readonly'    => true,
				),
				'timezone' => array(
					'description' => __( 'Site time zone.', 'terminarz' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'from'     => array(
					'type'     => 'string',
					'format'   => 'date',
					'readonly' => true,
				),
				'to'       => array(
					'type'     => 'string',
					'format'   => 'date',
					'readonly' => true,
				),
				'days'     => array(
					'description' => __( 'Every day of the range with its free slots.', 'terminarz' ),
					'type'        => 'array',
					'readonly'    => true,
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'date'  => array(
								'type'   => 'string',
								'format' => 'date',
							),
							'slots' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'start'     => array(
											'description' => __( 'Start, ISO 8601 with the site offset.', 'terminarz' ),
											'type'        => 'string',
											'format'      => 'date-time',
										),
										'end'       => array(
											'description' => __( 'End, ISO 8601 with the site offset.', 'terminarz' ),
											'type'        => 'string',
											'format'      => 'date-time',
										),
										'start_utc' => array(
											'description' => __( 'Start in UTC, ISO 8601.', 'terminarz' ),
											'type'        => 'string',
											'format'      => 'date-time',
										),
										'resource'  => array(
											'description' => __( 'Resource ID; null when any resource was requested.', 'terminarz' ),
											'type'        => array( 'integer', 'null' ),
										),
									),
								),
							),
						),
					),
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
