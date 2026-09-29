/**
 * Last step: summary of the chosen appointment and the customer form (name, e-mail, phone, note, consent,
 * honeypot). Errors are shown next to the fields (`aria-invalid` + `aria-describedby`); the entered data lives in
 * BookingApp, so nothing is lost when the chosen time turns out to be taken and the customer picks another one.
 */
import { useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { post } from './api';
import Step from './Step';
import Summary from './Summary';
import {
	LIMITS,
	bookingRequest,
	firstInvalidField,
	mapBookingError,
	safeRedirectUrl,
	validateForm,
} from '../lib/form';

/**
 * Translated message of a field error code.
 *
 * @param {string} field Field.
 * @param {string} code  Code.
 * @return {string} Message.
 */
function fieldMessage( field, code ) {
	switch ( code ) {
		case 'required':
			return field === 'email'
				? __( 'Please enter your e-mail address.', 'terminarz' )
				: __( 'Please enter your name.', 'terminarz' );
		case 'email':
			return __(
				'Please enter a valid e-mail address, e.g. name@example.com.',
				'terminarz'
			);
		case 'phone':
			return __(
				'The phone number may contain only digits, spaces and + ( ) - . /',
				'terminarz'
			);
		case 'tooLong':
			return __( 'This text is too long.', 'terminarz' );
		case 'consent':
			return __(
				'Please accept the terms to book an appointment.',
				'terminarz'
			);
		default:
			return __( 'Please check this field.', 'terminarz' );
	}
}

/**
 * Translated message of a request error that is not tied to a field.
 *
 * @param {{kind:string, message:string, retryAfter:number|null}} error Mapped error.
 * @return {string} Message.
 */
function requestMessage( error ) {
	switch ( error.kind ) {
		case 'rate':
			if ( error.retryAfter ) {
				const minutes = Math.max(
					1,
					Math.ceil( error.retryAfter / 60 )
				);
				return sprintf(
					/* translators: %d: number of minutes. */
					_n(
						'Too many booking attempts. Please try again in %d minute.',
						'Too many booking attempts. Please try again in %d minutes.',
						minutes,
						'terminarz'
					),
					minutes
				);
			}
			return __(
				'Too many booking attempts. Please try again later.',
				'terminarz'
			);
		case 'session':
			return __(
				'Your session has expired. Please reload the page and try again.',
				'terminarz'
			);
		case 'network':
			return __(
				'The booking could not be sent. Please check your connection and try again.',
				'terminarz'
			);
		case 'fields':
			return __( 'Please correct the marked fields.', 'terminarz' );
		default:
			return (
				error.message ||
				__( 'Something went wrong. Please try again.', 'terminarz' )
			);
	}
}

export default function DetailsStep( {
	stepProps,
	idPrefix,
	config,
	service,
	resource,
	resourceName,
	date,
	slot,
	timezone,
	backButton,
	form,
	setForm,
	announce,
	onBooked,
	onSlotUnavailable,
} ) {
	const [ errors, setErrors ] = useState( {} );
	const [ formError, setFormError ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );
	const formRef = useRef();
	const id = ( field ) => `${ idPrefix }-${ field }`;

	const focusField = ( field ) => {
		const element = formRef.current?.querySelector( `#${ id( field ) }` );
		element?.focus();
	};

	const update = ( field ) => ( event ) => {
		const value =
			event.target.type === 'checkbox'
				? event.target.checked
				: event.target.value;
		setForm( ( current ) => ( { ...current, [ field ]: value } ) );
		if ( errors[ field ] ) {
			setErrors( ( current ) => {
				const next = { ...current };
				delete next[ field ];
				return next;
			} );
		}
	};

	const onSubmit = async ( event ) => {
		event.preventDefault();
		if ( submitting || ! slot ) {
			return;
		}
		setFormError( '' );

		const invalid = validateForm( form );
		if ( Object.keys( invalid ).length ) {
			setErrors( invalid );
			focusField( firstInvalidField( invalid ) );
			return;
		}
		setErrors( {} );

		setSubmitting( true );
		announce( __( 'Sending your booking…', 'terminarz' ) );
		const result = await post(
			'/terminarz/v1/bookings',
			bookingRequest( form, service.id, resource, slot.start_utc )
		);
		setSubmitting( false );
		announce( '' );

		if ( result.ok && result.data ) {
			// Extension point for payments (M6): the server may ask for a redirect to the checkout.
			const redirect = safeRedirectUrl( result.data.payment_url );
			onBooked( result.data, redirect );
			return;
		}

		const error = mapBookingError( result );
		if ( error.kind === 'slot' ) {
			onSlotUnavailable(
				__(
					'Sorry, this time has just been booked by someone else. Please choose another time — your details are kept.',
					'terminarz'
				)
			);
			return;
		}
		if ( error.kind === 'fields' ) {
			setErrors( error.fields );
			setFormError( requestMessage( error ) );
			focusField( firstInvalidField( error.fields ) );
			return;
		}
		setFormError( requestMessage( error ) );
	};

	const describedBy = ( field, hint ) =>
		[
			hint && id( `${ field }-hint` ),
			errors[ field ] && id( `${ field }-error` ),
		]
			.filter( Boolean )
			.join( ' ' ) || undefined;

	const fieldError = ( field ) =>
		errors[ field ] && (
			<p id={ id( `${ field }-error` ) } className="trmz-field__error">
				{ fieldMessage( field, errors[ field ] ) }
			</p>
		);

	const textField = ( field, label, props = {}, hint = '' ) => (
		<div className="trmz-field">
			<label className="trmz-field__label" htmlFor={ id( field ) }>
				{ label }
				{ props.required && (
					<span className="trmz-field__required">
						{ ' ' }
						{ __( '(required)', 'terminarz' ) }
					</span>
				) }
			</label>
			{ hint && (
				<p id={ id( `${ field }-hint` ) } className="trmz-field__hint">
					{ hint }
				</p>
			) }
			<input
				id={ id( field ) }
				name={ field }
				className="trmz-field__input"
				value={ form[ field ] }
				onChange={ update( field ) }
				aria-invalid={ errors[ field ] ? true : undefined }
				aria-describedby={ describedBy( field, hint ) }
				maxLength={ LIMITS[ field ] }
				{ ...props }
			/>
			{ fieldError( field ) }
		</div>
	);

	return (
		<Step { ...stepProps }>
			{ slot && (
				<Summary
					serviceName={ service?.name ?? '' }
					resourceName={ resourceName }
					date={ date }
					start={ slot.start }
					end={ slot.end }
					timezone={ timezone }
					locale={ config.locale }
				/>
			) }
			<form
				ref={ formRef }
				className="trmz-booking__form"
				noValidate
				onSubmit={ onSubmit }
				aria-describedby={ formError ? id( 'form-error' ) : undefined }
			>
				{ textField( 'name', __( 'Full name', 'terminarz' ), {
					type: 'text',
					autoComplete: 'name',
					required: true,
				} ) }
				{ textField( 'email', __( 'E-mail', 'terminarz' ), {
					type: 'email',
					autoComplete: 'email',
					required: true,
				} ) }
				{ textField(
					'phone',
					__( 'Phone', 'terminarz' ),
					{ type: 'tel', autoComplete: 'tel' },
					__( 'Optional.', 'terminarz' )
				) }
				<div className="trmz-field">
					<label
						className="trmz-field__label"
						htmlFor={ id( 'note' ) }
					>
						{ __( 'Note for us', 'terminarz' ) }
					</label>
					<p id={ id( 'note-hint' ) } className="trmz-field__hint">
						{ __( 'Optional.', 'terminarz' ) }
					</p>
					<textarea
						id={ id( 'note' ) }
						name="note"
						className="trmz-field__input trmz-field__input--textarea"
						rows={ 3 }
						value={ form.note }
						onChange={ update( 'note' ) }
						maxLength={ LIMITS.note }
						aria-invalid={ errors.note ? true : undefined }
						aria-describedby={ describedBy( 'note', true ) }
					/>
					{ fieldError( 'note' ) }
				</div>
				<div className="trmz-field trmz-field--checkbox">
					<input
						id={ id( 'consent' ) }
						name="consent"
						type="checkbox"
						className="trmz-field__checkbox"
						checked={ form.consent }
						onChange={ update( 'consent' ) }
						required
						aria-invalid={ errors.consent ? true : undefined }
						aria-describedby={ describedBy( 'consent', false ) }
					/>
					<label
						className="trmz-field__label trmz-field__label--checkbox"
						htmlFor={ id( 'consent' ) }
					>
						{ /* Consent text from the settings, filtered on the server with wp_kses() (inline markup and links only). */ }
						<span
							dangerouslySetInnerHTML={ {
								__html: config.consentHtml,
							} }
						/>
						<span className="trmz-field__required">
							{ ' ' }
							{ __( '(required)', 'terminarz' ) }
						</span>
					</label>
					{ fieldError( 'consent' ) }
				</div>
				{ /* Honeypot: hidden from people and assistive technology; bots filling every field are rejected. */ }
				<div className="trmz-booking__hp" aria-hidden="true">
					<label htmlFor={ id( 'website' ) }>
						{ __( 'Leave this field empty', 'terminarz' ) }
					</label>
					<input
						id={ id( 'website' ) }
						name="website"
						type="text"
						tabIndex={ -1 }
						autoComplete="off"
						value={ form.website }
						onChange={ update( 'website' ) }
					/>
				</div>
				{ formError && (
					<p
						id={ id( 'form-error' ) }
						className="trmz-booking__error"
						role="alert"
					>
						{ formError }
					</p>
				) }
				<div className="trmz-booking__actions">
					{ backButton }
					<button
						type="submit"
						className="trmz-booking__button"
						aria-disabled={ submitting ? true : undefined }
					>
						{ submitting
							? __( 'Sending…', 'terminarz' )
							: __( 'Book appointment', 'terminarz' ) }
					</button>
				</div>
			</form>
		</Step>
	);
}
