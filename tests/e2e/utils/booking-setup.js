/**
 * Helpers configuring a bookable resource and service through the admin screens (same steps as a site owner).
 */
const { expect } = require( '@playwright/test' );

/**
 * Creates a resource and returns its ID.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').Admin} admin Admin utils.
 * @param {import('@playwright/test').Page}                      page  Page.
 * @param {string}                                               name  Resource name.
 * @return {Promise<number>} Resource ID.
 */
async function createResource( admin, page, name ) {
	await admin.visitAdminPage( 'admin.php', 'page=trmz-resources&view=edit' );
	await page.getByLabel( 'Name', { exact: true } ).fill( name );
	await page.getByRole( 'button', { name: 'Add resource' } ).click();
	await expect( page.getByText( 'Resource added.' ) ).toBeVisible();
	return Number( new URL( page.url() ).searchParams.get( 'id' ) );
}

/**
 * Creates a service performed by the given resources and returns its ID.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').Admin} admin              Admin utils.
 * @param {import('@playwright/test').Page}                      page               Page.
 * @param {Object}                                               options            Options.
 * @param {string}                                               options.name       Service name.
 * @param {string[]}                                             options.resources  Resource names.
 * @param {number}                                               [options.duration] Minutes (default 60).
 * @param {string}                                               [options.price]    Price (default "0").
 * @return {Promise<number>} Service ID.
 */
async function createService(
	admin,
	page,
	{ name, resources, duration = 60, price = '0' }
) {
	await admin.visitAdminPage( 'admin.php', 'page=trmz-services&view=edit' );
	await page.getByLabel( 'Name', { exact: true } ).fill( name );
	await page.getByLabel( 'Duration (minutes)' ).fill( String( duration ) );
	await page.getByLabel( 'Buffer after (minutes)' ).fill( '0' );
	await page.getByLabel( 'Price', { exact: true } ).fill( price );
	for ( const resource of resources ) {
		await page.getByLabel( resource, { exact: true } ).check();
	}
	await page.getByRole( 'button', { name: 'Add service' } ).click();
	await expect( page.getByText( 'Service added.' ) ).toBeVisible();
	return Number( new URL( page.url() ).searchParams.get( 'id' ) );
}

/**
 * Sets the same working hours for all seven days of the week.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').Admin} admin      Admin utils.
 * @param {import('@playwright/test').Page}                      page       Page.
 * @param {number}                                               resourceId Resource ID.
 * @param {string}                                               [start]    Start (default 09:00).
 * @param {string}                                               [end]      End (default 17:00).
 * @return {Promise<void>}
 */
async function setWorkingHours(
	admin,
	page,
	resourceId,
	start = '09:00',
	end = '17:00'
) {
	await admin.visitAdminPage(
		'admin.php',
		`page=trmz-schedule&resource=${ resourceId }`
	);
	for ( let day = 1; day <= 7; day++ ) {
		await page
			.locator( `input[name="work[${ day }][0][start]"]` )
			.fill( start );
		await page
			.locator( `input[name="work[${ day }][0][end]"]` )
			.fill( end );
	}
	await page.getByRole( 'button', { name: 'Save working hours' } ).click();
	await expect( page.getByText( 'Working hours saved.' ) ).toBeVisible();
}

/**
 * Creates and publishes a page containing the booking block; returns its URL.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils Request utils.
 * @param {string}                                                      title        Page title.
 * @param {Object}                                                      [attributes] Block attributes.
 * @return {Promise<string>} Page URL.
 */
async function createBookingPage( requestUtils, title, attributes = {} ) {
	const json = Object.keys( attributes ).length
		? ' ' + JSON.stringify( attributes )
		: '';
	const created = await requestUtils.rest( {
		path: '/wp/v2/pages',
		method: 'POST',
		data: {
			title,
			status: 'publish',
			content: `<!-- wp:terminarz/booking${ json } /-->`,
		},
	} );
	return created.link;
}

module.exports = {
	createResource,
	createService,
	setWorkingHours,
	createBookingPage,
};
