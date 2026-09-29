/**
 * Booking block — customer form: validation, successful booking (anonymous and logged-in), a time taken in the
 * meantime (409), rate limit (429), server-side field errors (400) and the payment redirect extension point.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	createBookingPage,
	createResource,
	createService,
	setWorkingHours,
} = require( '../utils/booking-setup' );

test.describe.configure( { mode: 'serial' } );

const suffix = Date.now().toString( 36 );
const RESOURCE = `E2E Form Room ${ suffix }`;
const SERVICE = `E2E Form Service ${ suffix }`;
const ANONYMOUS = { cookies: [], origins: [] };

let pageUrl;
let serviceId;

/**
 * Opens the page and picks the first free time of the first day with free times.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<{block: import('@playwright/test').Locator, startUtc: string}>} Block and chosen slot.
 */
async function chooseFirstTime( page ) {
	await page.goto( pageUrl );
	const block = page.locator( '.wp-block-terminarz-booking' );
	// One service with one resource: the calendar is the first step.
	await expect(
		block.getByRole( 'heading', { name: 'Choose a day' } )
	).toBeVisible();
	await block.locator( '.trmz-calendar__day--available' ).first().click();
	const radio = block.getByRole( 'radio' ).first();
	await radio.check();
	const startUtc = await radio.getAttribute( 'value' );
	await block.getByRole( 'button', { name: 'Continue' } ).click();
	await expect(
		block.getByRole( 'heading', { name: 'Your details' } )
	).toBeFocused();
	return { block, startUtc };
}

/**
 * @param {import('@playwright/test').Locator} block Block.
 * @param {string}                             email E-mail.
 */
async function fillForm( block, email ) {
	await block.getByLabel( 'Full name' ).fill( 'Ewa Nowak' );
	await block.getByLabel( 'E-mail' ).fill( email );
	await block.getByLabel( 'Phone' ).fill( '+48 600 100 200' );
	await block.getByLabel( 'Note for us' ).fill( 'First visit.' );
	await block.getByRole( 'checkbox', { name: /personal data/ } ).check();
}

