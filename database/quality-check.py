#!/usr/bin/env python3
"""
Quality check: compare original source CSVs against cleaned outputs.

Checks run per year:
  1. Row count: source vs cleaned (should be equal — ID-based dedup had 0 dupes)
  2. Agreement value sum: source vs cleaned (numeric normalisation only, no loss)
  3. Key field null rate in cleaned output (ref_number, recipient, value, date)
  4. Coded field validity: agreement_type in C/G/O, recipient_type in A/F/G/I/N/O/P/S,
     province is 2-char alpha or empty, dates match YYYY-MM-DD
  5. ID column always populated in cleaned output
  6. Column-shift detection: fields containing values that belong in a different column
  7. Sample spot-check: 10 random rows — verify key field transformations are correct

Cross-year check:
  8. ID uniqueness across all cleaned files — flag any ID appearing in multiple years

Usage:
  python3 database/quality-check.py
  python3 database/quality-check.py --year 2007    # single year
  python3 database/quality-check.py --sample 20    # larger random sample
"""

import argparse
import csv
import os
import random
import re
import sys
from decimal import Decimal, InvalidOperation
from pathlib import Path

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

DIR_SOURCE  = Path(r'C:\Users\user\Documents\GrantsConts')
DIR_CLEANED = DIR_SOURCE / 'cleaned'

# CSV column indices (source file — comma-delimited original)
S_REF    = 1
S_AMEND  = 2
S_VALUE  = 19
S_SDATE  = 22
S_RECIP  = 7
S_ID     = 38

# Cleaned file column indices (same positions, pipe-delimited)
C_REF    = 1
C_AMEND  = 2
C_ATYPE  = 4
C_RTYPE  = 5
C_PROV   = 10
C_VALUE  = 19
C_SDATE  = 22
C_RECIP  = 7
C_ID     = 38

VALID_ATYPES = {'C', 'G', 'O', ''}
VALID_RTYPES = {'A', 'F', 'G', 'I', 'N', 'O', 'P', 'S', ''}
DATE_PAT     = re.compile(r'^\d{4}-\d{2}-\d{2}$')

PASS = 'PASS'
FAIL = 'FAIL'
WARN = 'WARN'


def detect_delimiter(path):
    with open(path, newline='', encoding='utf-8-sig') as f:
        first = f.readline()
    return '|' if first.count('|') > first.count(',') else ','


def read_csv(path, delimiter=None):
    if delimiter is None:
        delimiter = detect_delimiter(path)
    with open(path, newline='', encoding='utf-8-sig') as f:
        reader = csv.reader(f, delimiter=delimiter)
        header = next(reader)
        rows = list(reader)
    return header, rows


def to_decimal(v):
    s = v.strip().replace(',', '').replace('$', '')
    if not s:
        return Decimal(0)
    try:
        return Decimal(s)
    except InvalidOperation:
        return Decimal(0)


def extract_year_label(filename):
    stem = Path(filename).stem
    m = re.search(r'(19|20)\d{2}[-_](19|20)\d{2}', stem)
    if m:
        return m.group().replace('_', '-')
    m = re.search(r'(19|20)\d{2}', stem)
    return m.group() if m else stem


def cleaned_path(year_label):
    return DIR_CLEANED / f'{year_label}_dedup_cleaned.csv'


# ---------------------------------------------------------------------------
# Per-year checks
# ---------------------------------------------------------------------------

