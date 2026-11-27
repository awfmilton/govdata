"""Sources for the country pages: ISO codes (reviewed CSV), Canada's aid by recipient (OECD DAC2a), where members of
Parliament and senators were born (Wikidata), and the laws and House bills whose title names a country.

Each loader is idempotent and replaces only what it owns, so the daily run can repeat it.
"""

import csv
import io
import json
import re
import unicodedata
import urllib.parse
import xml.etree.ElementTree as ET
from datetime import date

from .config import HERE
from .parl import get, _now

ISO_CSV = HERE / 'country_iso.csv'
TERMS_CSV = HERE / 'country_terms.csv'

OECD_URL = ('https://sdmx.oecd.org/public/rest/data/OECD.DCD.FSD,DSD_DAC2@DF_DAC2A,/CAN..{measures}.USD.V+Q'
            '?startPeriod=1960&format=csvfilewithlabels')
# 206 ODA net disbursements, 240 gross ODA, 201 grants, 216 humanitarian aid, 106 imputed multilateral ODA.
OECD_MEASURES = ['206', '240', '201', '216', '106']

WIKIDATA_URL = 'https://query.wikidata.org/sparql'
# Member of the House of Commons of Canada (Q15964890), member of the Senate of Canada (Q18524027).
SPARQL = """
SELECT ?person ?personLabel ?lop ?birth ?pob ?pobLabel ?cty ?ctyLabel ?iso2 ?pos ?start ?end WHERE {
  VALUES ?pos { wd:Q15964890 wd:Q18524027 }
  ?person p:P39 ?st . ?st ps:P39 ?pos .
  OPTIONAL { ?st pq:P580 ?start } OPTIONAL { ?st pq:P582 ?end }
  OPTIONAL { ?person wdt:P6616 ?lop }
  OPTIONAL { ?person wdt:P569 ?birth }
  OPTIONAL { ?person wdt:P19 ?pob . OPTIONAL { ?pob wdt:P17 ?cty . OPTIONAL { ?cty wdt:P297 ?iso2 } } }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }
}"""
MP_POS, SENATE_POS = 'Q15964890', 'Q18524027'
# Birth countries Wikidata gives only as a historical state, mapped to today's country where that is unambiguous.
HISTORICAL_BIRTH = {'Kingdom of Great Britain': 'GB', 'United Kingdom of Great Britain and Ireland': 'GB',
                    'Kingdom of England': 'GB', 'Kingdom of Scotland': 'GB', 'Kingdom of Ireland': 'IE',
                    'Kingdom of Prussia': 'DE', 'German Reich': 'DE', 'German Empire': 'DE', 'Weimar Republic': 'DE',
                    'Nazi Germany': 'DE', 'West Germany': 'DE', 'Kingdom of Italy': 'IT', 'Russian Empire': 'RU',
                    'Empire of Japan': 'JP', 'Kingdom of Hungary': 'HU', 'Republic of China (1912–1949)': 'CN',
                    'Colony of Jamaica': 'JM', 'British Hong Kong': 'HK', 'British Raj': 'IN',
                    'Dominion of Newfoundland': 'CA', 'Newfoundland Colony': 'CA', 'Province of Canada': 'CA',
                    'Netherlands': 'NL', 'Canada East': 'CA', 'Canada West': 'CA', 'Upper Canada': 'CA', 'Lower Canada': 'CA',
                    'Colony of Vancouver Island': 'CA', 'Colony of British Columbia': 'CA', 'Nova Scotia': 'CA',
                    'Colony of Prince Edward Island': 'CA', 'Province of Quebec': 'CA', 'Colony of New Brunswick': 'CA'}

SANCTIONS = re.compile(r'special economic measures|united nations|sanction|freezing assets|corrupt foreign|'
                       r'restrictive measures|export and import permits|arms', re.I)
TRADE = re.compile(r'free trade|trade agreement|economic partnership|investment|tariff|customs|trade and', re.I)
TAX = re.compile(r'tax convention|income tax|taxation|tax agreement|tax information|tax arrangement', re.I)
SOCIAL = re.compile(r'social security', re.I)
IMMIG = re.compile(r'permanent resident|immigra|refugee|travel documents|citizenship', re.I)
MILITARY = re.compile(r'canadian forces|visiting forces|active service|service aircraft', re.I)


