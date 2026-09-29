<?php
/**
 * Plugin Name: Terminarz E2E mail catcher
 * Description: Test-only. Captures every e-mail of the wp-env tests site instead of sending it and exposes the captured
 *              e-mails to Playwright (`trmz-e2e/v1/mails`). Mapped by .wp-env.tests.json, never shipped.
 *
 * @package Terminarz\Tests
 */

// PHPUnit integration tests use the MockPHPMailer of the WordPress test suite instead.
if ( defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	return;
}

const TRMZ_E2E_MAILS_OPTION = 'trmz_e2e_mails';

/*
 * `pre_wp_mail` short-circuits wp_mail(): the e-mail is stored (the newest 50) and reported as sent.
 */
add_filter(
	'pre_wp_mail',
	static function ( $result, array $atts ) {
		$headers = $atts['headers'] ?? array();
		$mails   = get_option( TRMZ_E2E_MAILS_OPTION, array() );
		$mails   = is_array( $mails ) ? $mails : array();
		$mails[] = array(
			'to'      => is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'],
			'subject' => (string) $atts['subject'],
			'message' => (string) $atts['message'],
			'headers' => is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers,
			'time'    => time(),
		);
		update_option( TRMZ_E2E_MAILS_OPTION, array_slice( $mails, -50 ), false );
		return true;
	},
	10,
	2
);

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'trmz-e2e/v1',
			'/mails',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
					'callback'            => static function () {
						$mails = get_option( TRMZ_E2E_MAILS_OPTION, array() );
						return rest_ensure_response( is_array( $mails ) ? array_values( $mails ) : array() );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
					'callback'            => static function () {
						delete_option( TRMZ_E2E_MAILS_OPTION );
						return rest_ensure_response( array( 'deleted' => true ) );
					},
				),
			)
		);
	}
);
