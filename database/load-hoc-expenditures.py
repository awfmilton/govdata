#!/usr/bin/env python3
"""
Load the House of Commons Members' Expenditures Reports (downloaded by fetch-hoc-expenditures.py) into grantconts.

Transaction level, as published, with the source file of every row:
  hoc_exp_periods          one row per quarter (fiscal year, quarter, start/end dates, summary CSV id)
  hoc_exp_summary          quarter summary per member: salaries, travel, hospitality, contracts
  hoc_exp_contracts        contract lines: supplier, description, date, total
  hoc_exp_hospitality      hospitality events: date, location, attendees, purpose, event type, claim, supplier, total
  hoc_exp_travel_claims    travel claims: claim, dates, transportation, accommodations, meals, points, total
  hoc_exp_travel_legs      the legs of each claim: traveller, traveller type, purpose, date, departure, destination
  hoc_exp_members          member id (House of Commons) -> name, and the matched mp_persons.id (or NULL)

House of Commons fiscal years are named by the year they END: "Q1 2026" = April-June 2025.
MP matching: a member's "Last, First" name is matched to mp_persons with a mandate that overlaps the quarter (or
ended up to 120 days before it: wind-down expenses);
exactly one candidate is required (otherwise mp_person_id stays NULL and match_method says why).
Re-runnable: the hoc_exp_* tables are rebuilt from the files each run.

Usage: python3 database/load-hoc-expenditures.py [DIR]   (default /mnt/govdata_build_data/local/hoc-expenditures)
DB: WRITE_HOST/WRITE_USER/WRITE_PASS (build user), database grantconts.
"""
import csv
import datetime as dt
import io
import json
import os
import re
import sys
import unicodedata
from collections import defaultdict

import mysql.connector

DIR = sys.argv[1] if len(sys.argv) > 1 else '/mnt/govdata_build_data/local/hoc-expenditures'
db = mysql.connector.connect(host=os.environ.get('WRITE_HOST', '127.0.0.1'), user=os.environ.get('WRITE_USER', 'pt_write'),
                             password=os.environ['WRITE_PASS'], database='grantconts', charset='utf8mb4')
cur = db.cursor()

DDL = """
DROP TABLE IF EXISTS hoc_exp_periods, hoc_exp_summary, hoc_exp_contracts, hoc_exp_hospitality,
                     hoc_exp_travel_claims, hoc_exp_travel_legs, hoc_exp_members;
CREATE TABLE hoc_exp_periods (
  period_id smallint unsigned PRIMARY KEY, fiscal_year char(9) NOT NULL, quarter tinyint NOT NULL,
  start_date date NOT NULL, end_date date NOT NULL, summary_csv_id char(36) NOT NULL, UNIQUE KEY (fiscal_year, quarter));
CREATE TABLE hoc_exp_members (
  member_uuid char(36) NOT NULL, period_id smallint unsigned NOT NULL, member_name varchar(200) NOT NULL,
  mp_person_id int unsigned NULL, match_method varchar(60) NOT NULL, PRIMARY KEY (member_uuid, period_id), KEY (mp_person_id));
CREATE TABLE hoc_exp_summary (
  id int unsigned AUTO_INCREMENT PRIMARY KEY, period_id smallint unsigned NOT NULL, member_name varchar(200) NOT NULL,
  constituency varchar(200), caucus varchar(100), salaries decimal(12,2), travel decimal(12,2), hospitality decimal(12,2),
  contracts decimal(12,2), mp_person_id int unsigned NULL, source_file varchar(120) NOT NULL, KEY (period_id), KEY (mp_person_id));
CREATE TABLE hoc_exp_contracts (
  id int unsigned AUTO_INCREMENT PRIMARY KEY, period_id smallint unsigned NOT NULL, member_uuid char(36) NOT NULL,
  mp_person_id int unsigned NULL, supplier varchar(400), description varchar(600), expense_date date, total decimal(12,2),
  source_file varchar(160) NOT NULL, KEY (period_id), KEY (mp_person_id), KEY (supplier(80)));
CREATE TABLE hoc_exp_hospitality (
  id int unsigned AUTO_INCREMENT PRIMARY KEY, period_id smallint unsigned NOT NULL, member_uuid char(36) NOT NULL,
  mp_person_id int unsigned NULL, event_date date, location varchar(200), attendees int, purpose varchar(600),
  event_type varchar(200), claim varchar(40), supplier varchar(400), total decimal(12,2), source_file varchar(160) NOT NULL,
  KEY (period_id), KEY (mp_person_id), KEY (supplier(80)));
CREATE TABLE hoc_exp_travel_claims (
  id int unsigned AUTO_INCREMENT PRIMARY KEY, period_id smallint unsigned NOT NULL, member_uuid char(36) NOT NULL,
  mp_person_id int unsigned NULL, claim varchar(40), start_date date, end_date date, transportation decimal(12,2),
  accommodations decimal(12,2), meals decimal(12,2), points_reg decimal(8,2), points_spec decimal(8,2),
  points_usa decimal(8,2), total decimal(12,2), source_file varchar(160) NOT NULL, KEY (period_id), KEY (mp_person_id), KEY (claim));
CREATE TABLE hoc_exp_travel_legs (
  id int unsigned AUTO_INCREMENT PRIMARY KEY, period_id smallint unsigned NOT NULL, member_uuid char(36) NOT NULL,
  mp_person_id int unsigned NULL, claim varchar(40), traveller_name varchar(200), traveller_type varchar(60),
  purpose varchar(300), leg_date date, departure varchar(200), destination varchar(200), source_file varchar(160) NOT NULL,
  KEY (period_id), KEY (mp_person_id), KEY (claim));
"""
for stmt in [s for s in DDL.split(';') if s.strip()]:
    cur.execute(stmt)


