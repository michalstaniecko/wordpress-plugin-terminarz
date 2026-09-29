<?php
/**
 * Parsing and validation of working hours entered in the admin.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Model\LocalTime;
use Terminarz\Domain\Model\TimeWindow;
use Terminarz\Domain\Model\WeeklySchedule;

/**
 * Turns rows of `start`/`end` fields ("HH:MM", site-local time) into domain windows and reports every problem as
 * a translated message: incomplete rows, end not after start, overlapping working hours, overlapping breaks and breaks
 * outside working hours. An end of "00:00" means midnight at the end of the day (24:00).
 */
final class ScheduleForm {

	/**
	 * Maximum number of rows per day and kind accepted from the form.
	 */
	public const MAX_ROWS = 10;

	/**
	 * Parses the weekly schedule form (`work[<weekday>][<n>][start|end]`, `breaks[...]`).
	 *
	 * @param array<string, mixed> $request Unslashed request.
	 * @return array{schedule: WeeklySchedule|null, errors: list<string>, input: array<string, mixed>}
	 */
	public static function weekly( array $request ): array {
		$errors = array();
		$work   = array();
		$breaks = array();
		$input  = array(
			'work'   => array(),
			'breaks' => array(),
		);

		$work_in  = isset( $request['work'] ) && is_array( $request['work'] ) ? $request['work'] : array();
		$break_in = isset( $request['breaks'] ) && is_array( $request['breaks'] ) ? $request['breaks'] : array();

		for ( $day = 1; $day <= 7; $day++ ) {
			$label = self::weekday( $day );

			$input['work'][ $day ]   = self::raw_rows( $work_in[ $day ] ?? array() );
			$input['breaks'][ $day ] = self::raw_rows( $break_in[ $day ] ?? array() );

			$day_work   = self::windows( $input['work'][ $day ], $label, $errors );
			$day_breaks = self::windows( $input['breaks'][ $day ], $label, $errors );

			$overlap = self::first_overlap( $day_work );
			if ( null !== $overlap ) {
				/* translators: 1: weekday, 2: time range, 3: time range. */
				$errors[] = sprintf( __( '%1$s: working hours %2$s and %3$s overlap.', 'terminarz' ), $label, self::range( $overlap[0] ), self::range( $overlap[1] ) );
			}
			$overlap = self::first_overlap( $day_breaks );
			if ( null !== $overlap ) {
				/* translators: 1: weekday, 2: time range, 3: time range. */
				$errors[] = sprintf( __( '%1$s: breaks %2$s and %3$s overlap.', 'terminarz' ), $label, self::range( $overlap[0] ), self::range( $overlap[1] ) );
			}
			foreach ( $day_breaks as $break ) {
				if ( ! self::within_any( $break, $day_work ) ) {
					/* translators: 1: weekday, 2: time range. */
					$errors[] = sprintf( __( '%1$s: the break %2$s must lie within working hours.', 'terminarz' ), $label, self::range( $break ) );
				}
			}

			if ( array() !== $day_work ) {
				$work[ $day ] = $day_work;
			}
			if ( array() !== $day_breaks ) {
				$breaks[ $day ] = $day_breaks;
			}
		}

		$schedule = null;
		if ( array() === $errors ) {
			try {
				$schedule = new WeeklySchedule( $work, $breaks );
			} catch ( InvalidValue $e ) {
				$errors[] = __( 'The working hours are not valid.', 'terminarz' );
			}
		}

		return array(
			'schedule' => $schedule,
			'errors'   => $errors,
			'input'    => $input,
		);
	}

