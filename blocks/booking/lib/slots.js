/**
 * Helpers for availability responses (`GET /terminarz/v1/availability`).
 *
 * Slot times come as ISO 8601 strings with the site offset ("2026-10-01T09:00:00+02:00"), so the wall-clock time
 * of the site is read directly from the string — the visitor's browser time zone never changes displayed times.
 */

/**
 * Wall-clock time "HH:MM" of an ISO 8601 date-time with offset.
 *
 * @param {string} iso Date-time.
 * @return {string} Time.
 */
export function timeOf( iso ) {
	const match = /T(\d{2}):(\d{2})/.exec( iso );
	return match ? `${ match[ 1 ] }:${ match[ 2 ] }` : '';
}

/**
 * Local date "Y-m-d" of an ISO 8601 date-time with offset.
 *
 * @param {string} iso Date-time.
 * @return {string} Date.
 */
export function dateOf( iso ) {
	return iso.slice( 0, 10 );
}

/**
 * Slots per day of an availability response.
 *
 * @param {{days?: Array<{date:string, slots:Array<Object>}>}|null} availability Response.
 * @return {Object<string, Array<Object>>} Slots keyed by date "Y-m-d".
 */
export function slotsByDate( availability ) {
	const result = {};
	( availability?.days ?? [] ).forEach( ( day ) => {
		result[ day.date ] = day.slots ?? [];
	} );
	return result;
}

/**
 * Dates that have at least one free slot.
 *
 * @param {{days?: Array<{date:string, slots:Array<Object>}>}|null} availability Response.
 * @return {Set<string>} Dates "Y-m-d".
 */
export function availableDates( availability ) {
	return new Set(
		( availability?.days ?? [] )
			.filter( ( day ) => ( day.slots ?? [] ).length > 0 )
			.map( ( day ) => day.date )
	);
}

/**
 * Part of the day of a slot start: morning (before 12:00), afternoon (12:00–16:59) or evening (17:00 and later).
 *
 * @param {string} iso Start date-time.
 * @return {'morning'|'afternoon'|'evening'} Period.
 */
export function periodOf( iso ) {
	const hour = Number( timeOf( iso ).slice( 0, 2 ) );
	if ( hour < 12 ) {
		return 'morning';
	}
	return hour < 17 ? 'afternoon' : 'evening';
}

/**
 * Slots grouped by part of the day, in chronological order; empty groups are omitted.
 *
 * @param {Array<{start:string}>} slots Slots of one day, sorted by start.
 * @return {Array<{period:string, slots:Array<Object>}>} Groups.
 */
export function groupSlotsByPeriod( slots ) {
	const groups = [];
	slots.forEach( ( slot ) => {
		const period = periodOf( slot.start );
		const last = groups[ groups.length - 1 ];
		if ( last && last.period === period ) {
			last.slots.push( slot );
		} else {
			groups.push( { period, slots: [ slot ] } );
		}
	} );
	return groups;
}

/**
 * Finds the slot with the given UTC start in a list.
 *
 * @param {Array<{start_utc:string}>} slots    Slots.
 * @param {string}                    startUtc Start in UTC.
 * @return {Object|null} Slot.
 */
export function findSlot( slots, startUtc ) {
	return (
		( slots ?? [] ).find( ( slot ) => slot.start_utc === startUtc ) ?? null
	);
}
