/**
 * Front end of the booking block: mounts the booking application in every block container rendered by
 * Terminarz\Blocks\BookingBlock (configuration in `data-trmz-config`).
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

import { configureApi } from './app/api';
import BookingApp from './app/BookingApp';
import { parseConfig } from './lib/config';

domReady( () => {
	document
		.querySelectorAll( '.wp-block-terminarz-booking[data-trmz-config]' )
		.forEach( ( container ) => {
			const config = parseConfig(
				container.getAttribute( 'data-trmz-config' )
			);
			if ( ! config ) {
				return;
			}
			configureApi( config );
			createRoot( container ).render( <BookingApp config={ config } /> );
		} );
} );
