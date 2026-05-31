#!/usr/bin/env python3
"""
Rebuild master_table from the full-width cleaned CSV files.

Builds a NEW table `master_table_rebuild` with identical structure to
master_table (plus a UNIQUE key on id), keyed on the globally-unique id
column. Sourced from the cleaned CSV files (full width) — NOT the staging
table, which truncated title/name to 255.

Steps:
  1. CREATE TABLE master_table_rebuild LIKE master_table
  2. Drop secondary indexes (keep PRIMARY) for fast bulk load
  3. Load all cleaned CSVs (1.26M rows) — full-width English data
  4. Add (ref_number, amendment_number) index for the French join
  5. Backfill French columns from old master on UNIQUE (ref,amend) matches only
     (collision refs are left NULL to avoid attaching the wrong department's text)
  6. Re-add all secondary indexes + UNIQUE key on id
  7. Verify counts

Does NOT touch the live master_table. The final swap is a separate, manual step.

Usage:
  python3 database/rebuild-master.py --step all
  python3 database/rebuild-master.py --step create   # steps 1-2
  python3 database/rebuild-master.py --step load     # step 3
  python3 database/rebuild-master.py --step french   # steps 4-5
  python3 database/rebuild-master.py --step index    # step 6
  python3 database/rebuild-master.py --step verify   # step 7
"""

import argparse
import csv
import re
import sys
from decimal import Decimal, InvalidOperation
from pathlib import Path
import mysql.connector

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

DB = dict(host='35.230.186.163', port=3306, database='grantconts',
          user='pt_read', password='***REMOVED***',
          connection_timeout=600, autocommit=True)

DIR_CLEANED = Path(r'C:\Users\user\Documents\GrantsConts\cleaned')
REBUILD = 'master_table_rebuild'
BATCH = 1000

# Cleaned CSV column index -> master_table column (full width). From import-csv.py.
COL_MAP = [
    (0,'format_en'),(1,'ref_number'),(2,'amendment_number'),(3,'amendment_date'),
    (4,'agreement_type'),(5,'recipient_type'),(6,'recipient_business_number'),
    (7,'recipient_legal_name'),(8,'research_organization_name'),(9,'recipient_country'),
    (10,'recipient_province'),(11,'recipient_city'),(12,'recipient_postal_code'),
    (13,'federal_riding_name_en'),(14,'federal_riding_number'),(15,'prog_name_en'),
    (16,'prog_purpose_en'),(17,'agreement_title_en'),(18,'agreement_number'),
    (19,'agreement_value'),(20,'foreign_currency_type'),(21,'foreign_currency_value'),
    (22,'agreement_start_date'),(23,'year'),(24,'agreement_end_date'),(25,'coverage'),
    (26,'description_eng'),(27,'naics_identifier'),(28,'expected_results_en'),
    (29,'additional_information_en'),(30,'owner_org_en'),(31,'agreement_value_range_en'),
    (32,'coverage_en'),(33,'has_amendments_en'),(35,'recipient_city_en'),
    (36,'fiscal_year'),(37,'quarter_en'),(38,'id'),
]
INT_COLS     = {'amendment_number','federal_riding_number','naics_identifier','year'}
DECIMAL_COLS = {'agreement_value','foreign_currency_value'}
DATE_COLS    = {'amendment_date','agreement_start_date','agreement_end_date'}

# master_table column max lengths (char/varchar). Text columns omitted (no limit).
# Mirrors the widths in master_table DDL so over-length cleaned values are
# truncated rather than rejected (1406 Data too long).
MAXLEN = {
    'ref_number': 50, 'agreement_type': 1, 'recipient_type': 1,
    'recipient_business_number': 255, 'recipient_legal_name': 350,
    'research_organization_name': 255, 'recipient_country': 2,
    'recipient_province': 2, 'recipient_city': 255, 'recipient_postal_code': 7,
    'federal_riding_name_en': 100, 'prog_name_en': 255, 'agreement_title_en': 800,
    'agreement_number': 100, 'foreign_currency_type': 3, 'has_amendments_en': 3,
    'format_en': 255, 'recipient_city_en': 255, 'fiscal_year': 9, 'quarter_en': 2,
    'id': 255,
}

INSERT_COLS = [c for _, c in COL_MAP]
INSERT_SQL = (f'INSERT INTO {REBUILD} (' + ','.join(f'`{c}`' for c in INSERT_COLS) +
              ') VALUES (' + ','.join(['%s'] * len(INSERT_COLS)) + ')')

