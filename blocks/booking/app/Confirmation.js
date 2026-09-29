/**
 * Final screen after a successful booking: the booked appointment (as returned by the server) and its status.
 */
import { __ } from '@wordpress/i18n';

import Step from './Step';
import Summary from './Summary';
import { dateOf } from '../lib/slots';

/**
 * Status description for the customer.
 *
 * @param {string} status Booking status from the API.
 * @return {string} Text.
 */
export function statusMessage( status ) {
	switch ( status ) {
		case 'confirmed':
			return __( 'Your booking is confirmed.', 'terminarz' );
		case 'pending_payment':
			return __( 'Your booking is waiting for payment.', 'terminarz' );
		default:
			return __(
				'Your booking is awaiting confirmation. We will get back to you soon.',
				'terminarz'
			);
	}
}

export default function Confirmation( {
	stepProps,
	booking,
	emailNotice = false,
	serviceName,
	resourceName,
	timezone,
	locale,
	onRestart,
} ) {
	return (
		<Step { ...stepProps }>
			<p className="trmz-booking__status-message">
				<strong>{ statusMessage( booking.status ) }</strong>
			</p>
			{ emailNotice && booking.status !== 'pending_payment' && (
				<p className="trmz-booking__email-notice">
					{ __(
						'We have sent the details to your e-mail address, together with a link to cancel the booking if you cannot come.',
						'terminarz'
					) }
				</p>
			) }
			<Summary
				serviceName={ serviceName }
				resourceName={ resourceName }
				date={ dateOf( booking.start ) }
				start={ booking.start }
				end={ booking.end }
				timezone={ timezone }
				locale={ locale }
			/>
			<div className="trmz-booking__actions">
				<button
					type="button"
					className="trmz-booking__button trmz-booking__button--secondary"
					onClick={ onRestart }
				>
					{ __( 'Book another appointment', 'terminarz' ) }
				</button>
			</div>
		</Step>
	);
}
