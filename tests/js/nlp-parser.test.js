'use strict';
/**
 * NLP Parser unit tests — publictech-terminal/assets/js/query-builder-ui.js
 *
 * Tests PT.NLP.parseQuery() and PT.NLP.intentToFilters() in a minimal Node
 * shim (no browser, no Compromise.js). Covers all 35 known query patterns
 * including the 5 bug-fix regressions from 2026-05-31.
 *
 * Run:  node --test tests/js/nlp-parser.test.js
 */

const { describe, it } = require('node:test');
const assert           = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { resolve }      = require('node:path');

// ---------------------------------------------------------------------------
// Bootstrap — execute query-builder-ui.js in a minimal browser shim
// ---------------------------------------------------------------------------
const src = readFileSync(
	resolve( __dirname, '../../publictech-terminal/assets/js/query-builder-ui.js' ),
	'utf8'
);

const _g       = { PT: {}, PT_BOOT: {} };
_g.window      = _g;
_g.document    = { getElementById: () => null, addEventListener: () => {}, querySelectorAll: () => [] };
_g.nlp         = null; // Compromise.js not needed — pure-regex path covers all cases

const _fn = new Function( 'global', 'window', 'document', src + '\nreturn global.PT;' );
const PT  = _fn( _g, _g, _g.document );

const { parseQuery, intentToFilters } = PT.NLP;

// ---------------------------------------------------------------------------
// Column set — mirrors publictech_columns_pt-grantconts-01_master_table
// ---------------------------------------------------------------------------
const COLS = new Set( [
	'id', 'ref_number', 'agreement_type', 'agreement_number', 'agreement_value',
	'agreement_start_date', 'agreement_end_date', 'year', 'fiscal_year',
	'agreement_title_en', 'description_eng', 'expected_results_en', 'coverage',
	'recipient_type', 'recipient_legal_name', 'recipient_operating_name',
	'recipient_country', 'recipient_province', 'recipient_city', 'recipient_postal_code',
	'federal_riding_name_en', 'federal_riding_number', 'prog_name_en', 'prog_purpose_en',
	'naics_identifier', 'owner_org_en', 'owner_org_title',
] );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function run( query ) {
	const bp = parseQuery( query );
	return {
		intent     : bp.intent,
		params     : bp.parameters,
		ai_fallback: bp.ai_fallback,
		filters    : intentToFilters( bp.intent, bp.parameters, COLS ),
	};
}