# French / French-only columns to backfill from old master
FRENCH_COLS = [
    'prog_name_fr', 'prog_purpose_fr', 'agreement_title_fr', 'description_fr',
    'expected_results_fr', 'additional_information_fr', 'recipient_operating_name',
    'federal_riding_name_fr', 'owner_org_title',
]


def conn():
    c = mysql.connector.connect(**DB)
    return c


def val(raw, col):
    s = raw.strip() if raw else ''
    if s == '':
        return None
    if col in INT_COLS:
        try: return int(s)
        except ValueError: return None
    if col in DECIMAL_COLS:
        try: return Decimal(s)
        except InvalidOperation: return None
    # Enforce master_table column width (truncate over-length cleaned values)
    limit = MAXLEN.get(col)
    if limit and len(s) > limit:
        s = s[:limit]
    return s


def get_secondary_index_defs(cur):
    """Return list of (index_name, create_clause) for all non-PRIMARY indexes."""
    cur.execute(f'SHOW CREATE TABLE master_table')
    ddl = cur.fetchone()[1]
    defs = []
    for line in ddl.split('\n'):
        line = line.strip().rstrip(',')
        m = re.match(r'(UNIQUE KEY|FULLTEXT KEY|KEY)\s+`([^`]+)`\s+(.*)', line)
        if m:
            kind, name, cols = m.group(1), m.group(2), m.group(3)
            defs.append((name, f'ADD {kind} `{name}` {cols}'))
    return defs


# ── Step 1-2: create + strip indexes ────────────────────────────────────────
def step_create(c):
    cur = c.cursor()
    print('STEP 1: CREATE TABLE master_table_rebuild LIKE master_table', flush=True)
    cur.execute(f'DROP TABLE IF EXISTS {REBUILD}')
    cur.execute(f'CREATE TABLE {REBUILD} LIKE master_table')

    print('STEP 2: dropping secondary indexes for fast load', flush=True)
    defs = get_secondary_index_defs(cur)
    for name, _ in defs:
        try:
            cur.execute(f'ALTER TABLE {REBUILD} DROP INDEX `{name}`')
        except mysql.connector.Error as e:
            print(f'   (skip {name}: {e.msg})')
    print(f'   Dropped {len(defs)} secondary indexes. PRIMARY retained.', flush=True)
    cur.close()


# ── Step 3: load ─────────────────────────────────────────────────────────────
def step_load(c):
    cur = c.cursor()
    files = sorted(DIR_CLEANED.glob('*_dedup_cleaned.csv'))
    print(f'STEP 3: loading {len(files)} cleaned files into {REBUILD}', flush=True)
    total = skipped = errors = 0
    batch = []

    def flush():
        nonlocal errors
        if not batch:
            return
        try:
            cur.executemany(INSERT_SQL, batch)
        except mysql.connector.Error:
            # Fall back to row-by-row so one bad row doesn't drop the batch
            for params in batch:
                try:
                    cur.execute(INSERT_SQL, params)
                except mysql.connector.Error as e2:
                    errors_local[0] += 1
                    if errors_local[0] <= 20:
                        print(f'      skip row: {e2.msg}', flush=True)
        batch.clear()

    errors_local = [0]
    for f in files:
        fr = 0
        with open(f, newline='', encoding='utf-8') as fh:
            reader = csv.reader(fh, delimiter='|')
            next(reader)
            for row in reader:
                if len(row) <= 38 or not row[1].strip():
                    skipped += 1
                    continue
                batch.append(tuple(val(row[i], col) for i, col in COL_MAP))
                fr += 1; total += 1
                if len(batch) >= BATCH:
                    flush()
        print(f'   {f.stem.replace("_dedup_cleaned",""):<12} {fr:>8,}', flush=True)
    flush()
    print(f'   Loaded {total - errors_local[0]:,} rows '
          f'({skipped} skipped, {errors_local[0]} row errors).', flush=True)
    cur.close()


