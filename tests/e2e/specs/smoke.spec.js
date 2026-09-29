/**
 * Smoke tests: the plugin is active and does not produce PHP diagnostics.
 */
const fs = require( 'fs' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	debugLogOffsetFile,
	readDebugLog,
	findPhpProblems,
} = require( '../utils/debug-log' );
const {
	activateWooCommerce,
	deactivateWooCommerce,
} = require( '../utils/woocommerce' );

test.describe( 'Smoke', () => {
	test.afterAll( async ( { requestUtils } ) => {
		await activateWooCommerce( requestUtils );
	} );

	test( 'plugin is active on the plugins screen', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'plugins.php' );

		const row = page.locator( 'tr[data-slug="terminarz"]' );
		await expect( row ).toHaveClass( /\bactive\b/ );
		await expect(
			row.getByRole( 'link', { name: /Deactivate Terminarz/i } )
		).toBeVisible();
	} );

	test( 'plugin stays active and loads without WooCommerce', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await deactivateWooCommerce( requestUtils );

		await admin.visitAdminPage( 'plugins.php' );
		await expect(
			page.locator( 'tr[data-slug="woocommerce"]' )
		).toHaveClass( /\binactive\b/ );
		await expect( page.locator( 'tr[data-slug="terminarz"]' ) ).toHaveClass(
			/\bactive\b/
		);

		const response = await page.goto( '/' );
		expect( response?.status() ).toBe( 200 );

		await activateWooCommerce( requestUtils );
	} );

	test( 'no PHP notices or warnings in debug.log', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Exercise a few typical requests first.
		await admin.visitAdminPage( 'index.php' );
		await admin.visitAdminPage( 'plugins.php' );
		await page.goto( '/' );

		const offset = Number( fs.readFileSync( debugLogOffsetFile, 'utf8' ) );
		const log = await readDebugLog( requestUtils.request );

		expect( findPhpProblems( log, offset ) ).toEqual( [] );
	} );
} );