def check_year(src_path, year_label, sample_n):
    cln_path = cleaned_path(year_label)
    results  = []

    if not cln_path.exists():
        return [(FAIL, f'Cleaned file missing: {cln_path}')]

    _, src_rows = read_csv(src_path)
    _, cln_rows = read_csv(cln_path, delimiter='|')

    src_n = len(src_rows)
    cln_n = len(cln_rows)

    # ── 1. Row count ──────────────────────────────────────────────────────────
    if src_n == cln_n:
        results.append((PASS, f'Row count: {cln_n:,} (source={src_n:,}, cleaned={cln_n:,})'))
    else:
        diff = src_n - cln_n
        status = WARN if diff <= 5 else FAIL
        results.append((status,
            f'Row count mismatch: source={src_n:,} cleaned={cln_n:,} diff={diff:+,}'))

    # ── 2. Agreement value sum ────────────────────────────────────────────────
    src_sum = sum(to_decimal(r[S_VALUE]) for r in src_rows if len(r) > S_VALUE)
    cln_sum = sum(to_decimal(r[C_VALUE]) for r in cln_rows if len(r) > C_VALUE)
    if src_sum == cln_sum:
        results.append((PASS, f'Agreement value sum: ${cln_sum:,.2f}'))
    else:
        results.append((FAIL,
            f'Value sum mismatch: source=${src_sum:,.2f} cleaned=${cln_sum:,.2f} diff=${cln_sum-src_sum:+,.2f}'))

    # ── 3. Key field null rates ───────────────────────────────────────────────
    checks = [
        (C_REF,   'ref_number',       lambda v: bool(v)),
        (C_RECIP, 'recipient_name',   lambda v: bool(v)),
        (C_VALUE, 'agreement_value',  lambda v: bool(v)),
        (C_SDATE, 'start_date',       lambda v: bool(v)),
        (C_ID,    'id',               lambda v: bool(v)),
    ]
    for col, name, ok_fn in checks:
        empty = sum(1 for r in cln_rows if len(r) > col and not ok_fn(r[col]))
        pct   = 100 * empty / cln_n if cln_n else 0
        status = PASS if pct < 5 else (WARN if pct < 20 else FAIL)
        results.append((status, f'{name} empty: {empty:,}/{cln_n:,} ({pct:.1f}%)'))

    # ── 4. Coded field validity ───────────────────────────────────────────────
    bad_atype = [r[C_ATYPE] for r in cln_rows
                 if len(r) > C_ATYPE and r[C_ATYPE] not in VALID_ATYPES]
    bad_rtype = [r[C_RTYPE] for r in cln_rows
                 if len(r) > C_RTYPE and r[C_RTYPE] not in VALID_RTYPES]
    bad_prov  = [r[C_PROV]  for r in cln_rows
                 if len(r) > C_PROV  and r[C_PROV]
                 and not (len(r[C_PROV]) == 2 and r[C_PROV].isalpha())]
    bad_date  = [r[C_SDATE] for r in cln_rows
                 if len(r) > C_SDATE and r[C_SDATE]
                 and not DATE_PAT.match(r[C_SDATE])]

    for label, bad in [('agreement_type', bad_atype), ('recipient_type', bad_rtype),
                        ('province code', bad_prov),   ('start_date format', bad_date)]:
        if bad:
            results.append((FAIL,
                f'{label} invalid values ({len(bad)}): {list(set(bad))[:5]}'))
        else:
            results.append((PASS, f'{label}: all valid'))

    # ── 5. Column-shift detection ─────────────────────────────────────────────
    # quarter_en (col 37) should be empty, Q1-Q4, or a short string — not an ID
    shifted = [r[37] for r in cln_rows
               if len(r) > 37 and len(r[37]) > 5]
    if shifted:
        results.append((WARN,
            f'Possible column-shift in quarter_en ({len(shifted)} rows): e.g. {shifted[0][:60]}'))
    else:
        results.append((PASS, 'No column-shift detected in quarter_en'))

    # ── 6. Spot-check sample ──────────────────────────────────────────────────
    # Build source lookup by ID for fast access
    src_by_id = {}
    for r in src_rows:
        if len(r) > S_ID:
            src_by_id[r[S_ID].strip()] = r

    sample = random.sample(cln_rows, min(sample_n, cln_n))
    mismatches = []
    for cr in sample:
        if len(cr) <= C_ID:
            continue
        uid = cr[C_ID].strip()
        sr  = src_by_id.get(uid)
        if not sr:
            continue
        # Verify: ref_number unchanged, value numeric-equivalent, date stripped
        if len(sr) > S_REF and len(cr) > C_REF:
            if sr[S_REF].strip() != cr[C_REF].strip():
                mismatches.append(f'ref mismatch id={uid}: src={sr[S_REF]} cln={cr[C_REF]}')
        if len(sr) > S_VALUE and len(cr) > C_VALUE:
            sv = to_decimal(sr[S_VALUE])
            cv = to_decimal(cr[C_VALUE])
            if sv != cv:
                mismatches.append(f'value mismatch id={uid}: src={sr[S_VALUE]} cln={cr[C_VALUE]}')
        if len(sr) > S_SDATE and len(cr) > C_SDATE:
            src_date = sr[S_SDATE].strip().split('T')[0] if 'T' in sr[S_SDATE] else sr[S_SDATE].strip()
            if src_date in ('0001-01-01', ''):
                src_date = ''
            if src_date and cr[C_SDATE] and src_date != cr[C_SDATE]:
                mismatches.append(f'date mismatch id={uid}: src={src_date} cln={cr[C_SDATE]}')

    if mismatches:
        results.append((FAIL, f'Spot-check ({sample_n} rows): {len(mismatches)} mismatch(es)'))
        for m in mismatches[:3]:
            results.append(('    ', m))
    else:
        results.append((PASS, f'Spot-check ({min(sample_n, cln_n)} rows sampled): all match'))

    return results


