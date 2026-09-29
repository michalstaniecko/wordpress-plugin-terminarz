/**
 * Locale-aware labels (Intl). Dates "Y-m-d" are formatted as UTC midnights with `timeZone: 'UTC'`, so the label
 * always shows the same calendar day regardless of the visitor's time zone.
 */
import { parseDate } from './calendar';

/**
 * @param {string} date Date "Y-m-d".
 * @return {Date} UTC midnight.
 */
function utcDate( date ) {
	const { year, month, day } = parseDate( date );
	return new Date( Date.UTC( year, month - 1, day ) );
}

/**
 * Creates an Intl.DateTimeFormat, falling back to the browser default for an unsupported locale.
 *
 * @param {string} locale  BCP 47 locale.
 * @param {Object} options Options.
 * @return {Intl.DateTimeFormat} Formatter.
 */
function dateFormatter( locale, options ) {
	try {
		return new Intl.DateTimeFormat( locale, {
			...options,
			timeZone: 'UTC',
		} );
	} catch {
		return new Intl.DateTimeFormat( undefined, {
			...options,
			timeZone: 'UTC',
		} );
	}
}

/**
 * "Thursday, 1 October 2026" (locale dependent).
 *
 * @param {string} date   Date "Y-m-d".
 * @param {string} locale Locale.
 * @return {string} Label.
 */
export function formatLongDate( date, locale ) {
	return dateFormatter( locale, {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	} ).format( utcDate( date ) );
}

/**
 * "October 2026" (locale dependent; standalone month form where the locale has one).
 *
 * @param {{year:number, month:number}} value  Month.
 * @param {string}                      locale Locale.
 * @return {string} Label.
 */
export function formatMonth( { year, month }, locale ) {
	return dateFormatter( locale, { month: 'long', year: 'numeric' } ).format(
		new Date( Date.UTC( year, month - 1, 1 ) )
	);
}

/**
 * Weekday names: long ("Monday") and short ("Mon").
 *
 * @param {number} day    0 = Sunday … 6 = Saturday.
 * @param {string} locale Locale.
 * @return {{long:string, short:string}} Names.
 */
export function weekdayName( day, locale ) {
	// 4 January 1970 was a Sunday.
	const date = new Date( Date.UTC( 1970, 0, 4 + day ) );
	return {
		long: dateFormatter( locale, { weekday: 'long' } ).format( date ),
		short: dateFormatter( locale, { weekday: 'short' } ).format( date ),
	};
}

/**
 * Price in minor units formatted with the site currency: "150.00 PLN" / "150,00 PLN".
 *
 * @param {number} minor    Amount in minor units.
 * @param {number} decimals Decimals of the currency.
 * @param {string} currency Currency code ('' = none).
 * @param {string} locale   Locale.
 * @return {string} Price.
 */
export function formatPrice( minor, decimals, currency, locale ) {
	const amount = minor / 10 ** decimals;
	let text;
	try {
		text = new Intl.NumberFormat( locale, {
			minimumFractionDigits: decimals,
			maximumFractionDigits: decimals,
		} ).format( amount );
	} catch {
		text = amount.toFixed( decimals );
	}
	return currency ? `${ text } ${ currency }` : text;
}

/**
 * Readable time zone name: IANA names as they are ("Europe/Warsaw"), fixed offsets as "UTC+02:00".
 *
 * @param {string} timezone Time zone of the availability response.
 * @return {string} Label.
 */
export function timezoneLabel( timezone ) {
	if ( /^[+-]\d{2}:\d{2}$/.test( timezone ?? '' ) ) {
		return `UTC${ timezone }`;
	}
	return timezone ?? '';
}
