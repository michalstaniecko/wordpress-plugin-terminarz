/**
 * Helpers controlling WooCommerce activation in E2E tests.
 *
 * The plugin must work with and without WooCommerce, so specs switch it explicitly.
 * Always restore the default state (active) in afterAll/afterEach.
 */

const WOOCOMMERCE_SLUG = 'woocommerce';

/**
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils Request utils.
 * @return {Promise<void>}
 */
async function activateWooCommerce( requestUtils ) {
	await requestUtils.activatePlugin( WOOCOMMERCE_SLUG );
}

/**
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils Request utils.
 * @return {Promise<void>}
 */
async function deactivateWooCommerce( requestUtils ) {
	await requestUtils.deactivatePlugin( WOOCOMMERCE_SLUG );
}

module.exports = {
	WOOCOMMERCE_SLUG,
	activateWooCommerce,
	deactivateWooCommerce,
};
