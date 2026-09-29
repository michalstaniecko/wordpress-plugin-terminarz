/**
 * "Terminarz → E-mails": editing a template, preview, test e-mail (captured by the mail catcher mu-plugin), reset.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { clearMails, waitForMail } = require( '../utils/mails' );

const EMAILS_PAGE = 'admin.php?page=trmz-emails';

test.describe( 'E-mail templates', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await clearMails( requestUtils );
	} );

	test( 'edits a template, sends a test e-mail and restores the default', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( EMAILS_PAGE );
		await expect(
			page.getByRole( 'heading', { name: 'E-mails', exact: true } )
		).toBeVisible();
		await page
			.getByRole( 'link', { name: 'Booking confirmed (customer)' } )
			.click();

		await page.getByLabel( 'Subject' ).fill( 'E2E subject {service_name}' );
		await page
			.getByLabel( 'Message', { exact: true } )
			.fill( '<p>E2E body for {customer_name}</p>' );
		await page.getByRole( 'button', { name: 'Save e-mail' } ).click();

		await expect( page.getByText( 'E-mail saved.' ) ).toBeVisible();
		await expect( page.locator( '#trmz-email-preview' ) ).toContainText(
			'E2E body for Jane Doe'
		);

		await page
			.getByRole( 'button', { name: 'Send a test e-mail to me' } )
			.click();
		await expect( page.getByText( /Test e-mail sent to/ ) ).toBeVisible();
		const mail = await waitForMail( requestUtils, ( m ) =>
			m.subject.startsWith( 'E2E subject Example service' )
		);
		expect( mail.message ).toContain( 'E2E body for Jane Doe' );
		expect( mail.headers ).toContain( 'Content-Type: text/html' );

		await page
			.getByRole( 'button', { name: 'Restore default text' } )
			.click();
		await expect(
			page.getByText( 'The default subject and text were restored.' )
		).toBeVisible();
		await expect( page.getByLabel( 'Subject' ) ).toHaveValue(
			/Your booking is confirmed/
		);
	} );
} );
