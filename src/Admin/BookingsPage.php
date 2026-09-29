<?php
/**
 * Bookings screen.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use DateInterval;
use DateTimeImmutable;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Exception\InvalidStatusTransition;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Exception\SlotUnavailable;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\Slot;
use Terminarz\Infrastructure\Capabilities;

/**
 * "Terminarz → Bookings" (`admin.php?page=trmz-bookings`), the first screen of the menu:
 *
 * - list with status views, filters (service, resource, dates), search (name, e-mail, public ID), sorting and paging;
 * - details (`view=view&id=`);
 * - reschedule (`view=reschedule&id=&date=`): free slots of the chosen local day from the availability engine, with the
 *   booking's own time excluded; saved atomically by `BookingService::reschedule()` (a lost race → message, no change);
 * - confirm / cancel through the booking state machine (`BookingService::change_status()`).
 */
class BookingsPage extends Screen {

	public const SLUG = 'trmz-bookings';

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function menu_title(): string {
		return __( 'Bookings', 'terminarz' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function page_title(): string {
		return __( 'Bookings', 'terminarz' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function position(): int {
		return 10;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function actions(): array {
		return array(
			'confirm_booking'    => 'confirm',
			'cancel_booking'     => 'cancel',
			'reschedule_booking' => 'reschedule',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		parent::register();
		add_action( 'admin_post_trmz_export_bookings', array( $this, 'export' ) );
	}

	/**
	 * `admin_post_trmz_export_bookings`: streams the bookings matching the list filters as a CSV download.
	 */
	public function export(): void {
		$filters  = $this->authorize_export();
		$exporter = new BookingsCsvExporter( $this->services() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $exporter->filename() . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming the response.
		if ( false !== $output ) {
			$exporter->write( $output, $filters );
			fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming the response.
		}
		exit;
	}

	/**
	 * Checks capability and nonce of an export request and returns its filters (`wp_die()` on failure).
	 */
	public function authorize_export(): BookingFilters {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'terminarz' ), 403 );
		}
		check_admin_referer( 'trmz_export_bookings' );

		// Filters are sanitized by BookingFilters::from_query().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- See above; nonce verified.
		return BookingFilters::from_query( wp_unslash( $_GET ) );
	}

	/**
	 * Handler: confirm a booking.
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function confirm( array $request ): string {
		return $this->change_status( Input::absint( $request, 'id' ), BookingStatus::Confirmed, __( 'Booking confirmed.', 'terminarz' ) );
	}

	/**
	 * Handler: cancel a booking (releases the slot).
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function cancel( array $request ): string {
		return $this->change_status( Input::absint( $request, 'id' ), BookingStatus::Cancelled, __( 'Booking cancelled.', 'terminarz' ) );
	}

	/**
	 * Handler: move a booking to the chosen slot (`slot` = "<UTC timestamp>:<resource ID>").
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function reschedule( array $request ): string {
		$id      = Input::absint( $request, 'id' );
		$booking = $this->services()->bookings()->get( $id );
		if ( null === $booking ) {
			Notices::error( __( 'The booking does not exist.', 'terminarz' ) );
			return $this->url();
		}

		$date = Input::date( $request, 'date' );
		$back = $this->url(
			array_filter(
				array(
					'view' => 'reschedule',
					'id'   => $id,
					'date' => $date,
				)
			)
		);
		if ( 1 !== preg_match( '/^(\d{1,12}):(\d{1,20})$/', Input::text( $request, 'slot' ), $matches ) ) {
			Notices::error( __( 'Choose a new time.', 'terminarz' ) );
			return $back;
		}
		$start       = ( new DateTimeImmutable( '@' . $matches[1] ) );
		$resource_id = (int) $matches[2];

		try {
			$moved = $this->services()->booking_service()->reschedule( $id, $start, $resource_id );
		} catch ( SlotUnavailable $e ) {
			Notices::error( __( 'The selected time is no longer available. Please choose another one.', 'terminarz' ) );
			return $back;
		} catch ( InvalidValue | EntityNotFound $e ) {
			Notices::error( __( 'This booking cannot be moved to the selected time (it is no longer active or the resource does not perform the service).', 'terminarz' ) );
			return $back;
		}

		Notices::success(
			sprintf(
				/* translators: %s: new date and time. */
				__( 'Booking moved to %s.', 'terminarz' ),
				Labels::datetime( $moved->range->start )
			)
		);
		return $this->url(
			array(
				'view' => 'view',
				'id'   => $id,
			)
		);
	}

	/**
	 * Free slots for moving a booking on one local day: every resource of the service, own time excluded.
	 *
	 * @param Booking $booking Booking.
	 * @param string  $date    Local date (Y-m-d).
	 * @return Slot[]
	 */
	public function reschedule_slots( Booking $booking, string $date ): array {
		try {
			return $this->services()->availability_service()->slots( $booking->service_id, $date, $date, null, $booking->id );
		} catch ( InvalidValue | EntityNotFound $e ) {
			return array();
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function render_view(): void {
		switch ( $this->current_view() ) {
			case 'view':
				$this->render_details();
				return;
			case 'reschedule':
				$this->render_reschedule();
				return;
			default:
				$this->render_list();
		}
	}

	/**
	 * Applies a status change and reports the result.
	 *
	 * @param int           $id      Booking ID.
	 * @param BookingStatus $target  Target status.
	 * @param string        $success Success message.
	 */
	private function change_status( int $id, BookingStatus $target, string $success ): string {
		try {
			$this->services()->booking_service()->change_status( $id, $target );
			Notices::success( $success );
		} catch ( EntityNotFound $e ) {
			Notices::error( __( 'The booking does not exist.', 'terminarz' ) );
		} catch ( InvalidStatusTransition $e ) {
			Notices::error( __( 'This action is not possible for the booking in its current status.', 'terminarz' ) );
		}
		return $this->back_url();
	}

	/**
	 * Where to return after an action: the booking screen the request came from (list with its filters or details),
	 * otherwise the list.
	 */
	private function back_url(): string {
		$referer = wp_get_referer();
		if ( is_string( $referer ) && str_contains( $referer, 'page=' . self::SLUG ) && ! str_contains( $referer, 'view=reschedule' ) ) {
			return $referer;
		}
		return $this->url();
	}

	/**
	 * List view.
	 */
	private function render_list(): void {
		$this->heading( __( 'Bookings', 'terminarz' ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$filters = BookingFilters::from_query( wp_unslash( $_GET ) );

		self::load_list_table();
		$table = new BookingsListTable( $this, $filters );
		$table->prepare_items();

		$table->views();
		echo '<form method="get" id="trmz-bookings-filter">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		$table->search_box( __( 'Search bookings', 'terminarz' ), 'trmz-booking' );
		$table->display();
		echo '</form>';
		echo '<p class="description trmz-timezone">' . esc_html( Labels::timezone_notice() ) . '</p>';
	}

	/**
	 * Booking by the `id` query argument, or an error message.
	 */
	private function requested_booking(): ?Booking {
		$booking = $this->services()->bookings()->get( $this->query_int( 'id' ) );
		if ( null === $booking ) {
			$this->heading( __( 'Booking', 'terminarz' ) );
			echo '<p>' . esc_html__( 'The booking does not exist.', 'terminarz' ) . ' <a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Back to bookings', 'terminarz' ) . '</a></p>';
		}
		return $booking;
	}

	/**
	 * Details view.
	 */
	private function render_details(): void {
		$booking = $this->requested_booking();
		if ( null === $booking ) {
			return;
		}
		$services = $this->services();
		$service  = $services->services()->get( $booking->service_id );
		$resource = $services->resources()->get( $booking->resource_id );

		$this->heading( __( 'Booking details', 'terminarz' ) );
		echo '<p><a href="' . esc_url( $this->url() ) . '">' . esc_html__( '&larr; Back to bookings', 'terminarz' ) . '</a></p>';

		$rows = array(
			__( 'Booking ID', 'terminarz' )   => '<code>' . esc_html( (string) $booking->public_id ) . '</code>',
			__( 'Status', 'terminarz' )       => esc_html( Labels::status( $booking->status ) ),
			__( 'Date', 'terminarz' )         => esc_html( Labels::datetime( $booking->range->start ) . '–' . Labels::time( $booking->range->end ) ),
			__( 'Service', 'terminarz' )      => esc_html( null === $service ? '#' . $booking->service_id : $service->name ),
			__( 'Resource', 'terminarz' )     => esc_html( null === $resource ? '#' . $booking->resource_id : $resource->name ),
			__( 'Buffer after', 'terminarz' ) => esc_html(
				/* translators: %d: number of minutes. */
				sprintf( _n( '%d minute', '%d minutes', $booking->buffer_after_minutes, 'terminarz' ), $booking->buffer_after_minutes )
			),
			__( 'Customer', 'terminarz' )     => esc_html( $booking->customer->name ),
			__( 'E-mail', 'terminarz' )       => '<a href="' . esc_url( 'mailto:' . $booking->customer->email ) . '">' . esc_html( $booking->customer->email ) . '</a>',
			__( 'Phone', 'terminarz' )        => '' === $booking->customer->phone ? '&mdash;' : esc_html( $booking->customer->phone ),
			__( 'Note', 'terminarz' )         => '' === $booking->customer->note ? '&mdash;' : nl2br( esc_html( $booking->customer->note ) ),
			__( 'Account', 'terminarz' )      => $this->account_link( $booking->customer->user_id ),
			__( 'Booked on', 'terminarz' )    => null === $booking->created_at ? '&mdash;' : esc_html( Labels::datetime( $booking->created_at ) ),
		);
		if ( null !== $booking->order_id ) {
			$rows[ __( 'Order', 'terminarz' ) ] = esc_html( '#' . $booking->order_id );
		}
		if ( null !== $booking->hold_expires_at && BookingStatus::PendingPayment === $booking->status ) {
			$rows[ __( 'Held until', 'terminarz' ) ] = esc_html( Labels::datetime( $booking->hold_expires_at ) );
		}

		/**
		 * Filters the rows of the booking details screen (the WooCommerce integration links the order).
		 *
		 * @param array<string, string> $rows    Label => value as escaped HTML (escape everything you add).
		 * @param Booking               $booking Booking.
		 */
		$rows = (array) apply_filters( 'trmz_admin_booking_details_rows', $rows, $booking );

		echo '<table class="form-table trmz-booking-details" role="presentation"><tbody>';
		foreach ( $rows as $label => $html ) {
			// Values are escaped above.
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</tbody></table>';

		$this->render_action_buttons( $booking );
	}

	/**
	 * Confirm / reschedule / cancel buttons of the details view.
	 *
	 * @param Booking $booking Booking.
	 */
	private function render_action_buttons( Booking $booking ): void {
		echo '<div class="trmz-booking-actions">';
		if ( $booking->status->can_transition_to( BookingStatus::Confirmed ) ) {
			$this->form_open( 'confirm_booking' );
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $booking->id ) . '" />';
			submit_button( __( 'Confirm booking', 'terminarz' ), 'primary', 'submit', false );
			echo '</form> ';
		}
		if ( $booking->status->is_active() ) {
			echo '<a class="button" href="' . esc_url(
				$this->url(
					array(
						'view' => 'reschedule',
						'id'   => (int) $booking->id,
					)
				)
			) . '">' . esc_html__( 'Reschedule', 'terminarz' ) . '</a> ';
		}
		if ( $booking->status->can_transition_to( BookingStatus::Cancelled ) ) {
			$this->form_open( 'cancel_booking' );
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $booking->id ) . '" />';
			submit_button(
				__( 'Cancel booking', 'terminarz' ),
				'delete',
				'submit',
				false,
				array( 'onclick' => 'return window.confirm(' . wp_json_encode( __( 'Cancel this booking?', 'terminarz' ) ) . ');' )
			);
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * Reschedule view: day picker and free slots.
	 */
	private function render_reschedule(): void {
		$booking = $this->requested_booking();
		if ( null === $booking ) {
			return;
		}

		$this->heading( __( 'Reschedule booking', 'terminarz' ) );
		$details = $this->url(
			array(
				'view' => 'view',
				'id'   => (int) $booking->id,
			)
		);
		echo '<p><a href="' . esc_url( $details ) . '">' . esc_html__( '&larr; Back to the booking', 'terminarz' ) . '</a></p>';

		if ( ! $booking->status->is_active() ) {
			echo '<p>' . esc_html__( 'Only active bookings can be moved.', 'terminarz' ) . '</p>';
			return;
		}

		$tz   = $this->services()->availability_settings()->timezone;
		$date = $this->query_text( 'date' );
		if ( '' === Input::date( array( 'date' => $date ), 'date' ) ) {
			$date = $booking->range->start->setTimezone( $tz )->format( 'Y-m-d' );
		}

		printf(
			'<p>%1$s <strong>%2$s</strong></p>',
			esc_html__( 'Current time:', 'terminarz' ),
			esc_html( Labels::datetime( $booking->range->start ) . '–' . Labels::time( $booking->range->end ) )
		);
		echo '<p class="description trmz-timezone">' . esc_html( Labels::timezone_notice() ) . '</p>';

		$day  = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $tz );
		$nav  = static fn( string $target ): array => array(
			'view' => 'reschedule',
			'id'   => (int) $booking->id,
			'date' => $target,
		);
		$prev = false === $day ? '' : $this->url( $nav( $day->sub( new DateInterval( 'P1D' ) )->format( 'Y-m-d' ) ) );
		$next = false === $day ? '' : $this->url( $nav( $day->add( new DateInterval( 'P1D' ) )->format( 'Y-m-d' ) ) );

		echo '<form method="get" class="trmz-reschedule-date">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<input type="hidden" name="view" value="reschedule" />';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $booking->id ) . '" />';
		if ( '' !== $prev ) {
			echo '<a class="button" href="' . esc_url( $prev ) . '">' . esc_html__( '&larr; Previous day', 'terminarz' ) . '</a> ';
		}
		echo '<label for="trmz-reschedule-date">' . esc_html__( 'Day', 'terminarz' ) . '</label> ';
		echo '<input type="date" id="trmz-reschedule-date" name="date" value="' . esc_attr( $date ) . '" /> ';
		submit_button( __( 'Show free times', 'terminarz' ), 'secondary', '', false );
		if ( '' !== $next ) {
			echo ' <a class="button" href="' . esc_url( $next ) . '">' . esc_html__( 'Next day &rarr;', 'terminarz' ) . '</a>';
		}
		echo '</form>';

		$slots = $this->reschedule_slots( $booking, $date );
		if ( array() === $slots ) {
			echo '<p class="trmz-no-slots">' . esc_html__( 'No free times on this day.', 'terminarz' ) . '</p>';
			return;
		}

		$names = array();
		foreach ( $this->services()->resources()->all() as $resource ) {
			$names[ (int) $resource->id ] = $resource->name;
		}

		$this->form_open( 'reschedule_booking', 'trmz-reschedule-form' );
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) $booking->id ) . '" />';
		echo '<input type="hidden" name="date" value="' . esc_attr( $date ) . '" />';
		echo '<fieldset class="trmz-slots"><legend>' . esc_html__( 'Free times', 'terminarz' ) . '</legend>';
		foreach ( $slots as $index => $slot ) {
			$value = $slot->start()->getTimestamp() . ':' . $slot->resource_id;
			printf(
				'<label class="trmz-slot"><input type="radio" name="slot" value="%1$s"%2$s /> %3$s — %4$s</label><br />',
				esc_attr( $value ),
				0 === $index ? ' required' : '',
				esc_html( Labels::time( $slot->start() ) . '–' . Labels::time( $slot->end() ) ),
				esc_html( $names[ $slot->resource_id ] ?? '#' . $slot->resource_id )
			);
		}
		echo '</fieldset>';
		submit_button( __( 'Move booking', 'terminarz' ) );
		echo '</form>';
	}

	/**
	 * Link to the customer's user account, or a dash.
	 *
	 * @param int|null $user_id User ID.
	 */
	private function account_link( ?int $user_id ): string {
		if ( null === $user_id ) {
			return esc_html__( 'Guest', 'terminarz' );
		}
		$user = get_userdata( $user_id );
		if ( false === $user ) {
			return esc_html( '#' . $user_id );
		}
		return current_user_can( 'edit_user', $user_id )
			? '<a href="' . esc_url( get_edit_user_link( $user_id ) ) . '">' . esc_html( $user->display_name ) . '</a>'
			: esc_html( $user->display_name );
	}
}
