<?php
/**
 * Integration tests for the working hours and exceptions screens.
 *
 * @package Terminarz\Tests
 */

declare(strict_types=1);

namespace Terminarz\Tests\Integration\Admin;

use Terminarz\Admin\ExceptionsPage;
use Terminarz\Admin\SchedulePage;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Domain\Model\ScheduleExceptionPeriod;
use Terminarz\Domain\Model\TimeWindow;

/**
 * @covers \Terminarz\Admin\SchedulePage
 * @covers \Terminarz\Admin\ExceptionsPage
 * @covers \Terminarz\Admin\ExceptionsListTable
 * @covers \Terminarz\Admin\ScheduleForm
 * @covers \Terminarz\Infrastructure\Persistence\WpdbScheduleExceptionRepository
 */
final class SchedulePageTest extends AdminTestCase {

	/**
	 * Working hours screen.
	 *
	 * @var SchedulePage
	 */
	private SchedulePage $schedule;

	/**
	 * Exceptions screen.
	 *
	 * @var ExceptionsPage
	 */
	private ExceptionsPage $exceptions;

	/**
	 * Resource.
	 *
	 * @var int
	 */
	private int $resource;

	public function set_up(): void {
		parent::set_up();
		$this->schedule   = new SchedulePage();
		$this->exceptions = new ExceptionsPage();
		$this->resource   = $this->make_resource( 'Anna' );
	}

	public function test_saves_several_ranges_and_breaks_per_day(): void {
		$this->run_action(
			$this->schedule,
			'save_schedule',
			array(
				'resource' => (string) $this->resource,
				'work'     => array(
					1 => array(
						array(
							'start' => '14:00',
							'end'   => '18:00',
						),
						array(
							'start' => '08:00',
							'end'   => '12:00',
						),
						array(
							'start' => '',
							'end'   => '',
						),
					),
					6 => array(
						array(
							'start' => '20:00',
							'end'   => '00:00',
						),
					),
				),
				'breaks'   => array(
					1 => array(
						array(
							'start' => '10:00',
							'end'   => '10:15',
						),
					),
				),
			)
		);

		$this->assertSame( array( 'Working hours saved.' ), $this->notices( 'success' ) );
		$schedule = $this->container->schedules()->for_resource( $this->resource );
		$this->assertSame( array( '08:00-12:00', '14:00-18:00' ), array_map( static fn( TimeWindow $w ) => $w->to_string(), $schedule->working_hours( 1 ) ) );
		$this->assertSame( array( '10:00-10:15' ), array_map( static fn( TimeWindow $w ) => $w->to_string(), $schedule->breaks( 1 ) ) );
		$this->assertSame( array( '20:00-24:00' ), array_map( static fn( TimeWindow $w ) => $w->to_string(), $schedule->working_hours( 6 ) ), '00:00 as end = midnight.' );
		$this->assertSame( array(), $schedule->working_hours( 2 ) );
	}

	/**
	 * Invalid schedules and the expected message fragment.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public function invalid_schedules(): array {
		$row = static fn( string $start, string $end ): array => array(
			'start' => $start,
			'end'   => $end,
		);
		return array(
			'overlapping work'  => array( array( 'work' => array( 1 => array( $row( '08:00', '12:00' ), $row( '11:00', '13:00' ) ) ) ), 'overlap' ),
			'overlapping break' => array(
				array(
					'work'   => array( 2 => array( $row( '08:00', '16:00' ) ) ),
					'breaks' => array( 2 => array( $row( '10:00', '11:00' ), $row( '10:30', '12:00' ) ) ),
				),
				'overlap',
			),
			'break outside'     => array(
				array(
					'work'   => array( 3 => array( $row( '08:00', '12:00' ) ) ),
					'breaks' => array( 3 => array( $row( '12:00', '13:00' ) ) ),
				),
				'within working hours',
			),
			'end before start'  => array( array( 'work' => array( 4 => array( $row( '12:00', '08:00' ) ) ) ), 'later than the start' ),
			'half row'          => array( array( 'work' => array( 5 => array( $row( '08:00', '' ) ) ) ), 'both the start and the end' ),
			'bad format'        => array( array( 'work' => array( 5 => array( $row( '8am', '9pm' ) ) ) ), 'HH:MM' ),
		);
	}

	/**
	 * Validation keeps the stored schedule.
	 *
	 * @dataProvider invalid_schedules
	 *
	 * @param array<string, mixed> $input    Form data.
	 * @param string               $fragment Expected message fragment.
	 */
	public function test_invalid_schedule_is_rejected( array $input, string $fragment ): void {
		$this->container->schedules()->save( $this->resource, new \Terminarz\Domain\Model\WeeklySchedule( array( 1 => array( TimeWindow::from_strings( '09:00', '17:00' ) ) ) ) );

		$this->run_action( $this->schedule, 'save_schedule', array( 'resource' => (string) $this->resource ) + $input );

		$errors = $this->notices( 'error' );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( $fragment, implode( ' ', $errors ) );
		$this->assertSame( '09:00-17:00', $this->container->schedules()->for_resource( $this->resource )->working_hours( 1 )[0]->to_string(), 'Stored schedule is untouched.' );
	}

