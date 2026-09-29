<?php
/**
 * The "Booking" block (terminarz/booking).
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Blocks;

use DateInterval;
use DateTimeImmutable;
use Terminarz\Admin\Money;
use Terminarz\Infrastructure\I18n;
use Terminarz\Infrastructure\Module;
use Terminarz\Infrastructure\Services;
use WP_Block_Type;

/**
 * Registers the dynamic booking block from `build/booking/block.json` and renders its container.
 *
 * The server renders only an empty container with the configuration in `data-trmz-config` (JSON) and a fallback
 * message for browsers without JavaScript; the booking application (view.js) mounts itself in the container and
 * talks to the public REST API (`terminarz/v1`). See docs/ARCHITECTURE.md, ADR-031.
 */
final class BookingBlock implements Module {

	public const NAME = 'terminarz/booking';

	/**
	 * Directory with the built block (block.json + assets).
	 *
	 * @var string
	 */
	private string $build_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $build_dir Directory of the built block; defaults to build/booking in the plugin.
	 */
	public function __construct( ?string $build_dir = null ) {
		$this->build_dir = $build_dir ?? dirname( TRMZ_FILE ) . '/build/booking';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Registers the block type (skipped when the assets were not built — e.g. a development checkout without `npm run build`).
	 */
	public function register_block(): void {
		if ( ! is_readable( $this->build_dir . '/block.json' ) ) {
			return;
		}

		$type = register_block_type(
			$this->build_dir,
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);

		if ( $type instanceof WP_Block_Type ) {
			$this->set_script_translations( $type );
		}
	}

	/**
	 * Loads JavaScript translations (`terminarz` text domain) for the editor and front-end scripts.
	 *
	 * @param WP_Block_Type $type Registered block type.
	 */
	private function set_script_translations( WP_Block_Type $type ): void {
		$handles = array_merge( $type->editor_script_handles, $type->view_script_handles );
		$path    = dirname( TRMZ_FILE ) . '/languages';
		foreach ( array_unique( $handles ) as $handle ) {
			wp_set_script_translations( $handle, I18n::TEXT_DOMAIN, $path );
		}
	}

	/**
	 * Renders the block container.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes ): string {
		$config = $this->config( $attributes );

		$wrapper = get_block_wrapper_attributes(
			array(
				'data-trmz-config' => (string) wp_json_encode( $config ),
			)
		);

		return sprintf(
			'<div %1$s><noscript><p class="trmz-booking__noscript">%2$s</p></noscript></div>',
			$wrapper, // Escaped by get_block_wrapper_attributes().
			esc_html__( 'Please enable JavaScript in your browser to book an appointment.', 'terminarz' )
		);
	}

	/**
	 * Front-end configuration of one block instance.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, mixed>
	 */
	public function config( array $attributes ): array {
		$attributes = self::sanitize_attributes( $attributes );
		$services   = Services::instance();
		$settings   = $services->availability_settings();
		$today      = new DateTimeImmutable( 'now', $settings->timezone );
		$last_date  = null === $settings->max_horizon_days
			? null
			: $today->add( new DateInterval( 'P' . $settings->max_horizon_days . 'D' ) )->format( 'Y-m-d' );

		$first_day = $attributes['firstDayOfWeek'];
		if ( $first_day < 0 ) {
			$first_day = (int) get_option( 'start_of_week', 1 );
		}

		$config = array(
			'restRoot'           => esc_url_raw( rest_url() ),
			'nonce'              => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'serviceIds'         => $attributes['serviceIds'],
			'defaultServiceId'   => $attributes['defaultServiceId'],
			'showResourcePicker' => $attributes['showResourcePicker'],
			'firstDayOfWeek'     => max( 0, min( 6, $first_day ) ),
			'today'              => $today->format( 'Y-m-d' ),
			'lastDate'           => $last_date,
			'locale'             => str_replace( '_', '-', determine_locale() ),
			'currency'           => Money::currency(),
			'priceDecimals'      => Money::decimals(),
			'consentHtml'        => self::consent_html( $services->settings()->consent_text() ),
		);

		/**
		 * Filters the front-end configuration of a booking block instance (JSON-encoded into the block markup).
		 *
		 * @param array<string, mixed> $config     Configuration.
		 * @param array<string, mixed> $attributes Sanitized block attributes.
		 */
		return (array) apply_filters( 'trmz_booking_block_config', $config, $attributes );
	}

	/**
	 * Consent label shown next to the required checkbox: the configured text (limited HTML) or a default one.
	 *
	 * @param string $text Configured text.
	 */
	private static function consent_html( string $text ): string {
		$text = trim( $text );
		if ( '' === $text ) {
			return esc_html__( 'I agree to the processing of my personal data for the purpose of handling this booking.', 'terminarz' );
		}
		// Inline markup only: the text is a <label>, so block-level and interactive elements other than links are dropped.
		return wp_kses(
			$text,
			array(
				'a'      => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				),
				'strong' => array(),
				'em'     => array(),
				'b'      => array(),
				'i'      => array(),
				'br'     => array(),
			)
		);
	}

	/**
	 * Normalises block attributes (they come from post content and may be edited by hand).
	 *
	 * @param array<string, mixed> $attributes Raw attributes.
	 * @return array{serviceIds: int[], defaultServiceId: int, showResourcePicker: bool, firstDayOfWeek: int}
	 */
	public static function sanitize_attributes( array $attributes ): array {
		$ids = array();
		if ( isset( $attributes['serviceIds'] ) && is_array( $attributes['serviceIds'] ) ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $attributes['serviceIds'] ) ) ) );
		}

		$first_day = isset( $attributes['firstDayOfWeek'] ) && is_numeric( $attributes['firstDayOfWeek'] ) ? (int) $attributes['firstDayOfWeek'] : -1;

		return array(
			'serviceIds'         => $ids,
			'defaultServiceId'   => isset( $attributes['defaultServiceId'] ) && is_numeric( $attributes['defaultServiceId'] ) ? absint( $attributes['defaultServiceId'] ) : 0,
			'showResourcePicker' => ! isset( $attributes['showResourcePicker'] ) || (bool) $attributes['showResourcePicker'],
			'firstDayOfWeek'     => $first_day >= 0 && $first_day <= 6 ? $first_day : -1,
		);
	}
}
