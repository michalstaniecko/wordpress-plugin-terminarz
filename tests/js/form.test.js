/**
 * Unit tests of the customer form: validation, request body and mapping of REST errors.
 */
import {
	EMPTY_FORM,
	bookingRequest,
	firstInvalidField,
	mapBookingError,
	safeRedirectUrl,
	validateForm,
} from '../../blocks/booking/lib/form';

const valid = {
	...EMPTY_FORM,
	name: 'Jan Kowalski',
	email: 'jan@example.org',
	phone: '+48 600-100-200',
	consent: true,
};

describe( 'validateForm', () => {
	it( 'accepts a complete form', () => {
		expect( validateForm( valid ) ).toEqual( {} );
		expect( validateForm( { ...valid, phone: '' } ) ).toEqual( {} );
	} );

	it( 'requires name, e-mail and consent', () => {
		expect( validateForm( EMPTY_FORM ) ).toEqual( {
			name: 'required',
			email: 'required',
			consent: 'consent',
		} );
		expect( validateForm( { ...valid, name: '   ' } ) ).toEqual( {
			name: 'required',
		} );
	} );

	it( 'checks formats and lengths', () => {
		expect( validateForm( { ...valid, email: 'jan@' } ) ).toEqual( {
			email: 'email',
		} );
		expect( validateForm( { ...valid, phone: '600 abc' } ) ).toEqual( {
			phone: 'phone',
		} );
		expect(
			validateForm( { ...valid, note: 'x'.repeat( 1001 ) } )
		).toEqual( { note: 'tooLong' } );
		expect( validateForm( { ...valid, name: 'x'.repeat( 192 ) } ) ).toEqual(
			{ name: 'tooLong' }
		);
	} );

	it( 'finds the first invalid field in display order', () => {
		expect( firstInvalidField( { consent: 'x', email: 'y' } ) ).toBe(
			'email'
		);
		expect( firstInvalidField( {} ) ).toBeNull();
	} );
} );

describe( 'bookingRequest', () => {
	it( 'builds the REST body with the UTC start and trimmed values', () => {
		expect(
			bookingRequest(
				{ ...valid, name: ' Jan ', note: 'Hi', website: '' },
				7,
				'any',
				'2026-10-01T07:00:00Z'
			)
		).toEqual( {
			service: 7,
			resource: 'any',
			start: '2026-10-01T07:00:00Z',
			name: 'Jan',
			email: 'jan@example.org',
			phone: '+48 600-100-200',
			note: 'Hi',
			consent: true,
			website: '',
		} );
		expect( bookingRequest( valid, 7, 3, 'x' ).resource ).toBe( '3' );
	} );
} );

describe( 'mapBookingError', () => {
	const result = ( status, data, retryAfter = null ) => ( {
		ok: false,
		status,
		data,
		retryAfter,
	} );

	it( 'recognises a taken slot', () => {
		expect(
			mapBookingError(
				result( 409, { code: 'trmz_slot_unavailable', message: 'm' } )
			).kind
		).toBe( 'slot' );
	} );

	it( 'passes Retry-After of the rate limit', () => {
		const error = mapBookingError(
			result( 429, { code: 'trmz_rate_limited' }, 120 )
		);
		expect( error.kind ).toBe( 'rate' );
		expect( error.retryAfter ).toBe( 120 );
	} );

	it( 'maps schema errors to fields', () => {
		expect(
			mapBookingError(
				result( 400, {
					code: 'rest_invalid_param',
					data: { params: { email: 'x', phone: 'y' } },
				} )
			)
		).toMatchObject( {
			kind: 'fields',
			fields: { email: 'email', phone: 'phone' },
		} );
		expect(
			mapBookingError(
				result( 400, {
					code: 'rest_missing_callback_param',
					data: { params: [ 'name', 'consent' ] },
				} )
			).fields
		).toEqual( { name: 'required', consent: 'consent' } );
	} );

	it( 'maps plugin validation codes', () => {
		expect(
			mapBookingError( result( 400, { code: 'trmz_consent_required' } ) )
				.fields
		).toEqual( { consent: 'consent' } );
		expect(
			mapBookingError( result( 400, { code: 'trmz_invalid_customer' } ) )
				.kind
		).toBe( 'fields' );
	} );

	it( 'treats errors of other parameters as general errors', () => {
		const error = mapBookingError(
			result( 400, {
				code: 'rest_invalid_param',
				message: 'Invalid parameter(s): service',
				data: { params: { service: 'x' } },
			} )
		);
		expect( error.kind ).toBe( 'general' );
		expect( error.message ).toBe( 'Invalid parameter(s): service' );
	} );

	it( 'recognises session, network and unknown errors', () => {
		expect(
			mapBookingError(
				result( 403, { code: 'rest_cookie_invalid_nonce' } )
			).kind
		).toBe( 'session' );
		expect( mapBookingError( result( 0, null ) ).kind ).toBe( 'network' );
		expect( mapBookingError( result( 500, null ) ).kind ).toBe( 'general' );
	} );
} );

describe( 'safeRedirectUrl', () => {
	it( 'accepts http(s) URLs only', () => {
		expect( safeRedirectUrl( 'https://shop.example.org/checkout/1' ) ).toBe(
			'https://shop.example.org/checkout/1'
		);
		expect( safeRedirectUrl( 'javascript:alert(1)' ) ).toBeNull();
		expect( safeRedirectUrl( 'data:text/html,x' ) ).toBeNull();
		expect( safeRedirectUrl( '' ) ).toBeNull();
		expect( safeRedirectUrl( undefined ) ).toBeNull();
	} );
} );
