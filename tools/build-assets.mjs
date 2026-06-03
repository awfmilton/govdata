/**
 * Minify the theme's own JS/CSS assets into *.min.js / *.min.css siblings.
 *
 * The PHP asset pipeline (Asset_Pipeline::maybe_min) serves the .min variant
 * automatically when it exists and SCRIPT_DEBUG is off, falling back to source
 * otherwise — so running this is optional in development but expected before a
 * release. Vendored libraries under assets/vendor/ are already minified and are
 * intentionally skipped.
 *
 * Usage: npm run build
 */
import { build } from 'esbuild';
import { readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const DIRS = [
	'publictech-core/assets/js',
	'publictech-core/assets/css',
	'publictech-terminal/assets/js',
	'publictech-terminal/assets/css',
];

const entries = [];
for ( const dir of DIRS ) {
	let files;
	try {
		files = readdirSync( dir );
	} catch {
		continue; // dir may not exist in every checkout
	}
	for ( const f of files ) {
		if ( ! /\.(js|css)$/.test( f ) ) continue;
		if ( /\.min\.(js|css)$/.test( f ) ) continue; // already minified
		const src = join( dir, f );
		if ( ! statSync( src ).isFile() ) continue;
		entries.push( { src, out: src.replace( /\.(js|css)$/, '.min.$1' ) } );
	}
}

await Promise.all(
	entries.map( ( { src, out } ) =>
		build( {
			entryPoints: [ src ],
			outfile: out,
			minify: true,
			bundle: false, // plain browser scripts/styles — just minify in place
			legalComments: 'none',
			logLevel: 'warning',
		} )
	)
);

console.log( `Minified ${ entries.length } asset(s).` );
