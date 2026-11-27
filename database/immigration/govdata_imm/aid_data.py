"""Canada's international assistance in Canadian dollars, from Global Affairs Canada's own publications.

  Historical Project Data Set (HPDS)  one file per fiscal year, 2005-06 onward. Two layouts:
      'cida' 2005-06 .. 2015-16  CIDA/DFATD only; one row per project x country x sector, "Amount spent"
      'wog'  2016-17 onward      every federal department and agency; one row per project x recipient x sector,
                                 "International Assistance" (ODA and other official flows, flagged)
  Statistical Report on International Assistance, by country (SRIA)  2000-01 .. 2016-17: disbursements by source
      (department / program) and recipient, every federal department, plus the sources.csv / recipient.csv lookups.

The list of files is read from the publishers on every run (the GAC data page for HPDS, the open.canada.ca package
for SRIA), so a new fiscal year is picked up without a code change. A file is reloaded only when its bytes change
(sha256); every download is archived. A file whose header is not one of the known layouts stops that file only.
"""

import csv
import gzip
import hashlib
import io
import json
import re
from pathlib import Path

from .countries import _country_lookup
from .country import norm
from .parl import get, _now

HPDS_PAGE = 'https://international.canada.ca/en/global-affairs/corporate/reports/international-assistance-data'
HPDS_BASE = 'https://international.canada.ca'
SRIA_PACKAGE = 'https://open.canada.ca/data/api/3/action/package_show?id=460ff7f1-b326-43b7-9394-0cbb24117d83'
MIRROR = ('https://www.international.gc.ca/department-ministere/assets/office_docs/',
          'https://international.canada.ca/international-canada/assets/office_docs/international-assistance-data/')


# Bump when a parser changes: the stored fingerprint covers the bytes AND the parser, so every file reloads once.
PARSER_VERSION = b'aid-parsers-3'


OLD_CODES = {'KV': 'XK'}           # Kosovo before ISO's user-assigned XK


# Department names GAC spells differently from year to year (reviewed 2026-11-27); applied at parse time.
DEPT_NAMES = {
    'Department of Finance Canada': 'Finance Canada',
    'Department of National Defence': 'National Defence',
    'Immigration, Refugees and Citizenships Canada': 'Immigration, Refugees and Citizenship Canada',
    'Cost of refugees in Canada (1st year) - Immigration, Refugees and Citizenship Canada': 'Immigration, Refugees and Citizenship Canada',
    'Employment and Social Development Canada (ESDC) - Labour Program': 'Employment and Social Development Canada - Labour Program',
    'EDC': 'Export Development Canada',
    'British-Co': 'British Columbia', 'British-Columbia': 'British Columbia', 'New-Brunswick': 'New Brunswick',
    'Nova-Scotia': 'Nova Scotia',
    'CSC-Correc': 'Correctional Service Canada',
    'PSC': 'Public Service Commission of Canada',
    'Transport': 'Transport Canada',
    'Justice': 'Justice Canada',
    'CIHR': 'Canadian Institutes of Health Research',
    'Canada Post - Universal Postal Union': 'Canada Post',
}


class LayoutError(Exception):
    pass


def _text(data: bytes) -> str:
    for enc in ('utf-8-sig', 'cp1252'):
        try:
            return data.decode(enc)
        except UnicodeDecodeError:
            continue
    return data.decode('cp1252', errors='replace')


def _num(s) -> float:
    s = (s or '').strip().replace(',', '').replace('$', '')
    if s in ('', '-', '—'):
        return 0.0
    if s.startswith('(') and s.endswith(')'):
        s = '-' + s[1:-1]
    try:
        return float(s)
    except ValueError:
        return 0.0


def _pct(s):
    s = (s or '').strip().replace('%', '')
    if not s:
        return None
    try:
        v = float(s)
    except ValueError:
        return None
    return v / 100 if v > 1.0 else v


def _fetch(url: str, ua: str) -> bytes:
    """GAC serves the same files from two hosts; the older one is often slow."""
    try:
        return get(url, ua, timeout=300)
    except Exception:  # noqa: BLE001
        for a, b in (MIRROR, MIRROR[::-1]):
            if url.startswith(a):
                return get(b + url[len(a):], ua, timeout=300)
        raise


def _archive(raw_dir: Path, key: str, data: bytes, sha: str) -> str:
    d = raw_dir / 'aid'
    d.mkdir(parents=True, exist_ok=True)
    path = d / f"{key.replace(':', '_')}-{sha[:12]}.csv.gz"
    if not path.exists():
        with gzip.open(path, 'wb') as f:
            f.write(data)
    return str(path)


