<?php
/**
 * Composition root for repositories and application services.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure;

use Terminarz\Application\AvailabilityService;
use Terminarz\Application\AvailabilitySettings;
use Terminarz\Application\BookingService;
use Terminarz\Application\Clock;
use Terminarz\Application\SystemClock;
use Terminarz\Domain\Availability\AvailabilityEngine;
use Terminarz\Domain\Exception\InvalidValue;
use Terminarz\Domain\Repository\BookingRepository;
use Terminarz\Domain\Repository\ResourceRepository;
use Terminarz\Domain\Repository\ScheduleExceptionRepository;
use Terminarz\Domain\Repository\ScheduleRepository;
use Terminarz\Domain\Repository\ServiceRepository;
use Terminarz\Infrastructure\Persistence\WpdbBookingRepository;
use Terminarz\Infrastructure\Persistence\WpdbResourceRepository;
use Terminarz\Infrastructure\Persistence\WpdbScheduleExceptionRepository;
use Terminarz\Infrastructure\Persistence\WpdbScheduleRepository;
use Terminarz\Infrastructure\Persistence\WpdbServiceRepository;
use wpdb;

/**
 * Builds and shares repositories and services wired to `$wpdb` (ADR-003: manual wiring, no DI container).
 * Adapters (REST, admin, WP-CLI) take their dependencies from here: `Services::instance()->booking_service()`.
 */
final class Services {

	/**
	 * Shared instance.
	 *
	 * @var Services|null
	 */
	private static ?Services $instance = null;

	/**
	 * Lazily built objects.
	 *
	 * @var ResourceRepository|null
	 */
	private ?ResourceRepository $resources = null;

	/**
	 * Lazily built objects.
	 *
	 * @var ServiceRepository|null
	 */
	private ?ServiceRepository $services = null;

	/**
	 * Lazily built objects.
	 *
	 * @var ScheduleRepository|null
	 */
	private ?ScheduleRepository $schedules = null;

	/**
	 * Lazily built objects.
	 *
	 * @var ScheduleExceptionRepository|null
	 */
	private ?ScheduleExceptionRepository $schedule_exceptions = null;

	/**
	 * Lazily built objects.
	 *
	 * @var BookingRepository|null
	 */
	private ?BookingRepository $bookings = null;

	/**
	 * Lazily built objects.
	 *
	 * @var BookingService|null
	 */
	private ?BookingService $booking_service = null;

	/**
	 * Lazily built objects.
	 *
	 * @var AvailabilityService|null
	 */
	private ?AvailabilityService $availability_service = null;

	/**
	 * Constructor.
	 *
	 * @param wpdb                      $db       Connection.
	 * @param Clock                     $clock    Clock.
	 * @param AvailabilitySettings|null $settings Availability settings; null = read from WordPress on first use.
	 */
	public function __construct(
		private readonly wpdb $db,
		private readonly Clock $clock,
		private ?AvailabilitySettings $settings = null
	) {
	}

	/**
	 * Shared instance bound to the global `$wpdb` and the system clock.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			global $wpdb;
			self::$instance = new self( $wpdb, new SystemClock() );
		}
		return self::$instance;
	}

	/**
	 * Resets the shared instance (tests).
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Clock.
	 */
	public function clock(): Clock {
		return $this->clock;
	}

	/**
	 * Resource repository.
	 */
	public function resources(): ResourceRepository {
		return $this->resources ??= new WpdbResourceRepository( $this->db );
	}

	/**
	 * Service repository.
	 */
	public function services(): ServiceRepository {
		return $this->services ??= new WpdbServiceRepository( $this->db );
	}

	/**
	 * Weekly schedule repository.
	 */
	public function schedules(): ScheduleRepository {
		return $this->schedules ??= new WpdbScheduleRepository( $this->db );
	}

	/**
	 * Schedule exception repository.
	 */
	public function schedule_exceptions(): ScheduleExceptionRepository {
		return $this->schedule_exceptions ??= new WpdbScheduleExceptionRepository( $this->db );
	}

	/**
	 * Booking repository.
	 */
	public function bookings(): BookingRepository {
		return $this->bookings ??= new WpdbBookingRepository( $this->db );
	}

	/**
	 * Booking service.
	 */
	public function booking_service(): BookingService {
		if ( null === $this->booking_service ) {
			$this->booking_service = new BookingService( $this->bookings(), $this->services(), $this->resources(), new WpEventDispatcher(), $this->clock );
			$this->booking_service->use_availability( $this->availability_service() );
		}
		return $this->booking_service;
	}

	/**
	 * Availability service.
	 */
	public function availability_service(): AvailabilityService {
		return $this->availability_service ??= new AvailabilityService(
			$this->services(),
			$this->resources(),
			$this->schedules(),
			$this->schedule_exceptions(),
			$this->bookings(),
			new AvailabilityEngine(),
			$this->clock,
			$this->availability_settings()
		);
	}

	/**
	 * Availability settings: site time zone (`wp_timezone()`) plus the `trmz_settings` option
	 * (`min_lead_minutes`, `max_horizon_days`, `slot_step_minutes`, `any_resource_strategy`), filterable
	 * with `trmz_availability_settings`. Invalid stored values fall back to the defaults.
	 */
	public function availability_settings(): AvailabilitySettings {
		if ( null !== $this->settings ) {
			return $this->settings;
		}

		$option  = get_option( 'trmz_settings', array() );
		$option  = is_array( $option ) ? $option : array();
		$horizon = $option['max_horizon_days'] ?? null;

		try {
			$settings = new AvailabilitySettings(
				wp_timezone(),
				absint( $option['min_lead_minutes'] ?? 0 ),
				null === $horizon || '' === $horizon ? null : absint( $horizon ),
				max( 1, absint( $option['slot_step_minutes'] ?? AvailabilitySettings::DEFAULT_STEP_MINUTES ) ),
				sanitize_key( (string) ( $option['any_resource_strategy'] ?? AvailabilitySettings::STRATEGY_ORDER ) )
			);
		} catch ( InvalidValue $e ) {
			$settings = new AvailabilitySettings( wp_timezone() );
		}

		/**
		 * Filters the availability settings (time zone, lead time, horizon, grid step, "any resource" strategy).
		 *
		 * @param AvailabilitySettings $settings Settings.
		 */
		$filtered = apply_filters( 'trmz_availability_settings', $settings );

		$this->settings = $filtered instanceof AvailabilitySettings ? $filtered : $settings;
		return $this->settings;
	}
}
