<?php
/**
 * Weekly working hours screen.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use Terminarz\Domain\Model\BookableResource;

/**
 * "Terminarz → Working hours" (`admin.php?page=trmz-schedule&resource=<id>`): working hours and breaks of a resource
 * per weekday, several ranges per day, in the site time zone. No JavaScript: every day shows its stored ranges plus
 * empty rows; saving with all rows used adds new empty rows.
 */
class SchedulePage extends Screen {

	public const SLUG = 'trmz-schedule';

	/**
	 * Empty rows added after the stored ones.
	 */
	private const EXTRA_WORK_ROWS  = 2;
	private const EXTRA_BREAK_ROWS = 1;

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
		return __( 'Working hours', 'terminarz' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function page_title(): string {
		return __( 'Working hours', 'terminarz' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function position(): int {
		return 24;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	protected function actions(): array {
		return array( 'save_schedule' => 'save' );
	}

	/**
	 * Handler: replace the weekly schedule of a resource.
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 */
	public function save( array $request ): string {
		$resource_id = Input::absint( $request, 'resource' );
		if ( null === $this->services()->resources()->get( $resource_id ) ) {
			Notices::error( __( 'The resource does not exist.', 'terminarz' ) );
			return $this->url();
		}

		$back   = $this->url( array( 'resource' => $resource_id ) );
		$parsed = ScheduleForm::weekly( $request );
		if ( null === $parsed['schedule'] ) {
			array_map( array( Notices::class, 'error' ), $parsed['errors'] );
			Notices::keep_input( $parsed['input'] );
			return $back;
		}

		$this->services()->schedules()->save( $resource_id, $parsed['schedule'] );
		Notices::success( __( 'Working hours saved.', 'terminarz' ) );
		return $back;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function render_view(): void {
		$this->heading( __( 'Working hours', 'terminarz' ) );

		$resources = $this->services()->resources()->all();
		if ( array() === $resources ) {
			printf(
				'<p>%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'There are no resources yet.', 'terminarz' ),
				esc_url( ( new ResourcesPage() )->url( array( 'view' => 'edit' ) ) ),
				esc_html__( 'Add a resource', 'terminarz' )
			);
			return;
		}

		$selected = $this->query_int( 'resource' );
		$resource = null;
		foreach ( $resources as $candidate ) {
			if ( (int) $candidate->id === $selected ) {
				$resource = $candidate;
			}
		}
		$resource ??= $resources[0];

		$this->render_picker( $resources, $resource );
		echo '<p class="description trmz-timezone">' . esc_html( Labels::timezone_notice() ) . '</p>';
		$this->render_form( $resource );
	}

	/**
	 * Resource picker (GET form).
	 *
	 * @param BookableResource[] $resources Resources.
	 * @param BookableResource   $current   Selected resource.
	 */
	private function render_picker( array $resources, BookableResource $current ): void {
		echo '<form method="get" class="trmz-resource-picker">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		echo '<label for="trmz-resource">' . esc_html__( 'Resource', 'terminarz' ) . '</label> ';
		echo '<select id="trmz-resource" name="resource">';
		foreach ( $resources as $resource ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $resource->id ),
				selected( $resource->id, $current->id, false ),
				esc_html( $resource->name . ( $resource->is_active ? '' : ' (' . __( 'inactive', 'terminarz' ) . ')' ) )
			);
		}
		echo '</select> ';
		submit_button( __( 'Show', 'terminarz' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * Weekly schedule form.
	 *
	 * @param BookableResource $bookable Resource.
	 */
	private function render_form( BookableResource $bookable ): void {
		$schedule = $this->services()->schedules()->for_resource( (int) $bookable->id );
		$old      = Notices::take_input();

		$this->form_open( 'save_schedule', 'trmz-schedule-form' );
		echo '<input type="hidden" name="resource" value="' . esc_attr( (string) $bookable->id ) . '" />';
		echo '<table class="widefat striped trmz-schedule"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Day', 'terminarz' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Working hours', 'terminarz' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Breaks', 'terminarz' ) . '</th>';
		echo '</tr></thead><tbody>';

		for ( $day = 1; $day <= 7; $day++ ) {
			$work   = is_array( $old ) ? ( $old['work'][ $day ] ?? array() ) : ScheduleForm::rows_from( $schedule->working_hours( $day ) );
			$breaks = is_array( $old ) ? ( $old['breaks'][ $day ] ?? array() ) : ScheduleForm::rows_from( $schedule->breaks( $day ) );

			echo '<tr><th scope="row">' . esc_html( ScheduleForm::weekday( $day ) ) . '</th><td>';
			$this->render_rows( 'work', $day, $work, self::EXTRA_WORK_ROWS );
			echo '</td><td>';
			$this->render_rows( 'breaks', $day, $breaks, self::EXTRA_BREAK_ROWS );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Leave a day empty to close it. An end time of 00:00 means midnight. Breaks must lie within working hours.', 'terminarz' ) . '</p>';
		submit_button( __( 'Save working hours', 'terminarz' ) );
		echo '</form>';
	}

	/**
	 * Time range inputs.
	 *
	 * @param string                                        $kind  `work` or `breaks`.
	 * @param int                                           $day   ISO weekday.
	 * @param array<int, array{start: string, end: string}> $rows  Values.
	 * @param int                                           $extra Empty rows to add.
	 */
	private function render_rows( string $kind, int $day, array $rows, int $extra ): void {
		$rows  = array_slice( array_values( $rows ), 0, ScheduleForm::MAX_ROWS - $extra );
		$total = count( $rows ) + $extra;
		$label = 'work' === $kind ? __( 'Working hours', 'terminarz' ) : __( 'Break', 'terminarz' );
		for ( $i = 0; $i < $total; $i++ ) {
			$start = (string) ( $rows[ $i ]['start'] ?? '' );
			$end   = (string) ( $rows[ $i ]['end'] ?? '' );
			$name  = sprintf( '%s[%d][%d]', $kind, $day, $i );
			$aria  = ScheduleForm::weekday( $day ) . ', ' . $label . ' ' . ( $i + 1 );
			printf(
				'<div class="trmz-range"><input type="time" step="60" name="%1$s[start]" value="%2$s" aria-label="%3$s" /> – <input type="time" step="60" name="%1$s[end]" value="%4$s" aria-label="%5$s" /></div>',
				esc_attr( $name ),
				esc_attr( $start ),
				/* translators: %s: weekday and row, e.g. "Monday, Break 1". */
				esc_attr( sprintf( __( '%s: start', 'terminarz' ), $aria ) ),
				esc_attr( $end ),
				/* translators: %s: weekday and row, e.g. "Monday, Break 1". */
				esc_attr( sprintf( __( '%s: end', 'terminarz' ), $aria ) )
			);
		}
	}
}
