<?php
/**
 * Schedule exceptions screen (days off, holidays, different hours).
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use DateTimeImmutable;
use Terminarz\Domain\Exception\EntityNotFound;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\ScheduleExceptionPeriod;
use Terminarz\Domain\Model\TimeRange;
use Terminarz\Domain\Repository\BookingCriteria;

/**
 * "Terminarz → Days off" (`admin.php?page=trmz-exceptions`): exceptions to the weekly schedule for a range of local
 * dates — closed (leave of a resource, public holiday for everybody) or different hours. Global exceptions apply to
 * every resource; an exception of a resource takes precedence over a global one on the same day (ADR-015). Periods of
 * the same scope must not overlap.
 */
class ExceptionsPage extends Screen {

	public const SLUG = 'trmz-exceptions';

	/**
	 * Rows of hours offered in the form.
	 */
	public const HOUR_ROWS = 3;

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
		return __( 'Days off', 'terminarz' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function page_title(): string {
		return __( 'Days off and exceptions', 'terminarz' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function position(): int {
		return 26;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function actions(): array {
		return array(
			'save_exception'   => 'save',
			'delete_exception' => 'delete',
		);
	}

	/**
	 * Handler: create or update an exception period.
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function save( array $request ): string {
		$id    = Input::absint( $request, 'id' );
		$scope = Input::absint( $request, 'resource' );
		$input = array(
			'resource' => $scope,
			'from'     => Input::date( $request, 'from' ),
			'to'       => Input::date( $request, 'to' ),
			'kind'     => 'custom' === Input::key( $request, 'kind' ) ? 'custom' : 'closed',
			'hours'    => ScheduleForm::raw_rows( $request['hours'] ?? array() ),
			'note'     => mb_substr( Input::text( $request, 'note' ), 0, 191 ),
		);
		if ( '' === $input['to'] ) {
			$input['to'] = $input['from'];
		}
		$back = $this->url(
			array(
				'view' => 'edit',
				'id'   => $id,
			)
		);

		$errors = array();
		if ( $scope > 0 && null === $this->services()->resources()->get( $scope ) ) {
			$errors[] = __( 'The resource does not exist.', 'terminarz' );
		}
		if ( '' === $input['from'] ) {
			$errors[] = __( 'Enter the first day.', 'terminarz' );
		} elseif ( $input['to'] < $input['from'] ) {
			$errors[] = __( 'The last day must not be before the first day.', 'terminarz' );
		}
		$windows = array();
		if ( 'custom' === $input['kind'] ) {
			$windows = ScheduleForm::windows( $input['hours'], __( 'Hours', 'terminarz' ), $errors );
			if ( array() === $windows && array() === $errors ) {
				$errors[] = __( 'Enter at least one range of hours, or choose "Closed".', 'terminarz' );
			}
		}

		$period = null;
		if ( array() === $errors ) {
			try {
				$period = new ScheduleExceptionPeriod( $scope > 0 ? $scope : null, $input['from'], $input['to'], $windows, $input['note'], $id > 0 ? $id : null );
			} catch ( InvalidValue $e ) {
				$errors[] = __( 'The dates or hours are not valid (overlapping hours or a period longer than a year).', 'terminarz' );
			}
		}
		if ( null !== $period ) {
			foreach ( $this->services()->schedule_exceptions()->conflicting_periods( $period ) as $conflict ) {
				$errors[] = sprintf(
					/* translators: %s: date range of an existing exception. */
					__( 'It overlaps an existing exception (%s) for the same resource. Edit that one instead.', 'terminarz' ),
					self::dates( $conflict )
				);
			}
		}

		if ( null === $period || array() !== $errors ) {
			array_map( array( Notices::class, 'error' ), $errors );
			Notices::keep_input( $input );
			return $back;
		}

		try {
			$saved = $this->services()->schedule_exceptions()->save_period( $period );
		} catch ( EntityNotFound $e ) {
			Notices::error( __( 'The exception does not exist.', 'terminarz' ) );
			return $this->url();
		}

		Notices::success( $id > 0 ? __( 'Exception updated.', 'terminarz' ) : __( 'Exception added.', 'terminarz' ) );
		$bookings = $this->bookings_within( $saved );
		if ( $bookings > 0 ) {
			Notices::warning(
				sprintf(
					/* translators: %d: number of bookings. */
					_n(
						'%d active booking falls within these days. It is not changed automatically — move or cancel it if needed.',
						'%d active bookings fall within these days. They are not changed automatically — move or cancel them if needed.',
						$bookings,
						'terminarz'
					),
					$bookings
				)
			);
		}
		return $this->url();
	}

