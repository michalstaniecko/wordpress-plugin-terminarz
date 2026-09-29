/**
 * Block "Booking" (terminarz/booking) — editor registration.
 *
 * The block is dynamic: the markup is rendered by PHP (Terminarz\Blocks\BookingBlock) and the booking
 * application is mounted by view.js on the front end, so save() returns null.
 */
import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import './editor.scss';
import './style.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
