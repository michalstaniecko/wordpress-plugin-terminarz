/**
 * Unit tests of reading the block configuration from the server-rendered markup.
 */
import { readConfig } from '../../blocks/booking/lib/config';

/**
 * Builds a container from HTML.
 *
 * @param {string} html Inner HTML of the document body.
 * @return {Element} First element.
 */
function container( html ) {
	document.body.innerHTML = html;
	return document.body.firstElementChild;
}

const json = JSON.stringify( {
	restRoot: 'https://example.org/wp-json/',
	consentHtml: 'I agree',
} );

describe( 'readConfig', () => {
	it( 'reads the configuration from the JSON script element', () => {
		const config = readConfig(
			container(
				`<div class="wp-block-terminarz-booking"><script type="application/json" class="trmz-booking__config">${ json }</script></div>`
			)
		);
		expect( config.restRoot ).toBe( 'https://example.org/wp-json/' );
		expect( config.consentHtml ).toBe( 'I agree' );
	} );

	it( 'ignores configuration in data attributes (content authors can forge them)', () => {
		const forged = container(
			`<div class="wp-block-terminarz-booking" data-trmz-config='{"restRoot":"https://evil.example/","consentHtml":"<img src=x onerror=alert(1)>"}'></div>`
		);
		expect( readConfig( forged ) ).toBeNull();
	} );

	it( 'ignores elements that are not script elements and nested scripts', () => {
		expect(
			readConfig(
				container(
					`<div class="wp-block-terminarz-booking"><div class="trmz-booking__config">${ json }</div></div>`
				)
			)
		).toBeNull();
		expect(
			readConfig(
				container(
					`<div class="wp-block-terminarz-booking"><p><script type="application/json" class="trmz-booking__config">${ json }</script></p></div>`
				)
			)
		).toBeNull();
		expect(
			readConfig(
				container(
					`<div class="wp-block-terminarz-booking"><script type="text/template" class="trmz-booking__config">${ json }</script></div>`
				)
			)
		).toBeNull();
	} );

	it( 'returns null for invalid JSON', () => {
		expect(
			readConfig(
				container(
					'<div><script type="application/json" class="trmz-booking__config">{nope</script></div>'
				)
			)
		).toBeNull();
	} );
} );
