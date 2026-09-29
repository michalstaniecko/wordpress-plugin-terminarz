/**
 * Unit tests of the editor helpers of the booking block.
 */
import {
	allowedServices,
	weekdayOptions,
} from '../../blocks/booking/lib/editor';

const services = [
	{ id: 1, name: 'A' },
	{ id: 2, name: 'B' },
	{ id: 3, name: 'C' },
];

describe( 'allowedServices', () => {
	it( 'returns every service when nothing is selected', () => {
		expect( allowedServices( services, [] ) ).toEqual( services );
		expect( allowedServices( services, undefined ) ).toEqual( services );
	} );

	it( 'keeps catalogue order of the selected services', () => {
		expect(
			allowedServices( services, [ 3, 1 ] ).map( ( s ) => s.id )
		).toEqual( [ 1, 3 ] );
	} );

	it( 'ignores selected services that no longer exist', () => {
		expect( allowedServices( services, [ 9 ] ) ).toEqual( [] );
	} );
} );

describe( 'weekdayOptions', () => {
	it( 'starts with the site default', () => {
		const options = weekdayOptions();
		expect( options[ 0 ].value ).toBe( '-1' );
		expect( options.map( ( o ) => o.value ) ).toEqual(
			expect.arrayContaining( [ '0', '1' ] )
		);
	} );
} );
