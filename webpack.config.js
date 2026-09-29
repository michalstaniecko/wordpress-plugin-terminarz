/**
 * Webpack configuration: @wordpress/scripts defaults + a private copy of @wordpress/api-fetch for the front end.
 *
 * `@wordpress/*` imports are normally externals (the `wp-*` scripts of WordPress). The core `wp-api-fetch` script
 * registers a nonce middleware for every visitor, also anonymous ones; a nonce baked into a cached page expires
 * and turns public bookings into 403 errors. The booking front end therefore imports `trmz-bundled-api-fetch`
 * (an alias of the same package, bundled into view.js) and adds the nonce only for logged-in users
 * (see docs/ARCHITECTURE.md, ADR-031).
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const withAlias = ( config ) => ( {
	...config,
	resolve: {
		...config.resolve,
		alias: {
			...( config.resolve?.alias ?? {} ),
			'trmz-bundled-api-fetch$':
				require.resolve( '@wordpress/api-fetch' ),
		},
	},
} );

module.exports = Array.isArray( defaultConfig )
	? defaultConfig.map( withAlias )
	: withAlias( defaultConfig );
