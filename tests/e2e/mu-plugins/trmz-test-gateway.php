<?php
/**
 * Plugin Name: Terminarz E2E test payment gateway
 * Description: Test-only WooCommerce payment method for Playwright (mapped by .wp-env.tests.json, never shipped). The
 *              customer picks the outcome — success or failure — no real payment provider, no keys.
 *
 * @package Terminarz\Tests
 */

// PHPUnit integration tests run in the same WordPress installation: leave them untouched.
if ( defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	return;
}

/*
 * New WooCommerce stores start in "coming soon" mode, which hides the shop pages (including "pay for order")
 * from visitors. The E2E store is always live.
 */
add_filter(
	'pre_option_woocommerce_coming_soon',
	static function (): string {
		return 'no';
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		/**
		 * Test gateway: "success" completes the payment, "failure" marks the order as failed.
		 */
		final class Trmz_Test_Gateway extends WC_Payment_Gateway {

			public const ID = 'trmz_test_gateway';

			/**
			 * Constructor.
			 */
			public function __construct() {
				$this->id                 = self::ID;
				$this->method_title       = 'Terminarz test payment';
				$this->method_description = 'E2E only: simulates a successful or failed payment.';
				$this->title              = 'Test payment';
				$this->description        = 'Simulated payment (E2E tests).';
				$this->has_fields         = true;
				$this->supports           = array( 'products' );
				$this->enabled            = 'yes';
			}

			/**
			 * Always available in the test store.
			 */
			public function is_available(): bool {
				return true;
			}

			/**
			 * Outcome picker.
			 */
			public function payment_fields(): void {
				echo '<fieldset id="trmz-test-gateway-outcome"><legend>Payment outcome</legend>';
				echo '<label><input type="radio" name="trmz_test_outcome" value="success" checked="checked" /> Payment succeeds</label><br />';
				echo '<label><input type="radio" name="trmz_test_outcome" value="failure" /> Payment fails</label>';
				echo '</fieldset>';
			}

			/**
			 * Processes the simulated payment.
			 *
			 * @param int $order_id Order ID.
			 * @return array<string, string>
			 */
			public function process_payment( $order_id ): array {
				$order = wc_get_order( $order_id );
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the pay/checkout nonce.
				$outcome = isset( $_POST['trmz_test_outcome'] ) ? sanitize_key( wp_unslash( $_POST['trmz_test_outcome'] ) ) : 'success';

				if ( 'failure' === $outcome ) {
					$order->update_status( 'failed', 'Test payment declined.' );
					wc_add_notice( 'Test payment declined.', 'error' );
					return array( 'result' => 'failure' );
				}

				$order->payment_complete( 'trmz-test-' . $order_id . '-' . time() );
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}
		}

		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ): array {
				$gateways[] = 'Trmz_Test_Gateway';
				return $gateways;
			}
		);
	}
);

/*
 * Test-only REST route: ends the payment hold of a booking now and runs the expiry job, so that "abandoned payment"
 * can be tested without waiting 15 minutes.
 */
add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'trmz-e2e/v1',
			'/expire-hold',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'args'                => array(
					'public_id' => array(
						'type'     => 'string',
						'pattern'  => '^[0-9a-f]{32}$',
						'required' => true,
					),
				),
				'callback'            => static function ( WP_REST_Request $request ): array {
					global $wpdb;
					$table = $wpdb->prefix . 'trmz_bookings';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper.
					$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hold_expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE public_id = %s AND status = 'pending_payment'", $request['public_id'] ) );
					do_action( 'trmz_expire_holds' );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test helper.
					$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE public_id = %s", $request['public_id'] ) );
					return array( 'status' => $status );
				},
			)
		);
	}
);