def _changed(cur, key: str, sha: str) -> bool:
    cur.execute('SELECT sha256 FROM aid_files WHERE file_key=%s', (key,))
    r = cur.fetchone()
    return not r or r[0] != sha


def _record(cur, key, kind, fy, url, sha, rows, total, path):
    cur.execute('INSERT INTO aid_files (file_key, kind, fy_start, url, sha256, row_count, amount_total, archive_path, fetched_at) '
                'VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE kind=VALUES(kind), fy_start=VALUES(fy_start), '
                'url=VALUES(url), sha256=VALUES(sha256), row_count=VALUES(row_count), amount_total=VALUES(amount_total), '
                'archive_path=VALUES(archive_path), fetched_at=VALUES(fetched_at)', (key, kind, fy, url, sha, rows, total, path, _now()))


# ── HPDS ────────────────────────────────────────────────────────────────────

HPDS_COLS = ('fy_start', 'layout', 'department', 'reporting_l1', 'project_number', 'project_title', 'status', 'aid_type',
             'aid_type_name', 'bilat_multi', 'flow_code', 'flow_name', 'finance_name', 'iae', 'org_name', 'org_level1',
             'org_level2', 'org_type', 'org_class', 'channel_code', 'sector_code', 'sector_name', 'sector_l2', 'humanitarian',
             'recipient_code', 'recipient_name', 'recipient_kind', 'continent', 'country_id', 'alloc_pct', 'gender_marker',
             'climate_mitigation', 'climate_adaptation', 'project_url', 'amount_cad', 'reflows_cad')


def _hnorm(s: str) -> str:
    """Header text for matching: lower case, single spaces, no spaces around '-' ('Agencies-EN' = 'Agencies - EN')."""
    s = re.sub(r'\s+', ' ', s.strip().lower())
    return re.sub(r'\s*-\s*', '-', s)


def _col(header, *needles, required=True):
    """Index of the first column whose name contains every needle (case-insensitive)."""
    for i, h in enumerate(header):
        hl = _hnorm(h)
        if all(_hnorm(n) in hl for n in needles):
            return i
    if required:
        raise LayoutError(f'no column matching {needles}')
    return None


def _rows(data: bytes) -> list[list[str]]:
    """CSV rows; or the first sheet of a workbook, which GAC has published under a .csv name (2022-23)."""
    if data[:2] == b'PK':
        return _xlsx_rows(data)
    return list(csv.reader(io.StringIO(_text(data), newline='')))     # some years quote line breaks inside fields


