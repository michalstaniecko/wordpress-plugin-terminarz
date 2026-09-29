/**
 * Pure helpers of the editor UI (unit tested).
 */
import { __ } from '@wordpress/i18n';

/**
 * Services offered by the block: all of them when the selection is empty, otherwise the selected ones
 * (in catalogue order).
 *
 * @param {Array<{id:number}>} services   Service catalogue.
 * @param {number[]}           serviceIds Selected IDs (empty = all).
 * @return {Array<Object>} Offered services.
 */
export function allowedServices( services, serviceIds ) {
	if ( ! Array.isArray( serviceIds ) || serviceIds.length === 0 ) {
		return services;
	}
	return services.filter( ( service ) => serviceIds.includes( service.id ) );
}

/**
 * Options of the "first day of the week" setting; -1 = the site setting (Settings → General).
 *
 * @return {Array<{value:string,label:string}>} Options.
 */
export function weekdayOptions() {
	return [
		{ value: '-1', label: __( 'Site default', 'terminarz' ) },
		{ value: '1', label: __( 'Monday', 'terminarz' ) },
		{ value: '0', label: __( 'Sunday', 'terminarz' ) },
		{ value: '6', label: __( 'Saturday', 'terminarz' ) },
	];
}
