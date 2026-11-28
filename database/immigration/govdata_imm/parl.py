"""House of Commons divisions and per-member votes, LEGISinfo bills, and Justice Laws amendment history.

Official publishers only: ourcommons.ca vote XML (38th Parliament, 2004, onward), LEGISinfo JSON (35th, 1994,
onward), laws-lois.justice.gc.ca XML. Each call is idempotent (upsert / replace per unit), so a weekly run that
re-reads the current session costs nothing when nothing moved.
"""

import hashlib
import http.client
import json
import re
import time
import urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime, timezone

# Sessions with published LEGISinfo records. Votes XML exists from 38-1. The current session and the two that could
# follow it are probed on every run, so a new session is picked up without editing this list.
KNOWN_SESSIONS = [(35, 1), (35, 2), (36, 1), (36, 2), (37, 1), (37, 2), (37, 3), (38, 1), (39, 1), (39, 2), (40, 1),
                  (40, 2), (40, 3), (41, 1), (41, 2), (42, 1), (43, 1), (43, 2), (44, 1), (45, 1)]
FIRST_VOTES_SESSION = (38, 1)

# Acts whose amendment history (and whose regulations' history) defines "immigration law" for tagging.
IMMIGRATION_ACTS = ['I-2.5', 'C-29']          # Immigration and Refugee Protection Act; Citizenship Act
SPONSOR_PAT = re.compile(r'citizenship and immigration|immigration, refugees and citizenship', re.I)
TITLE_PAT = re.compile(r'\b(immigra\w*|refugee\w*|asylum|citizenship|deport\w*|permanent resident\w*)', re.I)
LIMS = '{http://justice.gc.ca/lims}'

POLITE_DELAY = 0.35


def _now():
    return datetime.now(timezone.utc).replace(tzinfo=None)


def get(url: str, ua: str, timeout: int = 120, tries: int = 4, accept: str = '*/*') -> bytes:
    """GET with retries. The House site redirects its vote list (302), which urllib follows."""
    for attempt in range(tries):
        try:
            req = urllib.request.Request(url, headers={'User-Agent': ua, 'Accept': accept})
            with urllib.request.urlopen(req, timeout=timeout) as r:
                return r.read()
        except (OSError, http.client.HTTPException):   # URLError, timeouts, and RemoteDisconnected (Justice Laws)
            if attempt == tries - 1:
                raise
            time.sleep(3 * (attempt + 1))
    raise RuntimeError('unreachable')


def _dt(v):
    if not v:
        return None
    return datetime.fromisoformat(str(v)[:19])


def _int(v):
    try:
        return int(v)
    except (TypeError, ValueError):
        return None


def sessions(cur) -> list[tuple[int, int]]:
    cur.execute('SELECT MAX(parliament * 100 + session) FROM parl_bills')
    hi = cur.fetchone()[0]
    known = set(KNOWN_SESSIONS)
    p, s = divmod(hi, 100) if hi else KNOWN_SESSIONS[-1]
    known |= {(p, s), (p, s + 1), (p + 1, 1)}
    return sorted(known)


# ── bills ───────────────────────────────────────────────────────────────────

BILL_COLS = ('legisinfo_id', 'parliament', 'session', 'number_code', 'is_government', 'long_title_en', 'short_title_en',
             'status_en', 'sponsor_person_id', 'sponsor_name', 'sponsor_title_en', 'originating_chamber',
             'house_1r', 'house_2r', 'house_3r', 'senate_1r', 'senate_2r', 'senate_3r', 'royal_assent',
             'statute_year', 'statute_chapter', 'latest_event_at', 'summary_en', 'raw_json', 'fetched_at')


def _bill_row(b: dict) -> tuple:
    return (b['Id'], b['ParliamentNumber'], b['SessionNumber'], b['NumberCode'], 1 if b.get('IsGovernmentBill') else 0,
            b.get('LongTitleEn'), b.get('ShortTitleEn') or None, b.get('StatusNameEn'), _int(b.get('SponsorPersonId')),
            b.get('SponsorPersonName'), b.get('SponsorAffiliationTitleEn'), b.get('OriginatingChamberNameEn'),
            _dt(b.get('PassedHouseFirstReadingDateTime')), _dt(b.get('PassedHouseSecondReadingDateTime')),
            _dt(b.get('PassedHouseThirdReadingDateTime')), _dt(b.get('PassedSenateFirstReadingDateTime')),
            _dt(b.get('PassedSenateSecondReadingDateTime')), _dt(b.get('PassedSenateThirdReadingDateTime')),
            _dt(b.get('ReceivedRoyalAssentDateTime')), _int(b.get('StatuteYear')) or None,
            _int(b.get('StatuteChapter')) or None, _dt(b.get('LatestBillEventDateTime')),
            b.get('ShortLegislativeSummaryEn'), json.dumps(b, ensure_ascii=False), _now())


