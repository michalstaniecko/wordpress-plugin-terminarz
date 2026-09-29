/**
 * Admin menu and settings screen.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const SETTINGS_PAGE = 'admin.php?page=trmz-settings';

test.describe( 'Settings screen', () => {
	test( 'is reachable from the Terminarz menu', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'index.php' );

		const menu = page.locator( '#adminmenu' );
		await menu
			.getByRole( 'link', { name: 'Terminarz', exact: true } )
			.click();

		await expect(
			page.getByRole( 'heading', { name: 'Terminarz settings' } )
		).toBeVisible();
		await expect( page ).toHaveURL( /page=trmz-settings/ );
	} );

	test( 'saves and restores settings', async ( { admin, page } ) => {
		await admin.visitAdminPage( SETTINGS_PAGE );

		const step = page.locator( '#trmz-slot_step_minutes' );
		const autoConfirm = page.locator( '#trmz-auto_confirm' );
		const initialStep = await step.inputValue();
		const initialAutoConfirm = await autoConfirm.isChecked();

		await step.fill( '30' );
		await autoConfirm.setChecked( ! initialAutoConfirm );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();

		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();
		await expect( step ).toHaveValue( '30' );
		await expect( autoConfirm ).toBeChecked( {
			checked: ! initialAutoConfirm,
		} );

		// Restore the previous values so other specs see the defaults.
		await step.fill( initialStep );
		await autoConfirm.setChecked( initialAutoConfirm );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect( step ).toHaveValue( initialStep );
	} );
} );
