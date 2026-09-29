/**
 * Online payment through WooCommerce (test gateway from tests/e2e/mu-plugins/trmz-test-gateway.php):
 * booking in the block → "pay for order" page → payment → confirmed booking in the panel; abandoned payment → hold
 * expiry → slot free again; failed payment → slot released; WooCommerce deactivated → booking without payment.
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
const {
	bookThroughBlock: bookInBlock,
	offeredStarts: offeredOn,
} = require( '../utils/booking-flow' );

test.describe.configure( { mode: 'serial' } );

const suffix = Date.now().toString( 36 );
const RESOURCE = `E2E Pay Studio ${ suffix }`;
const SERVICE = `E2E Pay Session ${ suffix }`;

let resourceId;
let serviceId;
let pageUrl;

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
 * Books the first free slot of the paid service through the block.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {string}                          email Customer e-mail.
 * @return {Promise<{booking: Object, startUtc: string, date: string}>} Booking response, slot and day.
 */
function bookThroughBlock( page, email ) {
	return bookInBlock( page, pageUrl, { email, name: 'Pay Customer' } );
}

/**
 * Slot starts offered on a day.
 *
 * @param {import('@playwright/test').APIRequestContext} request Request context.
 * @param {string}                                       date    Day (Y-m-d).
 * @return {Promise<string[]>} UTC starts.
 */
function offeredStarts( request, date ) {
	return offeredOn( request, serviceId, resourceId, date );
}

/**
 * Status label of the booking of a customer in the bookings list.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').Admin} admin Admin utils.
 * @param {import('@playwright/test').Page}                      page  Page.
 * @param {string}                                               email Customer e-mail.
 * @return {import('@playwright/test').Locator} Row.
 */
async function bookingRow( admin, page, email ) {
	await admin.visitAdminPage(
		'admin.php',
		`page=trmz-bookings&s=${ encodeURIComponent( email ) }`
	);
	return page.locator( '#the-list tr' ).filter( { hasText: email } );
}

