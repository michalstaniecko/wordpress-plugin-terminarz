<?php
/**
 * Public booking creation.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Rest;

use DateTimeImmutable;
use Terminarz\Domain\Exception\DomainError;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Customer;
use Terminarz\Domain\Model\Service;
use Terminarz\Infrastructure\Database\DatabaseError;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `POST /terminarz/v1/bookings` — a customer books a slot (specific resource or "any").
 *
 * The slot is re-checked on the server (schedule, lead time, horizon, collisions) and taken atomically
 * by BookingService. The response identifies the booking by its random `public_id`, never by the sequential ID,
 * and does not contain the cancellation token (it is delivered by e-mail, M7).
 */
final class BookingsController extends Controller {

	public const START_PATTERN = '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})$';

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
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
					'args'                => $this->get_create_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Anyone may book, but a logged-in user (cookie authentication) must send a valid `wp_rest` nonce, so that
	 * a third-party page cannot book in the user's name (CSRF). Request limits are added by #19.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( is_user_logged_in() && ! did_action( 'application_password_did_authenticate' ) ) {
			$nonce = $request->get_header( 'X-WP-Nonce' ) ?? $request->get_param( '_wpnonce' );
			if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new WP_Error( 'rest_cookie_invalid_nonce', __( 'Your session has expired. Please reload the page and try again.', 'terminarz' ), array( 'status' => 403 ) );
			}
		}

		return true;
	}

	/**
	 * Creates the booking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		// Honeypot: the field is hidden from people; a bot filling every field gets a generic error without details.
		if ( '' !== trim( (string) $request['website'] ) ) {
			return new WP_Error( 'trmz_rejected', __( 'The booking could not be processed.', 'terminarz' ), array( 'status' => 400 ) );
		}
		if ( true !== $request['consent'] ) {
			return new WP_Error( 'trmz_consent_required', __( 'Please accept the terms to book an appointment.', 'terminarz' ), array( 'status' => 400 ) );
		}

		$start = self::parse_start( (string) $request['start'] );
		if ( null === $start ) {
			return new WP_Error( 'trmz_invalid_date', __( 'Please provide a valid date and time.', 'terminarz' ), array( 'status' => 400 ) );
		}

		$services   = $this->services();
		$service_id = (int) $request['service'];
		$service    = $services->services()->get( $service_id );
		if ( null === $service || ! $service->is_active ) {
			return ServicesController::not_found();
		}

		$resource_id = 'any' === $request['resource'] ? null : (int) $request['resource'];
		if ( null !== $resource_id && ! in_array( $resource_id, $services->services()->resource_ids( $service_id ), true ) ) {
			return AvailabilityController::resource_error();
		}

		$name  = (string) $request['name'];
		$email = (string) $request['email'];
		if ( '' === $name || '' === $email ) {
			return new WP_Error( 'trmz_invalid_customer', __( 'Please provide your name and a valid e-mail address.', 'terminarz' ), array( 'status' => 400 ) );
		}

		try {
			$customer = new Customer( $name, $email, (string) $request['phone'], (string) $request['note'], get_current_user_id() > 0 ? get_current_user_id() : null );
			$status   = $this->initial_status( $service );
			$booking  = null === $resource_id
				? $services->booking_service()->reserve_any( $service_id, $start, $customer, $status )->booking
				: $services->booking_service()->reserve( $service_id, $resource_id, $start, $customer, $status )->booking;
		} catch ( InvalidValue $e ) {
			return ErrorMapper::to_wp_error( $e, array( InvalidValue::class => __( 'The booking data is not valid.', 'terminarz' ) ) );
		} catch ( EntityNotFound $e ) {
			return ServicesController::not_found();
		} catch ( DomainError | DatabaseError $e ) {
			return ErrorMapper::to_wp_error( $e );
		}

		$response = $this->prepare_item_for_response( $booking, $request );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Status of a new booking: `confirmed` when bookings are confirmed automatically, otherwise `pending`
	 * (awaiting the business). Payments (`pending_payment`) come with the WooCommerce integration (M6).
	 *
	 * @param Service $service Booked service.
	 */
	private function initial_status( Service $service ): BookingStatus {
		/**
		 * Filters whether new bookings are confirmed automatically (default: the "Automatic confirmation" setting,
		 * off by default — the business confirms them).
		 *
		 * @param bool    $auto_confirm Auto-confirm.
		 * @param Service $service      Booked service.
		 */
		$auto_confirm = (bool) apply_filters( 'trmz_auto_confirm_bookings', $this->services()->settings()->auto_confirm(), $service );

		return $auto_confirm ? BookingStatus::Confirmed : BookingStatus::Pending;
	}

	/**
	 * Parses an ISO 8601 start with an explicit offset (local wall-clock times without offset are ambiguous around DST).
	 *
	 * @param string $value Value.
	 */
	public static function parse_start( string $value ): ?DateTimeImmutable {
		if ( 1 !== preg_match( '/' . self::START_PATTERN . '/', $value ) ) {
			return null;
		}
		// Normalise to "Y-m-d\TH:i:sP": add missing seconds, "Z" → "+00:00".
		$normalized = (string) preg_replace( '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})(?=Z|[+-])/', '$1:00', $value );
		$normalized = str_replace( 'Z', '+00:00', $normalized );
		$time       = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:sP', $normalized );
		if ( false === $time || $time->format( 'Y-m-d\TH:i:sP' ) !== $normalized ) {
			return null;
		}
		return $time;
	}

	/**
	 * Public representation of the created booking (no customer data, no internal ID, no token).
	 *
	 * @param Booking         $item    Booking.
	 * @param WP_REST_Request $request Request.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response {
		$data = array(
			'public_id' => (string) $item->public_id,
			'status'    => $item->status->value,
			'service'   => $item->service_id,
			'resource'  => $item->resource_id,
			'start'     => $this->local_iso( $item->range->start ),
			'end'       => $this->local_iso( $item->range->end ),
			'start_utc' => self::utc_iso( $item->range->start ),
		);

		$data = $this->add_additional_fields_to_object( $data, $request );

		return rest_ensure_response( $data );
	}

	/**
	 * Arguments of POST /bookings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_create_params(): array {
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
			'start'    => array(
				'description' => __( 'Start of the appointment, ISO 8601 with a time zone offset (e.g. the start_utc of a slot).', 'terminarz' ),
				'type'        => 'string',
				'pattern'     => self::START_PATTERN,
				'required'    => true,
			),
			'name'     => self::text_arg( __( 'Full name.', 'terminarz' ), 191, true ),
			'email'    => array(
				'description'       => __( 'E-mail address.', 'terminarz' ),
				'type'              => 'string',
				'format'            => 'email',
				'maxLength'         => 191,
				'required'          => true,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => static fn( $value ): string => sanitize_email( (string) $value ),
			),
			'phone'    => self::text_arg( __( 'Phone number.', 'terminarz' ), 50, false, '^[0-9+()./ -]*$' ),
			'note'     => array(
				'description'       => __( 'Note for the business.', 'terminarz' ),
				'type'              => 'string',
				'maxLength'         => 1000,
				'default'           => '',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => static fn( $value ): string => sanitize_textarea_field( (string) $value ),
			),
			'consent'  => array(
				'description' => __( 'Consent to the processing of personal data for the booking; must be true.', 'terminarz' ),
				'type'        => 'boolean',
				'required'    => true,
			),
			'website'  => array(
				'description' => __( 'Leave empty (anti-spam field).', 'terminarz' ),
				'type'        => 'string',
				'default'     => '',
			),
		);
	}

	/**
	 * A plain-text argument, sanitised with sanitize_text_field() after schema validation.
	 *
	 * @param string      $description Description.
	 * @param int         $max_length  Maximum length.
	 * @param bool        $required    Required.
	 * @param string|null $pattern     Optional pattern.
	 * @return array<string, mixed>
	 */
	private static function text_arg( string $description, int $max_length, bool $required, ?string $pattern = null ): array {
		$arg = array(
			'description'       => $description,
			'type'              => 'string',
			'maxLength'         => $max_length,
			'required'          => $required,
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => static fn( $value ): string => sanitize_text_field( (string) $value ),
		);
		if ( ! $required ) {
			$arg['default'] = '';
		}
		if ( null !== $pattern ) {
			$arg['pattern'] = $pattern;
		}
		return $arg;
	}

	/**
	 * Schema of the created booking.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'trmz-booking-public',
			'type'       => 'object',
			'properties' => array(
				'public_id' => array(
					'description' => __( 'Public booking identifier.', 'terminarz' ),
					'type'        => 'string',
					'readonly'    => true,
				),
				'status'    => array(
					'description' => __( 'Booking status.', 'terminarz' ),
					'type'        => 'string',
					'enum'        => array_map( static fn( BookingStatus $s ): string => $s->value, BookingStatus::cases() ),
					'readonly'    => true,
				),
				'service'   => array(
					'type'     => 'integer',
					'readonly' => true,
				),
				'resource'  => array(
					'description' => __( 'Resource assigned to the booking.', 'terminarz' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'start'     => array(
					'type'     => 'string',
					'format'   => 'date-time',
					'readonly' => true,
				),
				'end'       => array(
					'type'     => 'string',
					'format'   => 'date-time',
					'readonly' => true,
				),
				'start_utc' => array(
					'type'     => 'string',
					'format'   => 'date-time',
					'readonly' => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}
}
