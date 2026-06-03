/**
 * Copy the pinned third-party chart/NLP libraries from node_modules into
 * publictech-core/assets/vendor/ so the theme can self-host them (no CDN).
 *
 * Run after `npm install` when bumping a library version:  npm run vendor
 * The versions are pinned in package.json devDependencies; keep the version
 * strings referenced in class-asset-pipeline.php (wp_enqueue_script) in sync.
 */
import { copyFileSync, mkdirSync } from 'node:fs';
import { build } from 'esbuild';

const VENDOR = 'publictech-core/assets/vendor';
mkdirSync( VENDOR, { recursive: true } );

// Files that already ship minified — straight copy.
const copies = [
	[ 'node_modules/d3/dist/d3.min.js', `${ VENDOR }/d3.v7.min.js` ],
	[ 'node_modules/topojson-client/dist/topojson-client.min.js', `${ VENDOR }/topojson-client.min.js` ],
	[ 'node_modules/d3-sankey/dist/d3-sankey.min.js', `${ VENDOR }/d3-sankey.min.js` ],
	[ 'node_modules/billboard.js/dist/billboard.min.js', `${ VENDOR }/billboard.min.js` ],
	[ 'node_modules/billboard.js/dist/billboard.min.css', `${ VENDOR }/billboard.min.css` ],
];
for ( const [ from, to ] of copies ) {
	copyFileSync( from, to );
}

// compromise ships an unminified UMD build — minify it on the way in.
await build( {
	entryPoints: [ 'node_modules/compromise/builds/compromise.js' ],
	outfile: `${ VENDOR }/compromise.min.js`,
	minify: true,
	bundle: false,
	legalComments: 'none',
	logLevel: 'warning',
} );

console.log( `Vendored ${ copies.length + 1 } file(s) into ${ VENDOR }/.` );
