/**
 * Customer form: client-side validation and mapping of REST errors of `POST /terminarz/v1/bookings`.
 *
 * Both return error *codes*; the UI turns them into translated messages. Limits mirror the REST schema
 * (BookingsController::get_create_params()).
 */

export const EMPTY_FORM = {
	name: '',
	email: '',
	phone: '',
	note: '',
	consent: false,
	website: '',
};

export const LIMITS = { name: 191, email: 191, phone: 50, note: 1000 };

/** Same pattern as the REST schema of `phone`. */
const PHONE = /^[0-9+()./ -]*$/;

/** Pragmatic e-mail check (the server validates with is_email()). */
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Form fields in display order (for focusing the first invalid one). */
export const FIELD_ORDER = [ 'name', 'email', 'phone', 'note', 'consent' ];

/**
 * Validates the form.
 *
 * @param {Object} form Values.
 * @return {Object<string, string>} Error codes by field (empty object = valid): required, email, phone, tooLong, consent.
 */
export function validateForm( form ) {
	const errors = {};
	const name = ( form.name ?? '' ).trim();
	const email = ( form.email ?? '' ).trim();
	const phone = ( form.phone ?? '' ).trim();

	if ( ! name ) {
		errors.name = 'required';
	} else if ( name.length > LIMITS.name ) {
		errors.name = 'tooLong';
	}

	if ( ! email ) {
		errors.email = 'required';
	} else if ( email.length > LIMITS.email || ! EMAIL.test( email ) ) {
		errors.email = 'email';
	}

	if ( phone.length > LIMITS.phone || ! PHONE.test( phone ) ) {
		errors.phone = 'phone';
	}

	if ( ( form.note ?? '' ).length > LIMITS.note ) {
		errors.note = 'tooLong';
	}

	if ( form.consent !== true ) {
		errors.consent = 'consent';
	}

	return errors;
}

/**
 * The first field (in display order) with an error.
 *
 * @param {Object<string, string>} errors Errors by field.
 * @return {string|null} Field name.
 */
export function firstInvalidField( errors ) {
	return FIELD_ORDER.find( ( field ) => errors[ field ] ) ?? null;
}

/**
 * Request body of `POST /terminarz/v1/bookings`.
 *
 * @param {Object}        form      Form values.
 * @param {number}        serviceId Service.
 * @param {number|string} resource  Resource ID or "any".
 * @param {string}        startUtc  Slot start (UTC, ISO 8601).
 * @return {Object} Body.
 */
export function bookingRequest( form, serviceId, resource, startUtc ) {
	return {
		service: serviceId,
		resource: String( resource ),
		start: startUtc,
		name: form.name.trim(),
		email: form.email.trim(),
		phone: form.phone.trim(),
		note: form.note,
		consent: form.consent === true,
		website: form.website,
	};
}

/** Code for schema errors of each field. */
const PARAM_CODES = {
	name: 'invalid',
	email: 'email',
	phone: 'phone',
	note: 'tooLong',
	consent: 'consent',
};

/**
 * Classifies a failed booking request.
 *
 * @param {{status:number, data:Object|null, retryAfter:number|null}} result API result.
 * @return {{kind:string, fields:Object<string,string>, message:string, retryAfter:number|null}} Error:
 *   kind = fields | slot | rate | session | network | general.
 */
export function mapBookingError( result ) {
	const code = result.data?.code ?? '';
	const message =
		typeof result.data?.message === 'string' ? result.data.message : '';
	const error = ( kind, fields = {} ) => ( {
		kind,
		fields,
		message,
		retryAfter: result.retryAfter ?? null,
	} );

	if ( result.status === 0 ) {
		return error( 'network' );
	}
	if ( result.status === 409 || code === 'trmz_invalid_date' ) {
		return error( 'slot' );
	}
	if ( result.status === 429 ) {
		return error( 'rate' );
	}
	if ( code === 'rest_cookie_invalid_nonce' ) {
		return error( 'session' );
	}
	if ( code === 'trmz_consent_required' ) {
		return error( 'fields', { consent: 'consent' } );
	}
	if ( code === 'trmz_invalid_customer' ) {
		return error( 'fields', { name: 'invalid', email: 'email' } );
	}
	if (
		code === 'rest_invalid_param' ||
		code === 'rest_missing_callback_param'
	) {
		const params = result.data?.data?.params ?? {};
		const names = Array.isArray( params ) ? params : Object.keys( params );
		const fields = {};
		names.forEach( ( name ) => {
			if ( PARAM_CODES[ name ] ) {
				fields[ name ] =
					code === 'rest_missing_callback_param' && name !== 'consent'
						? 'required'
						: PARAM_CODES[ name ];
			}
		} );
		// Errors of non-form parameters (service, resource, start) cannot be fixed by the customer.
		return Object.keys( fields ).length
			? error( 'fields', fields )
			: error( 'general' );
	}
	return error( 'general' );
}

/**
 * Accepts only http(s) URLs for the payment redirect (M6), never `javascript:` or other schemes.
 *
 * @param {*} url Value of `payment_url`.
 * @return {string|null} URL or null.
 */
export function safeRedirectUrl( url ) {
	if ( typeof url !== 'string' || ! url ) {
		return null;
	}
	try {
		const parsed = new URL( url, window.location.href );
		return [ 'http:', 'https:' ].includes( parsed.protocol )
			? parsed.href
			: null;
	} catch {
		return null;
	}
}
