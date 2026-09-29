/**
 * Unit tests of availability helpers.
 */
import {
	availableDates,
	dateOf,
	findSlot,
	groupSlotsByPeriod,
	periodOf,
	slotsByDate,
	timeOf,
} from '../../blocks/booking/lib/slots';

const slot = ( start, startUtc ) => ( {
	start,
	end: start,
	start_utc: startUtc,
	resource: null,
} );

const availability = {
	timezone: 'Europe/Warsaw',
	days: [
		{
			date: '2026-10-24',
			slots: [
				slot( '2026-10-24T09:00:00+02:00', '2026-10-24T07:00:00Z' ),
				slot( '2026-10-24T11:45:00+02:00', '2026-10-24T09:45:00Z' ),
				slot( '2026-10-24T12:00:00+02:00', '2026-10-24T10:00:00Z' ),
				slot( '2026-10-24T17:00:00+02:00', '2026-10-24T15:00:00Z' ),
			],
		},
		{ date: '2026-10-25', slots: [] },
		{
			// DST ends: the offset changes, local times are still read from the string.
			date: '2026-10-26',
			slots: [
				slot( '2026-10-26T09:00:00+01:00', '2026-10-26T08:00:00Z' ),
			],
		},
	],
};

describe( 'time and date of a slot', () => {
	it( 'reads the site wall-clock time from the ISO string', () => {
		expect( timeOf( '2026-10-26T09:00:00+01:00' ) ).toBe( '09:00' );
		expect( timeOf( '2026-10-24T23:30:00+02:00' ) ).toBe( '23:30' );
		expect( timeOf( 'garbage' ) ).toBe( '' );
		expect( dateOf( '2026-10-24T23:30:00+02:00' ) ).toBe( '2026-10-24' );
	} );
} );

describe( 'slotsByDate / availableDates', () => {
	it( 'indexes slots by local date', () => {
		const byDate = slotsByDate( availability );
		expect( Object.keys( byDate ) ).toEqual( [
			'2026-10-24',
			'2026-10-25',
			'2026-10-26',
		] );
		expect( byDate[ '2026-10-25' ] ).toEqual( [] );
		expect( byDate[ '2026-10-26' ] ).toHaveLength( 1 );
	} );

	it( 'lists only days with free slots', () => {
		expect( [ ...availableDates( availability ) ] ).toEqual( [
			'2026-10-24',
			'2026-10-26',
		] );
		expect( availableDates( null ).size ).toBe( 0 );
		expect( slotsByDate( undefined ) ).toEqual( {} );
	} );
} );

describe( 'groupSlotsByPeriod', () => {
	it( 'groups slots into morning, afternoon and evening', () => {
		const groups = groupSlotsByPeriod( availability.days[ 0 ].slots );
		expect( groups.map( ( group ) => group.period ) ).toEqual( [
			'morning',
			'afternoon',
			'evening',
		] );
		expect( groups[ 0 ].slots ).toHaveLength( 2 );
		expect( groups[ 1 ].slots ).toHaveLength( 1 );
		expect( groups[ 2 ].slots ).toHaveLength( 1 );
	} );

	it( 'omits empty groups', () => {
		expect( groupSlotsByPeriod( [] ) ).toEqual( [] );
		expect(
			groupSlotsByPeriod( availability.days[ 2 ].slots ).map(
				( group ) => group.period
			)
		).toEqual( [ 'morning' ] );
	} );

	it( 'uses 12:00 and 17:00 as boundaries', () => {
		expect( periodOf( '2026-10-24T11:59:00+02:00' ) ).toBe( 'morning' );
		expect( periodOf( '2026-10-24T12:00:00+02:00' ) ).toBe( 'afternoon' );
		expect( periodOf( '2026-10-24T16:59:00+02:00' ) ).toBe( 'afternoon' );
		expect( periodOf( '2026-10-24T17:00:00+02:00' ) ).toBe( 'evening' );
	} );
} );

describe( 'findSlot', () => {
	it( 'finds a slot by its UTC start', () => {
		const slots = availability.days[ 0 ].slots;
		expect( findSlot( slots, '2026-10-24T10:00:00Z' ) ).toBe( slots[ 2 ] );
		expect( findSlot( slots, '2026-10-24T10:15:00Z' ) ).toBeNull();
		expect( findSlot( null, 'x' ) ).toBeNull();
	} );
} );
