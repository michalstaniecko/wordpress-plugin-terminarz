/**
 * Playwright configuration for E2E tests (see docs/ARCHITECTURE.md, ADR-010).
 *
 * Runs against the wp-env *tests* environment (http://localhost:8889, `.wp-env.tests.json`).
 */
const path = require( 'path' );
const { defineConfig, devices } = require( '@playwright/test' );

process.env.WP_ARTIFACTS_PATH ??= path.join( __dirname, 'artifacts' );
process.env.STORAGE_STATE_PATH ??= path.join(
	process.env.WP_ARTIFACTS_PATH,
	'storage-states/admin.json'
);
process.env.WP_BASE_URL ??= 'http://localhost:8889';

const baseURL = new URL( process.env.WP_BASE_URL );

module.exports = defineConfig( {
	testDir: './tests/e2e/specs',
	outputDir: path.join( process.env.WP_ARTIFACTS_PATH, 'test-results' ),
	globalSetup: require.resolve( './tests/e2e/global-setup.js' ),
	reporter: process.env.CI
		? [
				[ 'github' ],
				[ 'list' ],
				[
					'html',
					{ open: 'never', outputFolder: 'playwright-report' },
				],
			]
		: [
				[ 'list' ],
				[
					'html',
					{ open: 'never', outputFolder: 'playwright-report' },
				],
			],
	forbidOnly: !! process.env.CI,
	// Tests share one WordPress instance (plugin state, options), so they run serially.
	fullyParallel: false,
	workers: 1,
	retries: process.env.CI ? 1 : 0,
	timeout: 60_000,
	reportSlowTests: null,
	use: {
		baseURL: baseURL.href,
		headless: true,
		viewport: { width: 1280, height: 800 },
		locale: 'en-US',
		contextOptions: { reducedMotion: 'reduce', strictSelectors: true },
		storageState: process.env.STORAGE_STATE_PATH,
		actionTimeout: 10_000,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'on-first-retry',
	},
	webServer: {
		command: 'npm run env:start:tests',
		url: baseURL.href,
		timeout: 300_000,
		reuseExistingServer: true,
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
} );
