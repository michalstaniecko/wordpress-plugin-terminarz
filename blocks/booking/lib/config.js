/**
 * Parses and normalises the block configuration from `data-trmz-config` (see BookingBlock::config()).
 *
 * @param {string|null} json JSON.
 * @return {Object|null} Configuration, or null when missing/invalid.
 */
export function parseConfig( json ) {
	let raw;
	try {
		raw = JSON.parse( json ?? '' );
	} catch {
		return null;
	}
	if (
		! raw ||
		typeof raw !== 'object' ||
		typeof raw.restRoot !== 'string'
	) {
		return null;
	}
	const isDate = ( value ) =>
		typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test( value );
	const today = isDate( raw.today )
		? raw.today
		: new Date().toISOString().slice( 0, 10 );
	const firstDay = Number( raw.firstDayOfWeek );

	return {
		restRoot: raw.restRoot,
		nonce: typeof raw.nonce === 'string' ? raw.nonce : '',
		serviceIds: Array.isArray( raw.serviceIds )
			? raw.serviceIds.map( Number ).filter( ( id ) => id > 0 )
			: [],
		defaultServiceId: Number( raw.defaultServiceId ) || 0,
		showResourcePicker: raw.showResourcePicker !== false,
		firstDayOfWeek:
			Number.isInteger( firstDay ) && firstDay >= 0 && firstDay <= 6
				? firstDay
				: 1,
		today,
		lastDate:
			isDate( raw.lastDate ) && raw.lastDate >= today
				? raw.lastDate
				: null,
		locale:
			typeof raw.locale === 'string' && raw.locale ? raw.locale : 'en-US',
		currency: typeof raw.currency === 'string' ? raw.currency : '',
		priceDecimals: Number.isInteger( raw.priceDecimals )
			? raw.priceDecimals
			: 2,
		consentHtml: typeof raw.consentHtml === 'string' ? raw.consentHtml : '',
		emailNotice: raw.emailNotice === true,
	};
}
