/**
 * A group of native radio buttons styled as selectable cards.
 *
 * Native radios give the expected keyboard behaviour for free (Tab into the group, arrows move and select),
 * are announced as a radio group and work without extra ARIA (see ADR-032).
 */

/**
 * @param {Object}   props            Props.
 * @param {string}   props.name       Radio group name (unique on the page).
 * @param {string}   props.labelledBy ID of the element naming the group.
 * @param {Array}    props.options    Options {value, label, meta?}.
 * @param {*}        props.value      Selected value.
 * @param {Function} props.onChange   Called with the chosen value.
 * @param {string}   props.errorId    ID of the error message.
 * @param {string}   props.error      Error message ('' = none).
 * @param {string}   props.className  Extra class.
 * @return {Element} Radio group.
 */
export default function ChoiceList( {
	name,
	labelledBy,
	options,
	value,
	onChange,
	errorId,
	error,
	className = '',
} ) {
	return (
		<div
			role="radiogroup"
			aria-labelledby={ labelledBy }
			aria-describedby={ error ? errorId : undefined }
			aria-invalid={ error ? true : undefined }
			className={ `trmz-choices ${ className }`.trim() }
		>
			{ options.map( ( option ) => (
				<label
					className="trmz-choice"
					key={ option.value }
					htmlFor={ `${ name }-${ option.value }` }
				>
					<input
						id={ `${ name }-${ option.value }` }
						className="trmz-choice__input"
						type="radio"
						name={ name }
						value={ option.value }
						checked={ String( value ) === String( option.value ) }
						onChange={ () => onChange( option.value ) }
					/>
					<span className="trmz-choice__body">
						<span className="trmz-choice__label">
							{ option.label }
						</span>
						{ option.meta && (
							<span className="trmz-choice__meta">
								{ option.meta }
							</span>
						) }
					</span>
				</label>
			) ) }
		</div>
	);
}