test.describe( 'Payments with WooCommerce', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await activateWooCommerce( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await activateWooCommerce( requestUtils );
	} );

	test( 'admin enables full payment and configures a paid service', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setPaymentMode( admin, page, 'full' );
		await expect(
			page.getByText( 'Payments are processed by WooCommerce.' )
		).toBeVisible();

		resourceId = await createResource( admin, page, RESOURCE );
		await setWorkingHours( admin, page, resourceId, '09:00', '17:00' );
		serviceId = await createService( admin, page, {
			name: SERVICE,
			resources: [ RESOURCE ],
			duration: 60,
			price: '150',
		} );
		pageUrl = await createBookingPage(
			requestUtils,
			`Pay for a session ${ suffix }`,
			{ serviceIds: [ serviceId ] }
		);
		expect( serviceId ).toBeGreaterThan( 0 );
	} );

	test.describe( 'visitor', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'pays for the booking and gets a confirmed appointment', async ( {
			page,
		} ) => {
			const email = `pay-ok-${ suffix }@example.org`;
			const { booking } = await bookThroughBlock( page, email );

			expect( booking.status ).toBe( 'pending_payment' );
			expect( booking.payment_url ).toContain( 'order-pay' );
			await page.waitForURL( /order-pay/ );

			// "Pay for order" page: the order line shows the service, the amount is the full price.
			await expect( page.getByText( SERVICE ).first() ).toBeVisible();
			await expect( page.locator( 'body' ) ).toContainText( '150' );
			await page.getByLabel( 'Test payment' ).check();
			await page.getByLabel( 'Payment succeeds' ).check();
			await page
				.getByRole( 'button', { name: /Pay for order/i } )
				.click();

			await page.waitForURL( /order-received/ );
			await expect( page.locator( 'body' ) ).toContainText(
				/order has been received|Thank you/i
			);
		} );
	} );

	test( 'admin sees the paid booking confirmed and linked to the order', async ( {
		admin,
		page,
	} ) => {
		const email = `pay-ok-${ suffix }@example.org`;
		const row = await bookingRow( admin, page, email );
		await expect( row ).toHaveCount( 1 );
		await expect(
			row.getByText( 'Confirmed', { exact: true } )
		).toBeVisible();

		await row.locator( 'a.row-title' ).click();
		const details = page.locator( '.trmz-booking-details' );
		await expect( details ).toContainText( /Completed|Processing/ );
		await details.getByRole( 'link', { name: /^#\d+$/ } ).click();

		// Order edit screen (HPOS or legacy) with the booking box.
		const box = page.locator( '#trmz-booking' );
		await expect( box ).toBeVisible();
		await expect( box ).toContainText( SERVICE );
		await expect( box ).toContainText( 'Confirmed' );
		await expect(
			box.getByRole( 'link', { name: 'View booking' } )
		).toBeVisible();
	} );

	test.describe( 'visitor who abandons the payment', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		let abandoned;

		test( 'is redirected to the payment page and leaves', async ( {
			page,
			request,
		} ) => {
			abandoned = await bookThroughBlock(
				page,
				`pay-abandon-${ suffix }@example.org`
			);
			expect( abandoned.booking.status ).toBe( 'pending_payment' );
			await page.waitForURL( /order-pay/ );

			// While the payment is pending, the slot is held.
			expect(
				await offeredStarts( request, abandoned.date )
			).not.toContain( abandoned.startUtc );
		} );

		test( 'the hold expires and the slot is free again', async ( {
			request,
			requestUtils,
		} ) => {
			const result = await requestUtils.rest( {
				path: '/trmz-e2e/v1/expire-hold',
				method: 'POST',
				data: { public_id: abandoned.booking.public_id },
			} );
			expect( result.status ).toBe( 'expired' );

			expect( await offeredStarts( request, abandoned.date ) ).toContain(
				abandoned.startUtc
			);
		} );
	} );

	test( 'admin sees the expired booking and the cancelled order', async ( {
		admin,
		page,
	} ) => {
		const row = await bookingRow(
			admin,
			page,
			`pay-abandon-${ suffix }@example.org`
		);
		await expect(
			row.getByText( 'Expired', { exact: true } )
		).toBeVisible();
		await row.locator( 'a.row-title' ).click();
		await expect( page.locator( '.trmz-booking-details' ) ).toContainText(
			'Cancelled'
		);
	} );

	test.describe( 'visitor whose payment fails', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'sees the error and the slot is released', async ( {
			page,
			request,
		} ) => {
			const { date, startUtc } = await bookThroughBlock(
				page,
				`pay-fail-${ suffix }@example.org`
			);
			await page.waitForURL( /order-pay/ );
			await page.getByLabel( 'Test payment' ).check();
			await page.getByLabel( 'Payment fails' ).check();
			await page
				.getByRole( 'button', { name: /Pay for order/i } )
				.click();

			await expect(
				page.getByText( 'Test payment declined.' ).first()
			).toBeVisible();
			await expect
				.poll( async () => offeredStarts( request, date ) )
				.toContain( startUtc );
		} );
	} );

	test.describe( 'without WooCommerce', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await deactivateWooCommerce( requestUtils );
		} );

		test.afterAll( async ( { requestUtils } ) => {
			await activateWooCommerce( requestUtils );
		} );

		test.describe( 'visitor', () => {
			test.use( { storageState: { cookies: [], origins: [] } } );

			test( 'books without payment although a payment mode is set', async ( {
				page,
			} ) => {
				const { booking } = await bookThroughBlock(
					page,
					`pay-nowc-${ suffix }@example.org`
				);

				expect( booking.status ).toBe( 'pending' );
				expect( booking.payment_url ).toBeUndefined();
				const block = page.locator( '.wp-block-terminarz-booking' );
				await expect(
					block.getByRole( 'heading', {
						name: 'Thank you for your booking',
					} )
				).toBeVisible();
				await expect( page ).toHaveURL( pageUrl );
			} );
		} );
	} );

	test( 'admin turns payments off again', async ( { admin, page } ) => {
		await setPaymentMode( admin, page, 'none' );
		await expect( page.locator( '#trmz-payment_mode' ) ).toHaveValue(
			'none'
		);
	} );

	test( 'logs no PHP notices', async ( { requestUtils } ) => {
		const offset = Number( fs.readFileSync( debugLogOffsetFile, 'utf8' ) );
		const log = await readDebugLog( requestUtils.request );

		expect( findPhpProblems( log, offset ) ).toEqual( [] );
	} );
} );
