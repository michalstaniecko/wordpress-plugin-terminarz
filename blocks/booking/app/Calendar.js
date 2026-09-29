/**
 * Month calendar following the WAI-ARIA APG date grid pattern: a `grid` table with one focusable day at a time
 * (roving tabindex). Arrow keys move by day/week, Home/End to the start/end of the week, PageUp/PageDown by month
 * (also across months). Days without free times stay focusable but are `aria-disabled`.
 */
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	addMonths,
	compareMonths,
	formatDate,
	monthGrid,
	monthOf,
	moveFocus,
	parseDate,
	weekdayOrder,
} from '../lib/calendar';
import { formatLongDate, formatMonth, weekdayName } from '../lib/format';

export default function Calendar( {
	idPrefix,
	month,
	firstDayOfWeek,
	locale,
	today,
	lastDate,
	available,
	selectedDate,
	focusDate,
	loading,
	onFocusDate,
	onMonthChange,
	onSelect,
} ) {
	const grid = useRef();
	const wantsFocus = useRef( false );
	const firstMonth = monthOf( today );
	const lastMonth = lastDate ? monthOf( lastDate ) : null;
	const canGoBack = compareMonths( month, firstMonth ) > 0;
	const canGoForward = ! lastMonth || compareMonths( month, lastMonth ) < 0;
	const minFocus = formatDate( firstMonth.year, firstMonth.month, 1 );
	const weeks = monthGrid( month.year, month.month, firstDayOfWeek );
	const headingId = `${ idPrefix }-month`;

	// The day with tabindex=0: the focused day when it is in this month, else the selected day, else the first
	// day with free times, else today, else the 1st.
	const days = weeks.flat().filter( Boolean );
	const tabbable =
		[
			focusDate,
			selectedDate,
			days.find( ( day ) => available.has( day ) ),
			today,
		].find( ( day ) => day && days.includes( day ) ) ?? days[ 0 ];

	useEffect( () => {
		if ( ! wantsFocus.current || ! grid.current ) {
			return;
		}
		const button = grid.current.querySelector(
			`[data-date="${ tabbable }"]`
		);
		if ( button ) {
			button.focus();
			wantsFocus.current = false;
		}
	}, [ tabbable, month ] );

	const onKeyDown = ( event ) => {
		const current = event.target?.dataset?.date;
		if ( ! current ) {
			return;
		}
		const target = moveFocus(
			current,
			event.key,
			firstDayOfWeek,
			minFocus,
			lastDate
		);
		if ( ! target ) {
			return;
		}
		event.preventDefault();
		wantsFocus.current = true;
		onFocusDate( target );
		const targetMonth = monthOf( target );
		if ( compareMonths( targetMonth, month ) !== 0 ) {
			onMonthChange( targetMonth );
		}
	};

	const changeMonth = ( delta ) => {
		const next = addMonths( month, delta );
		onMonthChange( next );
	};

	return (
		<div className="trmz-calendar">
			<div className="trmz-calendar__header">
				<button
					type="button"
					className="trmz-calendar__nav"
					onClick={ () => changeMonth( -1 ) }
					disabled={ ! canGoBack }
					aria-label={ __( 'Previous month', 'terminarz' ) }
				>
					<span aria-hidden="true">‹</span>
				</button>
				<p id={ headingId } className="trmz-calendar__month">
					{ formatMonth( month, locale ) }
				</p>
				<button
					type="button"
					className="trmz-calendar__nav"
					onClick={ () => changeMonth( 1 ) }
					disabled={ ! canGoForward }
					aria-label={ __( 'Next month', 'terminarz' ) }
				>
					<span aria-hidden="true">›</span>
				</button>
			</div>
			<table
				role="grid"
				className="trmz-calendar__grid"
				aria-labelledby={ headingId }
				aria-busy={ loading ? true : undefined }
				ref={ grid }
				onKeyDown={ onKeyDown }
			>
				<thead>
					<tr>
						{ weekdayOrder( firstDayOfWeek ).map( ( day ) => {
							const name = weekdayName( day, locale );
							return (
								<th
									key={ day }
									scope="col"
									abbr={ name.long }
									className="trmz-calendar__weekday"
								>
									{ name.short }
								</th>
							);
						} ) }
					</tr>
				</thead>
				<tbody>
					{ weeks.map( ( week, row ) => (
						<tr key={ row }>
							{ week.map( ( date, column ) => {
								if ( ! date ) {
									return (
										<td
											key={ `empty-${ column }` }
											className="trmz-calendar__cell"
										/>
									);
								}
								const isAvailable =
									! loading && available.has( date );
								const isSelected = date === selectedDate;
								const longDate = formatLongDate( date, locale );
								const label = isAvailable
									? sprintf(
											/* translators: %s: date, e.g. "Thursday, 1 October 2026". */
											__(
												'%s, free times available',
												'terminarz'
											),
											longDate
										)
									: sprintf(
											/* translators: %s: date, e.g. "Thursday, 1 October 2026". */
											__(
												'%s, no free times',
												'terminarz'
											),
											longDate
										);
								return (
									<td
										key={ date }
										className="trmz-calendar__cell"
										aria-selected={ isSelected }
									>
										<button
											type="button"
											className={ [
												'trmz-calendar__day',
												isAvailable &&
													'trmz-calendar__day--available',
												isSelected &&
													'trmz-calendar__day--selected',
												date === today &&
													'trmz-calendar__day--today',
											]
												.filter( Boolean )
												.join( ' ' ) }
											data-date={ date }
											tabIndex={
												date === tabbable ? 0 : -1
											}
											aria-label={ label }
											aria-disabled={
												isAvailable ? undefined : true
											}
											aria-current={
												date === today
													? 'date'
													: undefined
											}
											onFocus={ () =>
												onFocusDate( date )
											}
											onClick={ () => {
												if ( isAvailable ) {
													onSelect( date );
												}
											} }
										>
											{ parseDate( date ).day }
										</button>
									</td>
								);
							} ) }
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}
