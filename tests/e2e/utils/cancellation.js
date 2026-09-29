/**
 * Helpers for the customer cancellation link (e-mail → confirmation page → cancel).
 */
const { expect } = require( '@playwright/test' );

/**
 * Cancellation link from an e-mail body (HTML entities decoded), as a path relative to the site.
 *
 * @param {string} html E-mail HTML.
 * @return {string} Path with query.
 */
function cancelLink( html ) {
	const match = html.match( /href="([^"]*trmz_cancel=[^"]*)"/ );
	expect( match ).not.toBeNull();
	const url = new URL( match[ 1 ].replace( /&#038;|&amp;/g, '&' ) );
	return url.pathname + url.search;
}

/**
 * Opens the cancellation link, checks the confirmation page (and its security headers) and cancels.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          link Link path.
 * @return {Promise<void>}
 */
async function cancelWithLink( page, link ) {
	const response = await page.goto( link );
	expect( response.status() ).toBe( 200 );
	expect( response.headers()[ 'x-robots-tag' ] ).toContain( 'noindex' );
	expect( response.headers()[ 'referrer-policy' ] ).toBe( 'no-referrer' );
	expect( response.headers()[ 'x-frame-options' ] ).toBe( 'DENY' );
	expect( response.headers()[ 'content-security-policy' ] ).toBe(
		"frame-ancestors 'none'"
	);
	await expect(
		page.getByRole( 'heading', { name: 'Cancel your booking' } )
	).toBeVisible();

	await page.getByRole( 'button', { name: 'Cancel booking' } ).click();
	await expect(
		page.getByRole( 'heading', { name: 'Booking cancelled' } )
	).toBeVisible();
	await expect( page.locator( 'main' ) ).toContainText(
		'Your booking has been cancelled.'
	);

	// The same link again: nothing left to cancel.
	await page.goto( link );
	await expect(
		page.getByRole( 'heading', { name: 'Booking not active' } )
	).toBeVisible();
}

module.exports = { cancelLink, cancelWithLink };
