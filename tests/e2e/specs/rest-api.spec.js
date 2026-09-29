/**
 * REST API smoke tests against a real WordPress (routing, permissions, headers).
 * Detailed behaviour is covered by the PHP integration tests in tests/php/integration/Rest.
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

const route = ( path ) => `/?rest_route=${ encodeURIComponent( path ) }`;

test.describe( 'REST API terminarz/v1', () => {
	test( 'public catalogue is readable anonymously', async ( { request } ) => {
		const response = await request.get( route( '/terminarz/v1/services' ) );

		expect( response.status() ).toBe( 200 );
		expect( Array.isArray( await response.json() ) ).toBe( true );
	} );

	test( 'availability validates its arguments', async ( { request } ) => {
		const response = await request.get(
			`${ route( '/terminarz/v1/availability' ) }&service=1&from=2030-01-01&to=2030-03-01`
		);

		expect( response.status() ).toBe( 400 );
		expect( ( await response.json() ).code ).toBe( 'trmz_invalid_range' );
	} );

	test( 'admin booking list needs authentication', async ( {
		request,
		requestUtils,
	} ) => {
		// Cookies without a REST nonce do not authenticate.
		const anonymous = await request.get(
			route( '/terminarz/v1/bookings' )
		);
		expect( anonymous.status() ).toBe( 401 );

		const bookings = await requestUtils.rest( {
			path: '/terminarz/v1/bookings',
		} );
		expect( Array.isArray( bookings ) ).toBe( true );
	} );
} );
