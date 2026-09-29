<?php
/**
 * Appointment reminders.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Notifications;

use Terminarz\Application\BookingService;
use Terminarz\Domain\Model\Booking;
use Terminarz\Domain\Model\BookingStatus;
use Terminarz\Infrastructure\HoldExpiryScheduler;
use Terminarz\Infrastructure\Module;
use Terminarz\Infrastructure\Services;

/**
 * Schedules one reminder per confirmed booking, `reminder_hours_before` hours before the start (setting; 0 = off):
 * a single Action Scheduler action (group `terminarz`) when Action Scheduler is available, otherwise a single WP-Cron
 * event — hook `trmz_send_reminder` with the booking ID as the only argument.
 *
 * - booking confirmed (created confirmed or confirmed later) → scheduled; moved → rescheduled; cancelled, expired or
 *   completed → unscheduled. A booking made later than the reminder time gets no reminder.
 * - The job checks the current state (confirmed, not started, reminders on) and sends through `BookingNotifier` with the
 *   key `customer_reminder:<start timestamp>`: a repeated run never sends twice, a moved booking gets a new reminder.
 * - A job that runs too early (the setting was lowered after scheduling) schedules itself again at the right time.
 */
final class ReminderScheduler implements Module {

	/**
	 * Action hook (Action Scheduler and WP-Cron).
	 */
	public const HOOK = 'trmz_send_reminder';

	/**
	 * Action Scheduler group.
	 */
	public const GROUP = HoldExpiryScheduler::GROUP;

	/**
	 * A job this many seconds early is still treated as on time (cron jitter).
	 */
	private const TOLERANCE = 300;

	/**
	 * Constructor.
	 *
	 * @param Services|null $services Composition root; null = the shared one (resolved when used).
	 */
	public function __construct( private readonly ?Services $services = null ) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( BookingService::EVENT_CREATED, array( $this, 'booking_created' ), 40 );
		add_action( BookingService::EVENT_STATUS_CHANGED, array( $this, 'booking_status_changed' ), 40, 2 );
		add_action( BookingService::EVENT_RESCHEDULED, array( $this, 'booking_rescheduled' ), 40 );
	}

	/**
	 * `trmz_booking_created`.
	 *
	 * @param mixed $booking Booking.
	 */
	public function booking_created( $booking ): void {
		if ( $booking instanceof Booking && BookingStatus::Confirmed === $booking->status ) {
			$this->schedule( $booking );
		}
	}

	/**
	 * `trmz_booking_status_changed`.
	 *
	 * @param mixed $booking  Booking (new state).
	 * @param mixed $previous Previous status.
	 */
	public function booking_status_changed( $booking, $previous = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		if ( ! $booking instanceof Booking || null === $booking->id ) {
			return;
		}
		if ( BookingStatus::Confirmed === $booking->status ) {
			$this->schedule( $booking );
		} elseif ( ! $booking->status->is_active() ) {
			self::unschedule( $booking->id );
		}
	}

	/**
	 * `trmz_booking_rescheduled`.
	 *
	 * @param mixed $booking Booking (new time).
	 */
	public function booking_rescheduled( $booking ): void {
		if ( ! $booking instanceof Booking || null === $booking->id ) {
			return;
		}
		if ( BookingStatus::Confirmed === $booking->status ) {
			$this->schedule( $booking );
		} else {
			self::unschedule( $booking->id );
		}
	}

	/**
	 * When the reminder of a booking is due (null when reminders are off or the time has passed).
	 *
	 * @param Booking $booking Booking.
	 */
	public function due_at( Booking $booking ): ?int {
		$hours = $this->services()->settings()->reminder_hours_before();
		if ( $hours <= 0 ) {
			return null;
		}
		$at = $booking->range->start->getTimestamp() - $hours * HOUR_IN_SECONDS;
		return $at > $this->services()->clock()->now()->getTimestamp() ? $at : null;
	}

	/**
	 * (Re)schedules the reminder of a confirmed booking; removes it when no reminder is due.
	 *
	 * @param Booking $booking Booking.
	 * @return int|null Scheduled time (timestamp) or null.
	 */
	public function schedule( Booking $booking ): ?int {
		if ( null === $booking->id ) {
			return null;
		}
		self::unschedule( $booking->id );
		$at = BookingStatus::Confirmed === $booking->status ? $this->due_at( $booking ) : null;
		if ( null === $at ) {
			return null;
		}

		$args = array( $booking->id );
		if ( HoldExpiryScheduler::uses_action_scheduler() && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $at, self::HOOK, $args, self::GROUP );
		} else {
			wp_schedule_single_event( $at, self::HOOK, $args );
		}
		return $at;
	}

	/**
	 * Removes the reminder of a booking from both schedulers.
	 *
	 * @param int $booking_id Booking ID.
	 */
	public static function unschedule( int $booking_id ): void {
		wp_clear_scheduled_hook( self::HOOK, array( $booking_id ) );
		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) > 0 ) {
			as_unschedule_all_actions( self::HOOK, array( $booking_id ), self::GROUP );
		}
	}

	/**
	 * Removes every scheduled reminder (uninstall).
	 */
	public static function unschedule_all(): void {
		wp_unschedule_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) > 0 ) {
			as_unschedule_all_actions( self::HOOK, null, self::GROUP );
		}
	}

	/**
	 * The job (action callback).
	 *
	 * @param mixed $booking_id Booking ID.
	 */
	public function run( $booking_id ): void {
		$this->send( is_numeric( $booking_id ) ? (int) $booking_id : 0 );
	}

	/**
	 * Sends the reminder of a booking if it is still due.
	 *
	 * @param int $booking_id Booking ID.
	 * @return bool Whether an e-mail was sent.
	 */
	public function send( int $booking_id ): bool {
		$services = $this->services();
		$booking  = $booking_id > 0 ? $services->bookings()->get( $booking_id ) : null;
		if ( null === $booking || BookingStatus::Confirmed !== $booking->status ) {
			return false;
		}
		$hours = $services->settings()->reminder_hours_before();
		$start = $booking->range->start->getTimestamp();
		$now   = $services->clock()->now()->getTimestamp();
		if ( $hours <= 0 || $now >= $start ) {
			return false;
		}
		if ( $now < $start - $hours * HOUR_IN_SECONDS - self::TOLERANCE ) {
			$this->schedule( $booking ); // Too early (setting lowered meanwhile): try again at the right time.
			return false;
		}

		return ( new BookingNotifier( $services ) )->notify( MessageType::CustomerReminder, $booking, MessageType::CustomerReminder->value . ':' . $start );
	}

	/**
	 * Composition root.
	 */
	private function services(): Services {
		return $this->services ?? Services::instance();
	}
}