test.describe( 'Booking block — customer form', () => {
	test( 'prepares a service and a page with the block', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await createResource( admin, page, RESOURCE );
		const resourceId = Number(
			new URL( page.url() ).searchParams.get( 'id' )
		);
		await setWorkingHours( admin, page, resourceId, '08:00', '18:00' );
		serviceId = await createService( admin, page, {
			name: SERVICE,
			resources: [ RESOURCE ],
			duration: 30,
		} );
		pageUrl = await createBookingPage( requestUtils, `Form ${ suffix }`, {
			serviceIds: [ serviceId ],
		} );
		expect( pageUrl ).toContain( '://' );
	} );

	test.describe( 'anonymous customer', () => {
		test.use( { storageState: ANONYMOUS } );

		test( 'validates the form and books the appointment', async ( {
			page,
			requestUtils,
		} ) => {
			const email = `form-${ suffix }@example.org`;
			const { block } = await chooseFirstTime( page );

			// Client-side validation: messages next to the fields, focus on the first invalid one.
			await block
				.getByRole( 'button', { name: 'Book appointment' } )
				.click();
			const name = block.getByLabel( 'Full name' );
			await expect( name ).toBeFocused();
			await expect( name ).toHaveAttribute( 'aria-invalid', 'true' );
			await expect( name ).toHaveAccessibleDescription(
				'Please enter your name.'
			);
			await expect(
				block.getByLabel( 'E-mail' )
			).toHaveAccessibleDescription(
				'Please enter your e-mail address.'
			);
			await expect(
				block.getByRole( 'checkbox', { name: /personal data/ } )
			).toHaveAccessibleDescription(
				'Please accept the terms to book an appointment.'
			);

			await block.getByLabel( 'E-mail' ).fill( 'not-an-email' );
			await block
				.getByRole( 'button', { name: 'Book appointment' } )
				.click();
			await expect(
				block.getByLabel( 'E-mail' )
			).toHaveAccessibleDescription( /valid e-mail address/ );

			await fillForm( block, email );
			const requestPromise = page.waitForRequest(
				( request ) =>
					request.url().includes( 'terminarz/v1/bookings' ) &&
					request.method() === 'POST'
			);
			await block
				.getByRole( 'button', { name: 'Book appointment' } )
				.click();
			const request = await requestPromise;
			// Anonymous visitors send no nonce (a cached nonce would expire).
			expect( request.headers()[ 'x-wp-nonce' ] ).toBeUndefined();
			expect( request.postDataJSON() ).toMatchObject( {
				service: serviceId,
				name: 'Ewa Nowak',
				email,
				consent: true,
				website: '',
			} );

			await expect(
				block.getByRole( 'heading', {
					name: 'Thank you for your booking',
				} )
			).toBeFocused();
			await expect( block ).toContainText(
				'Your booking is awaiting confirmation.'
			);
			await expect( block ).toContainText( SERVICE );
			await expect( block ).toContainText( RESOURCE );

			const bookings = await requestUtils.rest( {
				path: '/terminarz/v1/bookings',
				params: { search: email },
			} );
			expect( bookings ).toHaveLength( 1 );
			expect( bookings[ 0 ].status ).toBe( 'pending' );
		} );

		test( 'keeps the form when the time was taken in the meantime (409)', async ( {
			page,
			requestUtils,
		} ) => {
			const email = `conflict-${ suffix }@example.org`;
			const { block, startUtc } = await chooseFirstTime( page );
			await fillForm( block, email );

			// Someone else books the same time first.
			await requestUtils.rest( {
				path: '/terminarz/v1/bookings',
				method: 'POST',
				data: {
					service: serviceId,
					start: startUtc,
					name: 'Other customer',
					email: `other-${ suffix }@example.org`,
					consent: true,
				},
			} );

			await block
				.getByRole( 'button', { name: 'Book appointment' } )
				.click();

			await expect(
				block.getByRole( 'heading', { name: 'Choose a time' } )
			).toBeFocused();
			await expect( block.getByRole( 'alert' ) ).toContainText(
				'this time has just been booked by someone else'
			);
			// The taken time is no longer offered.
			await expect(
				block.locator( `input[value="${ startUtc }"]` )
			).toHaveCount( 0 );

			await block.getByRole( 'radio' ).first().check();
			await block.getByRole( 'button', { name: 'Continue' } ).click();
			await expect( block.getByLabel( 'Full name' ) ).toHaveValue(
				'Ewa Nowak'
			);
			await expect( block.getByLabel( 'E-mail' ) ).toHaveValue( email );
			await expect( block.getByLabel( 'Note for us' ) ).toHaveValue(
				'First visit.'
			);
			await expect(
				block.getByRole( 'checkbox', { name: /personal data/ } )
			).toBeChecked();

			await block
				.getByRole( 'button', { name: 'Book appointment' } )
				.click();
			await expect(
				block.getByRole( 'heading', {
					name: 'Thank you for your booking',
				} )
			).toBeVisible();
		} );

		test( 'shows the rate limit with the waiting time (429)', async ( {
			page,
		} ) => {
			await page.route( '**/terminarz/v1/bookings*', ( route ) =>
				route.fulfill( {
					status: 429,
					headers: { 'Retry-After': '300' },
					contentType: 'application/json',
					body: JSON.stringify( {
						code: 'trmz_rate_limited',
						message: 'Too many requests.',
						data: { status: 429 },
					} ),
				} )
			);
			const { block } = await chooseFirstTime( page );
			await fillForm( block, `limit-${ suffix }@example.org` );
			await block
				.getByRole( 'button', { name: 'Book appointment' } )
				.click();

			await expect( block.getByRole( 'alert' ) ).toHaveText(
				'Too many booking attempts. Please try again in 5 minutes.'
			);
			await expect( block.getByLabel( 'Full name' ) ).toHaveValue(
				'Ewa Nowak'
			);
		} );

		test( 'shows server-side field errors (400)', async ( { page } ) => {
			await page.route( '**/terminarz/v1/bookings*', ( route ) =>
				route.fulfill( {
					status: 400,
					contentType: 'application/json',
					body: JSON.stringify( {
						code: 'rest_invalid_param',
						message: 'Invalid parameter(s): email',
						data: {
							status: 400,
							params: { email: 'email is not valid.' },
						},
					} ),
				} )
			);
			const { block } = await chooseFirstTime( page );
			await fillForm( block, `invalid-${ suffix }@example.org` );
			await block
				.getByRole( 'button', { name: 'Book appointment' } )
				.click();

			const email = block.getByLabel( 'E-mail' );
			await expect( email ).toBeFocused();
			await expect( email ).toHaveAttribute( 'aria-invalid', 'true' );
			await expect( email ).toHaveAccessibleDescription(
				/valid e-mail address/
			);
			await expect( block.getByRole( 'alert' ) ).toHaveText(
				'Please correct the marked fields.'
			);
		} );

		test( 'redirects to the payment URL when the server returns one', async ( {
			page,
		} ) => {
			await page.route( '**/terminarz/v1/bookings*', ( route ) =>
				route.fulfill( {
					status: 201,
					contentType: 'application/json',
					body: JSON.stringify( {
						public_id: 'a'.repeat( 32 ),
						status: 'pending_payment',
						start: '2030-01-01T09:00:00+00:00',
						end: '2030-01-01T09:30:00+00:00',
						start_utc: '2030-01-01T09:00:00Z',
						payment_url: new URL( '/?trmz-e2e-payment=1', pageUrl )
							.href,
					} ),
				} )
			);
			const { block } = await chooseFirstTime( page );
			await fillForm( block, `pay-${ suffix }@example.org` );
			await block
				.getByRole( 'button', { name: 'Book appointment' } )
				.click();

			await page.waitForURL( /trmz-e2e-payment=1/ );
			expect(
				new URL( page.url() ).searchParams.get( 'trmz-e2e-payment' )
			).toBe( '1' );
		} );
	} );

	test( 'a logged-in customer books with the REST nonce', async ( {
		page,
	} ) => {
		const { block } = await chooseFirstTime( page );
		await fillForm( block, `member-${ suffix }@example.org` );
		const requestPromise = page.waitForRequest(
			( request ) =>
				request.url().includes( 'terminarz/v1/bookings' ) &&
				request.method() === 'POST'
		);
		await block.getByRole( 'button', { name: 'Book appointment' } ).click();
		expect(
			( await requestPromise ).headers()[ 'x-wp-nonce' ]
		).toBeTruthy();
		await expect(
			block.getByRole( 'heading', { name: 'Thank you for your booking' } )
		).toBeVisible();

		// Book another appointment starts again with the calendar.
		await block
			.getByRole( 'button', { name: 'Book another appointment' } )
			.click();
		await expect(
			block.getByRole( 'heading', { name: 'Choose a day' } )
		).toBeFocused();
	} );
} );
