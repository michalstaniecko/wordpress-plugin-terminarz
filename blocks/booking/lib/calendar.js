/**
 * Calendar arithmetic on local dates of the site time zone, represented as "Y-m-d" strings.
 *
 * Dates never go through the browser time zone: "Y-m-d" strings are converted to UTC midnights only to compute
 * weekdays and to add days, so the visitor's own time zone and DST cannot shift a day.
 */

const DAY_MS = 86400000;

/**
 * @param {number} number Number.
 * @return {string} Two-digit number.
 */
const pad = ( number ) => String( number ).padStart( 2, '0' );

/**
 * Parses "Y-m-d".
 *
 * @param {string} date Date.
 * @return {{year:number, month:number, day:number}} Parts (month 1–12).
 */
export function parseDate( date ) {
	const [ year, month, day ] = date.split( '-' ).map( Number );
	return { year, month, day };
}

/**
 * Builds "Y-m-d".
 *
 * @param {number} year  Year.
 * @param {number} month Month 1–12.
 * @param {number} day   Day.
 * @return {string} Date.
 */
export function formatDate( year, month, day ) {
	return `${ year }-${ pad( month ) }-${ pad( day ) }`;
}

/**
 * @param {string} date Date "Y-m-d".
 * @return {number} UTC timestamp of the date's midnight.
 */
function toUtc( date ) {
	const { year, month, day } = parseDate( date );
	return Date.UTC( year, month - 1, day );
}

/**
 * @param {number} timestamp UTC timestamp.
 * @return {string} Date "Y-m-d".
 */
function fromUtc( timestamp ) {
	const value = new Date( timestamp );
	return formatDate(
		value.getUTCFullYear(),
		value.getUTCMonth() + 1,
		value.getUTCDate()
	);
}

/**
 * Adds days to a date.
 *
 * @param {string} date Date "Y-m-d".
 * @param {number} days Days (may be negative).
 * @return {string} Date "Y-m-d".
 */
export function addDays( date, days ) {
	return fromUtc( toUtc( date ) + days * DAY_MS );
}

/**
 * Day of the week (0 = Sunday … 6 = Saturday).
 *
 * @param {string} date Date "Y-m-d".
 * @return {number} Weekday.
 */
export function weekday( date ) {
	return new Date( toUtc( date ) ).getUTCDay();
}

/**
 * Number of days in a month.
 *
 * @param {number} year  Year.
 * @param {number} month Month 1–12.
 * @return {number} Days.
 */
export function daysInMonth( year, month ) {
	return new Date( Date.UTC( year, month, 0 ) ).getUTCDate();
}

/**
 * Month shifted by `delta` months.
 *
 * @param {{year:number, month:number}} value Month.
 * @param {number}                      delta Months (may be negative).
 * @return {{year:number, month:number}} Month.
 */
export function addMonths( { year, month }, delta ) {
	const index = year * 12 + ( month - 1 ) + delta;
	return { year: Math.floor( index / 12 ), month: ( index % 12 ) + 1 };
}

/**
 * The month containing a date.
 *
 * @param {string} date Date "Y-m-d".
 * @return {{year:number, month:number}} Month.
 */
export function monthOf( date ) {
	const { year, month } = parseDate( date );
	return { year, month };
}

/**
 * Compares months.
 *
 * @param {{year:number, month:number}} a Month.
 * @param {{year:number, month:number}} b Month.
 * @return {number} Negative, 0 or positive.
 */
export function compareMonths( a, b ) {
	return a.year * 12 + a.month - ( b.year * 12 + b.month );
}

/**
 * Same day of another month, clamped to the length of that month (31 January + 1 month = 28/29 February).
 *
 * @param {string} date  Date "Y-m-d".
 * @param {number} delta Months.
 * @return {string} Date "Y-m-d".
 */
export function addMonthsToDate( date, delta ) {
	const { day } = parseDate( date );
	const target = addMonths( monthOf( date ), delta );
	return formatDate(
		target.year,
		target.month,
		Math.min( day, daysInMonth( target.year, target.month ) )
	);
}

