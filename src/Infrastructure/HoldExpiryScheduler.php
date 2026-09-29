<?php
/**
 * Recurring job expiring payment holds.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure;

/**
 * Runs `BookingService::expire_holds()` every 5 minutes: bookings awaiting payment whose hold ran out become `expired`
 * (each fires `trmz_booking_status_changed`, which the WooCommerce integration uses to cancel the unpaid order).
 *
 * Uses Action Scheduler (bundled with WooCommerce, group `terminarz`) when it is available, otherwise WP-Cron;
 * switching between them (WooCommerce activated/deactivated) moves the job. The availability engine already treats
 * expired holds as free, so a late run only delays the status change, never frees or blocks slots incorrectly.
 */
final class HoldExpiryScheduler implements Module {

	/**
	 * Action hook of the job (Action Scheduler and WP-Cron).
	 */
	public const HOOK = 'trmz_expire_holds';

	/**
	 * Action Scheduler group.
	 */
	public const GROUP = 'terminarz';

	/**
	 * Interval (seconds).
	 */
	public const INTERVAL = 300;

	/**
	 * WP-Cron schedule name.
	 */
	public const CRON_SCHEDULE = 'trmz_five_minutes';

	/**
	 * Bookings expired per batch; the job runs at most MAX_BATCHES batches.
	 */
	private const BATCH       = 100;
	private const MAX_BATCHES = 10;

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_filter( 'cron_schedules', array( self::class, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval -- 5 minutes (INTERVAL) is intended.
		add_action( 'init', array( $this, 'schedule' ), 20 );
	}

	/**
	 * `cron_schedules`: adds the 5-minute interval.
	 *
	 * @param mixed $schedules Schedules.
	 * @return array<string, array{interval:int, display:string}>
	 */
	public static function cron_schedules( $schedules ): array {
		$schedules                        = is_array( $schedules ) ? $schedules : array();
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => self::INTERVAL,
			'display'  => __( 'Every 5 minutes (Terminarz)', 'terminarz' ),
		);
		return $schedules;
	}

	/**
	 * Whether Action Scheduler can be used now.
	 */
	public static function uses_action_scheduler(): bool {
		return function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_has_scheduled_action' )
			&& did_action( 'action_scheduler_init' ) > 0;
	}

	/**
	 * `init`: makes sure the job is scheduled exactly once, in Action Scheduler or WP-Cron.
	 */
	public function schedule(): void {
		if ( self::uses_action_scheduler() ) {
			// One indexed query; checked only where Action Scheduler would run the job anyway.
			if ( ! ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_ajax() ) ) {
				return;
			}
			if ( false !== wp_next_scheduled( self::HOOK ) ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			if ( ! as_has_scheduled_action( self::HOOK, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 60, self::INTERVAL, self::HOOK, array(), self::GROUP, true );
			}
			return;
		}

		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Removes the job from both schedulers (plugin deactivation).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'action_scheduler_init' ) > 0 ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * The job (action callback).
	 */
	public function run(): void {
		$this->expire();
	}

	/**
	 * Expires every elapsed hold (in batches).
	 *
	 * @return int[] IDs of expired bookings.
	 */
	public function expire(): array {
		$service = Services::instance()->booking_service();
		$expired = array();
		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$ids     = $service->expire_holds( self::BATCH );
			$expired = array_merge( $expired, $ids );
			if ( count( $ids ) < self::BATCH ) {
				break;
			}
		}
		return $expired;
	}
}
