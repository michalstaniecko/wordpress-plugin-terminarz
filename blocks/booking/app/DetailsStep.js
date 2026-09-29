/**
 * Last step: summary of the chosen appointment. The customer form is added in #31.
 */
import { __ } from '@wordpress/i18n';

import Step from './Step';
import Summary from './Summary';

export default function DetailsStep( {
	stepProps,
	config,
	service,
	resourceName,
	date,
	slot,
	timezone,
	backButton,
} ) {
	return (
		<Step { ...stepProps }>
			{ slot ? (
				<Summary
					serviceName={ service?.name ?? '' }
					resourceName={ resourceName }
					date={ date }
					start={ slot.start }
					end={ slot.end }
					timezone={ timezone }
					locale={ config.locale }
				/>
			) : (
				<p className="trmz-booking__message">
					{ __( 'Please choose a time.', 'terminarz' ) }
				</p>
			) }
			<div className="trmz-booking__actions">{ backButton }</div>
		</Step>
	);
}
