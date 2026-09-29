/**
 * Polish translation (#44, #90): with the site language set to pl_PL the plugin admin, the booking block (front end and
 * editor — JSON translations of the built scripts), the e-mails and the cancellation page are in Polish.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const {
	createBookingPage,
	createResource,
	createService,
	setWorkingHours,
} = require( '../utils/booking-setup' );
const { offeredStarts } = require( '../utils/booking-flow' );
const { clearMails, waitForMail } = require( '../utils/mails' );

test.describe.configure( { mode: 'serial' } );

const suffix = Date.now().toString( 36 );
const RESOURCE = `E2E PL Room ${ suffix }`;
const SERVICE = `E2E PL Service ${ suffix }`;

let resourceId;
let serviceId;
let pageUrl;

/**
 * Sets the site language through the test-only REST route of the e2e mu-plugin.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils Request utils.
 * @param {string}                                                      locale       '' (English) or 'pl_PL'.
 */
async function setSiteLanguage( requestUtils, locale ) {
	await requestUtils.rest( {
		path: '/trmz-e2e/v1/site-language',
		method: 'POST',
		data: { locale },
	} );
}

test.describe( 'Polish translation', () => {
	test.afterAll( async ( { requestUtils } ) => {
		await setSiteLanguage( requestUtils, '' );
	} );

	test( 'configuration (in English)', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setSiteLanguage( requestUtils, '' );
		resourceId = await createResource( admin, page, RESOURCE );
		await setWorkingHours( admin, page, resourceId, '09:00', '17:00' );
		serviceId = await createService( admin, page, {
			name: SERVICE,
			resources: [ RESOURCE ],
			duration: 60,
		} );
		pageUrl = await createBookingPage( requestUtils, `PL ${ suffix }`, {
			serviceIds: [ serviceId ],
		} );
		await setSiteLanguage( requestUtils, 'pl_PL' );
		expect( serviceId ).toBeGreaterThan( 0 );
	} );

	test( 'admin screens are in Polish', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=trmz-bookings' );
		await expect(
			page.getByRole( 'heading', { name: 'Rezerwacje', level: 1 } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Filtruj' } )
		).toBeVisible();

		await admin.visitAdminPage( 'admin.php', 'page=trmz-settings' );
		await expect( page.getByText( 'Zasady rezerwacji' ) ).toBeVisible();
		await expect(
			page.getByText( 'Automatyczne potwierdzanie' )
		).toBeVisible();
	} );

	test( 'the block in the editor is in Polish', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( { postType: 'page', title: 'PL editor' } );
		await editor.insertBlock( { name: 'terminarz/booking' } );
		await expect(
			editor.canvas.locator( '[data-type="terminarz/booking"]' )
		).toContainText( 'Zarezerwuj wizytę' );
		await editor.openDocumentSettingsSidebar();
		await expect(
			page.getByText( 'Pierwszy dzień tygodnia' ).first()
		).toBeVisible();
	} );

	test.describe( 'visitor', () => {
		test.use( { storageState: { cookies: [], origins: [] } } );

		test( 'the booking block is in Polish', async ( { page } ) => {
			await page.goto( pageUrl );
			const block = page.locator( '.wp-block-terminarz-booking' );
			await expect( block ).toContainText( 'Wybierz dzień' );
			await expect( block ).toContainText( /Krok \d z \d/ );
		} );

		test( 'the e-mail and the cancellation page are in Polish', async ( {
			request,
			requestUtils,
			page,
		} ) => {
			await clearMails( requestUtils );
			const date = new Date( Date.now() + 3 * 86400000 )
				.toISOString()
				.slice( 0, 10 );
			const starts = await offeredStarts(
				request,
				serviceId,
				resourceId,
				date
			);
			expect( starts.length ).toBeGreaterThan( 0 );
			const email = `pl-${ suffix }@example.org`;
			const response = await request.post(
				'/wp-json/terminarz/v1/bookings',
				{
					data: {
						service: serviceId,
						resource: String( resourceId ),
						start: starts[ 0 ],
						name: 'Jan Kowalski',
						email,
						consent: true,
						website: '',
					},
				}
			);
			expect( response.status() ).toBe( 201 );

			const mail = await waitForMail(
				requestUtils,
				( captured ) => captured.to === email
			);
			expect( mail.subject ).toContain(
				'Otrzymaliśmy Twoje zgłoszenie rezerwacji'
			);
			expect( mail.message ).toContain( 'Dzień dobry' );

			await page.goto(
				`/?trmz_cancel=${ '0'.repeat( 32 ) }&token=${ '0'.repeat( 64 ) }`
			);
			await expect(
				page.getByRole( 'heading', { name: 'Nieprawidłowy link' } )
			).toBeVisible();
		} );
	} );
} );