/**
 * Weeks of a month for a calendar grid: rows of 7 cells, `null` for cells outside the month.
 *
 * @param {number} year           Year.
 * @param {number} month          Month 1–12.
 * @param {number} firstDayOfWeek 0 = Sunday … 6 = Saturday.
 * @return {Array<Array<string|null>>} Weeks.
 */
export function monthGrid( year, month, firstDayOfWeek ) {
	const first = formatDate( year, month, 1 );
	const lead = ( weekday( first ) - firstDayOfWeek + 7 ) % 7;
	const cells = Array( lead ).fill( null );
	const length = daysInMonth( year, month );
	for ( let day = 1; day <= length; day++ ) {
		cells.push( formatDate( year, month, day ) );
	}
	while ( cells.length % 7 !== 0 ) {
		cells.push( null );
	}
	const weeks = [];
	for ( let i = 0; i < cells.length; i += 7 ) {
		weeks.push( cells.slice( i, i + 7 ) );
	}
	return weeks;
}

/**
 * Weekday numbers in display order.
 *
 * @param {number} firstDayOfWeek 0 = Sunday … 6 = Saturday.
 * @return {number[]} Weekdays.
 */
export function weekdayOrder( firstDayOfWeek ) {
	return Array.from(
		{ length: 7 },
		( _, index ) => ( firstDayOfWeek + index ) % 7
	);
}

/**
 * Date range of a month to ask the availability endpoint for: the bookable part of the month
 * (not before `today`, not after `lastDate`). At most 31 days, as required by the API.
 *
 * @param {{year:number, month:number}} value    Month.
 * @param {string}                      today    First bookable date "Y-m-d".
 * @param {string|null}                 lastDate Last bookable date "Y-m-d" or null (no limit).
 * @return {{from:string, to:string}|null} Range, or null when no day of the month is bookable.
 */
export function monthRange( { year, month }, today, lastDate ) {
	let from = formatDate( year, month, 1 );
	let to = formatDate( year, month, daysInMonth( year, month ) );
	if ( from < today ) {
		from = today;
	}
	if ( lastDate && to > lastDate ) {
		to = lastDate;
	}
	return from <= to ? { from, to } : null;
}

/**
 * Whether a date lies within [min, max] (max may be null = no limit).
 *
 * @param {string}      date Date "Y-m-d".
 * @param {string}      min  First date.
 * @param {string|null} max  Last date.
 * @return {boolean} In range.
 */
export function inRange( date, min, max ) {
	return date >= min && ( ! max || date <= max );
}

/**
 * Target of a calendar keyboard command (arrow keys, Home/End, PageUp/PageDown), clamped to [min, max].
 *
 * @param {string}      date           Focused date "Y-m-d".
 * @param {string}      key            KeyboardEvent.key.
 * @param {number}      firstDayOfWeek 0 = Sunday … 6 = Saturday.
 * @param {string}      min            First reachable date.
 * @param {string|null} max            Last reachable date.
 * @return {string|null} New date, or null when the key is not a calendar command.
 */
export function moveFocus( date, key, firstDayOfWeek, min, max ) {
	let target;
	switch ( key ) {
		case 'ArrowLeft':
			target = addDays( date, -1 );
			break;
		case 'ArrowRight':
			target = addDays( date, 1 );
			break;
		case 'ArrowUp':
			target = addDays( date, -7 );
			break;
		case 'ArrowDown':
			target = addDays( date, 7 );
			break;
		case 'Home':
			target = addDays(
				date,
				-( ( weekday( date ) - firstDayOfWeek + 7 ) % 7 )
			);
			break;
		case 'End':
			target = addDays(
				date,
				6 - ( ( weekday( date ) - firstDayOfWeek + 7 ) % 7 )
			);
			break;
		case 'PageUp':
			target = addMonthsToDate( date, -1 );
			break;
		case 'PageDown':
			target = addMonthsToDate( date, 1 );
			break;
		default:
			return null;
	}
	if ( target < min ) {
		return min;
	}
	if ( max && target > max ) {
		return max;
	}
	return target;
}