def norm(s):
    s = unicodedata.normalize('NFKD', s or '').encode('ascii', 'ignore').decode().lower()
    return re.sub(r'[^a-z]+', ' ', s).strip()


def num(x):
    x = (x or '').strip().replace(',', '').replace('$', '')
    try:
        return float(x) if x else None
    except ValueError:
        return None


def date(x):
    try:
        return dt.date.fromisoformat((x or '').strip()[:10])
    except ValueError:
        return None


def read(path):
    """-> (title, header, rows). First line of a detail file is its title."""
    text = open(path, encoding='utf-8-sig').read()
    lines = list(csv.reader(io.StringIO(text)))
    if not lines:
        return '', [], []                      # the House published an empty report
    if lines and len(lines[0]) == 1:
        return lines[0][0], lines[1], lines[2:]
    return '', lines[0], lines[1:]


# MP candidates: name -> [(mp_person_id, start, end)]
cur.execute("SELECT p.id, p.first_name, p.last_name, m.start_date, m.end_date, p.op_politician_id FROM mp_persons p JOIN mp_mandates m ON m.mp_id = p.id")
mandates = defaultdict(list)
linked = set()                 # mp_persons with an OpenParliament politician id
for pid, first, last, s, e, op in cur.fetchall():
    mandates[(norm(last), norm(first))].append((pid, s, e or dt.date(2100, 1, 1)))
    if op:
        linked.add(pid)
by_last = defaultdict(set)
for (last, first) in mandates:
    by_last[last].add(first)