# ── Step 4-5: French backfill ────────────────────────────────────────────────
def step_french(c):
    cur = c.cursor()
    print('STEP 4: adding (ref_number, amendment_number) index for join', flush=True)
    try:
        cur.execute(f'ALTER TABLE {REBUILD} ADD INDEX idx_rb_ref_amend (ref_number, amendment_number)')
    except mysql.connector.Error as e:
        print(f'   (index: {e.msg})')

    # Build a helper table of (ref,amend) pairs that are UNIQUE in master_table.
    # MySQL forbids referencing the UPDATE target inside its own subquery, so we
    # materialise the safe keys first, then narrow to those also unique in rebuild.
    print('STEP 5a: building unique-key helper table', flush=True)
    cur.execute('DROP TABLE IF EXISTS _fr_keys')
    cur.execute(
        'CREATE TABLE _fr_keys ('
        '  rn VARCHAR(50) NOT NULL, an INT UNSIGNED NULL, '
        '  INDEX idx_fk (rn, an)) '
        'AS SELECT ref_number AS rn, amendment_number AS an '
        'FROM master_table '
        'GROUP BY ref_number, amendment_number HAVING COUNT(*) = 1'
    )
    cur.execute('SELECT COUNT(*) FROM _fr_keys')
    print(f'   keys unique in master: {cur.fetchone()[0]:,}', flush=True)

    # Remove keys that are NOT unique in the rebuild (collision refs across depts)
    cur.execute(
        f'DELETE k FROM _fr_keys k '
        f'WHERE (SELECT COUNT(*) FROM {REBUILD} r '
        f'       WHERE r.ref_number = k.rn AND r.amendment_number <=> k.an) <> 1'
    )
    cur.execute('SELECT COUNT(*) FROM _fr_keys')
    print(f'   keys unique in BOTH: {cur.fetchone()[0]:,}', flush=True)

    print('STEP 5b: backfilling French columns via helper join', flush=True)
    set_clause = ', '.join(f'r.`{col}` = m.`{col}`' for col in FRENCH_COLS)
    sql = (
        f'UPDATE {REBUILD} r '
        f'JOIN _fr_keys k ON r.ref_number = k.rn AND r.amendment_number <=> k.an '
        f'JOIN master_table m ON m.ref_number = k.rn AND m.amendment_number <=> k.an '
        f'SET {set_clause}'
    )
    cur.execute(sql)
    print(f'   French backfilled into {cur.rowcount:,} rows.', flush=True)

    cur.execute('DROP TABLE IF EXISTS _fr_keys')
    cur.close()


# ── Step 6: re-add indexes ───────────────────────────────────────────────────
def step_index(c):
    cur = c.cursor()
    print('STEP 6: re-adding all secondary indexes + UNIQUE on id', flush=True)
    defs = get_secondary_index_defs(cur)
    for name, clause in defs:
        try:
            cur.execute(f'ALTER TABLE {REBUILD} {clause}')
            print(f'   added {name}', flush=True)
        except mysql.connector.Error as e:
            if e.errno == 1061:
                print(f'   (exists {name})')
            else:
                print(f'   ERROR {name}: {e.msg}', file=sys.stderr)
    # UNIQUE on id (NULLs allowed multiple times in MySQL, so the 4 NULL-id rows are fine)
    try:
        cur.execute(f'ALTER TABLE {REBUILD} ADD UNIQUE INDEX uk_rb_id (id)')
        print('   added UNIQUE uk_rb_id (id)', flush=True)
    except mysql.connector.Error as e:
        print(f'   id unique: {e.msg}', file=sys.stderr)
    cur.close()


# ── Step 7: verify ───────────────────────────────────────────────────────────
def step_verify(c):
    cur = c.cursor()
    print('STEP 7: verification', flush=True)
    cur.execute(f'SELECT COUNT(*) FROM {REBUILD}')
    print(f'   {REBUILD} rows: {cur.fetchone()[0]:,}')
    cur.execute(f'SELECT COUNT(*) FROM {REBUILD} WHERE id IS NOT NULL')
    print(f'   rows with id:   {cur.fetchone()[0]:,}')
    cur.execute(f'SELECT COUNT(DISTINCT id) FROM {REBUILD} WHERE id IS NOT NULL')
    print(f'   distinct ids:   {cur.fetchone()[0]:,}')
    for col in ['prog_name_fr','description_fr','agreement_title_fr','recipient_operating_name']:
        cur.execute(f'SELECT COUNT(*) FROM {REBUILD} WHERE `{col}` IS NOT NULL AND `{col}`<>""')
        print(f'   {col} populated: {cur.fetchone()[0]:,}')
    cur.execute(f'SELECT SUM(agreement_value) FROM {REBUILD}')
    s = cur.fetchone()[0]
    print(f'   total agreement_value: ${s:,.2f}' if s else '   value: n/a')
    cur.close()


STEPS = {'create': [step_create], 'load': [step_load], 'french': [step_french],
         'index': [step_index], 'verify': [step_verify],
         'all': [step_create, step_load, step_french, step_index, step_verify]}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--step', choices=list(STEPS), default='all')
    args = ap.parse_args()
    c = conn()
    print(f'Connected to {DB["user"]}@{DB["host"]}/{DB["database"]}\n', flush=True)
    for fn in STEPS[args.step]:
        fn(c)
        print()
    c.close()
    print('Done.')


if __name__ == '__main__':
    main()
