/**
 * Access to e-mails captured on the tests site by the `trmz-mail-catcher.php` mu-plugin.
 */

/**
 * @typedef {Object} CapturedMail
 * @property {string} to      Recipients (comma-separated).
 * @property {string} subject Subject.
 * @property {string} message HTML body.
 * @property {string} headers Headers (one per line).
 */

/**
 * Removes every captured e-mail.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils Request utils.
 * @return {Promise<void>}
 */
async function clearMails( requestUtils ) {
	await requestUtils.rest( { path: '/trmz-e2e/v1/mails', method: 'DELETE' } );
}

/**
 * Captured e-mails, oldest first.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils Request utils.
 * @return {Promise<CapturedMail[]>} E-mails.
 */
async function getMails( requestUtils ) {
	return requestUtils.rest( { path: '/trmz-e2e/v1/mails' } );
}

/**
 * Waits until an e-mail matching the predicate is captured and returns it.
 *
 * @param {import('@wordpress/e2e-test-utils-playwright').RequestUtils} requestUtils Request utils.
 * @param {function(CapturedMail): boolean}                             predicate    Match.
 * @param {number}                                                      [timeout]    Milliseconds (default 10 s).
 * @return {Promise<CapturedMail>} E-mail.
 */
async function waitForMail( requestUtils, predicate, timeout = 10000 ) {
	const deadline = Date.now() + timeout;
	for (;;) {
		const found = ( await getMails( requestUtils ) ).find( predicate );
		if ( found ) {
			return found;
		}
		if ( Date.now() > deadline ) {
			throw new Error( 'Expected e-mail was not captured.' );
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 250 ) );
	}
}

module.exports = { clearMails, getMails, waitForMail };
