/**
 * Unit tests of calendar arithmetic (local dates as "Y-m-d").
 */
import {
	addDays,
	addMonths,
	addMonthsToDate,
	compareMonths,
	daysInMonth,
	inRange,
	monthGrid,
	monthRange,
	moveFocus,
	weekday,
	weekdayOrder,
} from '../../blocks/booking/lib/calendar';

describe( 'date arithmetic', () => {
	it( 'adds days across months, years and DST changes', () => {
		expect( addDays( '2026-09-30', 1 ) ).toBe( '2026-10-01' );
		expect( addDays( '2026-12-31', 1 ) ).toBe( '2027-01-01' );
		expect( addDays( '2026-03-01', -1 ) ).toBe( '2026-02-28' );
		// Europe: DST ends on 25 October 2026, starts on 29 March 2026.
		expect( addDays( '2026-10-24', 2 ) ).toBe( '2026-10-26' );
		expect( addDays( '2026-03-28', 2 ) ).toBe( '2026-03-30' );
	} );

	it( 'computes weekdays and month lengths', () => {
		expect( weekday( '2026-10-01' ) ).toBe( 4 ); // Thursday.
		expect( weekday( '2026-10-04' ) ).toBe( 0 ); // Sunday.
		expect( daysInMonth( 2028, 2 ) ).toBe( 29 );
		expect( daysInMonth( 2026, 2 ) ).toBe( 28 );
		expect( daysInMonth( 2026, 12 ) ).toBe( 31 );
	} );

	it( 'shifts months', () => {
		expect( addMonths( { year: 2026, month: 12 }, 1 ) ).toEqual( {
			year: 2027,
			month: 1,
		} );
		expect( addMonths( { year: 2026, month: 1 }, -1 ) ).toEqual( {
			year: 2025,
			month: 12,
		} );
		expect(
			compareMonths( { year: 2026, month: 1 }, { year: 2025, month: 12 } )
		).toBeGreaterThan( 0 );
		expect( addMonthsToDate( '2026-01-31', 1 ) ).toBe( '2026-02-28' );
		expect( addMonthsToDate( '2026-03-31', -1 ) ).toBe( '2026-02-28' );
	} );

	it( 'checks ranges with an optional upper bound', () => {
		expect( inRange( '2026-10-05', '2026-10-01', null ) ).toBe( true );
		expect( inRange( '2026-10-05', '2026-10-01', '2026-10-04' ) ).toBe(
			false
		);
		expect( inRange( '2026-09-30', '2026-10-01', null ) ).toBe( false );
	} );
} );

describe( 'monthGrid', () => {
	it( 'builds weeks starting on Monday', () => {
		const weeks = monthGrid( 2026, 10, 1 );
		// 1 October 2026 is a Thursday: three empty cells before it.
		expect( weeks[ 0 ] ).toEqual( [
			null,
			null,
			null,
			'2026-10-01',
			'2026-10-02',
			'2026-10-03',
			'2026-10-04',
		] );
		expect( weeks ).toHaveLength( 5 );
		expect( weeks.every( ( week ) => week.length === 7 ) ).toBe( true );
		expect( weeks[ 4 ].slice( -2 ) ).toEqual( [ '2026-10-31', null ] );
		expect( weeks.flat().filter( Boolean ) ).toHaveLength( 31 );
	} );

	it( 'builds weeks starting on Sunday', () => {
		const weeks = monthGrid( 2026, 10, 0 );
		expect( weeks[ 0 ].indexOf( '2026-10-01' ) ).toBe( 4 );
		expect( weeks[ 0 ][ 0 ] ).toBeNull();
	} );

	it( 'handles a month starting on the first day of the week', () => {
		// 1 February 2026 is a Sunday.
		expect( monthGrid( 2026, 2, 0 )[ 0 ][ 0 ] ).toBe( '2026-02-01' );
		expect( monthGrid( 2026, 2, 0 ) ).toHaveLength( 4 );
	} );

	it( 'orders weekdays from the first day of the week', () => {
		expect( weekdayOrder( 1 ) ).toEqual( [ 1, 2, 3, 4, 5, 6, 0 ] );
		expect( weekdayOrder( 6 ) ).toEqual( [ 6, 0, 1, 2, 3, 4, 5 ] );
	} );
} );

