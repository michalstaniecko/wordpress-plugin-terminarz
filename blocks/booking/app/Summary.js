/**
 * Summary of the chosen appointment (service, person/resource, day, time, time zone).
 */
import { __ } from '@wordpress/i18n';

import { formatLongDate, formatTime } from '../lib/format';

export default function Summary( {
	serviceName,
	resourceName,
	date,
	start,
	end,
	timezone,
	locale,
	hour12 = false,
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
					{ formatTime( start, locale, hour12 ) }–
					{ formatTime( end, locale, hour12 ) }
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
