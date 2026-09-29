/**
 * Booking block in the editor: inserting the block, configuring it in the sidebar and saving the page.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { createResource, createService } = require( '../utils/booking-setup' );

test.describe.configure( { mode: 'serial' } );

const suffix = Date.now().toString( 36 );
const RESOURCE = `E2E Block Room ${ suffix }`;
const SERVICE = `E2E Block Service ${ suffix }`;

test.describe( 'Booking block in the editor', () => {
	let serviceId;

	test( 'creates a service to offer', async ( { admin, page } ) => {
		await createResource( admin, page, RESOURCE );
		serviceId = await createService( admin, page, {
			name: SERVICE,
			resources: [ RESOURCE ],
		} );
		expect( serviceId ).toBeGreaterThan( 0 );
	} );

	test( 'inserts, configures and saves the block', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await admin.createNewPost( {
			postType: 'page',
			title: `Booking ${ suffix }`,
		} );
		await editor.insertBlock( { name: 'terminarz/booking' } );

		const block = editor.canvas.locator(
			'[data-type="terminarz/booking"]'
		);
		await expect( block ).toContainText( 'Book an appointment' );

		await editor.openDocumentSettingsSidebar();
		await page.getByRole( 'tab', { name: 'Block' } ).click();
		const settings = page.getByRole( 'region', {
			name: 'Editor settings',
		} );
		await settings
			.getByRole( 'checkbox', { name: SERVICE, exact: true } )
			.check();
		await settings
			.getByRole( 'combobox', { name: 'Preselected service' } )
			.selectOption( String( serviceId ) );
		await settings
			.getByRole( 'checkbox', {
				name: 'Let customers choose the person or resource',
			} )
			.uncheck();
		await settings
			.getByRole( 'combobox', { name: 'First day of the week' } )
			.selectOption( '0' );

		await expect( block ).toContainText( `Services: ${ SERVICE }` );
		await expect( block ).not.toContainText( 'Person or resource' );

		const postId = await editor.publishPost();

		const saved = await requestUtils.rest( {
			path: `/wp/v2/pages/${ postId }`,
			params: { context: 'edit' },
		} );
		const blocks = saved.content.raw;
		expect( blocks ).toContain( '<!-- wp:terminarz/booking' );
		expect( blocks ).toContain( `"serviceIds":[${ serviceId }]` );
		expect( blocks ).toContain( `"defaultServiceId":${ serviceId }` );
		expect( blocks ).toContain( '"showResourcePicker":false' );
		expect( blocks ).toContain( '"firstDayOfWeek":0' );

		// No block validation problems after a reload.
		await page.reload();
		await expect(
			editor.canvas.locator( '[data-type="terminarz/booking"]' )
		).toContainText( 'Book an appointment' );
		await expect(
			editor.canvas.getByText( 'This block contains unexpected' )
		).toHaveCount( 0 );

		// The front end renders the container with the configuration (JSON script element, replaced by the app on mount).
		const html = await ( await page.request.get( saved.link ) ).text();
		const match = html.match(
			/<script type="application\/json" class="trmz-booking__config">([\s\S]*?)<\/script>/
		);
		expect( match ).not.toBeNull();
		const config = JSON.parse( match[ 1 ] );
		await page.goto( saved.link );
		await expect(
			page.locator( '.wp-block-terminarz-booking' )
		).toHaveCount( 1 );
		expect( config.serviceIds ).toEqual( [ serviceId ] );
		expect( config.showResourcePicker ).toBe( false );
		expect( config.firstDayOfWeek ).toBe( 0 );
	} );
} );
