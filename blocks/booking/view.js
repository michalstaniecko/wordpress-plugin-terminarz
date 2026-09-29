/**
 * Front end of the booking block: mounts the booking application in every block container rendered by
 * Terminarz\Blocks\BookingBlock (configuration in a child `<script type="application/json">`, see readConfig()).
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

import { configureApi } from './app/api';
import BookingApp from './app/BookingApp';
import { readConfig } from './lib/config';

domReady( () => {
	document
		.querySelectorAll( '.wp-block-terminarz-booking' )
		.forEach( ( container ) => {
			const config = readConfig( container );
			if ( ! config ) {
				return;
			}
			configureApi( config );
			createRoot( container ).render( <BookingApp config={ config } /> );
		} );
} );
