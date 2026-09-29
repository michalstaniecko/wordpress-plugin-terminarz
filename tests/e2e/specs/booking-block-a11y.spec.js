/**
 * Booking block — accessibility (WCAG 2.2 AA): axe-core on every step, the whole path with the keyboard only
 * and a 320 px wide mobile viewport without horizontal scrolling.
 */
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	createBookingPage,
	createResource,
	createService,
	setWorkingHours,
} = require( '../utils/booking-setup' );

test.describe.configure( { mode: 'serial' } );

const suffix = Date.now().toString( 36 );
const ANNA = `E2E A11y Anna ${ suffix }`;
const BOB = `E2E A11y Bob ${ suffix }`;
const SERVICE = `E2E A11y Massage ${ suffix }`;
const OTHER = `E2E A11y Consultation ${ suffix }`;
const ANONYMOUS = { cookies: [], origins: [] };

let pageUrl;

/**
 * Runs axe-core (WCAG 2.0/2.1/2.2 A and AA rules) on the booking block and expects no violations.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          step Step name (for the failure message).
 */
async function expectNoAxeViolations( page, step ) {
	const results = await new AxeBuilder( { page } )
		.include( '.wp-block-terminarz-booking' )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
		.analyze();
	// Sanity check: the block was actually analysed.
	expect( results.passes.length ).toBeGreaterThan( 0 );
	expect(
		results.violations.map( ( violation ) => ( {
			id: violation.id,
			nodes: violation.nodes.map( ( node ) => node.target.join( ' ' ) ),
		} ) ),
		`axe violations on step "${ step }"`
	).toEqual( [] );
}

/**
 * Presses Tab (at most `max` times) until the locator has focus.
 *
 * @param {import('@playwright/test').Page}    page    Page.
 * @param {import('@playwright/test').Locator} locator Target.
 * @param {number}                             [max]   Maximum number of Tab presses.
 */
async function tabTo( page, locator, max = 25 ) {
	for ( let i = 0; i < max; i++ ) {
		if (
			await locator.evaluate(
				( el ) => el === el.ownerDocument.activeElement
			)
		) {
			return;
		}
		await page.keyboard.press( 'Tab' );
	}
	await expect( locator ).toBeFocused();
}