describe( 'monthRange', () => {
	it( 'starts at today in the current month', () => {
		expect(
			monthRange( { year: 2026, month: 9 }, '2026-09-29', null )
		).toEqual( { from: '2026-09-29', to: '2026-09-30' } );
	} );

	it( 'covers a whole future month (at most 31 days)', () => {
		expect(
			monthRange( { year: 2026, month: 10 }, '2026-09-29', null )
		).toEqual( { from: '2026-10-01', to: '2026-10-31' } );
	} );

	it( 'stops at the last bookable date', () => {
		expect(
			monthRange( { year: 2026, month: 10 }, '2026-09-29', '2026-10-12' )
		).toEqual( { from: '2026-10-01', to: '2026-10-12' } );
	} );

	it( 'returns null for months outside the bookable period', () => {
		expect(
			monthRange( { year: 2026, month: 8 }, '2026-09-29', null )
		).toBeNull();
		expect(
			monthRange( { year: 2026, month: 11 }, '2026-09-29', '2026-10-12' )
		).toBeNull();
	} );
} );

describe( 'moveFocus', () => {
	const min = '2026-09-01';

	it( 'moves by day and week with arrow keys', () => {
		expect( moveFocus( '2026-10-01', 'ArrowRight', 1, min, null ) ).toBe(
			'2026-10-02'
		);
		expect( moveFocus( '2026-10-01', 'ArrowLeft', 1, min, null ) ).toBe(
			'2026-09-30'
		);
		expect( moveFocus( '2026-10-01', 'ArrowDown', 1, min, null ) ).toBe(
			'2026-10-08'
		);
		expect( moveFocus( '2026-10-08', 'ArrowUp', 1, min, null ) ).toBe(
			'2026-10-01'
		);
	} );

	it( 'moves to the start and end of the week', () => {
		// Thursday 1 October; week Monday 28 September – Sunday 4 October.
		expect( moveFocus( '2026-10-01', 'Home', 1, min, null ) ).toBe(
			'2026-09-28'
		);
		expect( moveFocus( '2026-10-01', 'End', 1, min, null ) ).toBe(
			'2026-10-04'
		);
		// Week starting on Sunday: 27 September – 3 October.
		expect( moveFocus( '2026-10-01', 'Home', 0, min, null ) ).toBe(
			'2026-09-27'
		);
		expect( moveFocus( '2026-10-01', 'End', 0, min, null ) ).toBe(
			'2026-10-03'
		);
	} );

	it( 'moves by month with PageUp/PageDown', () => {
		expect( moveFocus( '2026-10-31', 'PageDown', 1, min, null ) ).toBe(
			'2026-11-30'
		);
		expect( moveFocus( '2026-10-15', 'PageUp', 1, min, null ) ).toBe(
			'2026-09-15'
		);
	} );

	it( 'clamps to the reachable range', () => {
		expect( moveFocus( '2026-09-01', 'ArrowLeft', 1, min, null ) ).toBe(
			min
		);
		expect( moveFocus( '2026-09-15', 'PageUp', 1, min, null ) ).toBe( min );
		expect(
			moveFocus( '2026-10-10', 'ArrowDown', 1, min, '2026-10-12' )
		).toBe( '2026-10-12' );
	} );

	it( 'ignores other keys', () => {
		expect( moveFocus( '2026-10-01', 'Enter', 1, min, null ) ).toBeNull();
		expect( moveFocus( '2026-10-01', 'a', 1, min, null ) ).toBeNull();
	} );
} );
