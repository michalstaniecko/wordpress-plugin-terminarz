/**
 * Global setup: logs in the admin user, stores the storage state and remembers
 * the current size of debug.log, so tests only inspect entries they caused.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { request } = require( '@playwright/test' );
const { RequestUtils } = require( '@wordpress/e2e-test-utils-playwright' );
const { debugLogOffsetFile, readDebugLog } = require( './utils/debug-log' );

/**
 * @param {import('@playwright/test').FullConfig} config Playwright config.
 */
async function globalSetup( config ) {
	const { storageState, baseURL } = config.projects[ 0 ].use;
	const storageStatePath =
		typeof storageState === 'string' ? storageState : undefined;

	const requestContext = await request.newContext( { baseURL } );
	const requestUtils = new RequestUtils( requestContext, {
		storageStatePath,
	} );

	// Logs in as admin (admin/password, wp-env test credentials) and saves cookies + REST nonce.
	await requestUtils.setupRest();

	// Known baseline: both plugins active before every run.
	await requestUtils.activatePlugin( 'terminarz' );
	await requestUtils.activatePlugin( 'woocommerce' );
	// Known baseline: 24-hour times in the block (specs compare "HH:MM" labels); 12 h is covered separately.
	await requestUtils.updateSiteSettings( { time_format: 'H:i' } );

	const log = await readDebugLog( requestContext );
	fs.mkdirSync( path.dirname( debugLogOffsetFile ), { recursive: true } );
	fs.writeFileSync( debugLogOffsetFile, String( log.length ) );

	await requestContext.dispose();
}

module.exports = globalSetup;
