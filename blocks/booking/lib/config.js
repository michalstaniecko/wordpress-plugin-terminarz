import { uses12HourClock } from './format';

/**
 * Class of the script element with the configuration (BookingBlock::CONFIG_CLASS).
 */
export const CONFIG_CLASS = 'trmz-booking__config';

/**
 * Reads the configuration of a block container rendered by the server.
 *
 * Only a `<script type="application/json">` child is trusted: authors without the `unfiltered_html` capability can put
 * a look-alike container with arbitrary `data-*` attributes into post content, but not a script element (ADR-048).
 *
 * @param {Element} container Block container.
 * @return {Object|null} Configuration, or null when the container was not rendered by the block.
 */
export function readConfig( container ) {
	const script = Array.from( container?.children ?? [] ).find(
		( child ) =>
			child.tagName === 'SCRIPT' &&
			child.type === 'application/json' &&
			child.classList.contains( CONFIG_CLASS )
	);
	return script ? parseConfig( script.textContent ) : null;
}

/**
 * Parses and normalises the block configuration JSON (see BookingBlock::config()).
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
		hour12: uses12HourClock( raw.timeFormat ),
	};
}
