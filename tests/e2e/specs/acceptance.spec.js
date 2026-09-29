/**
 * Acceptance suite of the 1.0 release (epic #1, issue #49): the whole path of a service business and its customer.
 *
 * (a) With WooCommerce: the admin configures a resource and a paid service in the panel → the customer books in the
 *     block and pays with the test gateway → the confirmation e-mail is captured → the admin sees the confirmed booking
 *     → the customer cancels with the link from the e-mail → the slot is free again.
 * (b) The same without WooCommerce (no payment): the booking waits for confirmation by the admin.
 * (c) Response time of `GET /availability` for 30 days × 10 resources on this wp-env site (reported, loose limit).
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
const { saveSettings } = require( '../utils/settings' );
const { cancelLink, cancelWithLink } = require( '../utils/cancellation' );

test.describe.configure( { mode: 'serial' } );

const suffix = Date.now().toString( 36 );
const RESOURCE = `Acceptance Therapist ${ suffix }`;
const SERVICE = `Acceptance Massage ${ suffix }`;
const PRICE = '180';

let resourceId;
let serviceId;
let pageUrl;

/**
 * Row of a customer's booking in Terminarz → Bookings.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').Admin} admin Admin utils.
 * @param {import('@playwright/test').Page}                      page  Page.
 * @param {string}                                               email Customer e-mail.
 * @return {Promise<import('@playwright/test').Locator>} Row.
 */
async function bookingRow( admin, page, email ) {
	await admin.visitAdminPage(
		'admin.php',
		`page=trmz-bookings&s=${ encodeURIComponent( email ) }`
	);
	const row = page.locator( '#the-list tr' ).filter( { hasText: email } );
	await expect( row ).toHaveCount( 1 );
	return row;
}

/**
 * Median of numbers.
 *
 * @param {number[]} values Values.
 * @return {number} Median.
 */
function median( values ) {
	const sorted = [ ...values ].sort( ( a, b ) => a - b );
	const middle = Math.floor( sorted.length / 2 );
	return sorted.length % 2
		? sorted[ middle ]
		: ( sorted[ middle - 1 ] + sorted[ middle ] ) / 2;
}

/**
 * Day (Y-m-d, UTC) `days` days from now.
 *
 * @param {number} days Days from today.
 * @return {string} Date.
 */
function dayFromNow( days ) {
	return new Date( Date.now() + days * 86400000 )
		.toISOString()
		.slice( 0, 10 );
}

