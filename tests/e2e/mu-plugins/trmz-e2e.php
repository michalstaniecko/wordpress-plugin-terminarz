<?php
/**
 * Plugin Name: Terminarz E2E helpers
 * Description: Test-only tweaks of the wp-env tests site used by Playwright (mapped by .wp-env.tests.json). Never shipped.
 *
 * @package Terminarz\Tests
 */

// PHPUnit integration tests run in the same WordPress installation: leave them untouched.
if ( defined( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	return;
}

/*
 * All browser tests come from one IP address, so the public booking limit (5 per 10 minutes, ADR-022) would make
 * repeated runs fail. The E2E site allows many more attempts; 429 handling of the block is tested with a mocked response
 * and the limiter itself by PHPUnit (RequestLimitTest).
 */
add_filter(
	'trmz_rate_limit',
	static function (): array {
		return array(
			'limit'  => 1000,
			'window' => 600,
		);
	}
);

/*
 * Site language switch for the translation test (i18n-polish.spec.js). The Polish core language pack is not installed
 * on wp-env, so WordPress refuses `WPLANG=pl_PL`; the `locale` filter gives the same site locale (core texts stay in
 * English, the plugin's own translations are loaded).
 */
add_filter(
	'locale',
	static function ( $locale ) {
		$forced = get_option( 'trmz_e2e_locale', '' );
		return is_string( $forced ) && '' !== $forced ? $forced : $locale;
	}
);

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'trmz-e2e/v1',
			'/site-language',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'args'                => array(
					'locale' => array(
						'type'     => 'string',
						'enum'     => array( '', 'pl_PL' ),
						'required' => true,
					),
				),
				'callback'            => static function ( WP_REST_Request $request ): array {
					update_option( 'trmz_e2e_locale', (string) $request['locale'] );
					return array( 'locale' => (string) $request['locale'] );
				},
			)
		);
	}
);