def slugify(name: str) -> str:
    s = unicodedata.normalize('NFKD', name).encode('ascii', 'ignore').decode().lower()
    return re.sub(r'[^a-z0-9]+', '-', s).strip('-')


# ── ISO codes ───────────────────────────────────────────────────────────────

def apply_iso(cur) -> dict:
    cur.execute("SELECT country_id, name_en FROM imm_country WHERE kind = 'country'")
    by_name = {n: cid for cid, n in cur.fetchall()}
    rows, missing = [], []
    with open(ISO_CSV, encoding='utf-8', newline='') as f:
        for r in csv.DictReader(f):
            cid = by_name.pop(r['name_en'], None)
            if cid is None:
                missing.append(r['name_en'])
                continue
            rows.append((cid, r['iso2'] or None, r['iso3'] or None, r['oecd_code'] or None, r['display_name'],
                         slugify(r['display_name']), r['notes'] or None))
    if missing:
        raise RuntimeError(f'country_iso.csv names not in imm_country: {missing}')
    cur.execute('DELETE FROM imm_country_iso')
    cur.executemany('INSERT INTO imm_country_iso (country_id, iso2, iso3, oecd_code, display_name, slug, notes) '
                    'VALUES (%s,%s,%s,%s,%s,%s,%s)', rows)
    # A country added to imm_country since the CSV was reviewed has no page until it is reviewed; say so.
    return {'countries': len(rows), 'not_in_csv': sorted(by_name)}


# ── OECD DAC2a ──────────────────────────────────────────────────────────────

def load_oda(cur, ua: str) -> dict:
    data = get(OECD_URL.format(measures='+'.join(OECD_MEASURES)), ua, timeout=300)
    cur.execute('SELECT oecd_code, country_id FROM imm_country_iso WHERE oecd_code IS NOT NULL')
    by_code = dict(cur.fetchall())
    rows, unmatched = [], set()
    for r in csv.DictReader(io.StringIO(data.decode('utf-8-sig'))):
        if r['DONOR'] != 'CAN' or r['MEASURE'] not in OECD_MEASURES:
            continue
        code = r['RECIPIENT']
        cid = by_code.get(code)
        if cid is None and len(code) == 3 and code.isalpha():
            unmatched.add(f"{code} {r['Recipient']}")
        val = r['OBS_VALUE']
        rows.append((code, r['MEASURE'], r['PRICE_BASE'], int(r['TIME_PERIOD']), r['Recipient'][:200], r['Measure'][:200],
                     cid, float(val) * 10 ** (int(r['UNIT_MULT'] or 6) - 6) if val not in ('', None) else None,
                     int(r['BASE_PER']) if r.get('BASE_PER') else None, _now()))
    if len(rows) < 10000:
        raise RuntimeError(f'OECD returned only {len(rows)} rows; keeping the previous load')
    cur.execute('DELETE FROM cty_oda')
    cur.executemany('INSERT INTO cty_oda (recipient_code, measure, price_base, year, recipient_label, measure_label, '
                    'country_id, usd_millions, base_year, fetched_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)', rows)
    return {'rows': len(rows), 'unmatched_country_codes': sorted(unmatched)}


# ── birthplaces (Wikidata) ──────────────────────────────────────────────────

def _qid(uri: str) -> str:
    return uri.rsplit('/', 1)[-1] if uri else ''


def _d(v: str):
    if not v or v.startswith('-') or v.startswith('http'):
        return None
    try:
        return date.fromisoformat(v[:10])
    except ValueError:
        return None


def _name_key(name: str) -> str:
    s = unicodedata.normalize('NFKD', name).encode('ascii', 'ignore').decode().lower()
    s = re.sub(r'\b(hon|the|right|sir|dr|jr|sr|q\.?c|p\.?c)\b\.?', ' ', s)
    s = re.sub(r'\([^)]*\)', ' ', s)
    parts = re.sub(r'[^a-z ]+', ' ', s).split()
    return f'{parts[0]} {parts[-1]}' if len(parts) >= 2 else ' '.join(parts)


