<?php
/**
 * Composition root for repositories and application services.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure;

use Terminarz\Application\BookingService;
use Terminarz\Application\Clock;
use Terminarz\Application\SystemClock;
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
	 * Constructor.
	 *
	 * @param wpdb  $db    Connection.
	 * @param Clock $clock Clock.
	 */
	public function __construct(
		private readonly wpdb $db,
		private readonly Clock $clock
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
		return $this->booking_service ??= new BookingService( $this->bookings(), $this->services(), $this->resources(), new WpEventDispatcher(), $this->clock );
	}
}
