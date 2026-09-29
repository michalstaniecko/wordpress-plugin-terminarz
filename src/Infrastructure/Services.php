<?php
/**
 * Composition root for repositories and application services.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure;

defined( 'ABSPATH' ) || exit; // No direct access.

use Terminarz\Application\AvailabilityService;
use Terminarz\Application\AvailabilitySettings;
use Terminarz\Application\BookingService;
use Terminarz\Application\CancelTokens;
use Terminarz\Application\PaymentProvider;
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
use Terminarz\Infrastructure\Persistence\WpdbNotificationLog;
use Terminarz\Infrastructure\Persistence\WpdbResourceRepository;
use Terminarz\Infrastructure\Persistence\WpdbScheduleExceptionRepository;
use Terminarz\Infrastructure\Persistence\WpdbScheduleRepository;
use Terminarz\Infrastructure\Persistence\WpdbServiceRepository;
use Terminarz\Notifications\Mailer;
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
	 * Plugin settings.
	 *
	 * @var Settings|null
	 */
	private ?Settings $plugin_settings = null;

	/**
	 * E-mail sender.
	 *
	 * @var Mailer|null
	 */
	private ?Mailer $mailer = null;

	/**
	 * Notification log.
	 *
	 * @var WpdbNotificationLog|null
	 */
	private ?WpdbNotificationLog $notification_log = null;

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
	 * Replaces the shared instance (tests: fixed clock, custom settings).
	 *
	 * @param Services $services Instance to share.
	 */
	public static function set_instance( Services $services ): void {
		self::$instance = $services;
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
			$this->booking_service = new BookingService( $this->bookings(), $this->services(), $this->resources(), new WpEventDispatcher(), $this->clock, $this->cancel_tokens() );
			$this->booking_service->use_availability( $this->availability_service() );
		}
		return $this->booking_service;
	}

	/**
	 * Cancellation link tokens keyed with `wp_salt( 'auth' )` (a secret kept outside the database, ADR-042).
	 */
	public function cancel_tokens(): CancelTokens {
		return new CancelTokens( wp_salt( 'auth' ) );
	}

	/**
	 * Request limiter (transients, same clock as the services).
	 */
	public function rate_limiter(): RateLimiter {
		return new RateLimiter( $this->clock );
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
	 * Plugin settings (`trmz_settings` option), read once per container.
	 */
	public function settings(): Settings {
		return $this->plugin_settings ??= Settings::load();
	}

	/**
	 * E-mail sender (templates from the `trmz_email_templates` option).
	 */
	public function mailer(): Mailer {
		return $this->mailer ??= new Mailer( $this );
	}

	/**
	 * Log of e-mails sent about bookings (deduplication).
	 */
	public function notification_log(): WpdbNotificationLog {
		return $this->notification_log ??= new WpdbNotificationLog( $this->db );
	}

	/**
	 * Online payment provider for bookings of paid services, or null when payments are off (payment mode `none`
	 * or no supported WooCommerce). Provided by the WooCommerce integration through the `trmz_payment_provider` filter.
	 */
	public function payment_provider(): ?PaymentProvider {
		if ( ! $this->settings()->payments_enabled() ) {
			return null;
		}

		/**
		 * Filters the online payment provider (the WooCommerce integration registers its own).
		 *
		 * @param PaymentProvider|null $provider Provider.
		 * @param Services             $services Composition root.
		 */
		$provider = apply_filters( 'trmz_payment_provider', null, $this );

		return $provider instanceof PaymentProvider ? $provider : null;
	}

	/**
	 * Availability settings: site time zone (`wp_timezone()`) plus `Settings` (`min_lead_minutes`, `max_horizon_days`,
	 * `slot_step_minutes`, `any_resource_strategy`), filterable with `trmz_availability_settings`.
	 * Invalid stored values fall back to the defaults (see `Settings::DEFAULTS`).
	 */
	public function availability_settings(): AvailabilitySettings {
		if ( null !== $this->settings ) {
			return $this->settings;
		}

		$plugin = $this->settings();
		try {
			$settings = new AvailabilitySettings(
				wp_timezone(),
				$plugin->min_lead_minutes(),
				$plugin->max_horizon_days(),
				$plugin->slot_step_minutes(),
				$plugin->any_resource_strategy()
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