def load_births(cur, ua: str) -> dict:
    url = WIKIDATA_URL + '?' + urllib.parse.urlencode({'query': SPARQL})
    data = get(url, ua, timeout=300, accept='text/csv')
    people: dict[str, dict] = {}
    for r in csv.DictReader(io.StringIO(data.decode('utf-8'))):
        q = _qid(r['person'])
        p = people.setdefault(q, {'name': r['personLabel'], 'lop': r['lop'] or None, 'birth': _d(r['birth']),
                                  'pob': r['pobLabel'] or None, 'pob_q': _qid(r['pob']) or None,
                                  'countries': {}, 'terms': set()})
        if r['ctyLabel']:
            p['countries'][r['ctyLabel']] = r['iso2'] or p['countries'].get(r['ctyLabel']) or ''
        role = 'mp' if _qid(r['pos']) == MP_POS else 'senator'
        p['terms'].add((role, r['start'][:10] if _d(r['start']) else '', r['end'][:10] if _d(r['end']) else ''))
    if len(people) < 4000:
        raise RuntimeError(f'Wikidata returned only {len(people)} parliamentarians; keeping the previous load')

    cur.execute('SELECT iso2, country_id FROM imm_country_iso WHERE iso2 IS NOT NULL')
    by_iso = dict(cur.fetchall())
    # House PersonIds from the division rolls (2004 onward), keyed by "first last".
    cur.execute('SELECT person_id, first_name, last_name, MIN(d.held_at), MAX(d.held_at) FROM parl_division_votes v '
                'JOIN parl_divisions d USING (parliament, session, division_no) GROUP BY person_id, first_name, last_name')
    roll: dict[str, list] = {}
    for pid, fn, ln, d0, d1 in cur.fetchall():
        roll.setdefault(_name_key(f'{fn} {ln}'), []).append((pid, d0.date(), d1.date()))

    rows, unresolved, matched = [], {}, 0
    for q, p in people.items():
        iso = next((i for i in p['countries'].values() if i), '')
        label = next((c for c, i in p['countries'].items() if i == iso), None) if iso else None
        if not iso:
            for c in p['countries']:
                if c in HISTORICAL_BIRTH:
                    iso, label = HISTORICAL_BIRTH[c], c
                    break
        if not label and p['countries']:
            label = sorted(p['countries'])[0]
            unresolved[label] = unresolved.get(label, 0) + 1
        terms = sorted(p['terms'], key=lambda t: (t[1] or '9999', t[0]))
        mp = [t for t in terms if t[0] == 'mp']
        sen = [t for t in terms if t[0] == 'senator']
        first = lambda ts: min((t[1] for t in ts if t[1]), default=None)  # noqa: E731
        last = lambda ts: max((t[2] for t in ts if t[2]), default=None)   # noqa: E731
        vote_pid, how = None, None
        if mp:
            cands = roll.get(_name_key(p['name']), [])
            mp_from = first(mp) or '1800-01-01'
            mp_to = '9999-12-31' if any(not t[2] for t in mp) else (last(mp) or '9999-12-31')
            cands = [c for c in cands if str(c[1]) <= mp_to and str(c[2]) >= mp_from]
            if len(cands) == 1:
                vote_pid, how = cands[0][0], 'name+tenure'
                matched += 1
            elif len(cands) > 1:
                how = f'ambiguous:{len(cands)}'
        rows.append((q, p['name'][:200], p['lop'], p['birth'], (p['pob'] or '')[:200] or None, p['pob_q'],
                     (label or '')[:200] or None, iso or None, by_iso.get(iso) if iso and iso != 'CA' else None,
                     int(bool(mp)), int(bool(sen)), first(mp), last(mp), first(sen), last(sen),
                     json.dumps([list(t) for t in terms]), vote_pid, how, _now()))
    cur.execute('DELETE FROM parl_person_birth')
    cur.executemany('INSERT INTO parl_person_birth (wikidata_qid, name, lop_person_id, birth_date, birth_place, '
                    'birth_place_qid, birth_country_label, birth_iso2, country_id, was_mp, was_senator, first_mp_start, '
                    'last_mp_end, first_senate_start, last_senate_end, terms_json, vote_person_id, vote_match, fetched_at) '
                    'VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)', rows)
    born_abroad = sum(1 for r in rows if r[7] and r[7] != 'CA')
    no_place = sum(1 for r in rows if not r[6])
    return {'people': len(rows), 'born_outside_canada': born_abroad, 'no_birth_country': no_place,
            'vote_roll_matched': matched, 'unresolved_birth_countries': unresolved}


