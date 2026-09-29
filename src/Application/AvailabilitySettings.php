<?php
/**
 * Availability settings.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Application;

use DateTimeZone;
use Terminarz\Domain\Availability\AvailabilityQuery;
use Terminarz\Domain\Exception\InvalidValue;

/**
 * Site-wide parameters of the availability search (built from WordPress options by the infrastructure).
 */
final class AvailabilitySettings {

	/**
	 * "Any resource": take the first free resource in the service's preference order.
	 */
	public const STRATEGY_ORDER = 'order';

	/**
	 * "Any resource": take the free resource with the fewest booked minutes on that local day
	 * (ties broken by the preference order).
	 */
	public const STRATEGY_LEAST_BUSY = 'least_busy';

	/**
	 * Default grid step (minutes).
	 */
	public const DEFAULT_STEP_MINUTES = AvailabilityQuery::DEFAULT_STEP_MINUTES;

	/**
	 * Constructor.
	 *
	 * @param DateTimeZone $timezone          Site time zone.
	 * @param int          $min_lead_minutes  Minimum time between now and a slot start.
	 * @param int|null     $max_horizon_days  Last bookable local day = today + N; null = unlimited.
	 * @param int          $slot_step_minutes Grid step.
	 * @param string       $strategy          Resource selection strategy for "any resource".
	 * @throws InvalidValue On invalid values.
	 */
	public function __construct(
		public readonly DateTimeZone $timezone,
		public readonly int $min_lead_minutes = 0,
		public readonly ?int $max_horizon_days = null,
		public readonly int $slot_step_minutes = self::DEFAULT_STEP_MINUTES,
		public readonly string $strategy = self::STRATEGY_ORDER
	) {
		if ( ! in_array( $strategy, array( self::STRATEGY_ORDER, self::STRATEGY_LEAST_BUSY ), true ) ) {
			throw new InvalidValue( 'Unknown resource selection strategy.' );
		}
		if ( $min_lead_minutes < 0 || ( null !== $max_horizon_days && $max_horizon_days < 0 ) || $slot_step_minutes <= 0 ) {
			throw new InvalidValue( 'Invalid availability settings.' );
		}
	}
}
