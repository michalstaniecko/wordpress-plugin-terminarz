<?php
/**
 * Entry point of the optional WooCommerce integration.
 *
 * @package Terminarz
 */

declare(strict_types=1);

namespace Terminarz\Integrations\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Terminarz\Infrastructure\Module;

/**
 * Declares compatibility with WooCommerce features and, once plugins are loaded, registers the integration components
 * — only when a supported WooCommerce (>= 8.0) is active. Without WooCommerce nothing is hooked and bookings work
 * without payments.
 */
final class WooCommerceModule implements Module {

	/**
	 * Action fired after the integration components were registered.
	 */
	public const LOADED_ACTION = 'trmz_woocommerce_integration_loaded';

	/**
	 * Components registered when WooCommerce is active.
	 *
	 * @var Module[]
	 */
	private array $components;

	/**
	 * Whether the components were registered.
	 *
	 * @var bool
	 */
	private bool $integration_loaded = false;

	/**
	 * Constructor.
	 *
	 * @param Module[]|null $components Components; null = the default set.
	 */
	public function __construct( ?array $components = null ) {
		$this->components = $components ?? self::default_components();
	}

	/**
	 * Default integration components.
	 *
	 * @return Module[]
	 */
	public static function default_components(): array {
		return array(
			new OrderPayments(),
			new OrderAdmin(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );

		if ( did_action( 'plugins_loaded' ) ) {
			$this->load_integration();
		} else {
			// WooCommerce is loaded after this plugin (alphabetical order), so the check waits for all plugins.
			add_action( 'plugins_loaded', array( $this, 'load_integration' ), 20 );
		}
	}

	/**
	 * `before_woocommerce_init`: declares support for HPOS (custom order tables) and the Cart/Checkout blocks.
	 * The integration only uses the WooCommerce CRUD API (never order post meta), so it works with both order stores.
	 */
	public function declare_compatibility(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			return;
		}
		FeaturesUtil::declare_compatibility( 'custom_order_tables', TRMZ_FILE, true );
		FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TRMZ_FILE, true );
	}

	/**
	 * `plugins_loaded`: registers the components when a supported WooCommerce is active; warns about an outdated one.
	 */
	public function load_integration(): void {
		if ( $this->integration_loaded ) {
			return;
		}

		if ( ! WooCommerce::is_active() ) {
			if ( WooCommerce::is_outdated() ) {
				add_action( 'admin_notices', array( $this, 'outdated_notice' ) );
			}
			return;
		}

		foreach ( $this->components as $component ) {
			$component->register();
		}
		$this->integration_loaded = true;

		/**
		 * Fires after the WooCommerce integration of Terminarz has been registered.
		 */
		do_action( 'trmz_woocommerce_integration_loaded' );
	}

	/**
	 * Whether the integration components are registered.
	 */
	public function is_integration_loaded(): bool {
		return $this->integration_loaded;
	}

	/**
	 * Registered components.
	 *
	 * @return Module[]
	 */
	public function components(): array {
		return $this->components;
	}

	/**
	 * Admin notice: WooCommerce is too old for online payments.
	 */
	public function outdated_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: required WooCommerce version, 2: installed WooCommerce version. */
					__( 'Terminarz online payments require WooCommerce %1$s or newer (installed: %2$s). Bookings are accepted without payment until WooCommerce is updated.', 'terminarz' ),
					WooCommerce::MIN_VERSION,
					WooCommerce::version()
				)
			)
		);
	}
}
