/**
 * Front end of the booking block: mounts the booking application in every block container.
 */
import domReady from '@wordpress/dom-ready';

domReady( () => {
	document
		.querySelectorAll( '.wp-block-terminarz-booking[data-trmz-config]' )
		.forEach( ( container ) => {
			container.classList.add( 'is-ready' );
		} );
} );
