/**
 * Customer path without WooCommerce: the admin configures a resource and a service → a visitor books through
 * the block → the admin sees the booking → the booked time disappears from the availability.
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

test.describe.configure( { mode: 'serial' } );

const suffix = Date.now().toString( 36 );
const RESOURCE = `E2E Path Studio ${ suffix }`;
const SERVICE = `E2E Path Session ${ suffix }`;
const EMAIL = `path-${ suffix }@example.org`;

let resourceId;
let serviceId;
let pageUrl;
let booked;

test.describe( 'Customer path without WooCommerce', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await deactivateWooCommerce( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await activateWooCommerce( requestUtils );
	} );

	test( 'admin configures a resource, a service and working hours', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( 'plugins.php' );
		await expect(
			page.locator( 'tr[data-slug="woocommerce"]' )
		).toHaveClass( /\binactive\b/ );

		resourceId = await createResource( admin, page, RESOURCE );
		await setWorkingHours( admin, page, resourceId, '09:00', '17:00' );
		serviceId = await createService( admin, page, {
			name: SERVICE,
			resources: [ RESOURCE ],
			duration: 45,
			price: '120',
		} );
		pageUrl = await createBookingPage(
			requestUtils,
			`Book a session ${ suffix }`,
			{ serviceIds: [ serviceId ] }
		);
		expect( serviceId ).toBeGreaterThan( 0 );
	} );

	test.describe( 'visitor', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'books an appointment through the block', async ( { page } ) => {
			await page.goto( pageUrl );
			const block = page.locator( '.wp-block-terminarz-booking' );

			await expect(
				block.getByRole( 'heading', { name: 'Choose a day' } )
			).toBeVisible();
			const day = block
				.locator( '.trmz-calendar__day--available' )
				.first();
			const date = await day.getAttribute( 'data-date' );
			await day.click();

			const slot = block.getByRole( 'radio' ).first();
			await slot.check();
			booked = {
				date,
				startUtc: await slot.getAttribute( 'value' ),
				time: (
					await block
						.locator( '.trmz-choice--slot .trmz-choice__label' )
						.first()
						.textContent()
				).trim(),
			};
			await block.getByRole( 'button', { name: 'Continue' } ).click();

			await block.getByLabel( 'Full name' ).fill( 'Path Customer' );
			await block.getByLabel( 'E-mail' ).fill( EMAIL );
			await block.getByLabel( 'Phone' ).fill( '600 700 800' );
			await block
				.getByRole( 'checkbox', { name: /personal data/ } )
				.check();
			await block
				.getByRole( 'button', { name: 'Book appointment' } )
				.click();

			await expect(
				block.getByRole( 'heading', {
					name: 'Thank you for your booking',
				} )
			).toBeVisible();
			await expect( block ).toContainText(
				'Your booking is awaiting confirmation.'
			);
			await expect( block ).toContainText( booked.time );
		} );

		test( 'the booked time is no longer offered', async ( {
			page,
			request,
		} ) => {
			const response = await request.get(
				'/wp-json/terminarz/v1/availability',
				{
					params: {
						service: serviceId,
						resource: String( resourceId ),
						from: booked.date,
						to: booked.date,
					},
				}
			);
			expect( response.ok() ).toBe( true );
			const availability = await response.json();
			const starts = availability.days[ 0 ].slots.map(
				( slot ) => slot.start_utc
			);
			expect( starts ).not.toContain( booked.startUtc );

			// The block shows the same after a reload.
			await page.goto( pageUrl );
			const block = page.locator( '.wp-block-terminarz-booking' );
			const day = block.locator( `[data-date="${ booked.date }"]` );
			await expect( day ).toBeVisible();
			// The day may have no free time left at all (then it is disabled).
			const available = await day.getAttribute( 'aria-disabled' );
			expect( [ null, 'true' ] ).toContain( available );
			await expect(
				block.locator( `input[value="${ booked.startUtc }"]` )
			).toHaveCount( 0 );
		} );
	} );

	test( 'admin sees the booking in the list', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'admin.php',
			`page=trmz-bookings&s=${ encodeURIComponent( EMAIL ) }`
		);
		const row = page.locator( '#the-list tr' ).filter( { hasText: EMAIL } );
		await expect( row ).toHaveCount( 1 );
		await expect( row ).toContainText( SERVICE );
		await expect( row ).toContainText( RESOURCE );
		await expect(
			row.getByText( 'Pending', { exact: true } )
		).toBeVisible();

		await row.locator( 'a.row-title' ).click();
		await expect(
			page.getByRole( 'heading', { name: 'Booking details' } )
		).toBeVisible();
		await expect( page.locator( '.trmz-booking-details' ) ).toContainText(
			'Path Customer'
		);
		// The panel uses the site time format (e.g. "09:00" or "9:00 am" for 09:00).
		const [ hour, minute ] = booked.time.split( ':' ).map( Number );
		const minutes = String( minute ).padStart( 2, '0' );
		await expect( page.locator( '.trmz-booking-details' ) ).toContainText(
			new RegExp(
				`\\b0?(${ hour }|${ hour % 12 || 12 }):${ minutes }\\b`
			)
		);
	} );

	test( 'logs no PHP notices', async ( { requestUtils } ) => {
		const offset = Number( fs.readFileSync( debugLogOffsetFile, 'utf8' ) );
		const log = await readDebugLog( requestUtils.request );

		expect( findPhpProblems( log, offset ) ).toEqual( [] );
	} );
} );
