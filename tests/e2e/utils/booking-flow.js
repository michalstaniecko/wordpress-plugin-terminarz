/**
 * Helpers for the customer booking flow in the booking block and for checking availability.
 */
const { expect } = require( '@playwright/test' );

/**
 * Books the first free slot through the block and returns the REST response of the booking.
 *
 * @param {import('@playwright/test').Page} page            Page.
 * @param {string}                          pageUrl         URL of a page with the booking block.
 * @param {Object}                          customer        Customer.
 * @param {string}                          customer.email  E-mail.
 * @param {string}                          [customer.name] Name (default "E2E Customer").
 * @return {Promise<{booking: Object, startUtc: string, date: string}>} Booking response, slot and day.
 */
async function bookThroughBlock(
	page,
	pageUrl,
	{ email, name = 'E2E Customer' }
) {
	await page.goto( pageUrl );
	const block = page.locator( '.wp-block-terminarz-booking' );
	await expect(
		block.getByRole( 'heading', { name: 'Choose a day' } )
	).toBeVisible();
	const day = block.locator( '.trmz-calendar__day--available' ).first();
	const date = await day.getAttribute( 'data-date' );
	await day.click();

	const slot = block.getByRole( 'radio' ).first();
	await slot.check();
	const startUtc = await slot.getAttribute( 'value' );
	await block.getByRole( 'button', { name: 'Continue' } ).click();

	await block.getByLabel( 'Full name' ).fill( name );
	await block.getByLabel( 'E-mail' ).fill( email );
	await block.getByLabel( 'Phone' ).fill( '600 700 800' );
	await block.getByRole( 'checkbox', { name: /personal data/ } ).check();

	// The block may navigate away (payment) right after the response: capture its body on the way.
	let booking;
	await page.route( /terminarz\/v1\/bookings/, async ( route ) => {
		if ( route.request().method() !== 'POST' ) {
			return route.continue();
		}
		const response = await route.fetch();
		booking = await response.json();
		return route.fulfill( { response } );
	} );
	await block.getByRole( 'button', { name: 'Book appointment' } ).click();
	await expect.poll( () => booking ).toBeDefined();
	await page.unroute( /terminarz\/v1\/bookings/ );

	return { booking, startUtc, date };
}

/**
 * Slot starts offered on a day.
 *
 * @param {import('@playwright/test').APIRequestContext} request    Request context.
 * @param {number}                                       serviceId  Service ID.
 * @param {number}                                       resourceId Resource ID.
 * @param {string}                                       date       Day (Y-m-d).
 * @return {Promise<string[]>} UTC starts.
 */
async function offeredStarts( request, serviceId, resourceId, date ) {
	const response = await request.get( '/wp-json/terminarz/v1/availability', {
		params: {
			service: serviceId,
			resource: String( resourceId ),
			from: date,
			to: date,
		},
	} );
	expect( response.ok() ).toBe( true );
	const availability = await response.json();
	return availability.days[ 0 ].slots.map( ( slot ) => slot.start_utc );
}

module.exports = { bookThroughBlock, offeredStarts };