def _xlsx_rows(data: bytes) -> list[list[str]]:
    import zipfile
    import xml.etree.ElementTree as ET
    ns = {'m': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
    rel = '{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id'
    z = zipfile.ZipFile(io.BytesIO(data))
    shared = []
    if 'xl/sharedStrings.xml' in z.namelist():
        for si in ET.fromstring(z.read('xl/sharedStrings.xml')).findall('m:si', ns):
            shared.append(''.join(t.text or '' for t in si.iter('{%s}t' % ns['m'])))
    wb = ET.fromstring(z.read('xl/workbook.xml'))
    first = wb.find('m:sheets/m:sheet', ns).get(rel)
    rels = ET.fromstring(z.read('xl/_rels/workbook.xml.rels'))
    target = next(r.get('Target') for r in rels if r.get('Id') == first).lstrip('/')
    path = target if target.startswith('xl/') else 'xl/' + target
    out = []
    for _, row in ET.iterparse(io.BytesIO(z.read(path))):
        if row.tag != '{%s}row' % ns['m']:
            continue
        cells = {}
        for c in row.findall('m:c', ns):
            ref = re.match(r'[A-Z]+', c.get('r', 'A'))
            col = 0
            for ch in ref.group(0):
                col = col * 26 + ord(ch) - 64
            t, v = c.get('t'), c.find('m:v', ns)
            if t == 's' and v is not None:
                val = shared[int(v.text)]
            elif t == 'inlineStr':
                val = ''.join(x.text or '' for x in c.iter('{%s}t' % ns['m']))
            else:
                val = v.text if v is not None and v.text is not None else ''
            cells[col - 1] = val
        out.append([cells.get(i, '') for i in range(max(cells) + 1)] if cells else [])
        row.clear()
    return out


def _parse_hpds(rows: list[list[str]], fy: int, look: dict, iso: dict) -> list[tuple]:
    h = rows[0]
    out = []
    if any('departments and agencies' in c.lower() for c in h):            # whole-of-government layout
        c = {k: _col(h, *n, required=req) for k, n, req in [
            ('dept', ('departments and agencies - en',), True), ('rl1', ('reporting level 1 - en',), False),
            ('pn', ('project number',), True), ('pt', ('project title - en',), False), ('at', ('type of aid/',), False),
            ('atn', ('type of aid description-en',), False), ('bm', ('bilat/multi',), False), ('fc', ('type of flow/',), False),
            ('fn', ('type of flow name - en',), False), ('fin', ('type of finance name - en',), False),
            ('iae', ('international assistance envelope',), False), ('org', ('recipient organization - en',), False),
            ('ol1', ('org level 1 - en',), False), ('ol2', ('org level 2 - en',), False), ('ot', ('organization type-en',), False),
            ('oc', ('organization class-en',), False), ('ch', ('channel of delivery code',), False), ('sc', ('sector code/',), False),
            ('sn', ('sector name-en',), False), ('s2', ('sector name lvl2 - en',), False), ('hum', ('humanitarian assistance',), False),
            ('rc', ('recipient code',), True), ('rn', ('recipient name-en',), True), ('cr', ('country or region',), False),
            ('cont', ('continent - en',), False), ('al', ('sector&country allocation',), False),
            ('gm', ('gender equality (oecd)',), False), ('cm', ('climate change - mitigation',), False),
            ('ca', ('climate change - adaptation',), False), ('url', ('project browser url',), False),
            ('ref', ('reflows',), False)]}
        # The amount column is 'International Assistance' (2017-18: '... (Disbursements)'); not the Envelope flag.
        c['amt'] = next((i for i, x in enumerate(h) if _hnorm(x).startswith('international assistance')
                         and 'envelope' not in _hnorm(x)), None)
        if c['amt'] is None:
            raise LayoutError('no International Assistance amount column')
        g = lambda r, k: (r[c[k]].strip() if c[k] is not None and c[k] < len(r) else '')  # noqa: E731
        for r in rows[1:]:
            if not r or not any(x.strip() for x in r):
                continue
            if not g(r, 'pn') and not g(r, 'dept'):
                continue                                   # a total line (rows with no project number are real)
            code, kind = g(r, 'rc'), (g(r, 'cr') or '')[:1]
            if not kind:                                   # 2016-17 has no country/region column
                kind = 'C' if code in iso else 'R'
            cid = iso.get(code) if kind == 'C' else None
            if kind == 'C' and cid is None:
                cid = look.get(norm(g(r, 'rn')))
                cid = None if cid == 'CA' else cid
            out.append((fy, 'wog', DEPT_NAMES.get(g(r, 'dept'), g(r, 'dept'))[:200], g(r, 'rl1')[:200] or None, g(r, 'pn')[:60], g(r, 'pt')[:600] or None, None,
                        g(r, 'at')[:10] or None, g(r, 'atn')[:200] or None, g(r, 'bm')[:10] or None, g(r, 'fc')[:10] or None,
                        g(r, 'fn')[:120] or None, g(r, 'fin')[:120] or None, 1 if g(r, 'iae') == '1' else 0 if g(r, 'iae') == '0' else None,
                        g(r, 'org')[:300] or None, g(r, 'ol1')[:100] or None, g(r, 'ol2')[:100] or None, g(r, 'ot')[:120] or None,
                        g(r, 'oc')[:100] or None, g(r, 'ch')[:20] or None, g(r, 'sc')[:10] or None, g(r, 'sn')[:200] or None,
                        g(r, 's2')[:200] or None, 1 if g(r, 'hum') == '1' else 0, code[:20] or None, g(r, 'rn')[:200] or None,
                        kind or None, g(r, 'cont')[:80] or None, cid, _pct(g(r, 'al')), g(r, 'gm')[:10] or None,
                        g(r, 'cm')[:10] or None, g(r, 'ca')[:10] or None, g(r, 'url')[:400] or None,
                        round(_num(g(r, 'amt')), 2), round(_num(g(r, 'ref')), 2)))
        return out
    if any('maximum cida contribution' in c.lower() for c in h):           # CIDA layout
        c = {k: _col(h, *n, required=req) for k, n, req in [
            ('pn', ('project number',), True), ('st', ('status',), False), ('br', ('branch name',), False),
            ('at', ('type of aid id',), False), ('atn', ('type of aid',), False), ('org', ('organisation name',), False),
            ('ot', ('organisation type',), False), ('oc', ('organisation class',), False), ('cont', ('continent name',), False),
            ('cc', ('project browser country id',), False), ('cn', ('country/region name',), True),
            ('cp', ('country/region percent',), False), ('si', ('sector id',), False), ('sn', ('sector name',), False),
            ('gm', ('gender equality',), False), ('cm', ('climate change mitigation',), False),
            ('ca', ('climate change adaptation',), False), ('amt', ('amount spent',), True)]}
        # 'type of aid' also matches 'type of aid id'; take the name column that is not the id column.
        c['atn'] = next((i for i, x in enumerate(h) if x.strip().lower() == 'type of aid'), c['atn'])
        g = lambda r, k: (r[c[k]].strip() if c[k] is not None and c[k] < len(r) else '')  # noqa: E731
        for r in rows[1:]:
            if not r or not any(x.strip() for x in r):
                continue
            if not g(r, 'pn') and not g(r, 'cn'):
                continue                                   # the total line at the end of 2013-14
            name = g(r, 'cn')
            cid = look.get(norm(name))
            cid = None if cid == 'CA' else cid
            out.append((fy, 'cida', 'Global Affairs Canada (CIDA)', g(r, 'br')[:200] or None, g(r, 'pn')[:60], None,
                        g(r, 'st')[:40] or None, g(r, 'at')[:10] or None, g(r, 'atn')[:200] or None, None, None, None, None, None,
                        g(r, 'org')[:300] or None, None, None, g(r, 'ot')[:120] or None, g(r, 'oc')[:100] or None, None,
                        g(r, 'si')[:10] or None, g(r, 'sn')[:200] or None, None, 0, g(r, 'cc')[:20] or None, name[:200] or None,
                        'C' if cid else 'R', g(r, 'cont')[:80] or None, cid, _pct(g(r, 'cp')), g(r, 'gm')[:10] or None,
                        g(r, 'cm')[:10] or None, g(r, 'ca')[:10] or None, None, round(_num(g(r, 'amt')), 2), 0.0))
        return out
    raise LayoutError('unknown HPDS header: ' + ','.join(h[:6]))


def hpds_files(ua: str) -> list[tuple[int, str]]:
    html = _text(get(HPDS_PAGE, ua, timeout=120))
    found = {}
    for href in re.findall(r'href="([^"]*hpds-(\d{4})-(\d{4})[^"]*\.csv)"', html, re.I):
        url, y0, _ = href
        if '-fra' in url.lower() or '-fr.' in url.lower():
            continue
        found.setdefault(int(y0), url if url.startswith('http') else HPDS_BASE + url)
    if len(found) < 15:
        raise RuntimeError(f'GAC data page listed only {len(found)} HPDS files; not trusting it')
    return sorted(found.items())


def load_hpds(conn, ua: str, raw_dir: Path) -> dict:
    cur = conn.cursor()
    look = _country_lookup(cur)
    cur.execute('SELECT iso2, country_id FROM imm_country_iso WHERE iso2 IS NOT NULL')
    iso = dict(cur.fetchall())
    loaded, unchanged, failed, unmatched = [], 0, {}, {}
    for fy, url in hpds_files(ua):
        key = f'hpds:{fy}'
        try:
            data = _fetch(url, ua)
            sha = hashlib.sha256(data + PARSER_VERSION).hexdigest()
            if not _changed(cur, key, sha):
                unchanged += 1
                continue
            rows = _parse_hpds(_rows(data), fy, look, iso)
            if not rows:
                raise LayoutError('no rows')
            path = _archive(raw_dir, key, data, sha)
            cur.execute('DELETE FROM aid_hpds WHERE fy_start=%s', (fy,))
            for i in range(0, len(rows), 2000):
                cur.executemany(f"INSERT INTO aid_hpds ({','.join(HPDS_COLS)}) VALUES ({','.join(['%s'] * len(HPDS_COLS))})",
                                rows[i:i + 2000])
            total = round(sum(r[-2] for r in rows), 2)
            _record(cur, key, 'hpds', fy, url, sha, len(rows), total, path)
            conn.commit()
            loaded.append(f'{fy}:{len(rows)}')
            for r in rows:
                if r[26] == 'C' and r[28] is None:
                    unmatched[r[25]] = unmatched.get(r[25], 0) + 1
        except Exception as e:  # noqa: BLE001 -- one bad file does not stop the others
            conn.rollback()
            failed[key] = f'{type(e).__name__}: {e}'[:200]
    if failed:
        raise RuntimeError(json.dumps({'loaded': loaded, 'failed': failed}))
    return {'loaded': loaded, 'unchanged': unchanged, 'unmatched_country_names': unmatched}


# ── SRIA by country ─────────────────────────────────────────────────────────

def load_sria(conn, ua: str, raw_dir: Path) -> dict:
    cur = conn.cursor()
    pkg = json.loads(get(SRIA_PACKAGE, ua, timeout=120))['result']['resources']
    urls = [r['url'] for r in pkg if r.get('url', '').lower().endswith('.csv')]
    look = _country_lookup(cur)
    cur.execute('SELECT iso2, country_id FROM imm_country_iso WHERE iso2 IS NOT NULL')
    iso = dict(cur.fetchall())
    # Lookups first: departments/programs and recipients.
    for url in urls:
        name = url.rsplit('/', 1)[-1].lower()
        if name not in ('sources.csv', 'recipient.csv'):
            continue
        rows = list(csv.DictReader(io.StringIO(_text(_fetch(url, ua)))))
        if name == 'sources.csv':
            cur.executemany('REPLACE INTO aid_sria_sources (source_code, name, department, program_group, channel) VALUES (%s,%s,%s,%s,%s)',
                            [(r['Source_Code'].strip(), r['Source_Name_En'][:300], (r.get('Source_Level1_Name_En') or '')[:200] or None,
                              (r.get('Source_Level2_Name_En') or '')[:200] or None, (r.get('Channel_Name_En') or '')[:120] or None)
                             for r in rows if r.get('Source_Code')])
        else:
            out = []
            for r in rows:
                code = (r.get('Recipient_Code') or '').strip()
                if not code:
                    continue
                by_country = 'country' in (r.get('Allocation_Type_EN') or '').lower()
                cid = iso.get(code) if by_country else None
                if by_country and cid is None:
                    cid = look.get(norm(r.get('Recipient_Name_EN') or ''))
                    cid = None if cid == 'CA' else cid
                out.append((code, (r.get('Recipient_Name_EN') or '')[:200], (r.get('Stats Report Continent Name EN') or '')[:120] or None,
                            'C' if by_country else 'R', cid))
            cur.executemany('REPLACE INTO aid_sria_recipients (recipient_code, name, continent, kind, country_id) VALUES (%s,%s,%s,%s,%s)', out)
    conn.commit()
    cur.execute('SELECT recipient_code, country_id FROM aid_sria_recipients')
    rec = dict(cur.fetchall())
    loaded, unchanged, failed, unknown = [], 0, {}, set()
    for url in urls:
        m = re.search(r'(\d{4})-(\d{4})', url)
        if not m or 'stacked' not in url.lower() and not re.search(r'\d{4}-\d{4}a\.csv$', url.lower()):
            continue
        fy = int(m.group(1))
        key = f'sria:{fy}'
        try:
            data = _fetch(url, ua)
            sha = hashlib.sha256(data + PARSER_VERSION).hexdigest()
            if not _changed(cur, key, sha):
                unchanged += 1
                continue
            rows = list(csv.reader(io.StringIO(_text(data))))
            h = [x.strip().lower() for x in rows[0]]
            iy, is_, ia = _col(h, 'year'), _col(h, 'source'), _col(h, 'disburs')
            ir = _col(h, 'recipient', required=False)
            if ir is None and h[2:3] == ['#n/a'] and is_ == 1 and ia == 3:
                ir = 2                       # 2014-15 file: Excel left "#N/A" as the recipient column's name
            if ir is None:
                raise LayoutError('no recipient column: ' + ','.join(h))
            out = []
            for r in rows[1:]:
                if len(r) <= max(iy, is_, ir, ia) or not r[ir].strip():
                    continue
                code = r[ir].strip()
                cid = rec.get(code)
                if code not in rec:
                    cid = iso.get(OLD_CODES.get(code, code))    # an ISO code the lookup table predates
                    if cid is None:
                        unknown.add(code)
                out.append((fy, r[is_].strip()[:20], code[:20], round(_num(r[ia]), 2), cid))
            if not out:
                raise LayoutError('no rows')
            path = _archive(raw_dir, key, data, sha)
            cur.execute('DELETE FROM aid_sria WHERE fy_start=%s', (fy,))
            cur.executemany('INSERT INTO aid_sria (fy_start, source_code, recipient_code, amount_cad, country_id) VALUES (%s,%s,%s,%s,%s)', out)
            _record(cur, key, 'sria', fy, url, sha, len(out), round(sum(x[3] for x in out), 2), path)
            conn.commit()
            loaded.append(f'{fy}:{len(out)}')
        except Exception as e:  # noqa: BLE001
            conn.rollback()
            failed[key] = f'{type(e).__name__}: {e}'[:200]
    if failed:
        raise RuntimeError(json.dumps({'loaded': loaded, 'failed': failed}))
    return {'loaded': loaded, 'unchanged': unchanged, 'unknown_recipient_codes': sorted(unknown)[:30]}
