<?php
/**
 * Plugin settings (the `trmz_settings` option).
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Infrastructure;

use Terminarz\Application\AvailabilitySettings;

/**
 * Typed, validated view of the `trmz_settings` option — the single source of defaults, ranges and sanitization.
 *
 * - Read: `Settings::load()` (or `Services::instance()->settings()`); invalid or missing stored values fall back to the defaults.
 * - Write: `Settings::sanitize()` is the `sanitize_callback` of the registered setting; an invalid submitted value keeps
 *   the previously stored one and reports a settings error.
 */
final class Settings {

	/**
	 * Option name (autoloaded).
	 */
	public const OPTION = 'trmz_settings';

	/**
	 * Payment modes.
	 */
	public const PAYMENT_NONE    = 'none';
	public const PAYMENT_DEPOSIT = 'deposit';
	public const PAYMENT_FULL    = 'full';

	/**
	 * Integer settings: key => [min, max].
	 *
	 * @var array<string, array{0:int, 1:int}>
	 */
	public const RANGES = array(
		'slot_step_minutes'           => array( 5, 240 ),
		'min_lead_minutes'            => array( 0, 43200 ),
		'max_horizon_days'            => array( 0, 730 ),
		'customer_cancel_limit_hours' => array( 0, 720 ),
		'hold_minutes'                => array( 5, 120 ),
		'deposit_percent'             => array( 1, 100 ),
	);

	/**
	 * Enumerated settings: key => allowed values.
	 *
	 * @var array<string, string[]>
	 */
	public const CHOICES = array(
		'payment_mode'          => array( self::PAYMENT_NONE, self::PAYMENT_DEPOSIT, self::PAYMENT_FULL ),
		'any_resource_strategy' => array( AvailabilitySettings::STRATEGY_ORDER, AvailabilitySettings::STRATEGY_LEAST_BUSY ),
	);

	/**
	 * Boolean settings.
	 *
	 * @var string[]
	 */
	public const FLAGS = array( 'auto_confirm', 'delete_data_on_uninstall' );

	/**
	 * Default values. `notification_email` = '' means "use the site's admin e-mail".
	 *
	 * @var array{slot_step_minutes:int, min_lead_minutes:int, max_horizon_days:int, customer_cancel_limit_hours:int, auto_confirm:bool, hold_minutes:int, payment_mode:string, deposit_percent:int, any_resource_strategy:string, notification_email:string, consent_text:string, delete_data_on_uninstall:bool}
	 */
	public const DEFAULTS = array(
		'slot_step_minutes'           => 15,
		'min_lead_minutes'            => 60,
		'max_horizon_days'            => 90,
		'customer_cancel_limit_hours' => 24,
		'auto_confirm'                => false,
		'hold_minutes'                => 15,
		'payment_mode'                => self::PAYMENT_NONE,
		'deposit_percent'             => 30,
		'any_resource_strategy'       => AvailabilitySettings::STRATEGY_ORDER,
		'notification_email'          => '',
		'consent_text'                => '',
		'delete_data_on_uninstall'    => false,
	);

	/**
	 * Normalized values (every key of DEFAULTS present, with the right type).
	 *
	 * @var array<string, int|bool|string>
	 */
	private array $values;

	/**
	 * Constructor.
	 *
	 * @param array<mixed> $stored Stored option value; unknown keys are ignored, invalid values replaced by defaults.
	 */
	public function __construct( array $stored = array() ) {
		$this->values = self::normalize( $stored );
	}

