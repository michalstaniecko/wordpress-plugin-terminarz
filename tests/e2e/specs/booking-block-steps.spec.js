/**
 * Booking block on the front end: service → person/resource → day → time, going back keeps the choices,
 * empty availability and the site time zone note.
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
const ANNA = `E2E Anna ${ suffix }`;
const BOB = `E2E Bob ${ suffix }`;
const IDLE = `E2E Idle ${ suffix }`;
const SERVICE = `E2E Haircut ${ suffix }`;
const EMPTY_SERVICE = `E2E No hours ${ suffix }`;

test.describe( 'Booking block — choosing a time', () => {
	let pageUrl;

	test( 'prepares services, people and a page with the block', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const anna = await createResource( admin, page, ANNA );
		const bob = await createResource( admin, page, BOB );
		await createResource( admin, page, IDLE );
		await setWorkingHours( admin, page, anna, '09:00', '12:00' );
		await setWorkingHours( admin, page, bob, '13:00', '17:00' );
		const serviceId = await createService( admin, page, {
			name: SERVICE,
			resources: [ ANNA, BOB ],
			duration: 30,
			price: '50',
		} );
		const emptyId = await createService( admin, page, {
			name: EMPTY_SERVICE,
			resources: [ IDLE ],
		} );

		pageUrl = await createBookingPage( requestUtils, `Book ${ suffix }`, {
			serviceIds: [ serviceId, emptyId ],
		} );
	} );

	test( 'walks through the steps and keeps choices when going back', async ( {
		page,
	} ) => {
		await page.goto( pageUrl );
		const block = page.locator( '.wp-block-terminarz-booking' );

		// Step 1: only the services selected in the block are offered.
		await expect(
			block.getByRole( 'heading', { name: 'Choose a service' } )
		).toBeVisible();
		await expect( block.getByRole( 'radio' ) ).toHaveCount( 2 );
		await block.getByRole( 'button', { name: 'Continue' } ).click();
		await expect( block.getByRole( 'alert' ) ).toHaveText(
			'Please choose a service.'
		);
		await block
			.getByRole( 'radio', { name: new RegExp( SERVICE ) } )
			.check();
		await expect( block ).toContainText( '30 min' );
		await block.getByRole( 'button', { name: 'Continue' } ).click();

		// Step 2: person or "any".
		const resourceHeading = block.getByRole( 'heading', {
			name: 'Choose a person or resource',
		} );
		await expect( resourceHeading ).toBeFocused();
		await expect( block.getByText( 'Step 2 of 5' ) ).toBeVisible();
		await block.getByRole( 'radio', { name: new RegExp( BOB ) } ).check();
		await block.getByRole( 'button', { name: 'Continue' } ).click();

		// Step 3: calendar with days that have free times.
		await expect(
			block.getByRole( 'heading', { name: 'Choose a day' } )
		).toBeFocused();
		const grid = block.getByRole( 'grid' );
		await expect( grid ).toBeVisible();
		const day = grid.locator( '.trmz-calendar__day--available' ).first();
		await expect( day ).toBeVisible();
		await expect(
			block.getByText( /Times are shown in the .+ time zone\./ )
		).toBeVisible();
		const date = await day.getAttribute( 'data-date' );
		await day.click();

		// Step 4: times of Bob (13:00–17:00, site time zone).
		await expect(
			block.getByRole( 'heading', { name: 'Choose a time' } )
		).toBeFocused();
		const times = block.getByRole( 'radio' );
		await expect( times.first() ).toBeVisible();
		const labels = await block
			.locator( '.trmz-choice--slot .trmz-choice__label' )
			.allTextContents();
		expect( labels.length ).toBeGreaterThan( 0 );
		for ( const label of labels ) {
			expect( label >= '13:00' && label <= '16:30' ).toBe( true );
		}
		await expect( block.getByText( 'Afternoon' ) ).toBeVisible();
		await expect( block.getByText( 'Morning' ) ).toHaveCount( 0 );
		const chosen = labels[ labels.length - 1 ];
		await block.getByRole( 'radio', { name: chosen } ).check();
		await block.getByRole( 'button', { name: 'Continue' } ).click();

		// Step 5: summary.
		await expect(
			block.getByRole( 'heading', { name: 'Your details' } )
		).toBeFocused();
		await expect( block ).toContainText( SERVICE );
		await expect( block ).toContainText( BOB );
		await expect( block ).toContainText( chosen );

		// Back: every choice is kept.
		await block.getByRole( 'button', { name: 'Back' } ).click();
		await expect(
			block.getByRole( 'radio', { name: chosen } )
		).toBeChecked();
		await block.getByRole( 'button', { name: 'Back' } ).click();
		await expect( grid.locator( `[data-date="${ date }"]` ) ).toHaveClass(
			/trmz-calendar__day--selected/
		);
		await block.getByRole( 'button', { name: 'Back' } ).click();
		await expect(
			block.getByRole( 'radio', { name: new RegExp( BOB ) } )
		).toBeChecked();
		await block.getByRole( 'button', { name: 'Back' } ).click();
		await expect(
			block.getByRole( 'radio', { name: new RegExp( SERVICE ) } )
		).toBeChecked();

		// "Any available" merges the hours of both people.
		await block.getByRole( 'button', { name: 'Continue' } ).click();
		await block.getByRole( 'radio', { name: /Any available/ } ).check();
		await block.getByRole( 'button', { name: 'Continue' } ).click();
		await grid.locator( '.trmz-calendar__day--available' ).first().click();
		await expect( block.getByText( 'Morning' ) ).toBeVisible();
	} );

	test( 'shows an empty state when there are no free times', async ( {
		page,
	} ) => {
		await page.goto( pageUrl );
		const block = page.locator( '.wp-block-terminarz-booking' );

		await block
			.getByRole( 'radio', { name: new RegExp( EMPTY_SERVICE ) } )
			.check();
		await block.getByRole( 'button', { name: 'Continue' } ).click();

		// A single person performs the service: the resource step is skipped.
		await expect(
			block.getByRole( 'heading', { name: 'Choose a day' } )
		).toBeFocused();
		await expect(
			block.getByText( 'There are no free times in this month.', {
				exact: false,
			} )
		).toBeVisible();
		await expect(
			block.locator( '.trmz-calendar__day--available' )
		).toHaveCount( 0 );
		await expect( block.getByRole( 'status' ) ).toContainText(
			'no free times'
		);
	} );
} );
