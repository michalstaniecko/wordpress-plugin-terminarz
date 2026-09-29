/**
 * Full path of the customer e-mails: booking → e-mail captured (mail catcher mu-plugin) → cancellation link from the
 * e-mail → confirmation page → cancel → slot free again in `/availability`. Without WooCommerce (staff confirms the
 * booking) and with WooCommerce (paid with the test gateway).
 */
const fs = require( 'fs' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	debugLogOffsetFile,
	findPhpProblems,
	readDebugLog,
} = require( '../utils/debug-log' );
const {
	activateWooCommerce,
	deactivateWooCommerce,
} = require( '../utils/woocommerce' );
const {
	createBookingPage,
	createResource,
	createService,
	setWorkingHours,
} = require( '../utils/booking-setup' );
const { bookThroughBlock, offeredStarts } = require( '../utils/booking-flow' );
const { clearMails, waitForMail } = require( '../utils/mails' );

test.describe.configure( { mode: 'serial' } );

const suffix = Date.now().toString( 36 );
const RESOURCE = `E2E Mail Room ${ suffix }`;
const FREE_SERVICE = `E2E Mail Consultation ${ suffix }`;
const PAID_SERVICE = `E2E Mail Paid Session ${ suffix }`;

let resourceId;
let freeServiceId;
let paidServiceId;
let freePageUrl;
let paidPageUrl;

/**
 * Sets the payment mode on the settings screen.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').Admin} admin Admin utils.
 * @param {import('@playwright/test').Page}                      page  Page.
 * @param {string}                                               mode  none|deposit|full.
 */
async function setPaymentMode( admin, page, mode ) {
	await admin.visitAdminPage( 'admin.php', 'page=trmz-settings' );
	await page.locator( '#trmz-payment_mode' ).selectOption( mode );
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();
	await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
}

/**
 * Sets the customer cancellation limit (hours before the start) on the settings screen.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').Admin} admin Admin utils.
 * @param {import('@playwright/test').Page}                      page  Page.
 * @param {number}                                               hours Hours.
 */
async function setCancelLimit( admin, page, hours ) {
	await admin.visitAdminPage( 'admin.php', 'page=trmz-settings' );
	await page
		.locator( '#trmz-customer_cancel_limit_hours' )
		.fill( String( hours ) );
	await page.getByRole( 'button', { name: 'Save Changes' } ).click();
	await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
}

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
 * Opens the cancellation link, checks the confirmation page and cancels.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          link Link path.
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