def match(name, start, end):
    """'Last, First' -> (mp_person_id or None, method)."""
    if ',' not in name:
        return None, 'unparsed name'
    last, first = [norm(x) for x in name.split(',', 1)]
    first = re.sub(r'^((right|rt|the|l)\s+)*hon\s+', '', first)    # "Hon. Pierre", "Right Hon. Justin"
    keys = [(last, first)]
    grace = dt.timedelta(days=120)    # offices keep filing wind-down expenses for a few months after a seat ends
    if not {pid for pid, s, e in mandates.get(keys[0], ()) if s <= end and e + grace >= start}:
        # First-name variants: first word only, or a listed first name starting the same way (Rob/Robert).
        keys = [(last, f) for f in by_last.get(last, ()) if f.split(' ')[0] == first.split(' ')[0]
                or f.startswith(first) or first.startswith(f)]
        # Compound surnames split differently ("Rempel Garner, Michelle" vs "Michelle Rempel" + "Garner").
        keys += [(l, f) for (l, f) in mandates if l == last.split(' ')[-1] and f == (first + ' ' + ' '.join(last.split(' ')[:-1])).strip()]
        keys += [(l, f) for (l, f) in mandates if l == last.split(' ')[0] and f.split(' ')[0] == first.split(' ')[0]]
        # A surname hyphenated later ("Fancy, Jessica" -> Jessica Fancy-Landry).
        keys += [(l, f) for (l, f) in mandates if l.startswith(last + ' ') and f.split(' ')[0] == first.split(' ')[0]]
    hits = {pid for k in keys for pid, s, e in mandates.get(k, ()) if s <= end and e + grace >= start}
    # mp_persons has 20 duplicates from a 2025 import with no OpenParliament link; prefer the linked record.
    if len(hits) > 1 and len(hits & linked) == 1:
        hits &= linked
    if len(hits) == 1:
        return hits.pop(), 'name+mandate' if keys == [(last, first)] else 'name variant+mandate'
    return None, 'no mandate match' if not hits else 'ambiguous (%d)' % len(hits)


manifest = [json.loads(l) for l in open(os.path.join(DIR, 'manifest.jsonl'), encoding='utf-8') if l.strip()]
summary_ids = {r['path'].split('/')[0]: r['path'].split('summary-')[1][:36] for r in manifest if '/summary-' in r['path']}

periods = {}
for pid, folder in enumerate(sorted(summary_ids), 1):
    fy_end, q = int(folder[:4]), int(folder[-1])
    start = dt.date(fy_end - 1 if q <= 3 else fy_end, {1: 4, 2: 7, 3: 10, 4: 1}[q], 1)
    nxt = dt.date(start.year + (start.month + 3 > 12), (start.month + 3 - 1) % 12 + 1, 1)
    end = nxt - dt.timedelta(days=1)
    periods[folder] = (pid, start, end)
    cur.execute("INSERT INTO hoc_exp_periods VALUES (%s,%s,%s,%s,%s,%s)",
                (pid, f'{fy_end - 1}-{fy_end}', q, start, end, summary_ids[folder]))

