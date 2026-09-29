/**
 * Editor UI of the booking block: settings in the sidebar and a static preview in the canvas.
 */
import apiFetch from '@wordpress/api-fetch';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	CheckboxControl,
	Notice,
	PanelBody,
	SelectControl,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { allowedServices, weekdayOptions } from './lib/editor';

/**
 * Loads the public service catalogue (`GET /terminarz/v1/services`).
 *
 * @return {{services: Array<Object>, loading: boolean, error: boolean}} State.
 */
function useServices() {
	const [ state, setState ] = useState( {
		services: [],
		loading: true,
		error: false,
	} );

	useEffect( () => {
		let active = true;
		apiFetch( { path: '/terminarz/v1/services' } )
			.then( ( services ) => {
				if ( active ) {
					setState( { services, loading: false, error: false } );
				}
			} )
			.catch( () => {
				if ( active ) {
					setState( { services: [], loading: false, error: true } );
				}
			} );
		return () => {
			active = false;
		};
	}, [] );

	return state;
}

export default function Edit( { attributes, setAttributes } ) {
	const { serviceIds, defaultServiceId, showResourcePicker, firstDayOfWeek } =
		attributes;
	const { services, loading, error } = useServices();
	const visible = allowedServices( services, serviceIds );

	const toggleService = ( id, checked ) => {
		const next = checked
			? [ ...serviceIds, id ]
			: serviceIds.filter( ( value ) => value !== id );
		const update = { serviceIds: next };
		// The default service must stay among the offered ones.
		if (
			defaultServiceId &&
			next.length > 0 &&
			! next.includes( defaultServiceId )
		) {
			update.defaultServiceId = 0;
		}
		setAttributes( update );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Services', 'terminarz' ) }>
					{ loading && <Spinner /> }
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ __( 'Could not load services.', 'terminarz' ) }
						</Notice>
					) }
					{ ! loading && ! error && services.length === 0 && (
						<p>
							{ __(
								'There are no active services yet. Add them under Terminarz → Services.',
								'terminarz'
							) }
						</p>
					) }
					{ services.length > 0 && (
						<>
							<p>
								{ __(
									'Offer only the selected services (none selected = all services).',
									'terminarz'
								) }
							</p>
							{ services.map( ( service ) => (
								<CheckboxControl
									key={ service.id }
									__nextHasNoMarginBottom
									label={ service.name }
									checked={ serviceIds.includes(
										service.id
									) }
									onChange={ ( checked ) =>
										toggleService( service.id, checked )
									}
								/>
							) ) }
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Preselected service',
									'terminarz'
								) }
								value={ String( defaultServiceId ) }
								options={ [
									{
										value: '0',
										label: __( 'None', 'terminarz' ),
									},
									...visible.map( ( service ) => ( {
										value: String( service.id ),
										label: service.name,
									} ) ),
								] }
								onChange={ ( value ) =>
									setAttributes( {
										defaultServiceId: Number( value ),
									} )
								}
							/>
						</>
					) }
				</PanelBody>
				<PanelBody title={ __( 'Booking steps', 'terminarz' ) }>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Let customers choose the person or resource',
							'terminarz'
						) }
						help={ __(
							'When off, the first available person or resource is assigned.',
							'terminarz'
						) }
						checked={ showResourcePicker }
						onChange={ ( value ) =>
							setAttributes( { showResourcePicker: value } )
						}
					/>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'First day of the week', 'terminarz' ) }
						value={ String( firstDayOfWeek ) }
						options={ weekdayOptions() }
						onChange={ ( value ) =>
							setAttributes( { firstDayOfWeek: Number( value ) } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<div className="trmz-booking trmz-booking--preview">
					<p className="trmz-booking__preview-title">
						{ __( 'Book an appointment', 'terminarz' ) }
					</p>
					<ol className="trmz-booking__preview-steps">
						<li>{ __( 'Service', 'terminarz' ) }</li>
						{ showResourcePicker && (
							<li>{ __( 'Person or resource', 'terminarz' ) }</li>
						) }
						<li>{ __( 'Day', 'terminarz' ) }</li>
						<li>{ __( 'Time', 'terminarz' ) }</li>
						<li>{ __( 'Your details', 'terminarz' ) }</li>
					</ol>
					{ visible.length > 0 && (
						<p className="trmz-booking__preview-services">
							{ sprintf(
								/* translators: %s: comma-separated list of service names. */
								__( 'Services: %s', 'terminarz' ),
								visible
									.map( ( service ) => service.name )
									.join( ', ' )
							) }
						</p>
					) }
				</div>
			</div>
		</>
	);
}