# ---------------------------------------------------------------------------
# Cross-year ID uniqueness
# ---------------------------------------------------------------------------

def check_cross_year(year_files):
    print('\nChecking cross-year ID uniqueness …', flush=True)
    id_to_year = {}
    collisions = []

    for year_label, _ in year_files:
        cln = cleaned_path(year_label)
        if not cln.exists():
            continue
        _, rows = read_csv(cln, delimiter='|')
        for r in rows:
            if len(r) > C_ID and r[C_ID].strip():
                uid = r[C_ID].strip()
                if uid in id_to_year and id_to_year[uid] != year_label:
                    collisions.append((uid, id_to_year[uid], year_label))
                else:
                    id_to_year[uid] = year_label

    if collisions:
        print(f'  {FAIL}: {len(collisions):,} IDs appear in multiple year files')
        for uid, y1, y2 in collisions[:5]:
            print(f'    id={uid}  years={y1},{y2}')
    else:
        print(f'  {PASS}: All {len(id_to_year):,} IDs are unique across all years')


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--year',   help='Check a single year only')
    parser.add_argument('--sample', type=int, default=10,
                        help='Rows to spot-check per year (default: 10)')
    args = parser.parse_args()

    random.seed(42)

    source_csvs = sorted(DIR_SOURCE.glob('*.csv'))
    year_files  = [(extract_year_label(p.name), p) for p in source_csvs]

    if args.year:
        year_files = [(y, p) for y, p in year_files if args.year in y]
        if not year_files:
            print(f'No source file found matching year: {args.year}')
            sys.exit(1)

    total_pass = total_fail = total_warn = 0

    for year_label, src_path in year_files:
        print(f'\n{"="*60}')
        print(f'  {year_label}  ({src_path.name})')
        print(f'{"="*60}')

        results = check_year(src_path, year_label, args.sample)
        for status, msg in results:
            tag = f'[{status}]' if status in (PASS, FAIL, WARN) else ''
            print(f'  {tag:<6} {msg}')
            if status == PASS:
                total_pass += 1
            elif status == FAIL:
                total_fail += 1
            elif status == WARN:
                total_warn += 1

    if not args.year:
        check_cross_year(year_files)

    print(f'\n{"="*60}')
    print(f'  SUMMARY: {total_pass} passed  {total_warn} warnings  {total_fail} failed')
    print(f'{"="*60}\n')


if __name__ == '__main__':
    main()
