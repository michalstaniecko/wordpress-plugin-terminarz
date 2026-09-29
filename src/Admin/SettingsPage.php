<?php
/**
 * Settings screen.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Admin;

use Terminarz\Application\AvailabilitySettings;
use Terminarz\Infrastructure\Capabilities;
use Terminarz\Infrastructure\Settings;

/**
 * "Terminarz → Settings": the `trmz_settings` option edited through the Settings API.
 *
 * The setting is registered on `init` (so its `sanitize_callback` also guards programmatic updates), the form on
 * `admin_init`. `options.php` normally requires `manage_options`; `option_page_capability_trmz_settings` lowers it
 * to `trmz_manage_bookings`.
 */
final class SettingsPage implements AdminPage {

	public const SLUG = 'trmz-settings';

	/**
	 * Settings group (the `option_page` field of the form).
	 */
	public const GROUP = 'trmz_settings';

	/**
	 * {@inheritDoc}
	 */
	public function slug(): string {
		return self::SLUG;
	}

	/**
	 * {@inheritDoc}
	 */
	public function menu_title(): string {
		return __( 'Settings', 'terminarz' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function page_title(): string {
		return __( 'Terminarz settings', 'terminarz' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function position(): int {
		return 90;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_setting' ) );
		add_action( 'admin_init', array( $this, 'register_fields' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( $this, 'capability' ) );
	}

	/**
	 * Capability required to save the settings form through `options.php`.
	 */
	public function capability(): string {
		return Capabilities::MANAGE_BOOKINGS;
	}

	/**
	 * Registers the option with its sanitization and defaults.
	 */
	public function register_setting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'object',
				'description'       => __( 'Terminarz settings.', 'terminarz' ),
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Registers sections and fields of the form.
	 */
	public function register_fields(): void {
		$sections = array(
			'trmz_booking'       => array( __( 'Booking rules', 'terminarz' ), null ),
			'trmz_payments'      => array( __( 'Payments', 'terminarz' ), array( $this, 'payments_intro' ) ),
			'trmz_notifications' => array( __( 'Notifications', 'terminarz' ), null ),
			'trmz_privacy'       => array( __( 'Privacy and data', 'terminarz' ), null ),
		);
		foreach ( $sections as $id => [ $title, $callback ] ) {
			add_settings_section( $id, $title, $callback, self::SLUG );
		}

		foreach ( $this->fields() as $key => $field ) {
			add_settings_field(
				'trmz-' . $key,
				Settings::label( $key ),
				array( $this, 'render_field' ),
				self::SLUG,
				$field['section'],
				array(
					'key'       => $key,
					'label_for' => 'trmz-' . $key,
				)
			);
		}
	}

	/**
	 * Field definitions: section, input type, help text and (for selects) options.
	 *
	 * @return array<string, array{section:string, type:string, help:string, options?:array<string,string>}>
	 */
	private function fields(): array {
		return array(
			'slot_step_minutes'           => array(
				'section' => 'trmz_booking',
				'type'    => 'number',
				'help'    => __( 'Start times are offered every N minutes from the beginning of working hours.', 'terminarz' ),
			),
			'min_lead_minutes'            => array(
				'section' => 'trmz_booking',
				'type'    => 'number',
				'help'    => __( 'How long before the start a slot can still be booked (e.g. 60 = one hour, 1440 = one day).', 'terminarz' ),
			),
			'max_horizon_days'            => array(
				'section' => 'trmz_booking',
				'type'    => 'number',
				'help'    => __( 'How many days ahead customers can book (0 = today only).', 'terminarz' ),
			),
			'customer_cancel_limit_hours' => array(
				'section' => 'trmz_booking',
				'type'    => 'number',
				'help'    => __( 'Customers can cancel on their own up to this many hours before the start (0 = until the start).', 'terminarz' ),
			),
			'auto_confirm'                => array(
				'section' => 'trmz_booking',
				'type'    => 'checkbox',
				'help'    => __( 'Confirm new bookings automatically. When off, bookings wait for confirmation by staff.', 'terminarz' ),
			),
			'any_resource_strategy'       => array(
				'section' => 'trmz_booking',
				'type'    => 'select',
				'help'    => __( 'How a resource is chosen when the customer books "any available".', 'terminarz' ),
				'options' => array(
					AvailabilitySettings::STRATEGY_ORDER => __( 'First free resource in the service order', 'terminarz' ),
					AvailabilitySettings::STRATEGY_LEAST_BUSY => __( 'Least busy resource that day', 'terminarz' ),
				),
			),
			'payment_mode'                => array(
				'section' => 'trmz_payments',
				'type'    => 'select',
				'help'    => __( 'Takes effect only while WooCommerce is active.', 'terminarz' ),
				'options' => array(
					Settings::PAYMENT_NONE    => __( 'No payment', 'terminarz' ),
					Settings::PAYMENT_DEPOSIT => __( 'Deposit (percentage of the price)', 'terminarz' ),
					Settings::PAYMENT_FULL    => __( 'Full price', 'terminarz' ),
				),
			),
			'deposit_percent'             => array(
				'section' => 'trmz_payments',
				'type'    => 'number',
				'help'    => __( 'Used when the payment mode is "Deposit".', 'terminarz' ),
			),
			'hold_minutes'                => array(
				'section' => 'trmz_payments',
				'type'    => 'number',
				'help'    => __( 'How long a slot is reserved while waiting for payment.', 'terminarz' ),
			),
			'notification_email'          => array(
				'section' => 'trmz_notifications',
				'type'    => 'email',
				'help'    => __( 'Where notifications about new bookings are sent. Leave empty to use the site administration e-mail.', 'terminarz' ),
			),
			'consent_text'                => array(
				'section' => 'trmz_privacy',
				'type'    => 'textarea',
				'help'    => __( 'Shown next to the consent checkbox in the booking form. Basic HTML (links) is allowed. Leave empty to hide the checkbox.', 'terminarz' ),
			),
			'delete_data_on_uninstall'    => array(
				'section' => 'trmz_privacy',
				'type'    => 'checkbox',
				'help'    => __( 'When the plugin is deleted, also delete all bookings, resources, services and settings. This cannot be undone.', 'terminarz' ),
			),
		);
	}

	/**
	 * Intro of the payments section: WooCommerce status.
	 */
	public function payments_intro(): void {
		if ( class_exists( 'WooCommerce' ) ) {
			$message = __( 'Payments are processed by WooCommerce.', 'terminarz' );
			$class   = 'description';
		} else {
			$message = __( 'Payments require an active WooCommerce plugin. WooCommerce is not active, so bookings are accepted without payment regardless of the mode below.', 'terminarz' );
			$class   = 'notice notice-warning inline';
		}
		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	/**
	 * Prints one field.
	 *
	 * @param array{key:string, label_for:string} $args Field arguments from `add_settings_field()`.
	 */
	public function render_field( array $args ): void {
		$key    = $args['key'];
		$fields = $this->fields();
		if ( ! isset( $fields[ $key ] ) ) {
			return;
		}
		$field  = $fields[ $key ];
		$values = Settings::load()->to_array();
		$value  = $values[ $key ];
		$id     = 'trmz-' . $key;
		$name   = Settings::OPTION . '[' . $key . ']';
		$help   = $id . '-help';

		switch ( $field['type'] ) {
			case 'number':
				[ $min, $max ] = Settings::RANGES[ $key ];
				printf(
					'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$s" min="%4$d" max="%5$d" step="1" required aria-describedby="%6$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					(int) $min,
					(int) $max,
					esc_attr( $help )
				);
				break;

			case 'checkbox':
				printf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s aria-describedby="%4$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_attr( $help )
				);
				break;

			case 'select':
				printf( '<select id="%1$s" name="%2$s" aria-describedby="%3$s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $help ) );
				foreach ( $field['options'] ?? array() as $option => $label ) {
					printf(
						'<option value="%1$s" %2$s>%3$s</option>',
						esc_attr( $option ),
						selected( (string) $value, $option, false ),
						esc_html( $label )
					);
				}
				echo '</select>';
				break;

			case 'email':
				printf(
					'<input type="email" class="regular-text" id="%1$s" name="%2$s" value="%3$s" placeholder="%4$s" aria-describedby="%5$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( (string) get_option( 'admin_email' ) ),
					esc_attr( $help )
				);
				break;

			case 'textarea':
				printf(
					'<textarea class="large-text" rows="4" id="%1$s" name="%2$s" aria-describedby="%3$s">%4$s</textarea>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $help ),
					esc_textarea( (string) $value )
				);
				break;
		}

		printf( '<p class="description" id="%1$s">%2$s</p>', esc_attr( $help ), esc_html( $field['help'] ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_BOOKINGS ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage Terminarz settings.', 'terminarz' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html( $this->page_title() ) );
		// Custom top-level pages do not include options-head.php, so saved/error notices are printed here.
		settings_errors();
		echo '<form action="options.php" method="post">';
		settings_fields( self::GROUP );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form></div>';
	}
}