test.describe( 'E-mail with a cancellation link', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await activateWooCommerce( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await activateWooCommerce( requestUtils );
	} );

	test( 'admin configures a free and a paid service', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setPaymentMode( admin, page, 'none' );
		// The first free slot may be tomorrow: allow cancelling until the start (default limit: 24 hours).
		await setCancelLimit( admin, page, 0 );
		resourceId = await createResource( admin, page, RESOURCE );
		await setWorkingHours( admin, page, resourceId, '09:00', '17:00' );
		freeServiceId = await createService( admin, page, {
			name: FREE_SERVICE,
			resources: [ RESOURCE ],
			duration: 60,
		} );
		paidServiceId = await createService( admin, page, {
			name: PAID_SERVICE,
			resources: [ RESOURCE ],
			duration: 60,
			price: '120',
		} );
		freePageUrl = await createBookingPage(
			requestUtils,
			`Consultation ${ suffix }`,
			{ serviceIds: [ freeServiceId ] }
		);
		paidPageUrl = await createBookingPage(
			requestUtils,
			`Paid session ${ suffix }`,
			{ serviceIds: [ paidServiceId ] }
		);
		expect( paidServiceId ).toBeGreaterThan( 0 );
	} );

	test.describe( 'without WooCommerce', () => {
		const email = `mail-nowc-${ suffix }@example.org`;
		let booked;

		test.beforeAll( async ( { requestUtils } ) => {
			await deactivateWooCommerce( requestUtils );
			await clearMails( requestUtils );
		} );

		test.afterAll( async ( { requestUtils } ) => {
			await activateWooCommerce( requestUtils );
		} );

		test.describe( 'visitor', () => {
			test.use( { storageState: { cookies: [], origins: [] } } );

			test( 'books and is told about the e-mail', async ( {
				page,
				request,
				requestUtils,
			} ) => {
				booked = await bookThroughBlock( page, freePageUrl, { email } );
				expect( booked.booking.status ).toBe( 'pending' );
				await expect(
					page.locator( '.trmz-booking__email-notice' )
				).toContainText( 'link to cancel' );
				expect(
					await offeredStarts(
						request,
						freeServiceId,
						resourceId,
						booked.date
					)
				).not.toContain( booked.startUtc );

				const received = await waitForMail(
					requestUtils,
					( mail ) =>
						mail.to === email &&
						mail.subject.includes( 'received your booking request' )
				);
				expect( received.message ).toContain( 'trmz_cancel=' );
			} );
		} );

		test( 'admin confirms the booking', async ( { admin, page } ) => {
			await admin.visitAdminPage(
				'admin.php',
				`page=trmz-bookings&s=${ encodeURIComponent( email ) }`
			);
			const row = page
				.locator( '#the-list tr' )
				.filter( { hasText: email } );
			await row.hover();
			await row.getByRole( 'link', { name: 'Confirm' } ).click();
			await expect(
				page.getByText( 'Booking confirmed.' )
			).toBeVisible();
		} );

		test.describe( 'customer', () => {
			test.use( { storageState: { cookies: [], origins: [] } } );

			test( 'cancels with the link from the confirmation e-mail and the slot is free again', async ( {
				page,
				request,
				requestUtils,
			} ) => {
				const confirmation = await waitForMail(
					requestUtils,
					( mail ) =>
						mail.to === email &&
						mail.subject.includes( 'Your booking is confirmed' )
				);
				expect( confirmation.message ).toContain( FREE_SERVICE );

				await cancelWithLink(
					page,
					cancelLink( confirmation.message )
				);

				expect(
					await offeredStarts(
						request,
						freeServiceId,
						resourceId,
						booked.date
					)
				).toContain( booked.startUtc );
				await waitForMail(
					requestUtils,
					( mail ) =>
						mail.to === email &&
						mail.subject.includes( 'has been cancelled' )
				);
				await waitForMail( requestUtils, ( mail ) =>
					mail.subject.includes( 'Booking cancelled:' )
				);
			} );
		} );
	} );

	test.describe( 'with WooCommerce', () => {
		const email = `mail-wc-${ suffix }@example.org`;
		let booked;

		test.beforeAll( async ( { requestUtils } ) => {
			await clearMails( requestUtils );
		} );

		test( 'admin enables full payment', async ( { admin, page } ) => {
			await setPaymentMode( admin, page, 'full' );
			await expect( page.locator( '#trmz-payment_mode' ) ).toHaveValue(
				'full'
			);
		} );

		test.describe( 'customer', () => {
			test.use( { storageState: { cookies: [], origins: [] } } );

			test( 'pays and gets the confirmation e-mail only after the payment', async ( {
				page,
				requestUtils,
			} ) => {
				booked = await bookThroughBlock( page, paidPageUrl, { email } );
				expect( booked.booking.status ).toBe( 'pending_payment' );
				await page.waitForURL( /order-pay/ );

				const before = await requestUtils.rest( {
					path: '/trmz-e2e/v1/mails',
				} );
				expect(
					before.filter( ( mail ) =>
						mail.subject.includes( 'Your booking is confirmed' )
					)
				).toEqual( [] );

				await page.getByLabel( 'Test payment' ).check();
				await page.getByLabel( 'Payment succeeds' ).check();
				await page
					.getByRole( 'button', { name: /Pay for order/i } )
					.click();
				await page.waitForURL( /order-received/ );
			} );

			test( 'cancels with the link from the e-mail and the slot is free again', async ( {
				page,
				request,
				requestUtils,
			} ) => {
				const confirmation = await waitForMail(
					requestUtils,
					( mail ) =>
						mail.to === email &&
						mail.subject.includes( 'Your booking is confirmed' )
				);
				expect(
					await offeredStarts(
						request,
						paidServiceId,
						resourceId,
						booked.date
					)
				).not.toContain( booked.startUtc );

				await cancelWithLink(
					page,
					cancelLink( confirmation.message )
				);

				expect(
					await offeredStarts(
						request,
						paidServiceId,
						resourceId,
						booked.date
					)
				).toContain( booked.startUtc );
			} );
		} );

		test( 'admin sees the cancelled booking; the payment was not refunded automatically', async ( {
			admin,
			page,
		} ) => {
			await admin.visitAdminPage(
				'admin.php',
				`page=trmz-bookings&s=${ encodeURIComponent( email ) }`
			);
			const row = page
				.locator( '#the-list tr' )
				.filter( { hasText: email } );
			await expect(
				row.getByText( 'Cancelled', { exact: true } )
			).toBeVisible();
			await row.locator( 'a.row-title' ).click();
			await page
				.locator( '.trmz-booking-details' )
				.getByRole( 'link', { name: /^#\d+$/ } )
				.click();
			await expect( page.locator( 'body' ) ).toContainText(
				'NOT refunded automatically'
			);
		} );

		test( 'admin restores the settings', async ( { admin, page } ) => {
			await setPaymentMode( admin, page, 'none' );
			await setCancelLimit( admin, page, 24 );
			await expect(
				page.locator( '#trmz-customer_cancel_limit_hours' )
			).toHaveValue( '24' );
		} );
	} );

	test( 'logs no PHP notices', async ( { requestUtils } ) => {
		const offset = Number( fs.readFileSync( debugLogOffsetFile, 'utf8' ) );
		const log = await readDebugLog( requestUtils.request );

		expect( findPhpProblems( log, offset ) ).toEqual( [] );
	} );
} );
