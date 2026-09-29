/**
 * Booking application: service → person/resource (or "any") → day → time → details.
 *
 * All state lives here, so going back and forth between steps keeps every choice. Times are shown in the site
 * time zone (the availability endpoint returns local times with the site offset and the zone name).
 */
import {
	Fragment,
	useCallback,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { get } from './api';
import Calendar from './Calendar';
import ChoiceList from './ChoiceList';
import Confirmation from './Confirmation';
import DetailsStep from './DetailsStep';
import Step from './Step';
import { monthOf, monthRange, addMonths, compareMonths } from '../lib/calendar';
import {
	formatLongDate,
	formatMonth,
	formatPrice,
	timezoneLabel,
} from '../lib/format';
import { EMPTY_FORM } from '../lib/form';
import {
	availableDates,
	findSlot,
	groupSlotsByPeriod,
	slotsByDate,
	timeOf,
} from '../lib/slots';

export const STEPS = {
	SERVICE: 'service',
	RESOURCE: 'resource',
	DATE: 'date',
	TIME: 'time',
	DETAILS: 'details',
	DONE: 'done',
};

/** How many empty months are skipped automatically when the calendar opens. */
const AUTO_ADVANCE_MONTHS = 2;

let instances = 0;

/**
 * Key of cached availability.
 *
 * @param {number}                      serviceId Service.
 * @param {number|string}               resource  Resource ID or "any".
 * @param {{year:number, month:number}} month     Month.
 * @return {string} Key.
 */
const monthKey = ( serviceId, resource, month ) =>
	`${ serviceId }|${ resource }|${ month.year }-${ month.month }`;

export default function BookingApp( { config } ) {
	const [ idPrefix ] = useState( () => `trmz-booking-${ ++instances }` );
	const [ step, setStep ] = useState( null );
	const [ navigated, setNavigated ] = useState( false );
	const [ announcement, setAnnouncement ] = useState( '' );

	const [ services, setServices ] = useState( null );
	const [ servicesError, setServicesError ] = useState( false );
	const [ resourcesByService, setResourcesByService ] = useState( {} );
	const [ resourcesError, setResourcesError ] = useState( false );
	const [ loadingResources, setLoadingResources ] = useState( false );
	const [ months, setMonths ] = useState( {} );

	const [ serviceId, setServiceId ] = useState( null );
	const [ resource, setResource ] = useState( null );
	const [ month, setMonth ] = useState( monthOf( config.today ) );
	const [ focusDate, setFocusDate ] = useState( null );
	const [ date, setDate ] = useState( null );
	const [ slotStart, setSlotStart ] = useState( null );
	const [ choiceError, setChoiceError ] = useState( '' );
	const [ timeNotice, setTimeNotice ] = useState( '' );
	const [ form, setForm ] = useState( EMPTY_FORM );
	const [ booking, setBooking ] = useState( null );
	const root = useRef();

	const autoAdvance = useRef( AUTO_ADVANCE_MONTHS );

	const service = services?.find( ( item ) => item.id === serviceId ) ?? null;
	const resources = serviceId ? resourcesByService[ serviceId ] : undefined;
	const skipService = services !== null && services.length === 1;
	const skipResource =
		! config.showResourcePicker ||
		( Array.isArray( resources ) && resources.length <= 1 );

	const steps = [
		! skipService && STEPS.SERVICE,
		! skipResource && STEPS.RESOURCE,
		STEPS.DATE,
		STEPS.TIME,
		STEPS.DETAILS,
	].filter( Boolean );

	const goTo = useCallback( ( next ) => {
		setChoiceError( '' );
		setAnnouncement( '' );
		if ( next !== STEPS.TIME ) {
			setTimeNotice( '' );
		}
		setNavigated( true );
		setStep( next );
	}, [] );

	// ---- Services ------------------------------------------------------------------------------------------------

	const loadServices = useCallback( () => {
		setServicesError( false );
		setServices( null );
		setAnnouncement( __( 'Loading services…', 'terminarz' ) );
		get( '/terminarz/v1/services' ).then( ( result ) => {
			if ( ! result.ok || ! Array.isArray( result.data ) ) {
				setServicesError( true );
				setAnnouncement( '' );
				return;
			}
			const offered = config.serviceIds.length
				? result.data.filter( ( item ) =>
						config.serviceIds.includes( item.id )
					)
				: result.data;
			setServices( offered );
			setAnnouncement( '' );
		} );
	}, [ config.serviceIds ] );

	useEffect( () => {
		loadServices();
	}, [ loadServices ] );

	// ---- Resources -----------------------------------------------------------------------------------------------

	const continueWithService = useCallback(
		( id, automatic = false ) => {
			setResourcesError( false );
			// Automatic transitions (a single service) happen on page load: no focus move then.
			const open = automatic ? setStep : goTo;
			const afterResources = ( list ) => {
				if ( ! config.showResourcePicker || list.length === 0 ) {
					setResource( 'any' );
					open( STEPS.DATE );
				} else if ( list.length === 1 ) {
					setResource( list[ 0 ].id );
					open( STEPS.DATE );
				} else {
					open( STEPS.RESOURCE );
				}
			};
			if ( resourcesByService[ id ] ) {
				afterResources( resourcesByService[ id ] );
				return;
			}
			setLoadingResources( true );
			setAnnouncement( __( 'Loading…', 'terminarz' ) );
			get( '/terminarz/v1/resources', { service: id } ).then(
				( result ) => {
					setLoadingResources( false );
					setAnnouncement( '' );
					if ( ! result.ok || ! Array.isArray( result.data ) ) {
						setResourcesError( true );
						return;
					}
					setResourcesByService( ( current ) => ( {
						...current,
						[ id ]: result.data,
					} ) );
					afterResources( result.data );
				}
			);
		},
		[ config.showResourcePicker, goTo, resourcesByService ]
	);

	// First step once services are known.
	const started = useRef( false );
	useEffect( () => {
		if ( services === null || step !== null || started.current ) {
			return;
		}
		started.current = true;
		if ( services.length === 1 ) {
			setServiceId( services[ 0 ].id );
			continueWithService( services[ 0 ].id, true );
			return;
		}
		const preselected = services.find(
			( item ) => item.id === config.defaultServiceId
		);
		if ( preselected ) {
			setServiceId( preselected.id );
		}
		setStep( STEPS.SERVICE );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ services, step ] );

	// ---- Availability --------------------------------------------------------------------------------------------

	const currentKey =
		serviceId && resource !== null
			? monthKey( serviceId, resource, month )
			: null;
	const monthData = currentKey ? months[ currentKey ] : undefined;

	const loadMonth = useCallback(
		( force = false ) => {
			if ( ! currentKey || ( ! force && months[ currentKey ] ) ) {
				return;
			}
			const range = monthRange( month, config.today, config.lastDate );
			if ( ! range ) {
				setMonths( ( current ) => ( {
					...current,
					[ currentKey ]: { status: 'ready', data: null },
				} ) );
				return;
			}
			setMonths( ( current ) => ( {
				...current,
				[ currentKey ]: {
					...( current[ currentKey ] ?? {} ),
					status: 'loading',
				},
			} ) );
			setAnnouncement( __( 'Loading available dates…', 'terminarz' ) );
			get( '/terminarz/v1/availability', {
				service: serviceId,
				resource: String( resource ),
				from: range.from,
				to: range.to,
			} ).then( ( result ) => {
				setMonths( ( current ) => ( {
					...current,
					[ currentKey ]: result.ok
						? { status: 'ready', data: result.data }
						: { status: 'error', data: null },
				} ) );
			} );
		},
		[
			currentKey,
			months,
			month,
			config.today,
			config.lastDate,
			serviceId,
			resource,
		]
	);

	useEffect( () => {
		if ( step === STEPS.DATE || step === STEPS.TIME ) {
			loadMonth();
		}
	}, [ step, loadMonth ] );

	const dates = availableDates( monthData?.data );
	// Remembered after the availability cache is cleared (confirmation screen).
	const knownTimezone = useRef( '' );
	if ( monthData?.data?.timezone ) {
		knownTimezone.current = timezoneLabel( monthData.data.timezone );
	}
	const timezone = knownTimezone.current;

	// Result announcement + skipping empty months when the calendar opens.
	useEffect( () => {
		if (
			step !== STEPS.DATE ||
			! monthData ||
			monthData.status === 'loading'
		) {
			return;
		}
		if ( monthData.status === 'error' ) {
			setAnnouncement( '' );
			return;
		}
		const lastMonth = config.lastDate ? monthOf( config.lastDate ) : null;
		if (
			dates.size === 0 &&
			! date &&
			autoAdvance.current > 0 &&
			( ! lastMonth || compareMonths( month, lastMonth ) < 0 )
		) {
			autoAdvance.current -= 1;
			setMonth( addMonths( month, 1 ) );
			return;
		}
		autoAdvance.current = 0;
		setAnnouncement(
			dates.size
				? sprintf(
						/* translators: 1: month and year, 2: number of days. */
						_n(
							'%1$s: %2$d day with free times.',
							'%1$s: %2$d days with free times.',
							dates.size,
							'terminarz'
						),
						formatMonth( month, config.locale ),
						dates.size
					)
				: sprintf(
						/* translators: %s: month and year. */
						__( '%s: no free times.', 'terminarz' ),
						formatMonth( month, config.locale )
					)
		);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ step, monthData, month ] );

	/**
	 * Clears choices that depend on the service/resource and reopens the calendar at the first bookable month.
	 */
	const resetSchedule = () => {
		setDate( null );
		setSlotStart( null );
		setFocusDate( null );
		setMonth( monthOf( config.today ) );
		autoAdvance.current = AUTO_ADVANCE_MONTHS;
	};

	/**
	 * Drops cached availability of the current service/resource and loads the current month again
	 * (e.g. after the chosen time was taken by someone else).
	 */
	const refreshAvailability = useCallback( () => {
		if ( ! currentKey ) {
			return;
		}
		setMonths( ( current ) => {
			const next = {};
			Object.keys( current ).forEach( ( key ) => {
				if ( ! key.startsWith( `${ serviceId }|${ resource }|` ) ) {
					next[ key ] = current[ key ];
				}
			} );
			return next;
		} );
	}, [ currentKey, serviceId, resource ] );

	// ---- Navigation ----------------------------------------------------------------------------------------------

	const back = () => {
		const index = steps.indexOf( step );
		if ( index > 0 ) {
			goTo( steps[ index - 1 ] );
		}
	};

	const stepProps = ( title ) => ( {
		id: `${ idPrefix }-${ step }`,
		title,
		index: steps.indexOf( step ) + 1,
		total: steps.length,
		focusOnMount: navigated,
	} );

	const errorId = `${ idPrefix }-choice-error`;
	const choiceErrorMessage = choiceError && (
		<p id={ errorId } className="trmz-booking__error" role="alert">
			{ choiceError }
		</p>
	);

	const backButton = (
		<button
			type="button"
			className="trmz-booking__button trmz-booking__button--secondary"
			onClick={ back }
		>
			{ __( 'Back', 'terminarz' ) }
		</button>
	);

	const timezoneNote = timezone && (
		<p className="trmz-booking__timezone">
			{ sprintf(
				/* translators: %s: time zone name, e.g. "Europe/Warsaw". */
				__( 'Times are shown in the %s time zone.', 'terminarz' ),
				timezone
			) }
		</p>
	);

	const slotsOfDate = date
		? ( slotsByDate(
				months[ monthKey( serviceId, resource, monthOf( date ) ) ]?.data
			)[ date ] ?? [] )
		: [];
	const slot = findSlot( slotsOfDate, slotStart );
	const slotId = ( item ) =>
		`${ idPrefix }-slot-${ item.start_utc.replace( /[^0-9A-Za-z]/g, '' ) }`;

	// ---- Rendering -----------------------------------------------------------------------------------------------

	let content = null;

	if ( servicesError ) {
		content = (
			<div className="trmz-booking__message" role="alert">
				<p>
					{ __(
						'The booking form could not be loaded.',
						'terminarz'
					) }
				</p>
				<button
					type="button"
					className="trmz-booking__button"
					onClick={ loadServices }
				>
					{ __( 'Try again', 'terminarz' ) }
				</button>
			</div>
		);
	} else if ( services === null || step === null ) {
		content = (
			<p className="trmz-booking__loading">
				{ __( 'Loading…', 'terminarz' ) }
			</p>
		);
	} else if ( services.length === 0 ) {
		content = (
			<p className="trmz-booking__message">
				{ __(
					'No services are available for booking at the moment.',
					'terminarz'
				) }
			</p>
		);
	} else if ( step === STEPS.SERVICE ) {
		const titleId = `${ idPrefix }-${ step }`;
		content = (
			<Step { ...stepProps( __( 'Choose a service', 'terminarz' ) ) }>
				<ChoiceList
					name={ `${ idPrefix }-service` }
					labelledBy={ titleId }
					value={ serviceId ?? '' }
					error={ choiceError }
					errorId={ errorId }
					options={ services.map( ( item ) => ( {
						value: item.id,
						label: item.name,
						meta: [
							sprintf(
								/* translators: %d: duration in minutes. */
								__( '%d min', 'terminarz' ),
								item.duration_minutes
							),
							item.is_free
								? __( 'Free', 'terminarz' )
								: formatPrice(
										item.price_minor,
										config.priceDecimals,
										config.currency,
										config.locale
									),
						].join( ' · ' ),
					} ) ) }
					onChange={ ( value ) => {
						const id = Number( value );
						if ( id !== serviceId ) {
							setServiceId( id );
							setResource( null );
							resetSchedule();
						}
						setChoiceError( '' );
					} }
				/>
				{ choiceErrorMessage }
				{ resourcesError && (
					<p className="trmz-booking__error" role="alert">
						{ __(
							'Something went wrong. Please try again.',
							'terminarz'
						) }
					</p>
				) }
				<div className="trmz-booking__actions">
					<button
						type="button"
						className="trmz-booking__button"
						aria-disabled={ loadingResources ? true : undefined }
						onClick={ () => {
							if ( loadingResources ) {
								return;
							}
							if ( ! serviceId ) {
								setChoiceError(
									__(
										'Please choose a service.',
										'terminarz'
									)
								);
								return;
							}
							continueWithService( serviceId );
						} }
					>
						{ loadingResources
							? __( 'Loading…', 'terminarz' )
							: __( 'Continue', 'terminarz' ) }
					</button>
				</div>
			</Step>
		);
	} else if ( step === STEPS.RESOURCE ) {
		const titleId = `${ idPrefix }-${ step }`;
		content = (
			<Step
				{ ...stepProps(
					__( 'Choose a person or resource', 'terminarz' )
				) }
			>
				<ChoiceList
					name={ `${ idPrefix }-resource` }
					labelledBy={ titleId }
					value={ resource ?? '' }
					error={ choiceError }
					errorId={ errorId }
					options={ [
						{
							value: 'any',
							label: __( 'Any available', 'terminarz' ),
							meta: __(
								'The first free person or resource is assigned.',
								'terminarz'
							),
						},
						...( resources ?? [] ).map( ( item ) => ( {
							value: item.id,
							label: item.name,
						} ) ),
					] }
					onChange={ ( value ) => {
						const next = value === 'any' ? 'any' : Number( value );
						if ( next !== resource ) {
							setResource( next );
							resetSchedule();
						}
						setChoiceError( '' );
					} }
				/>
				{ choiceErrorMessage }
				<div className="trmz-booking__actions">
					{ steps.indexOf( step ) > 0 && backButton }
					<button
						type="button"
						className="trmz-booking__button"
						onClick={ () => {
							if ( resource === null ) {
								setChoiceError(
									__(
										'Please choose a person or resource.',
										'terminarz'
									)
								);
								return;
							}
							goTo( STEPS.DATE );
						} }
					>
						{ __( 'Continue', 'terminarz' ) }
					</button>
				</div>
			</Step>
		);
	} else if ( step === STEPS.DATE ) {
		const loading = ! monthData || monthData.status === 'loading';
		content = (
			<Step { ...stepProps( __( 'Choose a day', 'terminarz' ) ) }>
				{ service && (
					<p className="trmz-booking__summary">{ service.name }</p>
				) }
				<Calendar
					idPrefix={ idPrefix }
					month={ month }
					firstDayOfWeek={ config.firstDayOfWeek }
					locale={ config.locale }
					today={ config.today }
					lastDate={ config.lastDate }
					available={ dates }
					selectedDate={ date }
					focusDate={ focusDate }
					loading={ loading }
					onFocusDate={ setFocusDate }
					onMonthChange={ ( next ) => {
						autoAdvance.current = 0;
						setMonth( next );
					} }
					onSelect={ ( value ) => {
						if ( value !== date ) {
							setDate( value );
							setSlotStart( null );
						}
						goTo( STEPS.TIME );
					} }
				/>
				{ monthData?.status === 'error' && (
					<div className="trmz-booking__message" role="alert">
						<p>
							{ __(
								'Available dates could not be loaded.',
								'terminarz'
							) }
						</p>
						<button
							type="button"
							className="trmz-booking__button trmz-booking__button--secondary"
							onClick={ () => loadMonth( true ) }
						>
							{ __( 'Try again', 'terminarz' ) }
						</button>
					</div>
				) }
				{ monthData?.status === 'ready' && dates.size === 0 && (
					<p className="trmz-booking__message">
						{ __(
							'There are no free times in this month. Please choose another month.',
							'terminarz'
						) }
					</p>
				) }
				{ timezoneNote }
				{ steps.indexOf( step ) > 0 && (
					<div className="trmz-booking__actions">{ backButton }</div>
				) }
			</Step>
		);
	} else if ( step === STEPS.TIME ) {
		const titleId = `${ idPrefix }-${ step }`;
		const loading = ! monthData || monthData.status === 'loading';
		const periods = {
			morning: __( 'Morning', 'terminarz' ),
			afternoon: __( 'Afternoon', 'terminarz' ),
			evening: __( 'Evening', 'terminarz' ),
		};
		content = (
			<Step { ...stepProps( __( 'Choose a time', 'terminarz' ) ) }>
				<p className="trmz-booking__summary">
					{ formatLongDate( date, config.locale ) }
				</p>
				{ timeNotice && (
					<p className="trmz-booking__error" role="alert">
						{ timeNotice }
					</p>
				) }
				{ loading && (
					<p className="trmz-booking__loading">
						{ __( 'Loading…', 'terminarz' ) }
					</p>
				) }
				{ ! loading && slotsOfDate.length === 0 && (
					<p className="trmz-booking__message">
						{ __(
							'There are no free times on this day any more. Please choose another day.',
							'terminarz'
						) }
					</p>
				) }
				{ ! loading && slotsOfDate.length > 0 && (
					<div
						role="radiogroup"
						aria-labelledby={ titleId }
						aria-describedby={ choiceError ? errorId : undefined }
						className="trmz-slots"
					>
						{ groupSlotsByPeriod( slotsOfDate ).map( ( group ) => (
							<div
								key={ group.period }
								role="group"
								aria-labelledby={ `${ idPrefix }-${ group.period }` }
								className="trmz-slots__group"
							>
								<p
									id={ `${ idPrefix }-${ group.period }` }
									className="trmz-slots__label"
								>
									{ periods[ group.period ] }
								</p>
								<div className="trmz-slots__list">
									{ group.slots.map( ( item ) => (
										// The label text is the slot time rendered below (not detectable statically).
										// eslint-disable-next-line jsx-a11y/label-has-associated-control
										<label
											className="trmz-choice trmz-choice--slot"
											key={ item.start_utc }
											htmlFor={ slotId( item ) }
										>
											<input
												id={ slotId( item ) }
												className="trmz-choice__input"
												type="radio"
												name={ `${ idPrefix }-slot` }
												value={ item.start_utc }
												checked={
													slotStart === item.start_utc
												}
												onChange={ () => {
													setSlotStart(
														item.start_utc
													);
													setChoiceError( '' );
													setTimeNotice( '' );
												} }
											/>
											<span className="trmz-choice__body">
												<span className="trmz-choice__label">
													{ timeOf( item.start ) }
												</span>
											</span>
										</label>
									) ) }
								</div>
							</div>
						) ) }
					</div>
				) }
				{ choiceErrorMessage }
				{ timezoneNote }
				<div className="trmz-booking__actions">
					{ backButton }
					{ slotsOfDate.length > 0 && (
						<button
							type="button"
							className="trmz-booking__button"
							onClick={ () => {
								if ( ! slot ) {
									setChoiceError(
										__(
											'Please choose a time.',
											'terminarz'
										)
									);
									return;
								}
								goTo( STEPS.DETAILS );
							} }
						>
							{ __( 'Continue', 'terminarz' ) }
						</button>
					) }
				</div>
			</Step>
		);
	} else if ( step === STEPS.DETAILS ) {
		const selectedResource =
			resource === 'any'
				? null
				: ( resources ?? [] ).find( ( item ) => item.id === resource );
		content = (
			<DetailsStep
				stepProps={ stepProps( __( 'Your details', 'terminarz' ) ) }
				idPrefix={ idPrefix }
				config={ config }
				service={ service }
				resource={ resource }
				resourceName={ selectedResource?.name ?? '' }
				date={ date }
				slot={ slot }
				timezone={ timezone }
				backButton={ backButton }
				form={ form }
				setForm={ setForm }
				announce={ setAnnouncement }
				onBooked={ ( created, redirect ) => {
					// Extension point: other scripts may react to a new booking (e.g. analytics).
					root.current?.dispatchEvent(
						new window.CustomEvent( 'terminarz:booking-created', {
							bubbles: true,
							detail: created,
						} )
					);
					if ( redirect ) {
						window.location.assign( redirect );
						return;
					}
					refreshAvailability();
					setBooking( created );
					goTo( STEPS.DONE );
				} }
				onSlotUnavailable={ ( message ) => {
					refreshAvailability();
					setTimeNotice( message );
					setSlotStart( null );
					setMonth( monthOf( date ) );
					goTo( STEPS.TIME );
				} }
			/>
		);
	} else if ( step === STEPS.DONE && booking ) {
		const assigned = ( resourcesByService[ serviceId ] ?? [] ).find(
			( item ) => item.id === booking.resource
		);
		content = (
			<Confirmation
				stepProps={ {
					...stepProps(
						__( 'Thank you for your booking', 'terminarz' )
					),
					index: 0,
				} }
				booking={ booking }
				serviceName={ service?.name ?? '' }
				resourceName={ assigned?.name ?? '' }
				timezone={ timezone }
				locale={ config.locale }
				onRestart={ () => {
					setBooking( null );
					setForm( EMPTY_FORM );
					resetSchedule();
					if ( skipService ) {
						continueWithService( serviceId );
					} else {
						goTo( STEPS.SERVICE );
					}
				} }
			/>
		);
	}

	return (
		<div className="trmz-booking" ref={ root }>
			<div
				className="trmz-booking__status"
				aria-live="polite"
				role="status"
			>
				{ announcement }
			</div>
			{ /* A new key per step remounts the step, so its heading receives focus. */ }
			<Fragment key={ step ?? 'start' }>{ content }</Fragment>
		</div>
	);
}
