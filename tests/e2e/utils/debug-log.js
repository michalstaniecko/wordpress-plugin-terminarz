/**
 * Helpers for inspecting wp-content/debug.log of the wp-env tests environment.
 *
 * The log is read over HTTP (the tests site serves wp-content/ directly), so no Docker access is needed.
 */
const path = require( 'path' );

const debugLogOffsetFile = path.join(
	process.env.WP_ARTIFACTS_PATH || path.join( process.cwd(), 'artifacts' ),
	'debug-log-offset.txt'
);

/**
 * PHP diagnostics that must never appear in the log.
 */
const PHP_PROBLEM =
	/PHP (Notice|Warning|Deprecated|Fatal error|Parse error|Recoverable fatal error)/;

/**
 * Known third-party entries that are not caused by this plugin. Keep this list short and specific.
 *
 * @type {RegExp[]}
 */
const IGNORED = [];

/**
 * Returns the whole debug.log ('' when it does not exist yet).
 *
 * @param {import('@playwright/test').APIRequestContext} requestContext Request context with baseURL.
 * @return {Promise<string>} Log contents.
 */
async function readDebugLog( requestContext ) {
	const response = await requestContext.get( '/wp-content/debug.log', {
		failOnStatusCode: false,
		headers: { 'Cache-Control': 'no-cache' },
	} );
	if ( response.status() === 404 ) {
		return '';
	}
	if ( ! response.ok() ) {
		throw new Error( `Cannot read debug.log: HTTP ${ response.status() }` );
	}
	return response.text();
}

/**
 * Returns PHP notices/warnings/errors logged after the given offset.
 *
 * @param {string} log    Full log contents.
 * @param {number} offset Number of characters to skip.
 * @return {string[]} Problem lines.
 */
function findPhpProblems( log, offset ) {
	return log
		.slice( offset )
		.split( '\n' )
		.filter( ( line ) => PHP_PROBLEM.test( line ) )
		.filter( ( line ) => ! IGNORED.some( ( re ) => re.test( line ) ) );
}

module.exports = { debugLogOffsetFile, readDebugLog, findPhpProblems };
