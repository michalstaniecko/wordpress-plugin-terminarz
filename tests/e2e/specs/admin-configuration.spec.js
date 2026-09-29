/**
 * Admin configuration end to end: resource → service → working hours → day off → booking created through REST →
 * confirm / cancel in the booking list → CSV export. Finally checks that no PHP notices were logged.
 */
const fs = require( 'fs' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	debugLogOffsetFile,
	findPhpProblems,
	readDebugLog,
} = require( '../utils/debug-log' );

const suffix = Date.now().toString( 36 );
const RESOURCE = `E2E Room ${ suffix }`;
const SERVICE = `E2E Consultation ${ suffix }`;
const EMAIL = `e2e-${ suffix }@example.org`;

/**
 * Local date (site time zone of wp-env = UTC) `days` from today, as Y-m-d.
 *
 * @param {number} days Offset in days.
 * @return {string} Date.
 */
const dayFromToday = ( days ) =>
	new Date( Date.now() + days * 86_400_000 ).toISOString().slice( 0, 10 );

const BOOKING_DAY = dayFromToday( 3 );
const DAY_OFF = dayFromToday( 4 );

test.describe.configure( { mode: 'serial' } );

test.describe( 'Admin configuration', () => {
	let resourceId;
	let serviceId;
	let publicId;

	test( 'creates a resource', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=trmz-resources&view=edit'
		);

		await page.getByLabel( 'Name', { exact: true } ).fill( RESOURCE );
		await page.getByLabel( 'Type' ).selectOption( 'room' );
		await page
			.getByLabel( 'Description' )
			.fill( 'Created by the E2E test.' );
		await page.getByRole( 'button', { name: 'Add resource' } ).click();

		await expect( page.getByText( 'Resource added.' ) ).toBeVisible();
		resourceId = Number( new URL( page.url() ).searchParams.get( 'id' ) );
		expect( resourceId ).toBeGreaterThan( 0 );

		await admin.visitAdminPage( 'admin.php', 'page=trmz-resources' );
		await expect(
			page.getByRole( 'link', { name: RESOURCE, exact: true } )
		).toBeVisible();
	} );

	test( 'creates a service performed by the resource', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=trmz-services&view=edit'
		);

		await page.getByLabel( 'Name', { exact: true } ).fill( SERVICE );
		await page.getByLabel( 'Duration (minutes)' ).fill( '60' );
		await page.getByLabel( 'Buffer after (minutes)' ).fill( '0' );
		await page.getByLabel( 'Price', { exact: true } ).fill( '100' );
		await page.getByLabel( RESOURCE ).check();
		await page.getByRole( 'button', { name: 'Add service' } ).click();

		await expect( page.getByText( 'Service added.' ) ).toBeVisible();
		serviceId = Number( new URL( page.url() ).searchParams.get( 'id' ) );
		expect( serviceId ).toBeGreaterThan( 0 );
	} );

	test( 'sets working hours and a day off', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'admin.php',
			`page=trmz-schedule&resource=${ resourceId }`
		);
		await expect(
			page.getByText( /Times are in the site time zone/ )
		).toBeVisible();

		for ( let day = 1; day <= 7; day++ ) {
			await page
				.locator( `input[name="work[${ day }][0][start]"]` )
				.fill( '09:00' );
			await page
				.locator( `input[name="work[${ day }][0][end]"]` )
				.fill( '17:00' );
		}
		await page
			.locator( 'input[name="breaks[1][0][start]"]' )
			.fill( '12:00' );
		await page.locator( 'input[name="breaks[1][0][end]"]' ).fill( '13:00' );
		await page
			.getByRole( 'button', { name: 'Save working hours' } )
			.click();

		await expect( page.getByText( 'Working hours saved.' ) ).toBeVisible();
		await expect(
			page.locator( 'input[name="work[3][0][start]"]' )
		).toHaveValue( '09:00' );

		await admin.visitAdminPage(
			'admin.php',
			'page=trmz-exceptions&view=edit'
		);
		await page
			.getByLabel( 'Applies to' )
			.selectOption( String( resourceId ) );
		await page.getByLabel( 'First day' ).fill( DAY_OFF );
		await page.getByLabel( 'Last day' ).fill( DAY_OFF );
		await page.getByLabel( 'Closed (no bookings)' ).check();
		await page.getByLabel( 'Note' ).fill( `E2E day off ${ suffix }` );
		await page.getByRole( 'button', { name: 'Add exception' } ).click();

		await expect( page.getByText( 'Exception added.' ) ).toBeVisible();
		await expect(
			page
				.locator( '#the-list tr' )
				.filter( { hasText: `E2E day off ${ suffix }` } )
		).toContainText( RESOURCE );
	} );

	test( 'availability reflects the schedule and the day off', async ( {
		requestUtils,
	} ) => {
		const dayOff = await requestUtils.rest( {
			path: '/terminarz/v1/availability',
			params: {
				service: serviceId,
				resource: String( resourceId ),
				from: DAY_OFF,
				to: DAY_OFF,
			},
		} );
		expect( dayOff.days[ 0 ].slots ).toEqual( [] );

		const open = await requestUtils.rest( {
			path: '/terminarz/v1/availability',
			params: {
				service: serviceId,
				resource: String( resourceId ),
				from: BOOKING_DAY,
				to: BOOKING_DAY,
			},
		} );
		expect( open.days[ 0 ].slots.length ).toBeGreaterThan( 0 );

		const booking = await requestUtils.rest( {
			path: '/terminarz/v1/bookings',
			method: 'POST',
			data: {
				service: serviceId,
				resource: String( resourceId ),
				start: open.days[ 0 ].slots[ 0 ].start_utc,
				name: 'E2E Customer',
				email: EMAIL,
				phone: '600 100 200',
				consent: true,
				website: '',
			},
		} );
		expect( booking.status ).toBe( 'pending' );
		publicId = booking.public_id;
	} );

	test( 'confirms and cancels the booking in the list', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'admin.php',
			`page=trmz-bookings&s=${ encodeURIComponent( EMAIL ) }`
		);

		const row = page.locator( '#the-list tr' ).filter( { hasText: EMAIL } );
		await expect( row ).toHaveCount( 1 );
		await expect(
			row.getByText( 'Pending', { exact: true } )
		).toBeVisible();
		await expect( row.getByText( publicId.slice( 0, 8 ) ) ).toBeVisible();

		await row.hover();
		await row.getByRole( 'link', { name: 'Confirm' } ).click();
		await expect( page.getByText( 'Booking confirmed.' ) ).toBeVisible();
		await expect(
			page
				.locator( '#the-list tr' )
				.filter( { hasText: EMAIL } )
				.getByText( 'Confirmed', { exact: true } )
		).toBeVisible();

		await page
			.locator( '#the-list tr' )
			.filter( { hasText: EMAIL } )
			.locator( 'a.row-title' )
			.click();
		await expect(
			page.getByRole( 'heading', { name: 'Booking details' } )
		).toBeVisible();
		await expect( page.getByText( publicId ) ).toBeVisible();

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'button', { name: 'Cancel booking' } ).click();
		await expect( page.getByText( 'Booking cancelled.' ) ).toBeVisible();
		await expect( page.locator( '.trmz-booking-details' ) ).toContainText(
			'Cancelled'
		);
		await expect(
			page.getByRole( 'button', { name: 'Cancel booking' } )
		).toHaveCount( 0 );
	} );

	test( 'exports the filtered list as CSV', async ( { admin, page } ) => {
		await admin.visitAdminPage(
			'admin.php',
			`page=trmz-bookings&s=${ encodeURIComponent( EMAIL ) }`
		);

		const downloadPromise = page.waitForEvent( 'download' );
		await page.locator( '#trmz-export-csv' ).click();
		const download = await downloadPromise;

		expect( download.suggestedFilename() ).toMatch(
			/^bookings-\d{4}-\d{2}-\d{2}\.csv$/
		);
		const csv = fs.readFileSync( await download.path(), 'utf8' );
		expect( csv.charCodeAt( 0 ) ).toBe( 0xfeff );
		const lines = csv.trim().split( '\n' );
		expect( lines ).toHaveLength( 2 );
		expect( lines[ 1 ] ).toContain( publicId );
		expect( lines[ 1 ] ).toContain( EMAIL );
		expect( lines[ 1 ] ).toContain( SERVICE );
	} );

	test( 'logs no PHP notices', async ( { requestUtils } ) => {
		const offset = Number( fs.readFileSync( debugLogOffsetFile, 'utf8' ) );
		const log = await readDebugLog( requestUtils.request );

		expect( findPhpProblems( log, offset ) ).toEqual( [] );
	} );
} );