test.describe( 'Acceptance: booking with payment, e-mail and cancellation', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await activateWooCommerce( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await activateWooCommerce( requestUtils );
	} );

	test( 'admin configures the resource, its hours, a paid service and the settings in the panel', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Full payment through WooCommerce; customers may cancel until the start (the first free slot may be tomorrow).
		await saveSettings( admin, page, {
			payment_mode: 'full',
			customer_cancel_limit_hours: 0,
			auto_confirm: false,
		} );
		await expect(
			page.getByText( 'Payments are processed by WooCommerce.' )
		).toBeVisible();

		resourceId = await createResource( admin, page, RESOURCE );
		await setWorkingHours( admin, page, resourceId, '08:00', '18:00' );
		serviceId = await createService( admin, page, {
			name: SERVICE,
			resources: [ RESOURCE ],
			duration: 60,
			price: PRICE,
		} );
		pageUrl = await createBookingPage(
			requestUtils,
			`Book a massage ${ suffix }`,
			{ serviceIds: [ serviceId ] }
		);
		expect( serviceId ).toBeGreaterThan( 0 );
	} );

	test.describe( '(a) with WooCommerce', () => {
		const email = `acceptance-wc-${ suffix }@example.org`;
		let booked;

		test.beforeAll( async ( { requestUtils } ) => {
			await clearMails( requestUtils );
		} );

		test.describe( 'customer books', () => {
			test.use( { storageState: { cookies: [], origins: [] } } );

			test( 'books in the block and pays with the test gateway', async ( {
				page,
				request,
			} ) => {
				booked = await bookThroughBlock( page, pageUrl, {
					email,
					name: 'Anna Acceptance',
				} );
				expect( booked.booking.status ).toBe( 'pending_payment' );
				await page.waitForURL( /order-pay/ );
				await expect( page.locator( 'body' ) ).toContainText( PRICE );

				// The slot is held while the customer pays.
				expect(
					await offeredStarts(
						request,
						serviceId,
						resourceId,
						booked.date
					)
				).not.toContain( booked.startUtc );

				await page.getByLabel( 'Test payment' ).check();
				await page.getByLabel( 'Payment succeeds' ).check();
				await page
					.getByRole( 'button', { name: /Pay for order/i } )
					.click();
				await page.waitForURL( /order-received/ );
			} );
		} );

		test( 'the confirmation e-mails are sent after the payment', async ( {
			requestUtils,
		} ) => {
			const confirmation = await waitForMail(
				requestUtils,
				( mail ) =>
					mail.to === email &&
					mail.subject.includes( 'Your booking is confirmed' )
			);
			expect( confirmation.message ).toContain( SERVICE );
			expect( confirmation.message ).toContain( 'trmz_cancel=' );
			await waitForMail( requestUtils, ( mail ) =>
				mail.subject.includes( `New booking: ${ SERVICE }` )
			);
		} );

		test( 'admin sees the confirmed booking linked to the paid order', async ( {
			admin,
			page,
		} ) => {
			const row = await bookingRow( admin, page, email );
			await expect(
				row.getByText( 'Confirmed', { exact: true } )
			).toBeVisible();
			await row.locator( 'a.row-title' ).click();
			const details = page.locator( '.trmz-booking-details' );
			await expect( details ).toContainText( SERVICE );
			await expect( details ).toContainText( RESOURCE );
			await expect( details ).toContainText( /Completed|Processing/ );
		} );

		test.describe( 'customer cancels', () => {
			test.use( { storageState: { cookies: [], origins: [] } } );

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

				await cancelWithLink(
					page,
					cancelLink( confirmation.message )
				);

				expect(
					await offeredStarts(
						request,
						serviceId,
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
			} );
		} );

		test( 'admin sees the booking cancelled and the business gets the cancellation e-mail', async ( {
			admin,
			page,
			requestUtils,
		} ) => {
			const row = await bookingRow( admin, page, email );
			await expect(
				row.getByText( 'Cancelled', { exact: true } )
			).toBeVisible();
			const business = await waitForMail( requestUtils, ( mail ) =>
				mail.subject.includes( `Booking cancelled: ${ SERVICE }` )
			);
			expect( business.message ).toContain( email );
		} );
	} );

	test.describe( '(b) without WooCommerce (no payment)', () => {
		const email = `acceptance-nowc-${ suffix }@example.org`;
		let booked;

		test.beforeAll( async ( { requestUtils } ) => {
			await deactivateWooCommerce( requestUtils );
			await clearMails( requestUtils );
		} );

		test.afterAll( async ( { requestUtils } ) => {
			await activateWooCommerce( requestUtils );
		} );

		test.describe( 'customer books', () => {
			test.use( { storageState: { cookies: [], origins: [] } } );

			test( 'books in the block without paying and gets the "request received" e-mail', async ( {
				page,
				request,
				requestUtils,
			} ) => {
				booked = await bookThroughBlock( page, pageUrl, {
					email,
					name: 'Bartek Acceptance',
				} );
				expect( booked.booking.status ).toBe( 'pending' );
				expect( booked.booking.payment_url ).toBeUndefined();
				await expect(
					page.locator( '.wp-block-terminarz-booking' )
				).toContainText( 'awaiting confirmation' );
				expect(
					await offeredStarts(
						request,
						serviceId,
						resourceId,
						booked.date
					)
				).not.toContain( booked.startUtc );

				await waitForMail(
					requestUtils,
					( mail ) =>
						mail.to === email &&
						mail.subject.includes( 'received your booking request' )
				);
			} );
		} );

		test( 'admin sees the booking and confirms it', async ( {
			admin,
			page,
		} ) => {
			const row = await bookingRow( admin, page, email );
			await expect(
				row.getByText( 'Pending', { exact: true } )
			).toBeVisible();
			await row.hover();
			await row.getByRole( 'link', { name: 'Confirm' } ).click();
			await expect(
				page.getByText( 'Booking confirmed.' )
			).toBeVisible();
		} );

		test.describe( 'customer cancels', () => {
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

				await cancelWithLink(
					page,
					cancelLink( confirmation.message )
				);

				expect(
					await offeredStarts(
						request,
						serviceId,
						resourceId,
						booked.date
					)
				).toContain( booked.startUtc );
			} );
		} );

		test( 'admin sees the booking cancelled', async ( { admin, page } ) => {
			const row = await bookingRow( admin, page, email );
			await expect(
				row.getByText( 'Cancelled', { exact: true } )
			).toBeVisible();
		} );
	} );

	test( '(c) GET /availability for 30 days × 10 resources', async ( {
		admin,
		page,
		request,
	} ) => {
		test.setTimeout( 240_000 );
		const names = [];
		for ( let i = 1; i <= 10; i++ ) {
			const name = `Perf Resource ${ i } ${ suffix }`;
			const id = await createResource( admin, page, name );
			await setWorkingHours( admin, page, id, '08:00', '20:00' );
			names.push( name );
		}
		const perfService = await createService( admin, page, {
			name: `Perf Service ${ suffix }`,
			resources: names,
			duration: 30,
		} );

		const params = {
			service: perfService,
			resource: 'any',
			from: dayFromNow( 1 ),
			to: dayFromNow( 30 ),
		};
		const url = '/wp-json/terminarz/v1/availability';
		const warmUp = await request.get( url, { params } );
		expect( warmUp.ok() ).toBe( true );
		const body = await warmUp.json();
		expect( body.days ).toHaveLength( 30 );
		const slots = body.days.reduce(
			( sum, day ) => sum + day.slots.length,
			0
		);

		const timings = [];
		for ( let i = 0; i < 20; i++ ) {
			const started = performance.now();
			const response = await request.get( url, { params } );
			expect( response.ok() ).toBe( true );
			await response.body();
			timings.push( performance.now() - started );
		}
		const result = {
			medianMs: Math.round( median( timings ) ),
			maxMs: Math.round( Math.max( ...timings ) ),
			requests: timings.length,
			days: 30,
			resources: 10,
			slots,
		};
		test.info().annotations.push( {
			type: 'performance',
			description: JSON.stringify( result ),
		} );
		// eslint-disable-next-line no-console
		console.log(
			`[performance] GET /availability: ${ JSON.stringify( result ) }`
		);

		// Loose limit for shared CI runners; the value itself goes to PROGRESS.md.
		expect( result.medianMs ).toBeLessThan( 2000 );
	} );

	test( 'admin restores the default settings', async ( { admin, page } ) => {
		await saveSettings( admin, page, {
			payment_mode: 'none',
			customer_cancel_limit_hours: 24,
		} );
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