# ── laws and bills that name a country ──────────────────────────────────────

def _terms(cur) -> list[tuple[re.Pattern, int, str]]:
    """Title terms per country: the display name, the short form of the canonical name, and reviewed extras
    (country_terms.csv: term, display_name or '-' to block a term, optional exclude regex)."""
    cur.execute('SELECT i.country_id, i.display_name, c.name_en FROM imm_country_iso i JOIN imm_country c USING (country_id)')
    by_display, terms = {}, {}
    for cid, disp, name in cur.fetchall():
        by_display[disp] = cid
        for t in {disp, re.sub(r',.*$', '', name).replace('The ', '').strip()}:
            if len(t) >= 4:
                terms[t] = (cid, None)
    with open(TERMS_CSV, encoding='utf-8', newline='') as f:
        for r in csv.DictReader(f):
            if r['country'] == '-':
                terms.pop(r['term'], None)
                continue
            terms[r['term']] = (by_display[r['country']], r.get('exclude') or None)
    out = []
    for t in sorted(terms, key=len, reverse=True):          # longest first: "South Sudan" before "Sudan"
        cid, excl = terms[t]
        out.append((re.compile(r'(?<![\w-])' + re.escape(t) + r'(?![\w])', re.I), cid, t, re.compile(excl, re.I) if excl else None))
    return out


def _match(title: str, terms) -> list[tuple[int, str]]:
    found, spans = [], []
    for pat, cid, term, excl in terms:
        for m in pat.finditer(title):
            if any(a < m.end() and m.start() < b for a, b in spans):
                continue                                     # inside a longer term already matched
            if excl and excl.search(title):
                continue
            spans.append((m.start(), m.end()))
            if cid not in [c for c, _ in found]:
                found.append((cid, term))
    return found


def _topic(title: str) -> str:
    if IMMIG.search(title):
        return 'immigration'
    if SOCIAL.search(title):
        return 'social security'
    if MILITARY.search(title):
        return 'military'
    if SANCTIONS.search(title):
        return 'sanctions'
    if TRADE.search(title):
        return 'trade'
    if TAX.search(title):
        return 'tax'
    return 'other'


def tag_country_laws(cur, ua: str) -> dict:
    terms = _terms(cur)
    catalogue = ET.fromstring(get('https://laws-lois.justice.gc.ca/eng/XML/Legis.xml', ua, timeout=300))
    rows = []
    for tag, kind in (('Act', 'act'), ('Regulation', 'regulation')):
        for el in catalogue.iter(tag):
            if el.findtext('Language') != 'eng':
                continue
            title = (el.findtext('Title') or '').strip()
            uid = el.findtext('UniqueId')
            for cid, term in _match(title, terms):
                rows.append((kind, uid, cid, title[:1000], term, _topic(title), el.findtext('OfficialNumber'),
                             el.findtext('CurrentToDate') or None,
                             (el.findtext('LinkToHTMLToC') or '').replace('http://', 'https://')[:400], _now()))
    n_laws = len(rows)
    cur.execute('SELECT legisinfo_id, parliament, session, number_code, COALESCE(NULLIF(short_title_en, ""), long_title_en), '
                'long_title_en FROM parl_bills')
    for bid, parl_no, sess, num, short, long_ in cur.fetchall():
        title = short or long_ or ''
        seen = set()
        for text in (short or '', long_ or ''):
            for cid, term in _match(text, terms):
                if cid in seen:
                    continue
                seen.add(cid)
                rows.append(('bill', str(bid), cid, title[:1000], term, _topic(f'{short} {long_}'), num, None,
                             f'https://www.parl.ca/legisinfo/en/bill/{parl_no}-{sess}/{num.lower()}', _now()))
    # Reviewed rows keep their status across runs; everything auto is rebuilt.
    cur.execute("DELETE FROM cty_law_tags WHERE review_status = 'auto'")
    cur.executemany('INSERT IGNORE INTO cty_law_tags (ref_kind, ref_id, country_id, title, matched_term, topic, '
                    'official_number, current_to, url, tagged_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)', rows)
    return {'law_tags': n_laws, 'bill_tags': len(rows) - n_laws}