def load_bills(cur, ua: str, which: list[tuple[int, int]]) -> dict:
    cols = ', '.join(BILL_COLS)
    upd = ', '.join(f'{c} = VALUES({c})' for c in BILL_COLS if c != 'legisinfo_id')
    n = 0
    for p, s in which:
        data = json.loads(get(f'https://www.parl.ca/legisinfo/en/bills/json?parlsession={p}-{s}', ua))
        rows = [_bill_row(b) for b in data if b.get('ParliamentNumber') == p and b.get('SessionNumber') == s]
        if rows:
            cur.executemany(f'INSERT INTO parl_bills ({cols}) VALUES ({", ".join(["%s"] * len(BILL_COLS))}) '
                            f'ON DUPLICATE KEY UPDATE {upd}', rows)
            n += len(rows)
        time.sleep(POLITE_DELAY)
    return {'bills': n}


# ── divisions ───────────────────────────────────────────────────────────────

def _txt(el, tag):
    v = el.findtext(tag)
    return v.strip() if v and v.strip() else None


def load_divisions(conn, ua: str, which: list[tuple[int, int]], refetch_last: int = 0) -> dict:
    """Upsert every division of each session, then fetch per-member ballots for divisions without them (and the
    last `refetch_last` of the newest session, in case a record was corrected the next morning). Commits per
    division so a long first run can resume."""
    new_divs = ballots = 0
    for p, s in which:
        if (p, s) < FIRST_VOTES_SESSION:
            continue
        # An unknown session silently returns the CURRENT session's list, so only matching records are accepted. The
        # site has also served that fallback for REAL sessions (41-1, 42-1, 43-1 on the 2026-11-26 back-load, which
        # then loaded nothing for them and said nothing): a known session that comes back as another one is retried,
        # then fails loudly. Only the speculative probes for a session that may not exist yet stay quiet.
        for attempt in range(4):
            root = ET.fromstring(get(f'https://www.ourcommons.ca/Members/en/votes/xml?parlSession={p}-{s}', ua))
            published = root.findall('Vote')
            votes = [v for v in published if _int(_txt(v, 'ParliamentNumber')) == p and _int(_txt(v, 'SessionNumber')) == s]
            if votes or not published or (p, s) not in KNOWN_SESSIONS:
                break
            time.sleep(20 * (attempt + 1))
        else:
            raise RuntimeError(f'{p}-{s}: the House returned {len(published)} divisions of another session four times '
                               '(its fallback for an unknown session); refusing to treat this session as empty')
        with conn.cursor() as cur:
            for v in votes:
                n = cur.execute(
                    'INSERT INTO parl_divisions (parliament, session, division_no, held_at, subject, result, yeas, nays, '
                    'paired, doc_type, bill_number, legisinfo_id, fetched_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'
                    '(SELECT legisinfo_id FROM parl_bills b WHERE b.parliament=%s AND b.session=%s AND b.number_code=%s LIMIT 1),%s) '
                    'ON DUPLICATE KEY UPDATE held_at=VALUES(held_at), subject=VALUES(subject), result=VALUES(result), '
                    'yeas=VALUES(yeas), nays=VALUES(nays), paired=VALUES(paired), doc_type=VALUES(doc_type), '
                    'bill_number=VALUES(bill_number), legisinfo_id=VALUES(legisinfo_id), fetched_at=VALUES(fetched_at)',
                    (p, s, _int(_txt(v, 'DecisionDivisionNumber')), _dt(_txt(v, 'DecisionEventDateTime')),
                     _txt(v, 'DecisionDivisionSubject') or '', _txt(v, 'DecisionResultName') or '',
                     _int(_txt(v, 'DecisionDivisionNumberOfYeas')) or 0, _int(_txt(v, 'DecisionDivisionNumberOfNays')) or 0,
                     _int(_txt(v, 'DecisionDivisionNumberOfPaired')) or 0, _txt(v, 'DecisionDivisionDocumentTypeName'),
                     _txt(v, 'BillNumberCode'), p, s, _txt(v, 'BillNumberCode'), _now()))
                new_divs += n == 1
            cur.execute('SELECT division_no FROM parl_divisions WHERE parliament=%s AND session=%s AND ballots IS NULL '
                        'ORDER BY division_no', (p, s))
            todo = [r[0] for r in cur.fetchall()]
            if refetch_last and (p, s) == which[-1]:
                cur.execute('SELECT division_no FROM parl_divisions WHERE parliament=%s AND session=%s '
                            'ORDER BY division_no DESC LIMIT %s', (p, s, refetch_last))
                todo = sorted(set(todo) | {r[0] for r in cur.fetchall()})
        conn.commit()
        for d in todo:
            vroot = ET.fromstring(get(f'https://www.ourcommons.ca/Members/en/votes/{p}/{s}/{d}/xml', ua))
            rows = []
            for vp in vroot.findall('VoteParticipant'):
                if _int(_txt(vp, 'DecisionDivisionNumber')) != d:
                    continue
                # Kept exactly as published. The record is not always clean: 39-1 #166 (2007) lists one member as
                # 'Yea/Nay' with IsVoteYea and IsVoteNay both true. The flags say what the House recorded.
                val = _txt(vp, 'VoteValueName') or ''
                flags = [1 if (_txt(vp, f) or '').lower() == 'true' else 0 for f in ('IsVoteYea', 'IsVoteNay', 'IsVotePaired')]
                rows.append((p, s, d, _int(_txt(vp, 'PersonId')), val, *flags, _txt(vp, 'CaucusShortName'),
                             _txt(vp, 'ConstituencyName'), _txt(vp, 'ConstituencyProvinceTerritoryName'),
                             _txt(vp, 'PersonOfficialFirstName'), _txt(vp, 'PersonOfficialLastName')))
            with conn.cursor() as cur:
                cur.execute('DELETE FROM parl_division_votes WHERE parliament=%s AND session=%s AND division_no=%s', (p, s, d))
                if rows:
                    cur.executemany('INSERT INTO parl_division_votes (parliament, session, division_no, person_id, vote, '
                                    'is_yea, is_nay, is_paired, caucus, constituency, province, first_name, last_name) '
                                    'VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)', rows)
                cur.execute('UPDATE parl_divisions SET ballots=%s WHERE parliament=%s AND session=%s AND division_no=%s',
                            (len(rows), p, s, d))
            conn.commit()
            ballots += len(rows)
            time.sleep(POLITE_DELAY)
    return {'new_divisions': new_divs, 'ballots_loaded': ballots}