/**
 * Asserts that the page does not scroll horizontally.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function expectNoHorizontalScroll( page ) {
	const { scrollWidth, clientWidth } = await page.evaluate( () => ( {
		scrollWidth: document.documentElement.scrollWidth,
		clientWidth: document.documentElement.clientWidth,
	} ) );
	expect( scrollWidth ).toBeLessThanOrEqual( clientWidth );
}

test.describe( 'Booking block — accessibility', () => {
	test.use( { storageState: ANONYMOUS } );

	test( 'prepares services, people and a page with the block', async ( {
		browser,
	} ) => {
		// Admin screens need the admin session: a separate context with the stored admin state.
		const context = await browser.newContext( {
			storageState: process.env.STORAGE_STATE_PATH,
		} );
		const page = await context.newPage();
		const {
			Admin,
			PageUtils,
			Editor,
			RequestUtils,
		} = require( '@wordpress/e2e-test-utils-playwright' );
		const pageUtils = new PageUtils( { page } );
		const editor = new Editor( { page } );
		const admin = new Admin( { page, pageUtils, editor } );

		const anna = await createResource( admin, page, ANNA );
		const bob = await createResource( admin, page, BOB );
		await setWorkingHours( admin, page, anna, '08:00', '20:00' );
		await setWorkingHours( admin, page, bob, '08:00', '20:00' );
		const serviceId = await createService( admin, page, {
			name: SERVICE,
			resources: [ ANNA, BOB ],
			duration: 30,
			price: '80',
		} );
		const otherId = await createService( admin, page, {
			name: OTHER,
			resources: [ ANNA ],
		} );

		const requestUtils = await RequestUtils.setup( {
			baseURL: new URL( page.url() ).origin,
			storageStatePath: process.env.STORAGE_STATE_PATH,
		} );
		pageUrl = await createBookingPage( requestUtils, `A11y ${ suffix }`, {
			serviceIds: [ serviceId, otherId ],
		} );
		await context.close();
		expect( pageUrl ).toContain( '://' );
	} );

	test( 'has no axe violations on any step', async ( { page } ) => {
		await page.goto( pageUrl );
		const block = page.locator( '.wp-block-terminarz-booking' );

		await expect(
			block.getByRole( 'heading', { name: 'Choose a service' } )
		).toBeVisible();
		await expectNoAxeViolations( page, 'service' );

		await block.getByRole( 'button', { name: 'Continue' } ).click();
		await expect( block.getByRole( 'alert' ) ).toBeVisible();
		await expectNoAxeViolations( page, 'service with error' );

		await block
			.getByRole( 'radio', { name: new RegExp( SERVICE ) } )
			.check();
		await block.getByRole( 'button', { name: 'Continue' } ).click();
		await expect(
			block.getByRole( 'heading', {
				name: 'Choose a person or resource',
			} )
		).toBeVisible();
		await expectNoAxeViolations( page, 'resource' );

		await block.getByRole( 'radio', { name: new RegExp( ANNA ) } ).check();
		await block.getByRole( 'button', { name: 'Continue' } ).click();
		await expect(
			block.locator( '.trmz-calendar__day--available' ).first()
		).toBeVisible();
		await expectNoAxeViolations( page, 'day' );

		await block.locator( '.trmz-calendar__day--available' ).first().click();
		await expect( block.getByRole( 'radio' ).first() ).toBeVisible();
		await expectNoAxeViolations( page, 'time' );

		await block.getByRole( 'radio' ).first().check();
		await block.getByRole( 'button', { name: 'Continue' } ).click();
		await expect( block.getByLabel( 'Full name' ) ).toBeVisible();
		await expectNoAxeViolations( page, 'details' );

		await block.getByRole( 'button', { name: 'Book appointment' } ).click();
		await expect( block.getByLabel( 'Full name' ) ).toHaveAttribute(
			'aria-invalid',
			'true'
		);
		await expectNoAxeViolations( page, 'details with errors' );

		await block.getByLabel( 'Full name' ).fill( 'Axe Tester' );
		await block
			.getByLabel( 'E-mail' )
			.fill( `axe-${ suffix }@example.org` );
		await block.getByRole( 'checkbox', { name: /personal data/ } ).check();
		await block.getByRole( 'button', { name: 'Book appointment' } ).click();
		await expect(
			block.getByRole( 'heading', { name: 'Thank you for your booking' } )
		).toBeVisible();
		await expectNoAxeViolations( page, 'confirmation' );
	} );

	test( 'can be completed with the keyboard only', async ( { page } ) => {
		await page.goto( pageUrl );
		const block = page.locator( '.wp-block-terminarz-booking' );
		await expect(
			block.getByRole( 'heading', { name: 'Choose a service' } )
		).toBeVisible();

		// Service: Tab into the radio group, arrows select, Enter on Continue.
		await tabTo( page, block.getByRole( 'radio' ).first() );
		await page.keyboard.press( 'Space' );
		await page.keyboard.press( 'ArrowDown' );
		await page.keyboard.press( 'ArrowUp' );
		// Services are listed in catalogue order: the first one is SERVICE (created first).
		const service = block.getByRole( 'radio', {
			name: new RegExp( SERVICE ),
		} );
		await expect( service ).toBeChecked();
		await tabTo( page, block.getByRole( 'button', { name: 'Continue' } ) );
		await page.keyboard.press( 'Enter' );

		// Resource: focus is on the step heading.
		await expect(
			block.getByRole( 'heading', {
				name: 'Choose a person or resource',
			} )
		).toBeFocused();
		await page.keyboard.press( 'Tab' );
		await expect(
			block.getByRole( 'radio', { name: /Any available/ } )
		).toBeFocused();
		await page.keyboard.press( 'Space' );
		await tabTo( page, block.getByRole( 'button', { name: 'Continue' } ) );
		await page.keyboard.press( 'Enter' );

		// Calendar grid: one tab stop, arrows/Home/End/PageDown/PageUp move focus.
		await expect(
			block.getByRole( 'heading', { name: 'Choose a day' } )
		).toBeFocused();
		const firstAvailable = block
			.locator( '.trmz-calendar__day--available' )
			.first();
		await expect( firstAvailable ).toBeVisible();
		await tabTo( page, firstAvailable );
		await expect(
			block.locator( '.trmz-calendar__day[tabindex="0"]' )
		).toHaveCount( 1 );

		const focusedDate = () =>
			page.locator( ':focus' ).getAttribute( 'data-date' );
		const start = await focusedDate();
		await page.keyboard.press( 'ArrowDown' );
		const weekLater = await focusedDate();
		expect( weekLater > start ).toBe( true );
		await page.keyboard.press( 'ArrowUp' );
		expect( await focusedDate() ).toBe( start );
		await page.keyboard.press( 'End' );
		await page.keyboard.press( 'Home' );
		await page.keyboard.press( 'PageDown' );
		await expect(
			block.locator( '.trmz-calendar__day[tabindex="0"]' )
		).toBeFocused();
		await page.keyboard.press( 'PageUp' );
		await expect(
			block.locator( '.trmz-calendar__day[tabindex="0"]' )
		).toBeFocused();
		// Back to the first day with free times and select it.
		await expect( firstAvailable ).toBeVisible();
		await firstAvailable.focus();
		await page.keyboard.press( 'Enter' );

		// Time: radios, arrow keys.
		await expect(
			block.getByRole( 'heading', { name: 'Choose a time' } )
		).toBeFocused();
		await page.keyboard.press( 'Tab' );
		await page.keyboard.press( 'Space' );
		await page.keyboard.press( 'ArrowRight' );
		await expect(
			block.locator( '.trmz-choice__input:checked' )
		).toHaveCount( 1 );
		await tabTo( page, block.getByRole( 'button', { name: 'Continue' } ) );
		await page.keyboard.press( 'Enter' );

		// Form.
		await expect(
			block.getByRole( 'heading', { name: 'Your details' } )
		).toBeFocused();
		await tabTo( page, block.getByLabel( 'Full name' ) );
		await page.keyboard.type( 'Keyboard User' );
		await page.keyboard.press( 'Tab' );
		await page.keyboard.type( `keys-${ suffix }@example.org` );
		await page.keyboard.press( 'Tab' );
		await page.keyboard.type( '600100200' );
		await page.keyboard.press( 'Tab' );
		await page.keyboard.type( 'Booked with the keyboard.' );
		await page.keyboard.press( 'Tab' );
		await expect(
			block.getByRole( 'checkbox', { name: /personal data/ } )
		).toBeFocused();
		await page.keyboard.press( 'Space' );
		await tabTo(
			page,
			block.getByRole( 'button', { name: 'Book appointment' } )
		);
		await page.keyboard.press( 'Enter' );

		await expect(
			block.getByRole( 'heading', { name: 'Thank you for your booking' } )
		).toBeFocused();
		await expect( block ).toContainText( SERVICE );
	} );

	test( 'works on a 320 px wide phone', async ( { page } ) => {
		await page.setViewportSize( { width: 320, height: 640 } );
		await page.goto( pageUrl );
		const block = page.locator( '.wp-block-terminarz-booking' );

		await expect(
			block.getByRole( 'heading', { name: 'Choose a service' } )
		).toBeVisible();
		await expectNoHorizontalScroll( page );
		await block
			.getByRole( 'radio', { name: new RegExp( SERVICE ) } )
			.check();
		await block.getByRole( 'button', { name: 'Continue' } ).click();
		await block.getByRole( 'radio', { name: new RegExp( BOB ) } ).check();
		await block.getByRole( 'button', { name: 'Continue' } ).click();

		const day = block.locator( '.trmz-calendar__day--available' ).first();
		await expect( day ).toBeVisible();
		await expectNoHorizontalScroll( page );
		// Targets at least 24×24 CSS px (WCAG 2.5.8).
		for ( const target of [
			day,
			block.getByRole( 'button', { name: 'Next month' } ),
			block.getByRole( 'button', { name: 'Back' } ),
		] ) {
			const box = await target.boundingBox();
			expect( box.width ).toBeGreaterThanOrEqual( 24 );
			expect( box.height ).toBeGreaterThanOrEqual( 24 );
		}
		await day.click();

		await expect( block.getByRole( 'radio' ).first() ).toBeVisible();
		await expectNoHorizontalScroll( page );
		const slotBox = await block
			.locator( '.trmz-choice--slot' )
			.first()
			.boundingBox();
		expect( slotBox.height ).toBeGreaterThanOrEqual( 24 );
		await block.getByRole( 'radio' ).first().check();
		await block.getByRole( 'button', { name: 'Continue' } ).click();

		await expect( block.getByLabel( 'Full name' ) ).toBeVisible();
		await expectNoHorizontalScroll( page );
		await block.getByLabel( 'Full name' ).fill( 'Mobile User' );
		await block
			.getByLabel( 'E-mail' )
			.fill( `mobile-${ suffix }@example.org` );
		await block.getByRole( 'checkbox', { name: /personal data/ } ).check();
		await block.getByRole( 'button', { name: 'Book appointment' } ).click();
		await expect(
			block.getByRole( 'heading', { name: 'Thank you for your booking' } )
		).toBeVisible();
		await expectNoHorizontalScroll( page );
		await expectNoAxeViolations( page, 'mobile confirmation' );
	} );
} );
