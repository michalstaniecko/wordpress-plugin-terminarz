<?php
/**
 * Local time-of-day window.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Model;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Domain\Exception\InvalidValue;

/**
 * Half-open window [start, end) of local (wall-clock) time within a single day,
 * e.g. working hours 09:00–17:00 or a break 12:00–12:30. Windows crossing midnight are not supported.
 */
final class TimeWindow {

	/**
	 * Creates the window.
	 *
	 * @param LocalTime $start Inclusive start.
	 * @param LocalTime $end   Exclusive end; later than start.
	 *
	 * @throws InvalidValue When end is not after start.
	 */
	public function __construct(
		public readonly LocalTime $start,
		public readonly LocalTime $end
	) {
		if ( $end->minutes <= $start->minutes ) {
			throw new InvalidValue( 'Time window end must be later than its start (windows cannot cross midnight).' );
		}
	}

	/**
	 * Creates a window from "HH:MM" strings.
	 *
	 * @param string $start Start, e.g. "09:00".
	 * @param string $end   End, e.g. "17:00".
	 */
	public static function from_strings( string $start, string $end ): self {
		return new self( LocalTime::from_string( $start ), LocalTime::from_string( $end ) );
	}

	/**
	 * Length in minutes.
	 */
	public function duration_minutes(): int {
		return $this->end->minutes - $this->start->minutes;
	}

	/**
	 * Whether both windows share at least one minute (touching windows do not overlap).
	 *
	 * @param self $other Other window.
	 */
	public function overlaps( self $other ): bool {
		return $this->start->minutes < $other->end->minutes && $other->start->minutes < $this->end->minutes;
	}

	/**
	 * Removes the given windows from this one.
	 *
	 * @param self[] $cuts Windows to subtract (any order, may overlap).
	 *
	 * @return list<self> Remaining parts, sorted.
	 */
	public function subtract( array $cuts ): array {
		usort( $cuts, static fn ( self $a, self $b ): int => $a->start->minutes <=> $b->start->minutes );

		$result = array();
		$cursor = $this->start->minutes;
		foreach ( $cuts as $cut ) {
			if ( $cut->end->minutes <= $cursor || $cut->start->minutes >= $this->end->minutes ) {
				continue;
			}
			if ( $cut->start->minutes > $cursor ) {
				$result[] = new self( new LocalTime( $cursor ), $cut->start );
			}
			$cursor = max( $cursor, $cut->end->minutes );
		}
		if ( $cursor < $this->end->minutes ) {
			$result[] = new self( new LocalTime( $cursor ), $this->end );
		}

		return $result;
	}

	/**
	 * Formats as "HH:MM-HH:MM".
	 */
	public function to_string(): string {
		return $this->start->to_string() . '-' . $this->end->to_string();
	}
}
