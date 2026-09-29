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