	/**
	 * Handler: delete an exception.
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function delete( array $request ): string {
		$id = Input::absint( $request, 'id' );
		if ( null === $this->services()->schedule_exceptions()->get_period( $id ) ) {
			Notices::error( __( 'The exception does not exist.', 'terminarz' ) );
			return $this->url();
		}
		$this->services()->schedule_exceptions()->delete( $id );
		Notices::success( __( 'Exception deleted.', 'terminarz' ) );
		return $this->url();
	}

	/**
	 * Number of active bookings starting on the days of a period (all resources for a global period).
	 *
	 * @param ScheduleExceptionPeriod $period Period.
	 */
	public function bookings_within( ScheduleExceptionPeriod $period ): int {
		$tz    = $this->services()->availability_settings()->timezone;
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $period->start_date, $tz );
		$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', $period->end_date, $tz );
		if ( false === $start || false === $end ) {
			return 0;
		}
		return $this->services()->bookings()->count(
			new BookingCriteria(
				statuses: BookingStatus::active(),
				resource_id: $period->resource_id,
				starts_in: new TimeRange( $start, $end->modify( '+1 day' ) )
			)
		);
	}

	/**
	 * Date range label.
	 *
	 * @param ScheduleExceptionPeriod $period Period.
	 */
	public static function dates( ScheduleExceptionPeriod $period ): string {
		$format = (string) get_option( 'date_format', 'Y-m-d' );
		$tz     = wp_timezone();
		$from   = (string) wp_date( $format, (int) ( new DateTimeImmutable( $period->start_date . ' 12:00', $tz ) )->getTimestamp(), $tz );
		if ( $period->start_date === $period->end_date ) {
			return $from;
		}
		return $from . ' – ' . (string) wp_date( $format, (int) ( new DateTimeImmutable( $period->end_date . ' 12:00', $tz ) )->getTimestamp(), $tz );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function render_view(): void {
		if ( 'edit' === $this->current_view() ) {
			$this->render_form();
			return;
		}

		$this->heading( __( 'Days off and exceptions', 'terminarz' ), $this->url( array( 'view' => 'edit' ) ), __( 'Add exception', 'terminarz' ) );
		echo '<p class="description">' . esc_html__( 'Closed days (leave, public holidays) and days with different hours. A global exception applies to all resources; an exception of a single resource takes precedence over it.', 'terminarz' ) . '</p>';

		self::load_list_table();
		$table = new ExceptionsListTable( $this );
		$table->prepare_items();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		$table->display();
		echo '</form>';
	}

	/**
	 * Add/edit form.
	 */
	private function render_form(): void {
		$id     = $this->query_int( 'id' );
		$period = $id > 0 ? $this->services()->schedule_exceptions()->get_period( $id ) : null;
		if ( $id > 0 && null === $period ) {
			$this->heading( __( 'Edit exception', 'terminarz' ) );
			echo '<p>' . esc_html__( 'The exception does not exist.', 'terminarz' ) . '</p>';
			return;
		}

		$old    = Notices::take_input() ?? array();
		$today  = $this->services()->clock()->now()->setTimezone( wp_timezone() )->format( 'Y-m-d' );
		$values = array(
			'resource' => (int) ( $old['resource'] ?? $period->resource_id ?? $this->query_int( 'resource' ) ),
			'from'     => (string) ( $old['from'] ?? $period->start_date ?? $today ),
			'to'       => (string) ( $old['to'] ?? $period->end_date ?? $today ),
			'kind'     => (string) ( $old['kind'] ?? ( null === $period || $period->is_closed() ? 'closed' : 'custom' ) ),
			'hours'    => isset( $old['hours'] ) && is_array( $old['hours'] ) ? $old['hours'] : ( null === $period ? array() : ScheduleForm::rows_from( $period->windows ) ),
			'note'     => (string) ( $old['note'] ?? $period->note ?? '' ),
		);

		$this->heading( null === $period ? __( 'Add exception', 'terminarz' ) : __( 'Edit exception', 'terminarz' ) );
		echo '<p><a href="' . esc_url( $this->url() ) . '">' . esc_html__( '&larr; Back to days off', 'terminarz' ) . '</a></p>';
		echo '<p class="description trmz-timezone">' . esc_html( Labels::timezone_notice() ) . '</p>';

		$this->form_open( 'save_exception', 'trmz-exception-form' );
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) ( $period->id ?? 0 ) ) . '" />';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="trmz-scope">' . esc_html__( 'Applies to', 'terminarz' ) . '</label></th><td><select id="trmz-scope" name="resource">';
		echo '<option value="0"' . selected( $values['resource'], 0, false ) . '>' . esc_html__( 'All resources (global)', 'terminarz' ) . '</option>';
		foreach ( $this->services()->resources()->all() as $resource ) {
			echo '<option value="' . esc_attr( (string) $resource->id ) . '"' . selected( $values['resource'], (int) $resource->id, false ) . '>' . esc_html( $resource->name ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="trmz-from">' . esc_html__( 'First day', 'terminarz' ) . '</label></th><td>';
		echo '<input type="date" id="trmz-from" name="from" required value="' . esc_attr( $values['from'] ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="trmz-to">' . esc_html__( 'Last day', 'terminarz' ) . '</label></th><td>';
		echo '<input type="date" id="trmz-to" name="to" value="' . esc_attr( $values['to'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'The same as the first day for a single day.', 'terminarz' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Type', 'terminarz' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Type', 'terminarz' ) . '</legend>';
		echo '<label><input type="radio" name="kind" value="closed"' . checked( $values['kind'], 'closed', false ) . ' /> ' . esc_html__( 'Closed (no bookings)', 'terminarz' ) . '</label><br />';
		echo '<label><input type="radio" name="kind" value="custom"' . checked( $values['kind'], 'custom', false ) . ' /> ' . esc_html__( 'Different hours (replace working hours and breaks):', 'terminarz' ) . '</label>';
		$rows = array_values( $values['hours'] );
		for ( $i = 0; $i < self::HOUR_ROWS; $i++ ) {
			printf(
				'<div class="trmz-range"><input type="time" step="60" name="hours[%1$d][start]" value="%2$s" aria-label="%3$s" /> – <input type="time" step="60" name="hours[%1$d][end]" value="%4$s" aria-label="%5$s" /></div>',
				(int) $i,
				esc_attr( (string) ( $rows[ $i ]['start'] ?? '' ) ),
				/* translators: %d: row number. */
				esc_attr( sprintf( __( 'Hours %d: start', 'terminarz' ), $i + 1 ) ),
				esc_attr( (string) ( $rows[ $i ]['end'] ?? '' ) ),
				/* translators: %d: row number. */
				esc_attr( sprintf( __( 'Hours %d: end', 'terminarz' ), $i + 1 ) )
			);
		}
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="trmz-note">' . esc_html__( 'Note', 'terminarz' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="trmz-note" name="note" maxlength="191" value="' . esc_attr( $values['note'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'For administrators only, e.g. "Christmas" or "Training".', 'terminarz' ) . '</p></td></tr>';

		echo '</tbody></table>';
		submit_button( null === $period ? __( 'Add exception', 'terminarz' ) : __( 'Save changes', 'terminarz' ) );
		echo '</form>';
	}
}