function filter( filters, col ) {
	return filters.find( f => f.col === col ) || null;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe( 'NLP — amount extraction', () => {
	it( 'parses "$1 million" as value_gt=1000000', () => {
		const r = run( 'grants over $1 million in Ontario' );
		assert.equal( r.ai_fallback, false );
		assert.equal( r.params.value_gt, 1000000 );
		assert.ok( filter( r.filters, 'agreement_value' )?.val === '1000000' );
	} );

	it( 'parses "$500k" abbreviation', () => {
		const r = run( 'ISED grants over $500k' );
		assert.equal( r.params.value_gt, 500000 );
	} );

	it( 'parses "under $50000" as value_lt', () => {
		const r = run( 'contributions under $50000 from ESDC' );
		assert.equal( r.params.value_lt, 50000 );
		assert.equal( filter( r.filters, 'agreement_value' )?.op, '<' );
	} );

	it( 'parses standalone "$500000" as value_approx BETWEEN ±20%', () => {
		const r = run( 'show me $500000 grants in Toronto' );
		const f = filter( r.filters, 'agreement_value' );
		assert.equal( f?.op, 'BETWEEN' );
		assert.deepEqual( f?.val, [ '400000', '600000' ] );
	} );
} );

describe( 'NLP — agreement type', () => {
	it( '"grants" maps to agreement_type=G', () => {
		const r = run( 'grants over $1 million in Ontario' );
		assert.equal( r.params.agreement_type, 'G' );
		assert.equal( filter( r.filters, 'agreement_type' )?.val, 'G' );
	} );

	it( '"contributions" maps to agreement_type=C', () => {
		const r = run( 'contributions under $50000 from ESDC' );
		assert.equal( r.params.agreement_type, 'C' );
	} );
} );

describe( 'NLP — province / geography', () => {
	it( 'full province name "Ontario" → ON', () => {
		const r = run( 'grants over $1 million in Ontario' );
		assert.equal( r.params.province, 'ON' );
		assert.equal( filter( r.filters, 'recipient_province' )?.val, 'ON' );
	} );

	it( '"British Columbia" → BC', () => {
		const r = run( 'contributions to not-for-profits in British Columbia' );
		assert.equal( r.params.province, 'BC' );
	} );

	it( 'city "Toronto" captured at end of string (Bug 2 fix)', () => {
		const r = run( 'show me $500000 grants in Toronto' );
		assert.equal( r.params.city, 'Toronto' );
		assert.ok( filter( r.filters, 'recipient_city' )?.val.includes( 'Toronto' ) );
	} );

	it( '"Nova Scotia" → NS', () => {
		const r = run( 'show me all government recipients in Nova Scotia' );
		assert.equal( r.params.province, 'NS' );
	} );
} );

describe( 'NLP — recipient type (plural forms — Bug 1 fix)', () => {
	it( '"not-for-profits" → recipient_type=N', () => {
		const r = run( 'contributions to not-for-profits in British Columbia' );
		assert.equal( r.params.recipient_type, 'N' );
		assert.equal( filter( r.filters, 'recipient_type' )?.val, 'N' );
		// Must NOT have been mistakenly captured as a recipient name
		assert.equal( r.params.recipient, undefined );
	} );

	it( '"universities" (plural) → recipient_type=S', () => {
		const r = run( 'grants to universities in Alberta' );
		assert.equal( r.params.recipient_type, 'S' );
		assert.equal( r.params.recipient, undefined );
	} );

	it( '"not-for-profits in Manitoba" without agreement type → recipient_type=N + province=MB', () => {
		const r = run( 'not-for-profits in Manitoba' );
		assert.equal( r.params.recipient_type, 'N' );
		assert.equal( r.params.province, 'MB' );
	} );

	it( '"charities in Ontario over $100k" → recipient_type=N + province=ON + value_gt', () => {
		const r = run( 'charities in Ontario over $100k' );
		assert.equal( r.params.recipient_type, 'N' );
		assert.equal( r.params.province, 'ON' );
		assert.equal( r.params.value_gt, 100000 );
	} );

	it( '"corporations in Quebec since 2021" → recipient_type=F + province=QC', () => {
		const r = run( 'corporations in Quebec since 2021' );
		assert.equal( r.params.recipient_type, 'F' );
		assert.equal( r.params.province, 'QC' );
		assert.ok( r.params.start_year );
	} );

	it( '"nonprofits" → recipient_type=N', () => {
		const r = run( 'nonprofits in Alberta' );
		assert.equal( r.params.recipient_type, 'N' );
	} );

	it( '"indigenous organizations" → recipient_type=A', () => {
		const r = run( 'indigenous organizations in Quebec' );
		assert.equal( r.params.recipient_type, 'A' );
	} );

	it( '"government recipients" → recipient_type=G', () => {
		const r = run( 'show me all government recipients in Nova Scotia' );
		assert.equal( r.params.recipient_type, 'G' );
	} );

	it( '"for-profit companies" → recipient_type=F', () => {
		const r = run( 'for-profit companies that received over $2 million' );
		assert.equal( r.params.recipient_type, 'F' );
		assert.equal( r.params.value_gt, 2000000 );
	} );

	it( '"sole proprietors in Saskatchewan" → recipient_type=P + province=SK', () => {
		const r = run( 'sole proprietors in Saskatchewan' );
		assert.equal( r.params.recipient_type, 'P' );
		assert.equal( r.params.province, 'SK' );
	} );

	it( '"NGOs that received ISED funding" → recipient_type=N + department=ISED', () => {
		const r = run( 'NGOs that received ISED funding' );
		assert.equal( r.params.recipient_type, 'N' );
		assert.ok( r.params.department?.includes( 'Innovation' ) );
	} );

	it( '"universities in Nova Scotia since 2019" → recipient_type=S + province=NS', () => {
		const r = run( 'universities in Nova Scotia since 2019' );
		assert.equal( r.params.recipient_type, 'S' );
		assert.equal( r.params.province, 'NS' );
		assert.ok( r.params.start_year );
	} );
} );

describe( 'NLP — recipient name (end-of-string lookahead — Bug 2 fix)', () => {
	it( '"grants awarded to Red Cross" captures recipient at end of string', () => {
		const r = run( 'grants awarded to Red Cross' );
		assert.equal( r.params.recipient, 'Red Cross' );
		assert.ok( filter( r.filters, 'recipient_legal_name' )?.val.includes( 'Red Cross' ) );
	} );

	it( '"contributions to Canadian Red Cross" → recipient=Canadian Red Cross', () => {
		const r = run( 'contributions to Canadian Red Cross' );
		assert.equal( r.params.agreement_type, 'C' );
		assert.equal( r.params.recipient, 'Canadian Red Cross' );
	} );
} );

describe( 'NLP — department / funding org', () => {
	it( '"from Health Canada since 2020" → department + start_year', () => {
		const r = run( 'funding from Health Canada since 2020' );
		assert.ok( r.params.department?.includes( 'Health Canada' ) );
		assert.ok( r.params.start_year );
		assert.equal( filter( r.filters, 'agreement_start_date' )?.val, '2020-01-01' );
	} );

	it( '"ISED" abbreviation expands correctly', () => {
		const r = run( 'ISED grants over $500k' );
		assert.ok( r.params.department?.includes( 'Innovation' ) );
	} );

	it( '"ESDC" abbreviation expands', () => {
		const r = run( 'contributions under $50000 from ESDC' );
		assert.ok( r.params.department?.includes( 'Employment and Social Development' ) );
	} );

	it( '"NSERC grants over $500k" → department + value_gt', () => {
		const r = run( 'NSERC grants over $500k' );
		assert.ok( r.params.department?.includes( 'Natural Sciences' ) );
		assert.equal( r.params.value_gt, 500000 );
	} );

	it( '"CIHR grants 2020-2021 in Ontario" → department + fiscal_year + province', () => {
		const r = run( 'CIHR grants 2020-2021 in Ontario' );
		assert.ok( r.params.department?.includes( 'Canadian Institutes of Health Research' ) );
		assert.equal( r.params.fiscal_year, '2020-2021' );
		assert.equal( r.params.province, 'ON' );
	} );
} );

describe( 'NLP — fiscal year', () => {
	it( '"2022-2023 fiscal year" → fiscal_year string', () => {
		const r = run( 'grants in the 2022-2023 fiscal year' );
		assert.equal( r.params.fiscal_year, '2022-2023' );
		assert.equal( filter( r.filters, 'fiscal_year' )?.val, '2022-2023' );
		// Must not capture "the fiscal year" as a city (regression guard)
		assert.equal( r.params.city, undefined );
	} );

	it( '"since 2020" → start_year (agreement_start_date >=)', () => {
		const r = run( 'funding from Health Canada since 2020' );
		assert.equal( filter( r.filters, 'agreement_start_date' )?.op, '>=' );
	} );

	it( '"before 2019" → end_year (agreement_end_date <=)', () => {
		const r = run( 'contributions in Saskatchewan before 2019' );
		assert.equal( filter( r.filters, 'agreement_end_date' )?.op, '<=' );
		assert.equal( filter( r.filters, 'agreement_end_date' )?.val, '2019-12-31' );
	} );
} );

describe( 'NLP — fulltext search (step ordering — Bug 3 fix)', () => {
	it( '"related to housing" routes to fulltext, not recipient name', () => {
		const r = run( 'grants related to housing and infrastructure' );
		assert.equal( r.params.fulltext, 'housing and infrastructure' );
		assert.equal( r.params.recipient, undefined );
		assert.ok( filter( r.filters, 'description_eng' )?.val.includes( 'housing' ) );
	} );

	it( '"about climate change" → fulltext intent', () => {
		const r = run( 'grants about climate change' );
		assert.equal( r.intent, 'fulltext_search' );
		assert.ok( r.params.fulltext?.includes( 'climate change' ) );
	} );

	it( '"involving youth programs" → fulltext intent', () => {
		const r = run( 'contributions involving youth programs' );
		assert.equal( r.intent, 'fulltext_search' );
		assert.ok( r.params.fulltext?.includes( 'youth programs' ) );
	} );

	it( '"search for mental health" → fulltext intent', () => {
		const r = run( 'search for mental health' );
		assert.equal( r.intent, 'fulltext_search' );
		assert.ok( r.params.fulltext?.includes( 'mental health' ) );
	} );
} );

describe( 'NLP — ai_fallback routing', () => {
	it( '"funding for climate change research" → ai_fallback (bare "research" removed from map)', () => {
		const r = run( 'funding for climate change research' );
		assert.equal( r.ai_fallback, true );
		// Must not incorrectly assign recipient_type=S via bare "research"
		assert.equal( r.params.recipient_type, undefined );
	} );

	it( '"show me everything" → ai_fallback (junk prevention — Bug 4 fix)', () => {
		const r = run( 'show me everything' );
		assert.equal( r.ai_fallback, true );
		assert.equal( r.params.recipient, undefined );
	} );

	it( '"hello" → ai_fallback (junk prevention — Bug 4 fix)', () => {
		const r = run( 'hello' );
		assert.equal( r.ai_fallback, true );
		assert.equal( r.params.recipient, undefined );
	} );
} );

describe( 'NLP — corrections history intent', () => {
	it( '"what changed in agreement values" → corrections_history intent, 0 filters on master_table', () => {
		const r = run( 'what changed in agreement values' );
		assert.equal( r.intent, 'corrections_history' );
		assert.equal( r.ai_fallback, false );
		// corrections_history columns don't exist in master_table → 0 filters (applyNL returns false)
		assert.equal( r.filters.length, 0 );
	} );
} );

describe( 'NLP — program / NAICS / riding', () => {
	it( '"Canada Cultural Spaces Fund program" → prog_name_en LIKE', () => {
		const r = run( 'grants through the Canada Cultural Spaces Fund program' );
		assert.ok( r.params.program?.includes( 'Canada Cultural Spaces' ) );
		assert.ok( filter( r.filters, 'prog_name_en' )?.val.includes( 'Canada Cultural Spaces' ) );
	} );

	it( '"NAICS code 541 in Ontario" → naics_identifier = 541', () => {
		const r = run( 'NAICS code 541 in Ontario' );
		assert.equal( r.params.naics, '541' );
		assert.equal( filter( r.filters, 'naics_identifier' )?.val, '541' );
	} );

	it( '"riding of Papineau" → federal_riding_name_en LIKE', () => {
		const r = run( 'riding of Papineau grants' );
		assert.ok( r.params.riding?.includes( 'Papineau' ) );
		assert.ok( filter( r.filters, 'federal_riding_name_en' )?.val.includes( 'Papineau' ) );
	} );
} );

describe( 'NLP — multi-filter combinations', () => {
	it( '"contributions to not-for-profits in BC" → 3 filters: type + recipient_type + province', () => {
		const r = run( 'contributions to not-for-profits in British Columbia' );
		assert.equal( r.filters.length, 3 );
		assert.equal( filter( r.filters, 'agreement_type' )?.val, 'C' );
		assert.equal( filter( r.filters, 'recipient_type' )?.val, 'N' );
		assert.equal( filter( r.filters, 'recipient_province' )?.val, 'BC' );
	} );

	it( '"grants to universities in Alberta" → 3 filters', () => {
		const r = run( 'grants to universities in Alberta' );
		assert.equal( r.filters.length, 3 );
		assert.equal( filter( r.filters, 'agreement_type' )?.val, 'G' );
		assert.equal( filter( r.filters, 'recipient_type' )?.val, 'S' );
		assert.equal( filter( r.filters, 'recipient_province' )?.val, 'AB' );
	} );

	it( '"contributions from Agriculture Canada to Manitoba farmers" → 3 filters', () => {
		const r = run( 'contributions from Agriculture Canada to Manitoba farmers' );
		assert.equal( filter( r.filters, 'agreement_type' )?.val, 'C' );
		assert.equal( filter( r.filters, 'recipient_province' )?.val, 'MB' );
		assert.ok( filter( r.filters, 'owner_org_en' )?.val.includes( 'Agriculture' ) );
	} );

	it( '"Health Canada contributions to Manitoba nonprofits" → type + province + recipient_type', () => {
		const r = run( 'Health Canada contributions to Manitoba nonprofits' );
		assert.equal( r.params.agreement_type, 'C' );
		assert.equal( r.params.province, 'MB' );
		assert.equal( r.params.recipient_type, 'N' );
	} );

	it( '"CIHR grants 2020-2021 in Ontario" → 4 filters: type + province + fiscal_year + dept', () => {
		const r = run( 'CIHR grants 2020-2021 in Ontario' );
		assert.ok( r.filters.length >= 3 );
		assert.equal( filter( r.filters, 'agreement_type' )?.val, 'G' );
		assert.equal( filter( r.filters, 'recipient_province' )?.val, 'ON' );
		assert.equal( filter( r.filters, 'fiscal_year' )?.val, '2020-2021' );
		assert.ok( filter( r.filters, 'owner_org_en' )?.val.includes( 'Health Research' ) );
	} );
} );
