/* PublicTech Query Builder + Compromise.js NLP Router
 *
 * A`rchitecture:
 *   1. Structured query builder — column/op/value rows → /stream/{schema}/{table}
 *   2. Deterministic NLP layer — Compromise.js parses text → Intent Blueprint
 *      → intentToFilters() populates query builder rows (no server round-trip)
 *   3. AI fallback slot — POST /nlp/query when Compromise.js yields zero matches
 *      → currently returns 503; replace PHP handler body to activate LLM
 *
 * Column names, decode maps, and value domains sourced exclusively from:
 *   database/grantconts-schema.json + database/nlp-schema-metadata.json
 *
 * Requires: global.PT_BOOT (localized by asset pipeline on publictech-charts).
 * Requires: global.nlp (compromise@14 from CDN) — degrades gracefully if absent.
 */
(function (global) {
	'use strict';

	const PT   = global.PT = global.PT || {};
	const boot = global.PT_BOOT || {};

	// ── Boot JSON injected by the PHP template ────────────────────────────────
	const qbBootNode = document.getElementById( 'pt-qb-boot' );
	const qbBoot     = qbBootNode ? JSON.parse( qbBootNode.textContent || '{}' ) : {};

	// ── Expose NLP namespace ─────────────────────────────────────────────────
	PT.NLP = PT.NLP || {};

	// ══════════════════════════════════════════════════════════════════════════
	// SECTION A — NLP DOMAIN DICTIONARY
	// All codes sourced from database/nlp-schema-metadata.json code_decode_maps.
	// All column names sourced from database/grantconts-schema.json master_table.
	// DO NOT invent column names — they must match publictech_columns_* filters.
	// ══════════════════════════════════════════════════════════════════════════

	// Province/territory: full English names + common variants + 2-letter codes
	// stored as char(2) in master_table.recipient_province
	const PROVINCE_MAP = {
		// Full names — longest first for greedy matching (see extractProvince)
		'northwest territories'      : 'NT',
		'prince edward island'       : 'PE',
		'newfoundland and labrador'  : 'NL',
		'british columbia'           : 'BC',
		'new brunswick'              : 'NB',
		'nova scotia'                : 'NS',
		'newfoundland'               : 'NL',
		'labrador'                   : 'NL',
		'alberta'                    : 'AB',
		'manitoba'                   : 'MB',
		'ontario'                    : 'ON',
		'quebec'                     : 'QC',
		'québec'                     : 'QC',
		'saskatchewan'               : 'SK',
		'nunavut'                    : 'NU',
		'yukon'                      : 'YT',
		// 2-letter codes (always uppercase in DB)
		'ab' : 'AB', 'bc' : 'BC', 'mb' : 'MB', 'nb' : 'NB', 'nl' : 'NL',
		'ns' : 'NS', 'nt' : 'NT', 'nu' : 'NU', 'on' : 'ON', 'pe' : 'PE',
		'pei': 'PE', 'qc' : 'QC', 'sk' : 'SK', 'yt' : 'YT',
	};

	// agreement_type: char(1) in master_table.agreement_type + agreements.agreement_type
	// Source: nlp-schema-metadata.json code_decode_maps.agreement_type
	const AGREEMENT_TYPE_MAP = {
		// G = Grant
		'grant'               : 'G', 'grants'              : 'G',
		'grant funding'       : 'G', 'grant agreement'     : 'G',
		'awarded a grant'     : 'G', 'non-repayable'       : 'G',
		'nonrepayable'        : 'G', 'grant money'         : 'G',
		'grant program'       : 'G', 'government grant'    : 'G',
		'direct grant'        : 'G', 'transfer grant'      : 'G',
		'unconditional grant' : 'G', 'block grant'         : 'G',
		'research grant'      : 'G', 'g type'              : 'G',
		// C = Contribution
		'contribution'           : 'C', 'contributions'       : 'C',
		'contribution agreement' : 'C', 'ca'                  : 'C',
		'contribution funding'   : 'C', 'repayable'           : 'C',
		'repayable contribution' : 'C', 'conditional funding' : 'C',
		'funding agreement'      : 'C', 'contribution grant'  : 'C',
		'project funding'        : 'C', 'targeted funding'    : 'C',
		'c type'                 : 'C', 'loan guarantee'      : 'C',
		// O = Other transfer payment
		'other transfer payment' : 'O', 'transfer payment'   : 'O',
		'other payment'          : 'O', 'o type'             : 'O',
		'other type'             : 'O', 'miscellaneous payment': 'O',
	};

	// recipient_type: char(1) in master_table.recipient_type; recipient_type_code in recipients
	// Source: nlp-schema-metadata.json code_decode_maps.recipient_type
	const RECIPIENT_TYPE_MAP = {
		// A = Aboriginal
		'aboriginal'           : 'A', 'indigenous'          : 'A',
		'first nations'        : 'A', 'first nation'        : 'A',
		'métis'                : 'A', 'metis'               : 'A',
		'inuit'                : 'A', 'aboriginal recipient': 'A',
		'indigenous organization': 'A', 'native'            : 'A',
		'fnmi'                 : 'A', 'a type'              : 'A',
		// F = For-profit
		'for-profit'           : 'F', 'for profit'          : 'F',
		'for-profits'          : 'F', 'for profits'         : 'F',
		'forprofit'            : 'F', 'business'            : 'F',
		'businesses'           : 'F', 'corporation'         : 'F',
		'corporations'         : 'F', 'company'             : 'F',
		'companies'            : 'F', 'corporate'           : 'F',
		'private sector'       : 'F', 'private company'     : 'F',
		'private companies'    : 'F', 'commercial'          : 'F',
		'enterprise'           : 'F', 'enterprises'         : 'F',
		'f type'               : 'F',
		// G = Government
		'government'           : 'G', 'government recipient': 'G',
		'gov'                  : 'G', 'municipal'           : 'G',
		'municipality'         : 'G', 'provincial'          : 'G',
		'crown corporation'    : 'G', 'public body'         : 'G',
		'government agency'    : 'G', 'g type'              : 'G',
		// I = International
		'international'             : 'I', 'international organization': 'I',
		'foreign'                   : 'I', 'foreign entity'           : 'I',
		'international ngos'        : 'I', 'international org'        : 'I',
		'i type'                    : 'I',
		// N = Not-for-profit
		'not-for-profit'       : 'N', 'not for profit'      : 'N',
		'not-for-profits'      : 'N', 'not for profits'     : 'N',
		'nonprofit'            : 'N', 'non-profit'          : 'N',
		'nonprofits'           : 'N', 'non-profits'         : 'N',
		'nfp'                  : 'N', 'charity'             : 'N',
		'charities'            : 'N', 'ngo'                 : 'N',
		'ngos'                 : 'N', 'charitable'          : 'N',
		'foundation'           : 'N', 'foundations'         : 'N',
		'association'          : 'N', 'associations'        : 'N',
		'registered charity'   : 'N', 'registered charities': 'N',
		'civil society'        : 'N', 'community organization': 'N',
		'community organizations': 'N',
		'n type'               : 'N',
		// O = Other
		'other'                : 'O', 'other recipient'     : 'O',
		'o type'               : 'O',
		// P = Individual / sole proprietor
		'individual'           : 'P', 'individuals'         : 'P',
		'sole proprietor'      : 'P', 'sole proprietors'    : 'P',
		'personal'             : 'P', 'person'              : 'P',
		'self-employed'        : 'P', 'sole trader'         : 'P',
		'sole traders'         : 'P', 'independent'         : 'P',
		'p type'               : 'P',
		// S = Academia
		'academia'             : 'S', 'academic'            : 'S',
		'university'           : 'S', 'universities'        : 'S',
		'college'              : 'S', 'colleges'            : 'S',
		'school'               : 'S', 'schools'             : 'S',
		'educational'          : 'S', 'institute'           : 'S',
		'institutes'           : 'S', 'polytechnic'         : 'S',
		'polytechnics'         : 'S', 'cégep'               : 'S',
		'cegep'                : 'S', 'cegeps'              : 'S',
		'research institution' : 'S', 'research institutions': 'S',
		'research institute'   : 'S', 'research institutes' : 'S',
		'research organization': 'S', 'research organisations': 'S',
		'higher education'     : 'S', 'post-secondary'      : 'S',
		'postsecondary'        : 'S',
		's type'               : 'S',
	};

	// Known federal department abbreviations → full name for owner_org_en LIKE matching
	const DEPT_ABBR = {
		'ised'  : 'Innovation, Science and Economic Development Canada',
		'phac'  : 'Public Health Agency of Canada',
		'nrcan' : 'Natural Resources Canada',
		'hc'    : 'Health Canada',
		'pch'   : 'Canadian Heritage',
		'tc'    : 'Transport Canada',
		'eccc'  : 'Environment and Climate Change Canada',
		'ec'    : 'Environment and Climate Change Canada',
		'dnd'   : 'National Defence',
		'ircc'  : 'Immigration, Refugees and Citizenship Canada',
		'esdc'  : 'Employment and Social Development Canada',
		'aafc'  : 'Agriculture and Agri-Food Canada',
		'dfo'   : 'Fisheries and Oceans Canada',
		'swc'   : 'Women and Gender Equality Canada',
		'wage'  : 'Women and Gender Equality Canada',
		'nserc' : 'Natural Sciences and Engineering Research Council of Canada',
		'sshrc' : 'Social Sciences and Humanities Research Council of Canada',
		'cihr'  : 'Canadian Institutes of Health Research',
		'cdc'   : 'Economic Development Agency of Canada for the Regions of Quebec',
		'feddev': 'Federal Economic Development Agency for Southern Ontario',
		'prdc'  : 'Pacific Economic Development Canada',
		'acoa'  : 'Atlantic Canada Opportunities Agency',
		'wd'    : 'Prairies Economic Development Canada',
		'cedc'  : 'Canada Economic Development',
		'nrc'   : 'National Research Council Canada',
		'isc'   : 'Indigenous Services Canada',
		'rcaanc': 'Crown-Indigenous Relations and Northern Affairs Canada',
		'canada heritage': 'Canadian Heritage',
	};

	// Operator catalogue — must exactly match Security::ALLOWED_OPS in class-security.php
	const OPERATORS = [
		{ op: '=',           label: '= equals',         input: 'single'  },
		{ op: '!=',          label: '≠ not equals',      input: 'single'  },
		{ op: '>',           label: '> greater than',    input: 'single'  },
		{ op: '>=',          label: '≥ at least',        input: 'single'  },
		{ op: '<',           label: '< less than',       input: 'single'  },
		{ op: '<=',          label: '≤ at most',         input: 'single'  },
		{ op: 'LIKE',        label: '~ contains',        input: 'single'  },
		{ op: 'IN',          label: '∈ in list',         input: 'list'    },
		{ op: 'BETWEEN',     label: '↔ between',         input: 'between' },
		{ op: 'IS NULL',     label: '∅ is empty',        input: 'none'    },
		{ op: 'IS NOT NULL', label: '¬∅ is not empty',   input: 'none'    },
	];

	// ══════════════════════════════════════════════════════════════════════════
	// SECTION B — AMOUNT EXTRACTION
	// Handles: "$500,000" | "$1.5M" | "$500k" | "one million dollars"
	// Uses Compromise.js doc.money() when available; pure-regex fallback always present.
	// ══════════════════════════════════════════════════════════════════════════

	const MULTIPLIERS = {
		k: 1e3, thousand: 1e3, thousands: 1e3,
		m: 1e6, million: 1e6, millions: 1e6,
		b: 1e9, billion: 1e9, billions: 1e9,
	};

	function extractAmount( text ) {
		if ( ! text ) return null;
		const s = text.replace( /,/g, '' ).trim();

		// "$1.5M", "$500k", "$2B"
		const dollarSuffix = s.match( /\$\s*([\d.]+)\s*(k|m|b|thousand|million|billion)s?\b/i );
		if ( dollarSuffix ) {
			const n    = parseFloat( dollarSuffix[1] );
			const mult = MULTIPLIERS[ dollarSuffix[2].toLowerCase() ] || 1;
			if ( ! isNaN( n ) ) return Math.round( n * mult );
		}

		// "$500,000" or "$1000"
		const dollarPlain = s.match( /\$\s*([\d.]+)/ );
		if ( dollarPlain ) {
			const n = parseFloat( dollarPlain[1].replace( /,/g, '' ) );
			if ( ! isNaN( n ) ) return Math.round( n );
		}

		// "1.5 million", "500 thousand", "2 billion"
		const wordNum = s.match( /([\d.]+)\s*(thousand|million|billion|k|m|b)s?\b/i );
		if ( wordNum ) {
			const n    = parseFloat( wordNum[1] );
			const mult = MULTIPLIERS[ wordNum[2].toLowerCase() ] || 1;
			if ( ! isNaN( n ) ) return Math.round( n * mult );
		}

		// Compromise.js money normalization
		if ( global.nlp ) {
			try {
				const doc   = nlp( text );
				const money = doc.money();
				if ( money.found ) {
					const normed = money.toNumber().text().replace( /[$,\s]/g, '' );
					const n = parseFloat( normed.match( /[\d.]+/ )?.[0] ?? '' );
					if ( ! isNaN( n ) && n > 0 ) return Math.round( n );
				}
				const vals = doc.values();
				if ( vals.found ) {
					const normed = vals.toNumber().text().replace( /[,\s]/g, '' );
					const n = parseFloat( normed.match( /[\d.]+/ )?.[0] ?? '' );
					if ( ! isNaN( n ) && n > 0 ) return Math.round( n );
				}
			} catch ( _ ) { /* nlp unavailable */ }
		}

		// Bare number fallback
		const bare = s.match( /[\d.]+/ );
		if ( bare ) {
			const n = parseFloat( bare[0] );
			if ( ! isNaN( n ) ) return Math.round( n );
		}

		return null;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECTION C — YEAR EXTRACTION
	// fiscal_year in DB is varchar(9) format "YYYY-YYYY"
	// Clamp range: 2001–2030 (metadata: beyond ~2030 are likely artefacts)
	// ══════════════════════════════════════════════════════════════════════════

	function extractYear( text ) {
		if ( ! text ) return null;

		// Fiscal year range: "2022-2023", "2022/23", "FY2022-23", "2022–23"
		const fiscal = text.match( /\b(20\d{2})[\/\-–](20)?(\d{2})\b/ );
		if ( fiscal ) {
			const start = parseInt( fiscal[1], 10 );
			const endYr = parseInt( fiscal[3], 10 );
			const end   = endYr < 100 ? 2000 + endYr : endYr;
			if ( start >= 2001 && start <= 2030 ) return `${start}-${end}`;
		}

		// Single 4-digit year
		const single = text.match( /\b(20\d{2}|19\d{2})\b/ );
		if ( single ) {
			const yr = parseInt( single[1], 10 );
			if ( yr >= 2001 && yr <= 2030 ) return String( yr );
		}

		return null;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECTION D — PROVINCE EXTRACTION
	// Longest-first to prevent "NB" matching inside "new brunswick"
	// ══════════════════════════════════════════════════════════════════════════

	function extractProvince( text ) {
		if ( ! text ) return null;
		const s     = text.toLowerCase();
		const names = Object.keys( PROVINCE_MAP ).sort( ( a, b ) => b.length - a.length );
		for ( const name of names ) {
			const escaped = name.replace( /[-[\]{}()*+?.,\\^$|#]/g, '\\$&' ).replace( /\s+/g, '\\s+' );
			if ( new RegExp( `\\b${escaped}\\b`, 'i' ).test( s ) ) {
				return PROVINCE_MAP[ name ];
			}
		}
		return null;
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECTION D — parseQuery(text)
	// Main NLP router. Returns Intent Blueprint: { intent, parameters, ai_fallback }
	//
	// Extraction pipeline — each step removes matched text from `consumed` so
	// later steps don't double-capture already-resolved tokens.
	// ══════════════════════════════════════════════════════════════════════════

	function parseQuery( text ) {
		if ( ! text || ! text.trim() ) {
			return { intent: 'unstructured_query', parameters: { raw_text: text }, ai_fallback: true };
		}

		const params     = {};
		let   intentName = 'filter_grants';
		let   consumed   = text;

		// Helper: remove a matched string from consumed
		function consume( str ) {
			if ( str ) consumed = consumed.replace( str, ' ' ).replace( /\s{2,}/g, ' ' ).trim();
		}

		const doc = ( global.nlp ) ? nlp( text ) : null;

		// ── 1. Corrections / audit intent ─────────────────────────────────────
		// Minimum 7 trigger phrases. Signals corrections_history table intent.
		const correctionTriggers = [
			/\bwhat\s+(was\s+|has\s+been\s+)?(changed|revised|corrected|updated|modified|altered)\b/i,
			/\b(data\s+)?correction(s)?\b/i,
			/\b(value|amount|funding|dollar)\s+change(s|d)?\b/i,
			/\brevised?\s+(funding|amount|value)\b/i,
			/\bcorrected\s+(amount|value|funding)\b/i,
			/\bchange(s|d)?\s+(to|in)\s+(amount|value|funding)\b/i,
			/\bamendment(s)?\s+to\s+(amount|value|the\s+amount)\b/i,
			/\bwhat\s+changed\b/i,
			/\baudit\s+(trail|log|history)\b/i,
			/\bmodification\s+history\b/i,
		];
		if ( correctionTriggers.some( re => re.test( text ) ) ) {
			intentName           = 'corrections_history';
			params.field_name    = 'agreement_value';
		}

		// ── 2. Agreement type ─────────────────────────────────────────────────
		// 15+ phrase mappings. Longest-first to avoid "grant" inside "grant funding".
		{
			const phrases = Object.keys( AGREEMENT_TYPE_MAP ).sort( ( a, b ) => b.length - a.length );
			for ( const phrase of phrases ) {
				const escaped = phrase.replace( /[-[\]{}()*+?.,\\^$|#]/g, '\\$&' ).replace( /\s+/g, '\\s+' );
				const re      = new RegExp( `\\b${escaped}\\b`, 'i' );
				if ( re.test( text ) ) {
					params.agreement_type = AGREEMENT_TYPE_MAP[ phrase ];
					consume( text.match( re )?.[0] );
					break;
				}
			}
		}

		// ── 3. Recipient type ─────────────────────────────────────────────────
		// 20+ phrase mappings.
		{
			const phrases = Object.keys( RECIPIENT_TYPE_MAP ).sort( ( a, b ) => b.length - a.length );
			for ( const phrase of phrases ) {
				const escaped = phrase.replace( /[-[\]{}()*+?.,\\^$|#]/g, '\\$&' ).replace( /\s+/g, '\\s+' );
				const re      = new RegExp( `\\b${escaped}\\b`, 'i' );
				if ( re.test( consumed ) ) {
					params.recipient_type = RECIPIENT_TYPE_MAP[ phrase ];
					consume( consumed.match( re )?.[0] );
					break;
				}
			}
		}

		// ── 4. Amount: greater-than ───────────────────────────────────────────
		// 15+ patterns
		{
			const gtRe = /(?:over|more\s+than|exceeding|greater\s+than|at\s+least|above|larger\s+than|bigger\s+than|not\s+less\s+than|minimum\s+of?|at\s+minimum|worth\s+more\s+than|valued\s+(?:at|above)|funding\s+over|amount\s+over|value\s+over|no\s+less\s+than)\s+\$?\s*[\d,.]+(?:\s*[kmb]|illion|housand)?/gi;
			const m = gtRe.exec( text );
			if ( m ) {
				const amountText = m[0].replace( /^[a-z\s]+/i, '' );
				const n          = extractAmount( amountText );
				if ( n != null ) { params.value_gt = n; consume( m[0] ); }
			}
		}

		// ── 5. Amount: less-than ─────────────────────────────────────────────
		// 12+ patterns
		{
			const ltRe = /(?:under|less\s+than|below|at\s+most|smaller\s+than|no\s+more\s+than|up\s+to|maximum\s+of?|not\s+exceeding|not\s+more\s+than|capped?\s+at|within)\s+\$?\s*[\d,.]+(?:\s*[kmb]|illion|housand)?/gi;
			const m = ltRe.exec( text );
			if ( m ) {
				const amountText = m[0].replace( /^[a-z\s]+/i, '' );
				const n          = extractAmount( amountText );
				if ( n != null ) { params.value_lt = n; consume( m[0] ); }
			}
		}

		// Standalone "$X" not caught above — treat as an approximate match hint
		if ( params.value_gt == null && params.value_lt == null ) {
			const standaloneAmt = text.match( /\$\s*[\d,.]+(?:\s*[kmb]|illion|housand)?\b/i );
			if ( standaloneAmt ) {
				const n = extractAmount( standaloneAmt[0] );
				if ( n != null ) { params.value_approx = n; }
			}
		}

		// ── 6. Province ───────────────────────────────────────────────────────
		{
			const prov = extractProvince( consumed );
			if ( prov ) {
				params.province = prov;
				// Remove the matched province text from consumed
				const provKey = Object.keys( PROVINCE_MAP ).find(
					k => PROVINCE_MAP[k] === prov && new RegExp( `\\b${k}\\b`, 'i' ).test( consumed )
				);
				if ( provKey ) consume( consumed.match( new RegExp( `\\b${provKey}\\b`, 'i' ) )?.[0] );
			}
		}

		// ── 7. Fiscal year ───────────────────────────────────────────────────
		{
			const yr = extractYear( consumed );
			if ( yr ) {
				// "since/after/from" → start constraint; "before/until/prior to" → end constraint
				if ( /\b(since|after|from|starting|beginning)\b/i.test( consumed ) ) {
					params.start_year = yr;
				} else if ( /\b(before|until|prior\s+to|up\s+to|ending|through)\b/i.test( consumed ) ) {
					params.end_year = yr;
				} else {
					params.fiscal_year = yr;
				}
				// Remove the year from consumed
				const yrEscaped = yr.replace( '-', '[\\-/–]' );
				consumed = consumed.replace( new RegExp( yrEscaped ), '' ).replace( /\s{2,}/g, ' ' ).trim();
			}
		}

		// ── 8. Department / funding organization ─────────────────────────────
		// Check known abbreviations first (ISED, PHAC, etc.)
		{
			const abbrMatch = Object.keys( DEPT_ABBR ).find( abbr =>
				new RegExp( `\\b${abbr}\\b`, 'i' ).test( consumed )
			);
			if ( abbrMatch ) {
				params.department = DEPT_ABBR[ abbrMatch ];
				consume( consumed.match( new RegExp( `\\b${abbrMatch}\\b`, 'i' ) )?.[0] );
			} else {
				// Syntactic patterns: "funded by X", "from X", "through X", etc.
				const deptRe = /(?:funded\s+by|from|through|via|distributed\s+by|administered\s+by|allocated\s+by|issued\s+by|awarded\s+by|department\s+of|ministry\s+of|agency\s+of)\s+([A-Z][A-Za-z\s&',.\-]{3,70}?)(?=\s+(?:grants?|contributions?|for|in|to|over|under|since|before|that|which|and|$))/i;
				const dm = deptRe.exec( consumed );
				if ( dm && dm[1] && dm[1].trim().length > 2 ) {
					params.department = dm[1].trim().replace( /[,.]$/, '' );
					consume( dm[0] );
				} else if ( doc ) {
					// Compromise.js org() as soft hint
					try {
						const orgs = doc.organizations();
						if ( orgs.found ) {
							const orgText = orgs.first().text().trim();
							if ( orgText.length > 4 && ! /\bcanada\b/i.test( orgText ) ) {
								params.department_hint = orgText; // mark as hint, lower confidence
							}
						}
					} catch ( _ ) {}
				}
			}
		}

		// ── 9. Program name ──────────────────────────────────────────────────
		// Patterns: "under [X] program", "through [X] initiative", "[X] fund", etc.
		{
			const progRe = /(?:under\s+the\s+|through\s+the\s+|the\s+)?([A-Z][A-Za-z\s&',.\-]{5,90}?)\s+(?:program|fund|initiative|project|scheme)(?:\s|$)/i;
			const pm = progRe.exec( consumed );
			if ( pm && pm[1] && pm[1].trim().length > 4 ) {
				// Don't double-capture what we already have as department
				const cand = pm[1].trim();
				if ( ! params.department || cand.toLowerCase() !== params.department.toLowerCase() ) {
					params.program = cand;
					consume( pm[0] );
				}
			}
		}

		// ── 10. Fulltext search trigger ─────────────────────────────────────
		// Must run before recipient-name extraction so "related to X" / "about X"
		// phrases aren't captured as recipient names.
		// Routes to description_eng LIKE (fulltext:true columns in metadata)
		{
			const ftRe = /(?:about|related\s+to|involving|mentioning|description\s+(?:includes?|contains?|with)|expected\s+results?\s+(?:includes?|contains?)|keyword\s*:?|search\s+for|containing\s+the\s+word|with\s+the\s+word)\s+(.{3,120})/i;
			const ft = ftRe.exec( consumed );
			if ( ft && ft[1] ) {
				params.fulltext = ft[1].trim().replace( /['"]/g, '' );
				intentName      = 'fulltext_search';
				consume( ft[0] );
			}
		}

		// ── 11. Recipient name ────────────────────────────────────────────────
		// Patterns: "to [Org]", "received by", "awarded to", "given to", "paid to",
		//           "for [Org]", "granted to", "beneficiary", "[Org] received", etc.
		// Lookahead allows entity at end-of-string (no trailing \s+ required before $).
		// Capital-letter check (post-match, without i-flag) ensures we only capture
		// proper nouns — prevents topic phrases like "climate change research" from
		// being mistaken for org names when matched via the "for" prefix.
		{
			const recipRe = /(?:(?:to|received\s+by|awarded\s+to|given\s+to|paid\s+to|for|granted\s+to|recipient\s*:|beneficiary\s*:|donated\s+to|transferred\s+to)\s+)([A-Z][A-Za-z\s&',.\-]{3,120}?)(?=(?:\s+(?:in|from|over|under|for|that|which|and|received|got)|$))/i;
			const rm = recipRe.exec( consumed );
			if ( rm && rm[1] && rm[1].trim().length > 3 ) {
				const cand = rm[1].trim().replace( /[,.]$/, '' );
				// Require genuine proper noun: first char must be uppercase (case-sensitive)
				if ( /^[A-Z]/.test( cand ) ) {
					// Avoid re-capturing department
					const deptLc = ( params.department || '' ).toLowerCase().slice( 0, 8 );
					if ( ! deptLc || ! cand.toLowerCase().startsWith( deptLc ) ) {
						params.recipient = cand;
						consume( rm[0] );
					}
				}
			}
		}

		// ── 12. Federal riding / constituency ────────────────────────────────
		// Patterns: "[X] riding", "constituency of [X]", "electoral district of [X]"
		{
			const ridingRe = /(?:(?:in\s+the\s+|the\s+)?([A-Z][A-Za-z\s\-']{5,80}?)\s+(?:riding|constituency|electoral\s+district|electoral\s+area|federal\s+riding)|(?:riding\s+of|constituency\s+of|electoral\s+district\s+of)\s+([A-Z][A-Za-z\s\-']{3,80}))/i;
			const rding = ridingRe.exec( consumed );
			if ( rding ) {
				params.riding = ( rding[1] || rding[2] ).trim();
				consume( rding[0] );
			}
		}

		// ── 13. City ─────────────────────────────────────────────────────────
		// Only if province hasn't already consumed the location phrase.
		// Lookahead allows city name at end-of-string (no trailing \s+ before $).
		// Capital-letter check (post-match, case-sensitive) ensures we only capture
		// proper noun city names, not lowercase residue like "the fiscal year".
		if ( ! params.province ) {
			const cityRe = /(?:in\s+|city\s+of\s+|located\s+in\s+|based\s+in\s+|recipients?\s+in\s+|organizations?\s+in\s+)([A-Z][A-Za-z\s\-']{2,50}?)(?=(?:\s+(?:that|which|and|,)|$))/i;
			const cm = cityRe.exec( consumed );
			if ( cm && cm[1] ) {
				const cand = cm[1].trim();
				// Require genuine proper noun: first char must be uppercase (case-sensitive)
				// and must not be a province name
				const isProvince = Object.keys( PROVINCE_MAP ).some(
					k => k.length > 2 && k === cand.toLowerCase()
				);
				if ( /^[A-Z]/.test( cand ) && ! isProvince ) {
					params.city = cand;
					consume( cm[0] );
				}
			}
		}

		// ── 14. NAICS / industry code ────────────────────────────────────────
		{
			const naics = consumed.match( /\b(?:naics|naics\s+code|naics\s+identifier)\s*:?\s*(\d{2,6})\b/i );
			if ( naics ) {
				params.naics = naics[1];
				consume( naics[0] );
			}
		}

		// ── 15. Value catalogue entity resolution (when catalogue is loaded) ─
		// Checks PT.NLP.catalogue for exact entity matches → column + value
		if ( PT.NLP.catalogue ) {
			try {
				const words = consumed.replace( /[^A-Za-z0-9\s\-'&,]/g, '' ).trim();
				if ( words.length > 3 ) {
					const entry = PT.NLP.catalogue[ words ] || PT.NLP.catalogue[ words.toLowerCase() ];
					if ( entry && entry.column && entry.value ) {
						params[ entry.column ] = entry.value;
						consume( words );
					}
				}
			} catch ( _ ) {}
		}

		// ── 16. Residual text → recipient_legal_name LIKE (last resort) ──────
		// Only promote if the residual looks like a proper noun (capital-letter start)
		// to prevent fragments like "me everything" or bare words from firing.
		{
			const residual = consumed
				.replace( /\b(show|find|list|get|fetch|give\s+me|display|all|the|some|any|grants?|contributions?|agreements?|funding|payments?|records?|data|where|how|which|what|who|tell\s+me|i\s+want|look\s+for|looking\s+for|me)\b/gi, '' )
				.replace( /[^A-Za-z0-9\s&'\-,\.]/g, '' )
				.replace( /\s{2,}/g, ' ' )
				.trim();
			if ( residual.length > 3 && /^[A-Z]/.test( residual ) && ! params.recipient ) {
				params.recipient_hint = residual; // Mark as hint — lower confidence
			}
		}

		// ── Confidence check ─────────────────────────────────────────────────
		const definite  = [ 'agreement_type', 'recipient_type', 'value_gt', 'value_lt',
		                     'province', 'fiscal_year', 'start_year', 'end_year',
		                     'department', 'program', 'recipient', 'riding', 'city',
		                     'naics', 'fulltext', 'field_name' ];
		const hasMatch  = definite.some( k => params[k] != null );

		if ( ! hasMatch ) {
			// Promote hints to definite if nothing else matched
			if ( params.recipient_hint ) {
				params.recipient = params.recipient_hint;
			} else if ( params.department_hint ) {
				params.department = params.department_hint;
			} else {
				return { intent: 'unstructured_query', parameters: { raw_text: text }, ai_fallback: true };
			}
		}

		// Clean up hints before returning
		delete params.recipient_hint;
		delete params.department_hint;

		return { intent: intentName, parameters: params, ai_fallback: false };
	}

	PT.NLP.parseQuery = parseQuery;

	// ══════════════════════════════════════════════════════════════════════════
	// SECTION E — intentToFilters(intent, params, availableCols)
	// Converts Intent Blueprint parameters to [{col, op, val}] filter spec.
	// Only emits filters for aliases present in availableCols (current table).
	// ══════════════════════════════════════════════════════════════════════════

	function intentToFilters( intent, params, availableCols ) {
		const filters = [];

		function push( col, op, val ) {
			if ( availableCols.has( col ) ) filters.push( { col, op, val } );
		}

		if ( intent === 'corrections_history' ) {
			push( 'field_name',    '=', params.field_name    || 'agreement_value' );
			push( 'change_source', '=', params.change_source || null );
			return filters.filter( f => f.val != null );
		}

		// Money (agreement_value is decimal(15,2) in master_table)
		if ( params.value_gt    != null ) push( 'agreement_value', '>',  String( params.value_gt ) );
		if ( params.value_lt    != null ) push( 'agreement_value', '<',  String( params.value_lt ) );
		// Approximate: treat as a BETWEEN ±20%
		if ( params.value_approx != null && params.value_gt == null && params.value_lt == null ) {
			const lo = Math.round( params.value_approx * 0.8 );
			const hi = Math.round( params.value_approx * 1.2 );
			if ( availableCols.has( 'agreement_value' ) ) {
				filters.push( { col: 'agreement_value', op: 'BETWEEN', val: [ String(lo), String(hi) ] } );
			}
		}

		// agreement_type: char(1) — G, C, O
		if ( params.agreement_type ) push( 'agreement_type', '=', params.agreement_type );

		// recipient_type: char(1) — A, F, G, I, N, O, P, S
		if ( params.recipient_type ) push( 'recipient_type', '=', params.recipient_type );

		// Geography — province is char(2), city is varchar (LIKE)
		if ( params.province ) push( 'recipient_province', '=',    params.province );
		if ( params.city     ) push( 'recipient_city',     'LIKE', `%${params.city}%` );

		// Fiscal year — varchar(9) e.g. "2022-2023"
		if ( params.fiscal_year ) push( 'fiscal_year', '=', params.fiscal_year );

		// Year range via date columns (agreement_start_date / agreement_end_date are DATE)
		if ( params.start_year ) {
			const yr = String( params.start_year ).slice( 0, 4 );
			push( 'agreement_start_date', '>=', `${yr}-01-01` );
		}
		if ( params.end_year ) {
			const yr = String( params.end_year ).slice( 0, 4 );
			push( 'agreement_end_date', '<=', `${yr}-12-31` );
		}

		// Department (owner_org_en is TEXT — LIKE is the only viable operator)
		if ( params.department ) push( 'owner_org_en', 'LIKE', `%${params.department}%` );

		// Program (prog_name_en is varchar(255))
		if ( params.program ) push( 'prog_name_en', 'LIKE', `%${params.program}%` );

		// Recipient (recipient_legal_name is varchar(350))
		if ( params.recipient ) push( 'recipient_legal_name', 'LIKE', `%${params.recipient}%` );

		// Riding (federal_riding_name_en is varchar(100))
		if ( params.riding ) push( 'federal_riding_name_en', 'LIKE', `%${params.riding}%` );

		// NAICS (naics_identifier is int unsigned — exact match)
		if ( params.naics ) push( 'naics_identifier', '=', params.naics );

		// Fulltext → description_eng LIKE
		// (fulltext: true per metadata, but stream uses LIKE; full-text index is future work)
		if ( params.fulltext ) push( 'description_eng', 'LIKE', `%${params.fulltext}%` );

		return filters;
	}

	PT.NLP.intentToFilters = intentToFilters;

	// ══════════════════════════════════════════════════════════════════════════
	// SECTION H — VALUE CATALOGUE LOADER
	// Loads nlp-value-catalogue.json when present (inverted index: entity → column+value).
	// Until present, parseQuery() falls back to decode maps above.
	// ══════════════════════════════════════════════════════════════════════════

	PT.NLP.catalogue = null;

	PT.NLP.loadValueCatalogue = function ( url ) {
		if ( ! url ) return;
		return fetch( url )
			.then( r => r.ok ? r.json() : null )
			.then( cat => { if ( cat ) PT.NLP.catalogue = cat; } )
			.catch( () => {} ); // catalogue is optional
	};

	// Auto-load if boot JSON carries a catalogueUrl
	if ( qbBoot.catalogueUrl ) PT.NLP.loadValueCatalogue( qbBoot.catalogueUrl );

	// ══════════════════════════════════════════════════════════════════════════
	// SECTION F — STRUCTURED QUERY BUILDER UI
	// ══════════════════════════════════════════════════════════════════════════

	// ── State ────────────────────────────────────────────────────────────────
	let columns  = []; // [{ alias, label }] from /schema endpoint
	let lastRows = []; // most recent result set for CSV export

	// ── DOM refs ─────────────────────────────────────────────────────────────
	function $( id ) { return document.getElementById( id ); }

	const elSchema       = $( 'pt-qb-schema' );
	const elTable        = $( 'pt-qb-table' );
	const elLoadCols     = $( 'pt-qb-load-cols' );
	const elAddCond      = $( 'pt-qb-add-condition' );
	const elConditions   = $( 'pt-qb-conditions' );
	const elCondEmpty    = $( 'pt-qb-conditions-empty' );
	const elSortCol      = $( 'pt-qb-sort-col' );
	const elSortDir      = $( 'pt-qb-sort-dir' );
	const elLimit        = $( 'pt-qb-limit' );
	const elRun          = $( 'pt-qb-run' );
	const elClear        = $( 'pt-qb-clear' );
	const elExport       = $( 'pt-qb-export' );
	const elStatus       = $( 'pt-qb-status' );
	const elResultsWrap  = $( 'pt-qb-results-wrap' );
	const elNLInput      = $( 'pt-qb-nl-input' );
	const elNLApply      = $( 'pt-qb-nl-apply' );
	const elNLPreview    = $( 'pt-qb-nl-preview' );

	// ── Helpers ──────────────────────────────────────────────────────────────
	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"]/g, c =>
			( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' } )[c]
		);
	}

	function setStatus( msg, isError ) {
		if ( ! elStatus ) return;
		elStatus.textContent = msg;
		elStatus.style.color = isError ? 'var(--neg)' : 'var(--ink-2)';
	}

	function setNLPreview( msg, isError ) {
		if ( elNLPreview ) {
			elNLPreview.textContent = msg;
			elNLPreview.style.color = isError ? 'var(--neg)' : 'var(--ink-2)';
		}
	}

	function getSortDir() {
		const on = elSortDir && elSortDir.querySelector( 'button.on' );
		return on ? on.dataset.val : 'ASC';
	}

	function inputStyle() {
		return 'background:var(--bg-3);border:1px solid var(--line-2);color:var(--ink-0);padding:7px 9px;border-radius:3px;font:12px "JetBrains Mono",monospace;';
	}

	// ── Column load ──────────────────────────────────────────────────────────
	function loadColumns() {
		if ( ! elSchema || ! elTable ) return;
		const schema = elSchema.value;
		const table  = elTable.value.trim();
		if ( ! schema || ! table ) { setStatus( 'Select a schema and enter a table name.', true ); return; }

		// Fast path: columns inlined into the page (window.PT_QB_COLUMNS) render
		// instantly with no REST round-trip. The server endpoint costs ~0.3s of
		// WP bootstrap and only fires after all page JS loads. Only fall through
		// to fetch() when no inline columns are present (e.g. the standalone
		// query-builder page where the table can be changed by the user).
		if ( Array.isArray( window.PT_QB_COLUMNS ) && window.PT_QB_COLUMNS.length ) {
			columns = window.PT_QB_COLUMNS;
			if ( elLoadCols ) elLoadCols.disabled = false;
			populateSortSelect();
			setStatus( columns.length + ' columns ready.', false );
			if ( elCondEmpty ) elCondEmpty.style.display = 'none';
			return;
		}

		setStatus( 'Loading columns…', false );
		if ( elLoadCols ) elLoadCols.disabled = true;

		fetch( boot.rest + '/schema/' + encodeURIComponent( schema ) + '/' + encodeURIComponent( table ), {
			credentials: 'same-origin',
			headers: { 'x-wp-nonce': boot.nonce },
		} )
			.then( r => {
				if ( ! r.ok ) throw new Error( 'No exposed columns for this table.' );
				return r.json();
			} )
			.then( data => {
				columns = Array.isArray( data.columns ) ? data.columns : [];
				if ( elLoadCols ) elLoadCols.disabled = false;
				if ( ! columns.length ) { setStatus( 'No columns exposed for this table.', true ); return; }
				populateSortSelect();
				setStatus( columns.length + ' columns ready.', false );
				if ( elCondEmpty ) elCondEmpty.style.display = 'none';
			} )
			.catch( e => {
				if ( elLoadCols ) elLoadCols.disabled = false;
				setStatus( e.message || 'Failed to load columns.', true );
			} );
	}

	function populateSortSelect() {
		if ( ! elSortCol ) return;
		elSortCol.innerHTML = '<option value="">— none —</option>';
		columns.forEach( c => {
			const opt = document.createElement( 'option' );
			opt.value       = c.alias;
			opt.textContent = c.label;
			elSortCol.appendChild( opt );
		} );
	}

	// ── Condition rows ────────────────────────────────────────────────────────
	function addConditionRow( prefill ) {
		if ( ! elConditions ) return;
		if ( elCondEmpty ) elCondEmpty.style.display = 'none';

		const row = document.createElement( 'div' );
		row.className = 'pt-qb-cond-row';
		row.style.cssText = 'display:flex;gap:8px;align-items:flex-start;margin-bottom:8px;flex-wrap:wrap';

		// Column select
		const colSel = document.createElement( 'select' );
		colSel.setAttribute( 'aria-label', 'Column' );
		colSel.style.cssText = inputStyle() + 'min-width:180px;flex-shrink:0';
		const blankOpt = document.createElement( 'option' );
		blankOpt.value = '';
		blankOpt.textContent = '— column —';
		colSel.appendChild( blankOpt );
		columns.forEach( c => {
			const opt = document.createElement( 'option' );
			opt.value       = c.alias;
			opt.textContent = c.label;
			colSel.appendChild( opt );
		} );
		if ( prefill && prefill.col ) colSel.value = prefill.col;

		// Operator select
		const opSel = document.createElement( 'select' );
		opSel.setAttribute( 'aria-label', 'Operator' );
		opSel.style.cssText = inputStyle() + 'min-width:148px;flex-shrink:0';
		OPERATORS.forEach( o => {
			const opt = document.createElement( 'option' );
			opt.value       = o.op;
			opt.textContent = o.label;
			opSel.appendChild( opt );
		} );
		if ( prefill && prefill.op ) opSel.value = prefill.op;

		// Value area (re-renders on operator change)
		const valWrap = document.createElement( 'div' );
		valWrap.className = 'pt-qb-val-wrap';
		valWrap.style.cssText = 'display:flex;gap:6px;align-items:center;flex:1;min-width:180px';

		function rebuildVal() {
			valWrap.innerHTML = '';
			const op   = opSel.value;
			const mode = ( OPERATORS.find( o => o.op === op ) || {} ).input || 'single';

			if ( mode === 'none' ) {
				const hint = document.createElement( 'span' );
				hint.className   = 'dim mono';
				hint.style.fontSize = '10px';
				hint.textContent = '(no value required)';
				valWrap.appendChild( hint );

			} else if ( mode === 'between' ) {
				const fromEl = document.createElement( 'input' );
				fromEl.type        = 'text';
				fromEl.placeholder = 'from';
				fromEl.setAttribute( 'aria-label', 'From value' );
				fromEl.style.cssText = inputStyle() + 'flex:1';
				const sep = document.createElement( 'span' );
				sep.className = 'dim';
				sep.style.fontSize = '11px';
				sep.textContent = 'AND';
				const toEl = document.createElement( 'input' );
				toEl.type        = 'text';
				toEl.placeholder = 'to';
				toEl.setAttribute( 'aria-label', 'To value' );
				toEl.style.cssText = inputStyle() + 'flex:1';
				valWrap.appendChild( fromEl );
				valWrap.appendChild( sep );
				valWrap.appendChild( toEl );
				if ( prefill && Array.isArray( prefill.val ) && prefill.val.length === 2 ) {
					fromEl.value = prefill.val[0];
					toEl.value   = prefill.val[1];
				}

			} else if ( mode === 'list' ) {
				const ta = document.createElement( 'textarea' );
				ta.rows        = 2;
				ta.placeholder = 'one value per line or comma-separated';
				ta.setAttribute( 'aria-label', 'List of values' );
				ta.style.cssText = 'flex:1;background:var(--bg-3);border:1px solid var(--line-2);color:var(--ink-0);padding:6px 9px;border-radius:3px;font:11px "JetBrains Mono",monospace;resize:vertical';
				if ( prefill && Array.isArray( prefill.val ) ) ta.value = prefill.val.join( '\n' );
				valWrap.appendChild( ta );

			} else { // single
				const inp = document.createElement( 'input' );
				inp.type        = 'text';
				inp.placeholder = 'value';
				inp.setAttribute( 'aria-label', 'Filter value' );
				inp.style.cssText = inputStyle() + 'flex:1';
				if ( prefill && prefill.val != null && ! Array.isArray( prefill.val ) ) {
					inp.value = String( prefill.val );
				}
				valWrap.appendChild( inp );
			}
			// prefill applied — clear so operator change doesn't re-apply
			prefill = null;
		}
		rebuildVal();
		opSel.addEventListener( 'change', rebuildVal );

		// Remove button
		const rmBtn = document.createElement( 'button' );
		rmBtn.className   = 'btn ghost';
		rmBtn.textContent = '×';
		rmBtn.setAttribute( 'aria-label', 'Remove condition' );
		rmBtn.style.cssText = 'padding:7px 11px;flex-shrink:0;font-size:14px;line-height:1';
		rmBtn.addEventListener( 'click', () => {
			row.remove();
			if ( elConditions && ! elConditions.querySelector( '.pt-qb-cond-row' ) && elCondEmpty ) {
				elCondEmpty.style.display = '';
			}
		} );

		row.appendChild( colSel );
		row.appendChild( opSel );
		row.appendChild( valWrap );
		row.appendChild( rmBtn );
		elConditions.appendChild( row );
	}

	// ── Build filter array from condition rows ────────────────────────────────
	function buildFilters() {
		if ( ! elConditions ) return [];
		const filters = [];
		elConditions.querySelectorAll( '.pt-qb-cond-row' ).forEach( row => {
			const col = row.querySelector( 'select:first-child' ).value;
			const op  = row.querySelector( 'select:nth-child(2)' ).value;
			if ( ! col ) return;

			const mode = ( OPERATORS.find( o => o.op === op ) || {} ).input || 'single';
			let val;

			if ( mode === 'none' ) {
				val = null;
			} else if ( mode === 'between' ) {
				const inputs = row.querySelectorAll( '.pt-qb-val-wrap input' );
				const from   = ( inputs[0] || {} ).value || '';
				const to     = ( inputs[1] || {} ).value || '';
				if ( ! from || ! to ) return; // skip incomplete BETWEEN
				val = [ from, to ];
			} else if ( mode === 'list' ) {
				const raw = ( row.querySelector( 'textarea' ) || {} ).value || '';
				val = raw.split( /[\n,]+/ ).map( v => v.trim() ).filter( Boolean );
				if ( ! val.length ) return;
			} else {
				val = ( row.querySelector( 'input' ) || {} ).value || '';
				if ( ! val ) return;
				if ( op === 'LIKE' && ! val.includes( '%' ) ) val = `%${val}%`;
			}

			filters.push( { col, op, val } );
		} );
		return filters;
	}

	// ── Run query ────────────────────────────────────────────────────────────
	function runQuery() {
		if ( ! elSchema || ! elTable ) return;
		const schema = elSchema.value;
		const table  = elTable.value.trim();
		if ( ! schema || ! table ) { setStatus( 'Select schema and table first.', true ); return; }

		const filters = buildFilters();
		const sortCol = elSortCol ? elSortCol.value : '';
		const order   = sortCol ? `${sortCol} ${getSortDir()}` : '';
		const limit   = Math.min( 1000, Math.max( 1, parseInt( ( elLimit && elLimit.value ) || '100', 10 ) ) );

		const url = new URL( boot.rest + '/stream/' + encodeURIComponent( schema ) + '/' + encodeURIComponent( table ) );
		if ( filters.length ) url.searchParams.set( 'filters', JSON.stringify( filters ) );
		if ( order )          url.searchParams.set( 'order', order );
		url.searchParams.set( 'limit', String( limit ) );

		setStatus( 'Querying…', false );
		if ( elRun )    elRun.disabled   = true;
		if ( elExport ) elExport.disabled = true;
		if ( elResultsWrap ) {
			elResultsWrap.innerHTML = '<div class="pt-loading" style="padding:20px 0 8px">Streaming…</div>';
		}

		fetch( url.toString(), {
			credentials: 'same-origin',
			headers: { 'x-wp-nonce': boot.nonce },
		} )
			.then( r => {
				if ( r.status === 429 ) throw new Error( ( boot.i18n && boot.i18n.rateLimit ) || 'Rate limit reached.' );
				if ( r.status === 403 ) throw new Error( ( boot.i18n && boot.i18n.tierLocked ) || 'Access denied.' );
				if ( ! r.ok ) throw new Error( `Query failed (${r.status}).` );
				return r.json();
			} )
			.then( rows => {
				if ( elRun ) elRun.disabled = false;
				lastRows = Array.isArray( rows ) ? rows : [];
				renderResultsTable( lastRows );
				setStatus( `${lastRows.length} row${lastRows.length === 1 ? '' : 's'} returned.`, false );
				if ( elExport ) elExport.disabled = lastRows.length === 0;
				// Notify data-explorer.js (and any other listener) that results are ready.
				document.dispatchEvent( new CustomEvent( 'pt:qb:results', {
					detail: {
						rows    : lastRows,
						schema  : elSchema ? elSchema.value : '',
						table   : elTable  ? elTable.value.trim() : '',
						filters : filters,
						limit   : limit,
					},
				} ) );
			} )
			.catch( e => {
				if ( elRun ) elRun.disabled = false;
				if ( elResultsWrap ) {
					const d = document.createElement( 'div' );
					d.className   = 'pt-error';
					d.textContent = ( e && e.message ) ? String( e.message ) : 'Query failed.';
					elResultsWrap.innerHTML = '';
					elResultsWrap.appendChild( d );
				}
				setStatus( 'Query failed.', true );
			} );
	}

	// ── Render results table ─────────────────────────────────────────────────
	function renderResultsTable( rows ) {
		if ( ! elResultsWrap ) return;
		elResultsWrap.innerHTML = '';

		if ( ! rows.length ) {
			const d = document.createElement( 'div' );
			d.className   = 'dim mono';
			d.style.cssText = 'font-size:11px;padding:20px 0';
			d.textContent = 'No rows matched.';
			elResultsWrap.appendChild( d );
			return;
		}

		const colKeys  = Object.keys( rows[0] );
		const labelMap = {};
		columns.forEach( c => { labelMap[ c.alias ] = c.label; } );

		// Detect numeric columns (all non-null values parse as finite numbers)
		const isNum = {};
		colKeys.forEach( k => {
			isNum[k] = rows.every( r => r[k] == null || ( r[k] !== '' && isFinite( Number( r[k] ) ) ) );
		} );

		const table  = document.createElement( 'table' );
		table.className = 'stream';
		table.style.cssText = 'width:100%';

		const thead = document.createElement( 'thead' );
		const htr   = document.createElement( 'tr' );
		colKeys.forEach( k => {
			const th = document.createElement( 'th' );
			th.textContent = labelMap[k] || k.replace( /_/g, ' ' ).replace( /\b\w/g, c => c.toUpperCase() );
			if ( isNum[k] ) th.style.textAlign = 'right';
			htr.appendChild( th );
		} );
		thead.appendChild( htr );
		table.appendChild( thead );

		const tbody = document.createElement( 'tbody' );
		rows.forEach( row => {
			const tr = document.createElement( 'tr' );
			colKeys.forEach( k => {
				const td = document.createElement( 'td' );
				if ( isNum[k] ) {
					td.className   = 'num';
					const n        = Number( row[k] );
					td.textContent = isNaN( n ) ? '' : n.toLocaleString( undefined, { maximumFractionDigits: 2 } );
				} else {
					td.textContent = row[k] == null ? '' : String( row[k] );
				}
				tr.appendChild( td );
			} );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		elResultsWrap.appendChild( table );

		const foot = document.createElement( 'div' );
		foot.className = 'stream-foot';
		foot.innerHTML = `Returned <b>${esc( String( rows.length ) )}</b> row${rows.length === 1 ? '' : 's'}`;
		elResultsWrap.appendChild( foot );
	}

	// ── CSV export ────────────────────────────────────────────────────────────
	function exportCSV() {
		if ( ! lastRows.length ) return;
		const colKeys  = Object.keys( lastRows[0] );
		const labelMap = {};
		columns.forEach( c => { labelMap[ c.alias ] = c.label; } );

		function csvField( v ) {
			const s = v == null ? '' : String( v );
			if ( s.includes( ',' ) || s.includes( '"' ) || s.includes( '\n' ) ) {
				return '"' + s.replace( /"/g, '""' ) + '"';
			}
			return s;
		}

		const header = colKeys.map( k => csvField( labelMap[k] || k ) ).join( ',' );
		const lines  = lastRows.map( row => colKeys.map( k => csvField( row[k] ) ).join( ',' ) );
		const csv    = '﻿' + [ header, ...lines ].join( '\r\n' );

		const blob   = new Blob( [ csv ], { type: 'text/csv;charset=utf-8;' } );
		const objUrl = URL.createObjectURL( blob );
		const a      = document.createElement( 'a' );
		const schema = elSchema ? elSchema.value : 'data';
		const table  = elTable  ? elTable.value.trim() : 'export';
		const ts     = new Date().toISOString().slice( 0, 10 );
		a.href       = objUrl;
		a.download   = [ schema, table, ts ].join( '-' ) + '.csv';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( objUrl );
	}

	// ── Clear all ─────────────────────────────────────────────────────────────
	function clearAll() {
		if ( elConditions ) {
			elConditions.querySelectorAll( '.pt-qb-cond-row' ).forEach( r => r.remove() );
		}
		if ( elCondEmpty ) elCondEmpty.style.display = '';
		if ( elSortCol )  elSortCol.selectedIndex = 0;
		if ( elSortDir ) {
			elSortDir.querySelectorAll( 'button' ).forEach( b => b.classList.toggle( 'on', b.dataset.val === 'ASC' ) );
		}
		if ( elLimit )  elLimit.value = '100';
		if ( elResultsWrap ) elResultsWrap.innerHTML = '';
		lastRows = [];
		if ( elExport ) elExport.disabled = true;
		setStatus( '', false );
		if ( elNLInput )   elNLInput.value   = '';
		setNLPreview( '', false );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// SECTION G — applyNL()
	// Orchestrates NLP parse → filter injection → AI fallback route
	// ══════════════════════════════════════════════════════════════════════════

	async function applyNL() {
		const text = elNLInput ? elNLInput.value.trim() : '';
		if ( ! text ) return false;

		if ( ! columns.length ) {
			setNLPreview( 'Load columns before using NL Quick Search.', true );
			return false;
		}

		if ( elNLApply ) elNLApply.disabled = true;
		setNLPreview( 'Parsing…', false );

		const blueprint = parseQuery( text );

		if ( ! blueprint.ai_fallback ) {
			// ── Deterministic path — no server round-trip ────────────────────
			const availableCols = new Set( columns.map( c => c.alias ) );
			const filters       = intentToFilters( blueprint.intent, blueprint.parameters, availableCols );

			if ( elNLApply ) elNLApply.disabled = false;

			if ( ! filters.length ) {
				setNLPreview( 'Parsed intent "' + blueprint.intent + '" had no matching columns for this table.', true );
				return false;
			}

			filters.forEach( f => addConditionRow( { col: f.col, op: f.op, val: f.val } ) );
			const summary = filters.map( f => `${f.col} ${f.op}` ).join( ', ' );
			setNLPreview( `${filters.length} condition${filters.length === 1 ? '' : 's'} added — ${summary}` );
			return true;
		}

		// ── AI fallback path — POST to PHP endpoint ──────────────────────────
		setNLPreview( 'Pattern not recognized — trying AI fallback…', false );

		try {
			const payload = {
				...blueprint,
				schema: elSchema ? elSchema.value : '',
				table : elTable  ? elTable.value.trim() : '',
			};
			const resp = await fetch( boot.rest + '/nlp/query', {
				method     : 'POST',
				credentials: 'same-origin',
				headers    : { 'Content-Type': 'application/json', 'x-wp-nonce': boot.nonce },
				body       : JSON.stringify( payload ),
			} );
			const data = await resp.json();

			if ( elNLApply ) elNLApply.disabled = false;

			if ( data.filters && Array.isArray( data.filters ) && data.filters.length ) {
				data.filters.forEach( f => addConditionRow( { col: f.col, op: f.op, val: f.val } ) );
				setNLPreview( `AI (${data.source || 'ai'}) added ${data.filters.length} condition${data.filters.length === 1 ? '' : 's'}.` );
				return true;
			} else {
				setNLPreview( data.message || 'AI layer returned no filters.', true );
				return false;
			}
		} catch ( e ) {
			if ( elNLApply ) elNLApply.disabled = false;
			setNLPreview( 'AI fallback unavailable: ' + ( e.message || 'network error' ), true );
			return false;
		}
	}

	// ── Event wiring ─────────────────────────────────────────────────────────
	document.addEventListener( 'DOMContentLoaded', function () {
		// Segmented ASC/DESC buttons
		if ( elSortDir ) {
			elSortDir.querySelectorAll( 'button' ).forEach( b => {
				b.addEventListener( 'click', () => {
					elSortDir.querySelectorAll( 'button' ).forEach( x => x.classList.remove( 'on' ) );
					b.classList.add( 'on' );
				} );
			} );
		}

		if ( elLoadCols ) elLoadCols.addEventListener( 'click', loadColumns );
		if ( elAddCond  ) elAddCond.addEventListener( 'click', () => {
			if ( ! columns.length ) { setStatus( 'Load columns first.', true ); return; }
			addConditionRow();
		} );
		if ( elRun    ) elRun.addEventListener(    'click', runQuery );
		if ( elClear  ) elClear.addEventListener(  'click', clearAll );
		if ( elExport ) elExport.addEventListener( 'click', exportCSV );
		if ( elNLApply ) elNLApply.addEventListener( 'click', applyNL );
		if ( elNLInput  ) {
			elNLInput.addEventListener( 'keydown', e => {
				if ( e.key === 'Enter' ) { e.preventDefault(); applyNL(); }
			} );
		}

		// Auto-load columns if schema+table pre-set from boot JSON
		if ( qbBoot.schema && qbBoot.table && elSchema && elTable ) {
			elSchema.value = qbBoot.schema;
			elTable.value  = qbBoot.table;
			loadColumns();
		}
	} );

	// ── Public API ────────────────────────────────────────────────────────────
	PT.QueryBuilder = { loadColumns, addConditionRow, buildFilters, runQuery, clearAll, exportCSV, applyNL };

})(window);