	/**
	 * Parses rows of one list (e.g. the hours of an exception day).
	 *
	 * @param array<int, array{start: string, end: string}> $rows    Raw rows ({@see self::raw_rows()}).
	 * @param string                                        $context Label used in messages (e.g. weekday).
	 * @param string[]                                      $errors  Collected messages (appended).
	 * @return list<TimeWindow>
	 */
	public static function windows( array $rows, string $context, array &$errors ): array {
		$windows = array();
		foreach ( $rows as $row ) {
			$start = $row['start'];
			$end   = $row['end'];
			if ( '' === $start && '' === $end ) {
				continue;
			}
			if ( '' === $start || '' === $end ) {
				/* translators: %s: weekday or other label. */
				$errors[] = sprintf( __( '%s: enter both the start and the end time.', 'terminarz' ), $context );
				continue;
			}
			$from = self::time( $start );
			$to   = '00:00' === $end ? new LocalTime( LocalTime::MINUTES_PER_DAY ) : self::time( $end );
			if ( null === $from || null === $to ) {
				/* translators: %s: weekday or other label. */
				$errors[] = sprintf( __( '%s: use the HH:MM time format.', 'terminarz' ), $context );
				continue;
			}
			if ( $to->minutes <= $from->minutes ) {
				/* translators: 1: weekday or other label, 2: start time, 3: end time. */
				$errors[] = sprintf( __( '%1$s: the end (%3$s) must be later than the start (%2$s).', 'terminarz' ), $context, $start, $end );
				continue;
			}
			$windows[] = new TimeWindow( $from, $to );
		}
		usort( $windows, static fn( TimeWindow $a, TimeWindow $b ): int => $a->start->minutes <=> $b->start->minutes );
		return $windows;
	}

	/**
	 * Normalises submitted rows to `[['start' => 'HH:MM', 'end' => 'HH:MM'], …]` (sanitized strings, at most MAX_ROWS).
	 *
	 * @param mixed $rows Submitted value.
	 * @return array<int, array{start: string, end: string}>
	 */
	public static function raw_rows( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$result = array();
		foreach ( array_slice( $rows, 0, self::MAX_ROWS ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$result[] = array(
				'start' => is_scalar( $row['start'] ?? null ) ? sanitize_text_field( (string) $row['start'] ) : '',
				'end'   => is_scalar( $row['end'] ?? null ) ? sanitize_text_field( (string) $row['end'] ) : '',
			);
		}
		return $result;
	}

	/**
	 * Rows for the form from stored windows (24:00 shown as 00:00, which the time input understands).
	 *
	 * @param TimeWindow[] $windows Windows.
	 * @return array<int, array{start: string, end: string}>
	 */
	public static function rows_from( array $windows ): array {
		return array_map(
			static fn( TimeWindow $w ): array => array(
				'start' => $w->start->to_string(),
				'end'   => LocalTime::MINUTES_PER_DAY === $w->end->minutes ? '00:00' : $w->end->to_string(),
			),
			array_values( $windows )
		);
	}

	/**
	 * Localised weekday name for an ISO weekday (1 = Monday).
	 *
	 * @param int $iso_weekday 1–7.
	 */
	public static function weekday( int $iso_weekday ): string {
		global $wp_locale;
		return $wp_locale instanceof \WP_Locale ? (string) $wp_locale->get_weekday( $iso_weekday % 7 ) : (string) $iso_weekday;
	}

	/**
	 * Human-readable time range.
	 *
	 * @param TimeWindow $window Window.
	 */
	public static function range( TimeWindow $window ): string {
		return $window->start->to_string() . '–' . $window->end->to_string();
	}

	/**
	 * Parses "HH:MM" (00:00–23:59).
	 *
	 * @param string $value Value.
	 */
	private static function time( string $value ): ?LocalTime {
		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $value ) ) {
			return null;
		}
		return LocalTime::from_string( $value );
	}

	/**
	 * First pair of overlapping windows (sorted input), or null.
	 *
	 * @param TimeWindow[] $windows Sorted windows.
	 * @return array{0: TimeWindow, 1: TimeWindow}|null
	 */
	private static function first_overlap( array $windows ): ?array {
		$count = count( $windows );
		for ( $i = 1; $i < $count; $i++ ) {
			if ( $windows[ $i - 1 ]->overlaps( $windows[ $i ] ) ) {
				return array( $windows[ $i - 1 ], $windows[ $i ] );
			}
		}
		return null;
	}

	/**
	 * Whether the window lies entirely within one of the given windows.
	 *
	 * @param TimeWindow   $window     Window.
	 * @param TimeWindow[] $containers Candidate containers.
	 */
	private static function within_any( TimeWindow $window, array $containers ): bool {
		foreach ( $containers as $container ) {
			if ( $container->start->minutes <= $window->start->minutes && $window->end->minutes <= $container->end->minutes ) {
				return true;
			}
		}
		return false;
	}
}