# ── laws ────────────────────────────────────────────────────────────────────

_CIT = re.compile(r'(?:(\d{4}),\s*)?c\.\s*(\d+)(?:,\s*((?:ss?|Sch)\.?\s*[^;,]*?(?:,\s*\d[\w.()]*)*))?(?=,\s*(?:\d{4},\s*)?c\.|$)')


def parse_citations(text: str) -> list[tuple[str, int | None, int | None, str | None]]:
    """'2001, c. 27, s. 2; 2012, c. 17, s. 2, c. 19, s. 700' -> [(citation, year, chapter, sections), ...].
    A chapter without a year inherits the previous year. Non-statute amendments (SOR/2004-167) keep year/chapter None."""
    out = []
    for seg in [x.strip() for x in text.split(';') if x.strip()]:
        year = None
        found = False
        for m in _CIT.finditer(seg):
            year = int(m.group(1)) if m.group(1) else year
            if year is None:
                continue
            found = True
            cite = f'{year}, c. {m.group(2)}' + (f', {m.group(3).strip()}' if m.group(3) else '')
            out.append((cite, year, int(m.group(2)), (m.group(3) or '').strip() or None))
        if not found:
            out.append((seg[:300], None, None, None))
    return out


def _instrument_amendments(xml_bytes: bytes, uid: str) -> list[tuple]:
    root = ET.fromstring(xml_bytes)
    parent = {c: p for p in root.iter() for c in p}
    rows = []
    for note in root.iter('HistoricalNote'):
        sec, node = None, note
        while node is not None and sec is None:
            node = parent.get(node)
            if node is not None and node.tag in ('Section', 'Schedule', 'Provision'):
                sec = node
        label = (sec.findtext('Label') or '').strip() if sec is not None else None
        inforce = sec.get(f'{LIMS}inforce-start-date') if sec is not None else None
        for item in note.iter('HistoricalNoteSubItem'):
            text = ''.join(item.itertext()).strip()
            for cite, y, ch, secs in parse_citations(text):
                rows.append((uid, label or None, cite, y, ch, secs, inforce))
    return rows


