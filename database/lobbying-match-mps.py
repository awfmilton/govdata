#!/usr/bin/env python3
"""
Match the designated public office holders (DPOH) named in lobbying communication reports to MPs.

Input : lob_communication_dpoh + lob_communication_primary (verbatim OCL tables, load-csv-verbatim.py)
        mp_persons + mp_mandates (built by migrate-mp-schema.py et al.)
Output: lob_dpoh_mp_match — one row per DPOH row (src_row), with the matched mp_persons.id (or NULL),
        the method, a confidence label and the reason. Nothing in the source tables is modified.

Rules (deliberately conservative; an auditing site must not invent a meeting):
  * A DPOH row is matched only to an MP whose mandate covers the communication date.
  * Staff titles (assistant, advisor, chief of staff, director, ...) are never matched to an MP.
  * Methods, best first:
      exact        normalized first+last name equal                          -> high
      first_initial last name equal, first names share the first letter     -> medium
      fuzzy_last   first name equal, last name similarity >= 0.88 (typos)     -> medium
    A row with MORE THAN ONE candidate MP is not matched; it is labelled 'ambiguous' for review.
  * Rows whose title says MP/Minister/Parliamentary Secretary but find no candidate are 'unmatched_mp'
    (review queue); everything else is 'not_mp'.

Usage: python3 database/lobbying-match-mps.py
"""

import os
import re
import unicodedata
from collections import defaultdict
from datetime import date
from difflib import SequenceMatcher

import mysql.connector

MP_TITLE = re.compile(r"\b(m\.?\s?p\.?|member of par\w*|d[ée]put[ée]e?|minist\w*|parliamentary secretary|"
                      r"secr[ée]taire parlementaire|secretary of state|prime minister|premier ministre|"
                      r"leader|whip|speaker|president de la chambre)\b", re.I)
# Deputy / associate / assistant deputy ministers are senior public servants, not MPs.
PUBLIC_SERVANT = re.compile(r"\b(deputy|associate|assistant|sous|adjoint\w*)[- ]+minist\w*", re.I)
STAFF_TITLE = re.compile(r"\b(assistant|adjoint\w*|advis\w*|conseill\w*|chief of staff|chef de cabinet|director|"
                         r"directeur|directrice|coordinat\w*|analyst\w*|officer|agent\w*|manager|staff|"
                         r"clerk|greffier|researcher|recherch\w*|executive|exécutif|liaison)\b", re.I)
HONORIFIC = re.compile(r"\b(the\s+)?(right\s+)?(hon\.?|honourable|honorable|l'hon\.?|très\s+hon\.?|mr\.?|mrs\.?|ms\.?|"
                       r"dr\.?|m\.|mme|p\.?c\.?|c\.?p\.?|m\.?p\.?|député\w*)\b", re.I)


# Common English/French given-name variants; both sides are mapped to the first form before comparing.
NICKNAMES = {
    'bob': 'robert', 'bobby': 'robert', 'rob': 'robert', 'robbie': 'robert', 'bill': 'william', 'will': 'william',
    'billy': 'william', 'jim': 'james', 'jimmy': 'james', 'jamie': 'james', 'mike': 'michael', 'mick': 'michael',
    'dave': 'david', 'tom': 'thomas', 'tommy': 'thomas', 'dan': 'daniel', 'danny': 'daniel', 'joe': 'joseph',
    'steve': 'stephen', 'stephane': 'stephane', 'chris': 'christopher', 'pat': 'patrick', 'matt': 'matthew',
    'nick': 'nicholas', 'tony': 'anthony', 'ed': 'edward', 'ted': 'edward', 'ken': 'kenneth', 'larry': 'lawrence',
    'jerry': 'gerald', 'gerry': 'gerald', 'ron': 'ronald', 'don': 'donald', 'rick': 'richard', 'dick': 'richard',
    'rich': 'richard', 'greg': 'gregory', 'jeff': 'jeffrey', 'andy': 'andrew', 'drew': 'andrew', 'alex': 'alexander',
    'sam': 'samuel', 'ben': 'benjamin', 'pete': 'peter', 'kathy': 'katherine', 'kate': 'katherine', 'cathy': 'catherine',
    'liz': 'elizabeth', 'beth': 'elizabeth', 'sue': 'susan', 'jen': 'jennifer', 'jenny': 'jennifer', 'deb': 'deborah',
    'peggy': 'margaret', 'maggie': 'margaret', 'judy': 'judith', 'pam': 'pamela', 'ray': 'raymond', 'wally': 'walter',
    'randy': 'randall', 'terry': 'terence', 'vic': 'victor', 'gord': 'gordon', 'fred': 'frederick', 'harry': 'harold',
}


def given(s):
    """Normalised first given name, nickname-folded; middle names/initials dropped."""
    parts = [p for p in norm(s).split() if len(p) > 1]
    return NICKNAMES.get(parts[0], parts[0]) if parts else ''


def norm(s):
    s = (s or '').strip()
    s = HONORIFIC.sub(' ', s)
    s = unicodedata.normalize('NFKD', s)
    s = ''.join(ch for ch in s if not unicodedata.combining(ch))
    s = re.sub(r"[^a-zA-Z' -]", ' ', s).lower()
    s = re.sub(r"[\s'-]+", ' ', s).strip()
    return s


