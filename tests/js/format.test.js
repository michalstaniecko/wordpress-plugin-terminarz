/**
 * Unit tests of locale-aware labels and the block configuration parser.
 */
import { parseConfig } from '../../blocks/booking/lib/config';
import {
	formatLongDate,
	formatMonth,
	formatPrice,
	timezoneLabel,
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
	} );
} );