	public function test_form_shows_time_zone_stored_hours_and_kept_input(): void {
		$this->container->schedules()->save( $this->resource, new \Terminarz\Domain\Model\WeeklySchedule( array( 2 => array( TimeWindow::from_strings( '09:00', '17:00' ) ) ) ) );

		$html = $this->render(
			$this->schedule,
			array(
				'page'     => SchedulePage::SLUG,
				'resource' => $this->resource,
			)
		);
		$this->assertStringContainsString( 'Europe/Warsaw', $html );
		$this->assertStringContainsString( 'name="work[2][0][start]" value="09:00"', $html );
		$this->assertStringContainsString( 'value="trmz_save_schedule"', $html );

		$this->run_action(
			$this->schedule,
			'save_schedule',
			array(
				'resource' => (string) $this->resource,
				'work'     => array(
					3 => array(
						array(
							'start' => '10:00',
							'end'   => '<b>',
						),
					),
				),
			)
		);
		$html = $this->render(
			$this->schedule,
			array(
				'page'     => SchedulePage::SLUG,
				'resource' => $this->resource,
			)
		);
		$this->assertStringContainsString( 'name="work[3][0][start]" value="10:00"', $html, 'Submitted values are shown again.' );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	public function test_schedule_actions_require_capability_and_nonce(): void {
		$this->assertDies( fn() => $this->run_action( $this->schedule, 'save_schedule', array( 'resource' => (string) $this->resource ), 'nope' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertDies( fn() => $this->run_action( $this->schedule, 'save_schedule', array( 'resource' => (string) $this->resource ) ) );
		$this->assertDies( fn() => $this->run_action( $this->exceptions, 'save_exception', array( 'from' => '2030-01-01' ) ) );
	}

	public function test_adds_resource_leave_as_one_period_used_by_availability(): void {
		$this->run_action(
			$this->exceptions,
			'save_exception',
			array(
				'resource' => (string) $this->resource,
				'from'     => '2030-01-14',
				'to'       => '2030-01-18',
				'kind'     => 'closed',
				'note'     => 'Leave',
			)
		);

		$this->assertSame( array( 'Exception added.' ), $this->notices( 'success' ) );
		$periods = $this->container->schedule_exceptions()->periods();
		$this->assertCount( 1, $periods );
		$this->assertSame( '2030-01-14', $periods[0]->start_date );
		$this->assertSame( '2030-01-18', $periods[0]->end_date );
		$this->assertSame( 'Leave', $periods[0]->note );
		$days = $this->container->schedule_exceptions()->in_range( '2030-01-01', '2030-01-31', array( $this->resource ) );
		$this->assertCount( 5, $days, 'Expanded to one exception per day.' );

		// Availability: open Mon–Fri, the leave week has no slots.
		$this->container->schedules()->save( $this->resource, new \Terminarz\Domain\Model\WeeklySchedule( array_fill_keys( array( 1, 2, 3, 4, 5 ), array( TimeWindow::from_strings( '09:00', '12:00' ) ) ) ) );
		$service = $this->make_service( 60, 0, array( $this->resource ) );
		$this->assertSame( array(), $this->container->availability_service()->slots( $service, '2030-01-14', '2030-01-18' ) );
		$this->assertNotEmpty( $this->container->availability_service()->slots( $service, '2030-01-21', '2030-01-21' ) );
	}

	public function test_global_holiday_with_custom_hours(): void {
		$this->run_action(
			$this->exceptions,
			'save_exception',
			array(
				'resource' => '0',
				'from'     => '2030-12-24',
				'kind'     => 'custom',
				'hours'    => array(
					array(
						'start' => '08:00',
						'end'   => '12:00',
					),
				),
			)
		);

		$period = $this->container->schedule_exceptions()->periods()[0];
		$this->assertTrue( $period->is_global() );
		$this->assertSame( '2030-12-24', $period->end_date, 'A missing last day means a single day.' );
		$this->assertSame( '08:00-12:00', $period->windows[0]->to_string() );
	}

	public function test_overlapping_periods_of_the_same_scope_are_rejected(): void {
		$this->container->schedule_exceptions()->save_period( new ScheduleExceptionPeriod( $this->resource, '2030-02-01', '2030-02-10' ) );
		$this->container->schedule_exceptions()->save_period( new ScheduleExceptionPeriod( null, '2030-02-01', '2030-02-10' ) );

		$this->run_action(
			$this->exceptions,
			'save_exception',
			array(
				'resource' => (string) $this->resource,
				'from'     => '2030-02-10',
				'to'       => '2030-02-12',
				'kind'     => 'closed',
			)
		);
		$this->assertStringContainsString( 'overlaps', implode( ' ', $this->notices( 'error' ) ) );
		$this->assertCount( 2, $this->container->schedule_exceptions()->periods() );

		$other = $this->make_resource( 'Bob' );
		$this->run_action(
			$this->exceptions,
			'save_exception',
			array(
				'resource' => (string) $other,
				'from'     => '2030-02-05',
				'to'       => '2030-02-06',
				'kind'     => 'closed',
			)
		);
		$this->assertCount( 3, $this->container->schedule_exceptions()->periods(), 'Another resource may overlap a global exception.' );
	}

	public function test_editing_a_period_does_not_conflict_with_itself(): void {
		$saved = $this->container->schedule_exceptions()->save_period( new ScheduleExceptionPeriod( null, '2030-03-01', '2030-03-02' ) );

		$this->run_action(
			$this->exceptions,
			'save_exception',
			array(
				'id'       => (string) $saved->id,
				'resource' => '0',
				'from'     => '2030-03-01',
				'to'       => '2030-03-05',
				'kind'     => 'closed',
			)
		);

		$this->assertSame( array( 'Exception updated.' ), $this->notices( 'success' ) );
		$this->assertSame( '2030-03-05', $this->container->schedule_exceptions()->get_period( (int) $saved->id )->end_date );
	}

	/**
	 * Invalid exception input.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function invalid_exceptions(): array {
		return array(
			'no date'         => array( array( 'from' => '' ) ),
			'bad date'        => array( array( 'from' => '2030-02-30' ) ),
			'to before from'  => array(
				array(
					'from' => '2030-01-10',
					'to'   => '2030-01-09',
				),
			),
			'custom no hours' => array( array( 'kind' => 'custom' ) ),
			'too long'        => array( array( 'to' => '2031-06-01' ) ),
			'unknown scope'   => array( array( 'resource' => '999999' ) ),
		);
	}

	/**
	 * Validation.
	 *
	 * @dataProvider invalid_exceptions
	 *
	 * @param array<string, mixed> $override Invalid fields.
	 */
	public function test_invalid_exception_is_rejected( array $override ): void {
		$this->run_action(
			$this->exceptions,
			'save_exception',
			array_merge(
				array(
					'resource' => '0',
					'from'     => '2030-01-10',
					'to'       => '2030-01-10',
					'kind'     => 'closed',
				),
				$override
			)
		);

		$this->assertNotEmpty( $this->notices( 'error' ) );
		$this->assertSame( array(), $this->container->schedule_exceptions()->periods() );
	}

	public function test_warns_about_bookings_on_closed_days_and_deletes(): void {
		$service = $this->make_service( 60, 0, array( $this->resource ) );
		// 2030-01-15 08:00 UTC = 09:00 Warsaw.
		$this->container->bookings()->create( $this->booking( $this->resource, '2030-01-15 08:00', 60, 0, BookingStatus::Confirmed, null, $service ), $this->clock->now() );

		$this->run_action(
			$this->exceptions,
			'save_exception',
			array(
				'resource' => '0',
				'from'     => '2030-01-15',
				'kind'     => 'closed',
			)
		);
		$this->assertCount( 1, $this->notices( 'warning' ) );

		$id = (int) $this->container->schedule_exceptions()->periods()[0]->id;
		$this->run_action( $this->exceptions, 'delete_exception', array( 'id' => (string) $id ) );
		$this->assertSame( array(), $this->container->schedule_exceptions()->periods() );
	}

	public function test_list_shows_upcoming_and_escapes(): void {
		$this->container->schedule_exceptions()->save_period( new ScheduleExceptionPeriod( null, '2029-12-24', '2029-12-26', array(), 'Old' ) );
		$this->container->schedule_exceptions()->save_period( new ScheduleExceptionPeriod( $this->resource, '2030-01-14', '2030-01-18', array(), '<script>x</script>' ) );

		$html = $this->render( $this->exceptions, array( 'page' => ExceptionsPage::SLUG ) );
		$this->assertStringContainsString( '2030-01-14 – 2030-01-18', $html );
		$this->assertStringNotContainsString( '2029-12-24', $html, 'Past exceptions are hidden by default.' );
		$this->assertStringNotContainsString( '<script>x', $html );
		$this->assertStringContainsString( 'Anna', $html );

		$html = $this->render(
			$this->exceptions,
			array(
				'page'  => ExceptionsPage::SLUG,
				'past'  => '1',
				'scope' => 'global',
			)
		);
		$this->assertStringContainsString( '2029-12-24', $html );
		$this->assertStringNotContainsString( '2030-01-14', $html );
	}
}
