/**
 * One step of the booking flow: a focusable heading (focus moves there when the step changes), the step
 * position and the step content.
 */
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

export default function Step( {
	id,
	title,
	index,
	total,
	focusOnMount,
	children,
} ) {
	const heading = useRef();

	useEffect( () => {
		if ( focusOnMount && heading.current ) {
			heading.current.focus();
		}
		// Only when the step appears.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	return (
		<section className="trmz-booking__step" aria-labelledby={ id }>
			{ index > 0 && total > 0 && (
				<p className="trmz-booking__progress">
					{ sprintf(
						/* translators: 1: current step number, 2: number of steps. */
						__( 'Step %1$d of %2$d', 'terminarz' ),
						index,
						total
					) }
				</p>
			) }
			<h2
				id={ id }
				className="trmz-booking__title"
				tabIndex={ -1 }
				ref={ heading }
			>
				{ title }
			</h2>
			{ children }
		</section>
	);
}
