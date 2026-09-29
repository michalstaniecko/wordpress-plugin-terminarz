/**
 * Helpers changing Terminarz settings through the settings screen (as a site owner would).
 */
const { expect } = require( '@playwright/test' );

/**
 * Saves setting fields on Terminarz → Settings.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').Admin} admin  Admin utils.
 * @param {import('@playwright/test').Page}                      page   Page.
 * @param {Object}                                               fields Values keyed by setting name, e.g. `{ payment_mode: 'full' }`.
 * @return {Promise<void>}
 */
async function saveSettings( admin, page, fields ) {
	await admin.visitAdminPage( 'admin.php', 'page=trmz-settings' );
	for ( const [ name, value ] of Object.entries( fields ) ) {
		const field = page.locator( `#trmz-${ name }` );
		const tag = await field.evaluate( ( el ) => el.tagName );
		const type = await field.getAttribute( 'type' );
		if ( tag === 'SELECT' ) {
			await field.selectOption( String( value ) );
		} else if ( type === 'checkbox' ) {
			await field.setChecked( Boolean( value ) );
		} else {
			await field.fill( String( value ) );
		}
	}
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();
	await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
}

/**
 * Sets the payment mode.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').Admin} admin Admin utils.
 * @param {import('@playwright/test').Page}                      page  Page.
 * @param {string}                                               mode  none|deposit|full.
 * @return {Promise<void>}
 */
function setPaymentMode( admin, page, mode ) {
	return saveSettings( admin, page, { payment_mode: mode } );
}

/**
 * Sets the customer cancellation limit (hours before the start).
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').Admin} admin Admin utils.
 * @param {import('@playwright/test').Page}                      page  Page.
 * @param {number}                                               hours Hours.
 * @return {Promise<void>}
 */
function setCancelLimit( admin, page, hours ) {
	return saveSettings( admin, page, { customer_cancel_limit_hours: hours } );
}

module.exports = { saveSettings, setPaymentMode, setCancelLimit };
