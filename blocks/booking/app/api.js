/**
 * REST client of the booking front end.
 *
 * Uses a private (bundled) copy of @wordpress/api-fetch — see webpack.config.js and ADR-031: the nonce middleware
 * is added only for logged-in users, anonymous visitors send no nonce (a nonce from a cached page would expire).
 */
// Webpack alias of @wordpress/api-fetch (bundled, not the wp.apiFetch global) — see webpack.config.js.
// eslint-disable-next-line import/no-unresolved
import apiFetch from 'trmz-bundled-api-fetch';
import { addQueryArgs } from '@wordpress/url';

let configured = false;

/**
 * Configures the client once per page (every block instance on a page gets the same root and nonce).
 *
 * @param {{restRoot:string, nonce:string}} config Block configuration.
 */
export function configureApi( { restRoot, nonce } ) {
	if ( configured ) {
		return;
	}
	apiFetch.use( apiFetch.createRootURLMiddleware( restRoot ) );
	if ( nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( nonce ) );
	}
	configured = true;
}

/**
 * Normalised API result.
 *
 * @typedef {Object} ApiResult
 * @property {boolean}     ok         Whether the status is 2xx.
 * @property {number}      status     HTTP status (0 = network error).
 * @property {Object|null} data       Parsed JSON body (error bodies: {code, message, data}).
 * @property {number|null} retryAfter Seconds from the Retry-After header, if any.
 */

/**
 * Sends a request and never throws: errors are returned as {ok: false}.
 *
 * @param {Object} options api-fetch options (path, method, data).
 * @return {Promise<ApiResult>} Result.
 */
export async function request( options ) {
	let response;
	try {
		response = await apiFetch( { ...options, parse: false } );
	} catch ( error ) {
		if ( typeof Response !== 'undefined' && error instanceof Response ) {
			response = error;
		} else {
			return { ok: false, status: 0, data: null, retryAfter: null };
		}
	}

	let data = null;
	try {
		data = await response.json();
	} catch {
		data = null;
	}
	const retryAfter = Number( response.headers?.get( 'Retry-After' ) );

	return {
		ok: response.ok,
		status: response.status,
		data,
		retryAfter:
			Number.isFinite( retryAfter ) && retryAfter > 0 ? retryAfter : null,
	};
}

/**
 * GET with query arguments.
 *
 * @param {string} path  Path under the REST root, e.g. "/terminarz/v1/services".
 * @param {Object} query Query arguments.
 * @return {Promise<ApiResult>} Result.
 */
export function get( path, query = {} ) {
	return request( { path: addQueryArgs( path, query ) } );
}

/**
 * POST with a JSON body.
 *
 * @param {string} path Path under the REST root.
 * @param {Object} data Body.
 * @return {Promise<ApiResult>} Result.
 */
export function post( path, data ) {
	return request( { path, method: 'POST', data } );
}
