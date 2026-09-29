<?php
/**
 * Wall-clock time of day.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Domain\Model;

use Terminarz\Domain\Exception\InvalidValue;

/**
 * Local (wall-clock) time of day with minute precision, stored as minutes since midnight.
 *
 * The range is 00:00–24:00; 24:00 (1440) exists only to express "until the end of the day".
 */
final class LocalTime {

	public const MINUTES_PER_DAY = 1440;

	/**
	 * Minutes since local midnight (0–1440).
	 *
	 * @var int
	 */
	public readonly int $minutes;

	/**
	 * Creates the time.
	 *
	 * @param int $minutes Minutes since midnight, 0–1440.
	 *
	 * @throws InvalidValue When out of range.
	 */
	public function __construct( int $minutes ) {
		if ( $minutes < 0 || $minutes > self::MINUTES_PER_DAY ) {
			throw new InvalidValue( 'Local time must be between 00:00 and 24:00.' );
		}

		$this->minutes = $minutes;
	}

	/**
	 * Parses "HH:MM" (24-hour clock, 00:00–24:00).
	 *
	 * @param string $value Time string.
	 *
	 * @throws InvalidValue When the string is malformed.
	 */
	public static function from_string( string $value ): self {
		if ( 1 !== preg_match( '/^([01]\d|2[0-4]):([0-5]\d)$/', $value, $matches ) ) {
			throw new InvalidValue( 'Local time must use the HH:MM format.' );
		}

		return new self( (int) $matches[1] * 60 + (int) $matches[2] );
	}

	/**
	 * Formats as "HH:MM".
	 */
	public function to_string(): string {
		return sprintf( '%02d:%02d', intdiv( $this->minutes, 60 ), $this->minutes % 60 );
	}

	/**
	 * Hour part (0–24).
	 */
	public function hour(): int {
		return intdiv( $this->minutes, 60 );
	}

	/**
	 * Minute part (0–59).
	 */
	public function minute(): int {
		return $this->minutes % 60;
	}
}