counts = defaultdict(int)
unmatched = defaultdict(int)
for folder, (pid, start, end) in periods.items():
    base = os.path.join(DIR, folder)
    sfile = f'{folder}/summary-{summary_ids[folder]}.csv'
    _, header, rows = read(os.path.join(DIR, sfile))
    for r in rows:
        if len(r) < 7 or not r[0].strip():
            continue
        name = re.sub(r'\s+', ' ', r[0]).strip()
        mp, _ = match(name, start, end)
        cur.execute("INSERT INTO hoc_exp_summary (period_id, member_name, constituency, caucus, salaries, travel, hospitality, contracts, mp_person_id, source_file) "
                    "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)", (pid, name, r[1], r[2], num(r[3]), num(r[4]), num(r[5]), num(r[6]), mp, sfile))
        counts['summary'] += 1
    for kind in ('contract', 'hospitality', 'travel'):
        kdir = os.path.join(base, kind)
        if not os.path.isdir(kdir):
            continue
        for fn in sorted(os.listdir(kdir)):
            if not fn.endswith('.csv'):
                continue
            uuid, rel = fn[:36], f'{folder}/{kind}/{fn}'
            title, header, rows = read(os.path.join(kdir, fn))
            m = re.search(r'Report\s+[–-]\s+(.+?)\s+[–-]\s+Q\d', title)
            name = re.sub(r'\s+', ' ', m.group(1)).strip() if m else ''
            mp, how = match(name, start, end)
            cur.execute("INSERT IGNORE INTO hoc_exp_members VALUES (%s,%s,%s,%s,%s)", (uuid, pid, name, mp, how))
            if mp is None:
                unmatched[name] += 1
            if kind == 'contract':
                vals = [(pid, uuid, mp, r[0], r[1], date(r[2]), num(r[3]), rel) for r in rows if len(r) >= 4 and any(r)]
                cur.executemany("INSERT INTO hoc_exp_contracts (period_id, member_uuid, mp_person_id, supplier, description, expense_date, total, source_file) "
                                "VALUES (%s,%s,%s,%s,%s,%s,%s,%s)", vals)
            elif kind == 'hospitality':
                # Two published layouts: before 2026-Q2 (Type of Event, Claim, Supplier, Total) and from 2026-Q2
                # (Supplier, Amount, Total). Read by header name.
                ix = {h.strip(): i for i, h in enumerate(header)}
                g = lambda r, k: r[ix[k]] if k in ix and ix[k] < len(r) else ''
                vals = [(pid, uuid, mp, date(g(r, 'Date')), g(r, 'Location'), int(num(g(r, 'Total of Attendees')) or 0),
                         g(r, 'Purpose of Hospitality'), g(r, 'Type of Event'), g(r, 'Claim'), g(r, 'Supplier'),
                         num(g(r, 'Amount') if 'Amount' in ix else g(r, 'Total')), rel) for r in rows if any(r)]
                # From 2026-Q2 an event with several suppliers has one line per supplier: Amount is the line,
                # Total (the event total) repeats on every line, so the line Amount is stored.
                cur.executemany("INSERT INTO hoc_exp_hospitality (period_id, member_uuid, mp_person_id, event_date, location, attendees, purpose, event_type, claim, supplier, total, source_file) "
                                "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)", vals)
            else:
                # Two published layouts. Before 2026-Q2: a claim totals row (no traveller) followed by its legs
                # (traveller named, no amounts). From 2026-Q2: one row per trip with traveller and amounts, no
                # claim number; each row is stored as both a claim (claim = NULL) and its leg.
                ix = {h.strip(): i for i, h in enumerate(header)}
                g = lambda r, k: r[ix[k]] if k in ix and ix[k] < len(r) else ''
                claims, legs = [], []
                old = 'Claim' in ix
                for r in rows:
                    if not any(r):
                        continue
                    traveller = g(r, 'Traveller Name').strip()
                    if old and traveller:
                        legs.append((pid, uuid, mp, g(r, 'Claim'), traveller, g(r, 'Traveller Type'), g(r, 'Purpose of Travel'),
                                     date(g(r, 'Dates')), g(r, 'Departure'), g(r, 'Destination'), rel))
                        continue
                    claims.append((pid, uuid, mp, g(r, 'Claim') or None, date(g(r, 'Travel start date')), date(g(r, 'Travel end date')),
                                   num(g(r, 'Transportation')), num(g(r, 'Accommodations')), num(g(r, 'Meals and Incidentals')),
                                   num(g(r, 'Points Reg.')), num(g(r, 'Points Spec.')), num(g(r, 'Points U.S.A.')), num(g(r, 'Total')), rel))
                    if not old:
                        legs.append((pid, uuid, mp, None, traveller, g(r, 'Traveller Type'), g(r, 'Purpose of Travel'),
                                     date(g(r, 'Travel start date')), g(r, 'Departure'), g(r, 'Places Visited'), rel))
                cur.executemany("INSERT INTO hoc_exp_travel_claims (period_id, member_uuid, mp_person_id, claim, start_date, end_date, transportation, accommodations, meals, points_reg, points_spec, points_usa, total, source_file) "
                                "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)", claims)
                cur.executemany("INSERT INTO hoc_exp_travel_legs (period_id, member_uuid, mp_person_id, claim, traveller_name, traveller_type, purpose, leg_date, departure, destination, source_file) "
                                "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)", legs)
                counts['travel_legs'] += len(legs)
                vals = claims
            counts[kind] += len(vals)
    db.commit()
    print(folder, dict(counts), flush=True)

print('rows:', dict(counts))
print('detail files with no MP match:', sum(unmatched.values()), 'members:', len(unmatched))
for n, c in sorted(unmatched.items(), key=lambda x: -x[1])[:25]:
    print('  ', c, n)
