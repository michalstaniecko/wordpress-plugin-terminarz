/**
 * Summary of the chosen appointment (service, person/resource, day, time, time zone).
 */
import { __ } from '@wordpress/i18n';

import { formatLongDate } from '../lib/format';
import { timeOf } from '../lib/slots';

export default function Summary( {
	serviceName,
	resourceName,
	date,
	start,
	end,
	timezone,
	locale,
} ) {
	return (
		<dl className="trmz-booking__details">
			<div className="trmz-booking__detail">
				<dt>{ __( 'Service', 'terminarz' ) }</dt>
				<dd>{ serviceName }</dd>
			</div>
			{ resourceName && (
				<div className="trmz-booking__detail">
					<dt>{ __( 'With', 'terminarz' ) }</dt>
					<dd>{ resourceName }</dd>
				</div>
			) }
			<div className="trmz-booking__detail">
				<dt>{ __( 'Date', 'terminarz' ) }</dt>
				<dd>{ formatLongDate( date, locale ) }</dd>
			</div>
			<div className="trmz-booking__detail">
				<dt>{ __( 'Time', 'terminarz' ) }</dt>
				<dd>
					{ timeOf( start ) }–{ timeOf( end ) }
					{ timezone && (
						<span className="trmz-booking__zone">
							{ ' ' }
							({ timezone })
						</span>
					) }
				</dd>
			</div>
		</dl>
	);
}
