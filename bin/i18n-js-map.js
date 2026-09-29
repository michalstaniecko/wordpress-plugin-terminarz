/**
 * Writes the source → build map used by `wp i18n make-json --use-map` (ADR-047).
 *
 * WordPress loads the JSON translations of a script from `terminarz-<locale>-<md5 of the script path>.json`, where the
 * path is the built file (e.g. `build/booking/view.js`), while `.po` references point at the sources in `blocks/`.
 * Every JavaScript source of a block is mapped to every script built for that block: the few extra strings in each
 * JSON file cost less than keeping an exact import graph in sync.
 *
 * Usage: node bin/i18n-js-map.js <output.json>
 */
const fs = require( 'fs' );
const path = require( 'path' );

const root = path.resolve( __dirname, '..' );
const output = process.argv[ 2 ];
if ( ! output ) {
	process.stderr.write( 'Usage: node bin/i18n-js-map.js <output.json>\n' );
	process.exit( 1 );
}

/**
 * Relative POSIX paths of files with the given extension under a directory.
 *
 * @param {string} dir       Directory relative to the plugin root.
 * @param {RegExp} extension File name pattern.
 * @return {string[]} Paths.
 */
function files( dir, extension ) {
	const absolute = path.join( root, dir );
	if ( ! fs.existsSync( absolute ) ) {
		return [];
	}
	return fs
		.readdirSync( absolute, { withFileTypes: true } )
		.flatMap( ( entry ) => {
			const relative = path.posix.join( dir, entry.name );
			if ( entry.isDirectory() ) {
				return files( relative, extension );
			}
			return extension.test( entry.name ) ? [ relative ] : [];
		} )
		.sort();
}

const map = {};
for ( const block of fs.readdirSync( path.join( root, 'blocks' ) ) ) {
	const built = files( path.posix.join( 'build', block ), /\.js$/ );
	if ( built.length === 0 ) {
		process.stderr.write(
			`No built scripts for blocks/${ block } — run "npm run build" first.\n`
		);
		process.exit( 1 );
	}
	for ( const source of files(
		path.posix.join( 'blocks', block ),
		/\.jsx?$/
	) ) {
		map[ source ] = built;
	}
}

fs.mkdirSync( path.dirname( path.resolve( output ) ), { recursive: true } );
fs.writeFileSync( output, JSON.stringify( map, null, '\t' ) + '\n' );