def parse_date(s):
    m = re.match(r'(\d{4})-(\d{2})-(\d{2})', s or '')
    return date(int(m[1]), int(m[2]), int(m[3])) if m else None


def main():
    conn = mysql.connector.connect(host=os.environ.get('WRITE_HOST', '127.0.0.1'), user=os.environ['WRITE_USER'],
                                   password=os.environ['WRITE_PASS'], database=os.environ.get('WRITE_DB', 'grantconts'))
    cur = conn.cursor()

    # MPs with their mandate periods
    cur.execute('SELECT p.id, p.first_name, p.last_name, m.start_date, m.end_date '
                'FROM mp_persons p JOIN mp_mandates m ON m.mp_id = p.id')
    periods = defaultdict(list)
    names = {}
    for pid, fn, ln, sd, ed in cur.fetchall():
        periods[pid].append((sd, ed))
        names[pid] = (given(fn), norm(ln))
    by_last = defaultdict(set)
    for pid, (fn, ln) in names.items():
        by_last[ln].add(pid)
        if ' ' in ln:                                   # compound surnames: index each part too
            for part in ln.split():
                by_last[part].add(pid)

    def in_office(pid, d):
        return d is not None and any((sd is None or sd <= d) and (ed is None or d <= ed) for sd, ed in periods[pid])

    cur.execute('SELECT d.src_row, d.comlog_id, d.dpoh_first_nm_prenom_tcpd, d.dpoh_last_nm_tcpd, '
                'd.dpoh_title_titre_tcpd, d.institution, p.comm_date '
                'FROM lob_communication_dpoh d JOIN lob_communication_primary p ON p.comlog_id = d.comlog_id')
    rows = cur.fetchall()

    out = []
    for src_row, comlog, fn_raw, ln_raw, title, inst, cdate in rows:
        title = title or ''
        d = parse_date(cdate)
        fn, ln = given(fn_raw), norm(ln_raw)
        public_servant = bool(PUBLIC_SERVANT.search(title))
        # A staff title wins over the word "Minister" ("Chief of Staff to the Minister", "Minister Exempt Staff").
        staff = bool(STAFF_TITLE.search(title)) or bool(re.search(r'\bstaff\b|\bexempt\b', title, re.I)) or public_servant
        mp_titled = bool(MP_TITLE.search(title)) and not staff
        if staff or not ln:
            out.append((src_row, comlog, None, 'not_mp', 'none', 'staff title' if staff else 'no name'))
            continue
        # candidates in office on the date, by method
        pool = {pid for pid in by_last.get(ln, set()) | set().union(*(by_last.get(p, set()) for p in ln.split()))
                if in_office(pid, d)}
        exact = [pid for pid in pool if names[pid][0] == fn or (fn and names[pid][0].split()[:1] == fn.split()[:1])]
        method, conf, cands = None, None, []
        if exact:
            cands = exact
            method, conf = ('exact', 'high') if all(names[p][0] == fn for p in exact) else ('first_name_part', 'high')
        elif fn:
            cands = [pid for pid in pool if names[pid][0][:1] == fn[:1]]
            method, conf = 'first_initial', 'medium'
            if not cands and mp_titled:
                cands = [pid for pid, (pf, pl) in names.items()
                         if pf == fn and in_office(pid, d) and SequenceMatcher(None, pl, ln).ratio() >= 0.88]
                method, conf = 'fuzzy_last', 'medium'
        if len(cands) == 1:
            out.append((src_row, comlog, cands[0], method, conf, None))
        elif len(cands) > 1:
            out.append((src_row, comlog, None, 'ambiguous', 'none', 'candidates ' + ','.join(map(str, sorted(cands)))))
        elif mp_titled:
            out.append((src_row, comlog, None, 'unmatched_mp', 'none', 'MP-type title, no MP in office with this name'))
        else:
            out.append((src_row, comlog, None, 'not_mp', 'none', 'no MP in office with this name'))

    cur.execute('DROP TABLE IF EXISTS lob_dpoh_mp_match')
    cur.execute('''CREATE TABLE lob_dpoh_mp_match (
        dpoh_src_row INT UNSIGNED NOT NULL PRIMARY KEY, comlog_id VARCHAR(32) NOT NULL,
        mp_person_id INT UNSIGNED NULL,
        method ENUM('exact','first_name_part','first_initial','fuzzy_last','ambiguous','unmatched_mp','not_mp') NOT NULL,
        confidence ENUM('high','medium','none') NOT NULL, note VARCHAR(500) NULL,
        matched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_mp (mp_person_id), KEY idx_comlog (comlog_id), KEY idx_method (method)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
      COMMENT="DPOH->MP matches for lobbying communications; see database/lobbying-match-mps.py"''')
    for i in range(0, len(out), 5000):
        cur.executemany('INSERT INTO lob_dpoh_mp_match (dpoh_src_row, comlog_id, mp_person_id, method, confidence, note) '
                        'VALUES (%s,%s,%s,%s,%s,%s)', out[i:i + 5000])
    conn.commit()
    cur.execute('SELECT method, confidence, COUNT(*), COUNT(DISTINCT mp_person_id) FROM lob_dpoh_mp_match GROUP BY 1, 2 ORDER BY 3 DESC')
    for r in cur.fetchall():
        print(*r, sep='\t')


if __name__ == '__main__':
    main()
