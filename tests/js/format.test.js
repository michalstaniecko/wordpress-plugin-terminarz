/**
 * Unit tests of locale-aware labels and the block configuration parser.
 */
import { parseConfig } from '../../blocks/booking/lib/config';
import {
	formatLongDate,
	formatMonth,
	formatPrice,
	formatTime,
	timezoneLabel,
	uses12HourClock,
	weekdayName,
} from '../../blocks/booking/lib/format';

describe( 'labels', () => {
	it( 'formats dates without shifting the day', () => {
		expect( formatLongDate( '2026-10-01', 'en-US' ) ).toBe(
			'Thursday, October 1, 2026'
		);
		expect( formatMonth( { year: 2026, month: 10 }, 'en-US' ) ).toBe(
			'October 2026'
		);
		expect( weekdayName( 1, 'en-US' ) ).toEqual( {
			long: 'Monday',
			short: 'Mon',
		} );
		expect( weekdayName( 0, 'en-US' ).long ).toBe( 'Sunday' );
	} );

	it( 'falls back for an invalid locale', () => {
		expect( formatLongDate( '2026-10-01', 'not a locale!' ) ).toContain(
			'2026'
		);
	} );

	it( 'formats prices in minor units', () => {
		expect( formatPrice( 15000, 2, 'PLN', 'en-US' ) ).toBe( '150.00 PLN' );
		expect( formatPrice( 15050, 2, '', 'en-US' ) ).toBe( '150.50' );
		expect( formatPrice( 1500, 0, 'JPY', 'en-US' ) ).toBe( '1,500 JPY' );
	} );

	it( 'labels fixed-offset time zones', () => {
		expect( timezoneLabel( '+02:00' ) ).toBe( 'UTC+02:00' );
		expect( timezoneLabel( 'Europe/Warsaw' ) ).toBe( 'Europe/Warsaw' );
		expect( timezoneLabel( '' ) ).toBe( '' );
	} );
} );

describe( 'parseConfig', () => {
	it( 'returns null for missing or invalid JSON', () => {
		expect( parseConfig( null ) ).toBeNull();
		expect( parseConfig( '{' ) ).toBeNull();
		expect( parseConfig( '{"nonce":"x"}' ) ).toBeNull();
	} );

	it( 'normalises values', () => {
		const config = parseConfig(
			JSON.stringify( {
				restRoot: 'http://example.org/wp-json/',
				serviceIds: [ 3, '4', -1, 'x' ],
				defaultServiceId: '4',
				showResourcePicker: false,
				firstDayOfWeek: 9,
				today: '2026-09-29',
				lastDate: '2026-09-01',
				locale: 'pl-PL',
				priceDecimals: 2,
			} )
		);
		expect( config.serviceIds ).toEqual( [ 3, 4 ] );
		expect( config.defaultServiceId ).toBe( 4 );
		expect( config.showResourcePicker ).toBe( false );
		expect( config.firstDayOfWeek ).toBe( 1 );
		expect( config.today ).toBe( '2026-09-29' );
		expect( config.lastDate ).toBeNull();
		expect( config.nonce ).toBe( '' );
		expect( config.consentHtml ).toBe( '' );
		expect( config.emailNotice ).toBe( false );
		expect( config.hour12 ).toBe( false );
	} );

	it( 'derives the clock from the site time format', () => {
		const hour12 = ( timeFormat ) =>
			parseConfig(
				JSON.stringify( {
					restRoot: 'http://example.org/wp-json/',
					timeFormat,
				} )
			).hour12;
		expect( hour12( 'g:i a' ) ).toBe( true );
		expect( hour12( 'H:i' ) ).toBe( false );
		expect( hour12( 42 ) ).toBe( false );
	} );

	it( 'passes the e-mail notice flag only when it is true', () => {
		const parse = ( emailNotice ) =>
			parseConfig(
				JSON.stringify( {
					restRoot: 'http://example.org/wp-json/',
					emailNotice,
				} )
			).emailNotice;
		expect( parse( true ) ).toBe( true );
		expect( parse( 'yes' ) ).toBe( false );
	} );
} );

describe( 'time format', () => {
	// ICU may put a narrow no-break space before AM/PM.
	const plain = ( text ) => text.replace( /\s/g, ' ' );

	it( 'detects a 12-hour clock from the PHP time format', () => {
		expect( uses12HourClock( 'g:i a' ) ).toBe( true );
		expect( uses12HourClock( 'h:i A' ) ).toBe( true );
		expect( uses12HourClock( 'g:i' ) ).toBe( true );
		expect( uses12HourClock( 'H:i' ) ).toBe( false );
		expect( uses12HourClock( 'G:i' ) ).toBe( false );
		expect( uses12HourClock( 'H\\h i' ) ).toBe( false );
		expect( uses12HourClock( '' ) ).toBe( false );
		expect( uses12HourClock( undefined ) ).toBe( false );
	} );

	it( 'keeps 24-hour times unchanged by default', () => {
		expect( formatTime( '2026-10-01T09:05:00+02:00', 'en-US' ) ).toBe(
			'09:05'
		);
		expect(
			formatTime( '2026-10-01T00:00:00+02:00', 'en-US', false )
		).toBe( '00:00' );
		expect(
			formatTime( '2026-10-01T12:00:00+02:00', 'en-US', false )
		).toBe( '12:00' );
	} );

	it( 'formats a 12-hour clock including noon and midnight', () => {
		const time = ( iso ) => plain( formatTime( iso, 'en-US', true ) );
		expect( time( '2026-10-01T09:05:00+02:00' ) ).toBe( '9:05 AM' );
		expect( time( '2026-10-01T12:00:00+02:00' ) ).toBe( '12:00 PM' );
		expect( time( '2026-10-01T00:00:00+02:00' ) ).toBe( '12:00 AM' );
		expect( time( '2026-10-01T23:30:00-05:00' ) ).toBe( '11:30 PM' );
	} );

	it( 'never shifts the time to the browser time zone', () => {
		expect(
			plain( formatTime( '2026-10-25T02:30:00+01:00', 'en-US', true ) )
		).toBe( '2:30 AM' );
	} );

	it( 'handles invalid values and locales', () => {
		expect( formatTime( 'garbage', 'en-US', true ) ).toBe( '' );
		expect(
			plain(
				formatTime( '2026-10-01T13:00:00+02:00', 'not a locale!', true )
			)
		).toMatch( /1:00/ );
	} );
} );
