/**
 * ESLint flat config: @wordpress/scripts defaults + project rules.
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );
const wpPlugin = require( '@wordpress/eslint-plugin' );

module.exports = [
	{
		ignores: [
			'**/build/**',
			'**/node_modules/**',
			'**/vendor/**',
			'artifacts/**',
			'playwright-report/**',
			'test-results/**',
		],
	},
	...defaultConfig,
	{
		rules: {
			'@wordpress/i18n-text-domain': [
				'error',
				{ allowedTextDomain: 'terminarz' },
			],
		},
	},
	...wpPlugin.configs[ 'test-playwright' ].map( ( config ) => ( {
		...config,
		files: [ 'tests/e2e/**/*.{js,ts}', 'playwright.config.{js,ts}' ],
	} ) ),
];