def load_laws(cur, ua: str) -> dict:
    catalogue = ET.fromstring(get('https://laws-lois.justice.gc.ca/eng/XML/Legis.xml', ua))
    acts = {a.findtext('UniqueId'): a for a in catalogue.iter('Act') if a.findtext('Language') == 'eng'}
    regs_by_id = {r.get('id'): r for r in catalogue.iter('Regulation') if r.findtext('Language') == 'eng'}
    wanted = []
    for uid in IMMIGRATION_ACTS:
        a = acts[uid]
        wanted.append(('act', uid, a, None))
        for ref in a.iter('Reg'):
            r = regs_by_id.get(ref.get('idRef'))
            if r is not None:
                wanted.append(('regulation', r.findtext('UniqueId'), r, uid))
    changed = rows_total = 0
    for kind, uid, el, enabling in wanted:
        url = (el.findtext('LinkToXML') or '').replace('http://', 'https://')
        data = get(url, ua)
        sha = hashlib.sha256(data).hexdigest()
        cur.execute('SELECT xml_sha256 FROM law_instruments WHERE unique_id=%s', (uid,))
        prev = cur.fetchone()
        root = ET.fromstring(data)
        cur.execute('INSERT INTO law_instruments (unique_id, kind, title, official_number, enabling_act, current_to, '
                    'last_amended, xml_url, xml_sha256, fetched_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) '
                    'ON DUPLICATE KEY UPDATE title=VALUES(title), official_number=VALUES(official_number), '
                    'enabling_act=VALUES(enabling_act), current_to=VALUES(current_to), last_amended=VALUES(last_amended), '
                    'xml_url=VALUES(xml_url), xml_sha256=VALUES(xml_sha256), fetched_at=VALUES(fetched_at)',
                    (uid, kind, el.findtext('Title') or uid, el.findtext('OfficialNumber'), enabling,
                     el.findtext('CurrentToDate'), root.get(f'{LIMS}lastAmendedDate'), url, sha, _now()))
        if prev and prev[0] == sha:
            continue
        rows = _instrument_amendments(data, uid)
        cur.execute('DELETE FROM law_amendments WHERE unique_id=%s', (uid,))
        cur.executemany('INSERT INTO law_amendments (unique_id, provision, citation, statute_year, statute_chapter, '
                        'sections, inforce_start) VALUES (%s,%s,%s,%s,%s,%s,%s)', rows)
        changed += 1
        rows_total += len(rows)
        time.sleep(POLITE_DELAY)
    return {'instruments': len(wanted), 'changed': changed, 'amendment_rows': rows_total}


# ── tagging ─────────────────────────────────────────────────────────────────

def tag_bills(cur) -> dict:
    """Rules, each recorded as a reason: the bill became an Act that amended an immigration instrument; it was
    sponsored by the immigration minister; its title names immigration. Reviewed rows keep their status."""
    reasons: dict[int, set] = {}
    cur.execute('SELECT DISTINCT b.legisinfo_id, i.unique_id, COALESCE(i.enabling_act, i.unique_id) FROM parl_bills b '
                'JOIN law_amendments a ON a.statute_year = b.statute_year AND a.statute_chapter = b.statute_chapter '
                'JOIN law_instruments i ON i.unique_id = a.unique_id')
    for bid, uid, act in cur.fetchall():
        reasons.setdefault(bid, set()).add(f'amends:{uid}')
    cur.execute('SELECT legisinfo_id, sponsor_title_en, CONCAT_WS(" ", short_title_en, long_title_en) FROM parl_bills')
    for bid, sponsor, title in cur.fetchall():
        if sponsor and SPONSOR_PAT.search(sponsor):
            reasons.setdefault(bid, set()).add(f'sponsor:{sponsor}')
        m = TITLE_PAT.search(title or '')
        if m:
            reasons.setdefault(bid, set()).add(f'title:{m.group(1).lower()}')
    rows = [(bid, '; '.join(sorted(rs))[:600], _now()) for bid, rs in reasons.items()]
    cur.executemany('INSERT INTO imm_bill_tags (legisinfo_id, reasons, tagged_at) VALUES (%s,%s,%s) '
                    'ON DUPLICATE KEY UPDATE reasons=VALUES(reasons), tagged_at=VALUES(tagged_at)', rows)
    return {'tagged_bills': len(rows)}


# ── Express Entry rounds ────────────────────────────────────────────────────

def load_ee_draws(cur, ua: str) -> dict:
    data = json.loads(get('https://www.canada.ca/content/dam/ircc/documents/json/ee_rounds_123_en.json', ua))
    rows = []
    for r in data['rounds']:
        href = re.search(r"href='([^']+)'", r.get('mitext') or '')
        num = str(r['drawNumber']).strip()
        rows.append((num, int(re.match(r'\d+', num).group(0)), r['drawDate'], (r.get('drawName') or '').strip(),
                     _int(str(r.get('drawSize') or '').replace(',', '')), _int(str(r.get('drawCRS') or '').replace(',', '')),
                     ('https://www.canada.ca' + href.group(1).replace('/content/canadasite', '')) if href else None,
                     json.dumps(r, ensure_ascii=False), _now()))
    cur.executemany('INSERT INTO imm_ee_draws (draw_number, draw_seq, draw_date, draw_name, invitations, crs_cutoff, instruction_url, '
                    'raw_json, fetched_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE draw_seq=VALUES(draw_seq), draw_date=VALUES(draw_date), '
                    'draw_name=VALUES(draw_name), invitations=VALUES(invitations), crs_cutoff=VALUES(crs_cutoff), '
                    'instruction_url=VALUES(instruction_url), raw_json=VALUES(raw_json), fetched_at=VALUES(fetched_at)', rows)
    return {'draws': len(rows)}