	/**
	 * Settings read from the `trmz_settings` option.
	 */
	public static function load(): self {
		$stored = get_option( self::OPTION, array() );
		return new self( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Default values.
	 *
	 * @return array<string, int|bool|string>
	 */
	public static function defaults(): array {
		return self::DEFAULTS;
	}

	/**
	 * Validates stored values: every known key present with its type; invalid values → defaults. No user feedback.
	 *
	 * @param array<mixed> $stored Stored values.
	 * @return array<string, int|bool|string>
	 */
	public static function normalize( array $stored ): array {
		$values = self::DEFAULTS;
		foreach ( self::DEFAULTS as $key => $default ) {
			if ( ! array_key_exists( $key, $stored ) ) {
				continue;
			}
			$value = self::validate( $key, $stored[ $key ] );
			if ( null !== $value ) {
				$values[ $key ] = $value;
			}
		}
		return $values;
	}

	/**
	 * `sanitize_callback` of the registered setting: sanitizes a submitted form.
	 *
	 * Missing checkboxes mean "off"; any other missing key keeps its current value. An invalid value keeps the current
	 * value and adds a settings error (shown on the settings page).
	 *
	 * @param mixed $input Submitted value.
	 * @return array<string, int|bool|string>
	 */
	public static function sanitize( $input ): array {
		$current = self::load()->to_array();
		if ( ! is_array( $input ) ) {
			return $current;
		}

		$values = $current;
		foreach ( self::DEFAULTS as $key => $default ) {
			if ( ! array_key_exists( $key, $input ) ) {
				if ( in_array( $key, self::FLAGS, true ) ) {
					$values[ $key ] = false;
				}
				continue;
			}

			// options.php has already unslashed the submitted value.
			$value = self::validate( $key, $input[ $key ] );
			if ( null === $value ) {
				self::report_invalid( $key );
				continue;
			}
			$values[ $key ] = $value;
		}
		return $values;
	}

	/**
	 * Validates and sanitizes a single value.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value.
	 * @return int|bool|string|null Sanitized value, or null when invalid.
	 */
	private static function validate( string $key, $value ): int|bool|string|null {
		if ( isset( self::RANGES[ $key ] ) ) {
			if ( is_string( $value ) ) {
				$value = trim( $value );
			}
			$int = filter_var( $value, FILTER_VALIDATE_INT );
			if ( false === $int || $int < self::RANGES[ $key ][0] || $int > self::RANGES[ $key ][1] ) {
				return null;
			}
			return $int;
		}

		if ( isset( self::CHOICES[ $key ] ) ) {
			return is_string( $value ) && in_array( $value, self::CHOICES[ $key ], true ) ? $value : null;
		}

		if ( in_array( $key, self::FLAGS, true ) ) {
			if ( is_bool( $value ) ) {
				return $value;
			}
			return is_scalar( $value ) && in_array( (string) $value, array( '1', 'on', 'yes', 'true' ), true );
		}

		if ( 'notification_email' === $key ) {
			if ( ! is_string( $value ) ) {
				return null;
			}
			if ( '' === trim( $value ) ) {
				return '';
			}
			$email = sanitize_email( $value );
			return false !== is_email( $email ) ? $email : null;
		}

		if ( 'consent_text' === $key ) {
			return is_string( $value ) ? trim( wp_kses_post( $value ) ) : null;
		}

		return null;
	}

	/**
	 * Adds a translated settings error for an invalid field.
	 *
	 * @param string $key Setting key.
	 */
	private static function report_invalid( string $key ): void {
		if ( ! function_exists( 'add_settings_error' ) ) {
			return;
		}

		if ( isset( self::RANGES[ $key ] ) ) {
			$message = sprintf(
				/* translators: 1: field label, 2: minimum value, 3: maximum value. */
				__( '%1$s: enter a whole number from %2$d to %3$d. The previous value was kept.', 'terminarz' ),
				self::label( $key ),
				self::RANGES[ $key ][0],
				self::RANGES[ $key ][1]
			);
		} elseif ( 'notification_email' === $key ) {
			$message = __( 'Notification e-mail: enter a valid e-mail address. The previous value was kept.', 'terminarz' );
		} else {
			$message = sprintf(
				/* translators: %s: field label. */
				__( '%s: invalid value. The previous value was kept.', 'terminarz' ),
				self::label( $key )
			);
		}

		add_settings_error( self::OPTION, 'trmz_invalid_' . $key, $message, 'error' );
	}

	/**
	 * Translated label of a setting (used by the settings page and error messages).
	 *
	 * @param string $key Setting key.
	 */
	public static function label( string $key ): string {
		$labels = array(
			'slot_step_minutes'           => __( 'Slot grid step (minutes)', 'terminarz' ),
			'min_lead_minutes'            => __( 'Minimum lead time (minutes)', 'terminarz' ),
			'max_horizon_days'            => __( 'Booking horizon (days)', 'terminarz' ),
			'customer_cancel_limit_hours' => __( 'Customer cancellation limit (hours)', 'terminarz' ),
			'auto_confirm'                => __( 'Automatic confirmation', 'terminarz' ),
			'any_resource_strategy'       => __( '"Any resource" selection', 'terminarz' ),
			'payment_mode'                => __( 'Payment mode', 'terminarz' ),
			'deposit_percent'             => __( 'Deposit (%)', 'terminarz' ),
			'hold_minutes'                => __( 'Slot hold for payment (minutes)', 'terminarz' ),
			'notification_email'          => __( 'Notification e-mail', 'terminarz' ),
			'consent_text'                => __( 'Consent text', 'terminarz' ),
			'delete_data_on_uninstall'    => __( 'Delete data on uninstall', 'terminarz' ),
		);
		return $labels[ $key ] ?? $key;
	}

	/**
	 * All values (normalized; `notification_email` as stored, possibly '').
	 *
	 * @return array<string, int|bool|string>
	 */
	public function to_array(): array {
		return $this->values;
	}

	/**
	 * Grid step of generated slots (minutes).
	 */
	public function slot_step_minutes(): int {
		return (int) $this->values['slot_step_minutes'];
	}

	/**
	 * Minimum time between now and a slot start (minutes).
	 */
	public function min_lead_minutes(): int {
		return (int) $this->values['min_lead_minutes'];
	}

	/**
	 * Last bookable local day = today + N days.
	 */
	public function max_horizon_days(): int {
		return (int) $this->values['max_horizon_days'];
	}

	/**
	 * Customers may cancel up to N hours before the start.
	 */
	public function customer_cancel_limit_hours(): int {
		return (int) $this->values['customer_cancel_limit_hours'];
	}

	/**
	 * Whether new bookings are confirmed immediately (otherwise they wait as pending).
	 */
	public function auto_confirm(): bool {
		return (bool) $this->values['auto_confirm'];
	}

	/**
	 * How long a slot is held while waiting for payment (minutes).
	 */
	public function hold_minutes(): int {
		return (int) $this->values['hold_minutes'];
	}

	/**
	 * Configured payment mode (`none`, `deposit`, `full`), regardless of WooCommerce being active.
	 */
	public function payment_mode(): string {
		return (string) $this->values['payment_mode'];
	}

	/**
	 * Whether payments are required: a payment mode is set and WooCommerce is active.
	 */
	public function payments_enabled(): bool {
		return self::PAYMENT_NONE !== $this->payment_mode() && class_exists( 'WooCommerce' );
	}

	/**
	 * Deposit as a percentage of the price (used when the payment mode is `deposit`).
	 */
	public function deposit_percent(): int {
		return (int) $this->values['deposit_percent'];
	}

	/**
	 * "Any resource" selection strategy (`order`, `least_busy`).
	 */
	public function any_resource_strategy(): string {
		return (string) $this->values['any_resource_strategy'];
	}

	/**
	 * Recipient of admin notifications: the configured address or the site's admin e-mail.
	 */
	public function notification_email(): string {
		$email = (string) $this->values['notification_email'];
		return '' !== $email ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Consent text shown in the booking form (sanitized HTML; escape with `wp_kses_post()` on output).
	 */
	public function consent_text(): string {
		return (string) $this->values['consent_text'];
	}

	/**
	 * Whether uninstall removes the plugin's tables, options and capabilities.
	 */
	public function delete_data_on_uninstall(): bool {
		return (bool) $this->values['delete_data_on_uninstall'];
	}
}
