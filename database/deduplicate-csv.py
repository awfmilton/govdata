#!/usr/bin/env python3
"""
Deduplicate a government grants CSV before MySQL import.

The CSV contains two row types for each amended agreement:
  - Amendment Number = "current"  → stored in master_table with amendment_number IS NULL
  - Amendment Number = "01", "02" → stored in master_table with amendment_number = 1, 2, ...

Both row types are kept — the DB stores all of them.

Deduplication logic (in order):
  1. Intra-CSV dedup: skip any (ref_number, amendment_number) that already appeared
     earlier in the same file (guards against duplicate rows in the export).
  2. DB dedup: loads all existing (ref_number, amendment_number) pairs from
     master_table into a set at startup, then skips rows already in the DB.

Output format:
  Pipe-delimited (|), all fields quoted — e.g. "field1"|"field2"|...
  Input delimiter is auto-detected (comma or pipe).

Output filename:
  Year or fiscal-year label extracted from the input filename.
  search_grants_2023-2024_en.csv  →  2023-2024_dedup.csv
  Use --output to override.

Usage:
  # Staging workflow (no DB filter — get everything for comparison):
  python3 database/deduplicate-csv.py 2023-2024.csv --no-db-check

  # With DB credentials to skip already-imported rows:
  export SRC_PASS=yourpassword
  python3 database/deduplicate-csv.py grants.csv

  # Custom output path:
  python3 database/deduplicate-csv.py grants.csv --output clean.csv

  # Dry-run: print stats only, write nothing:
  python3 database/deduplicate-csv.py grants.csv --dry-run

Dependencies:
  pip install mysql-connector-python
"""

import argparse
import csv
import os
import re
import sys
from pathlib import Path

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
if hasattr(sys.stderr, 'reconfigure'):
    sys.stderr.reconfigure(encoding='utf-8', errors='replace')

# ---------------------------------------------------------------------------
# DB defaults (override with env vars)
# ---------------------------------------------------------------------------
DEFAULT_SRC_HOST = '35.230.186.163'
DEFAULT_SRC_DB   = 'grantconts'
DEFAULT_SRC_USER = 'pt_read'
DEFAULT_SRC_PORT = 3306

# CSV column indices (0-based) — standard government grants export format.
COL_REF_NUMBER       = 1   # "Reference Number"
COL_AMENDMENT_NUMBER = 2   # "Amendment Number"  ("current" | "01" | "02" …)
COL_ID               = 38  # "ID" — "org_prefix,ref_number,amendment_number" — globally unique
                            # Early years (2006-2016) reused ref_numbers across departments;
                            # the ID column includes the org prefix and is the safe dedup key.

# Output format — pipe-delimited, every field quoted for maximum clarity.
OUT_DELIMITER = '|'
OUT_QUOTING   = csv.QUOTE_ALL


def detect_delimiter(path: str) -> str:
    """Read the first line and return '|' or ',' based on which appears more."""
    with open(path, newline='', encoding='utf-8-sig') as f:
        first = f.readline()
    return '|' if first.count('|') > first.count(',') else ','


def extract_year_label(filename: str) -> str:
    """
    Pull a year or fiscal-year label out of a filename.
    'search_grants_2023-2024_en.csv' → '2023-2024'
    'grants_2025_Q1.csv'             → '2025'
    Falls back to the full stem if no recognisable year found.
    Only matches realistic years starting with 19xx or 20xx.
    """
    stem = Path(filename).stem
    m = re.search(r'(19|20)\d{2}[-_](19|20)\d{2}', stem)   # fiscal: 2023-2024
    if m:
        return m.group().replace('_', '-')
    m = re.search(r'(19|20)\d{2}', stem)                    # single: 2025
    if m:
        return m.group()
    return stem


def csv_amend_to_db(value: str):
    """
    Convert a CSV Amendment Number string to the equivalent DB value.
    "current" → None (NULL in DB)
    "01", "02" → 1, 2 (int in DB)
    """
    v = value.strip()
    if v.lower() == 'current':
        return None
    try:
        return int(v)
    except ValueError:
        return v  # Unexpected value — keep as-is for reporting.


def row_unique_id(row: list) -> str:
    """
    Return the globally unique ID for a row.

    Prefer the pre-built ID column (col 38) — format: 'org,ref_number,amendment'.
    Fall back to constructing it from owner_org_en (col 30) + ref_number (col 1)
    + amendment_number (col 2) when col 38 is empty or missing.

    Early years (2006-2016) reused ref_numbers across departments, so keying on
    ref+amend alone causes false-positive deduplication. The org-prefixed ID is
    the only truly unique identifier across all years and departments.
    """
    if len(row) > COL_ID:
        id_val = row[COL_ID].strip()
        if id_val and id_val not in ('-', ''):
            return id_val

    # Construct fallback from constituent parts
    ref   = row[COL_REF_NUMBER].strip()       if len(row) > COL_REF_NUMBER       else ''
    amend = row[COL_AMENDMENT_NUMBER].strip() if len(row) > COL_AMENDMENT_NUMBER else ''
    org   = row[30].strip()                   if len(row) > 30                   else ''
    return f'{org}|{ref}|{amend}'


def connect_db():
    try:
        import mysql.connector
    except ImportError:
        print("ERROR: mysql-connector-python not installed.\n  pip install mysql-connector-python", file=sys.stderr)
        sys.exit(1)

    host     = os.environ.get('SRC_HOST', DEFAULT_SRC_HOST)
    db       = os.environ.get('SRC_DB',   DEFAULT_SRC_DB)
    user     = os.environ.get('SRC_USER', DEFAULT_SRC_USER)
    port     = int(os.environ.get('SRC_PORT', str(DEFAULT_SRC_PORT)))
    password = os.environ.get('SRC_PASS')

    if not password:
        import getpass
        password = getpass.getpass(f"Password for {user}@{host}/{db}: ")

    print(f"Connecting to {user}@{host}:{port}/{db} …", flush=True)
    conn = mysql.connector.connect(
        host=host, port=port, database=db,
        user=user, password=password,
        connection_timeout=30,
        autocommit=True,
    )
    print("  Connected.", flush=True)
    return conn


def load_existing_keys(conn) -> set:
    """
    Load all (ref_number, amendment_number) pairs from master_table into a set.
    amendment_number is stored as int or None (NULL) in the DB.
    Read-only — uses pt_read credentials.
    """
    cur = conn.cursor()
    print("Loading existing (ref_number, amendment_number) pairs from master_table …", flush=True)
    cur.execute("SELECT ref_number, amendment_number FROM master_table WHERE ref_number IS NOT NULL")
    keys = set()
    for ref, amend in cur:
        keys.add((ref, amend))  # amend is already int or None from the DB driver
    cur.close()
    print(f"  Loaded {len(keys):,} existing records.", flush=True)
    return keys


def deduplicate(input_path: str, output_path: str, existing_keys: set, dry_run: bool) -> dict:
    stats = {
        'read':     0,
        'db_dup':   0,  # already in DB
        'csv_dup':  0,  # duplicate within the CSV
        'written':  0,
    }

    seen_in_csv: set = set()

    delim    = detect_delimiter(input_path)
    in_file  = open(input_path, newline='', encoding='utf-8-sig')
    out_file = None if dry_run else open(output_path, 'w', newline='', encoding='utf-8')

    reader = csv.reader(in_file, delimiter=delim)
    writer = csv.writer(out_file, delimiter=OUT_DELIMITER, quoting=OUT_QUOTING) if out_file else None

    header = next(reader)

    if len(header) <= COL_AMENDMENT_NUMBER:
        print(f"ERROR: CSV has only {len(header)} columns; expected at least {COL_AMENDMENT_NUMBER + 1}.", file=sys.stderr)
        sys.exit(1)

    if header[COL_REF_NUMBER].strip() != 'Reference Number':
        print(f"WARNING: column {COL_REF_NUMBER} is '{header[COL_REF_NUMBER]}', expected 'Reference Number'.", file=sys.stderr)
    if header[COL_AMENDMENT_NUMBER].strip() != 'Amendment Number':
        print(f"WARNING: column {COL_AMENDMENT_NUMBER} is '{header[COL_AMENDMENT_NUMBER]}', expected 'Amendment Number'.", file=sys.stderr)

    if writer:
        writer.writerow(header)

    for row in reader:
        stats['read'] += 1

        if stats['read'] % 50_000 == 0:
            print(f"  … {stats['read']:,} rows read, {stats['written']:,} kept so far", flush=True)

        if len(row) <= COL_AMENDMENT_NUMBER:
            # Malformed short row — skip.
            stats['csv_dup'] += 1
            continue

        uid = row_unique_id(row)

        # 1. Intra-CSV duplicate check (keyed on full org+ref+amend ID).
        if uid in seen_in_csv:
            stats['csv_dup'] += 1
            continue
        seen_in_csv.add(uid)

        # 2. DB duplicate check — compare on (ref_number, amendment_number) since
        #    the DB doesn't reliably store the org-prefixed ID.
        ref   = row[COL_REF_NUMBER].strip()       if len(row) > COL_REF_NUMBER       else ''
        amend = csv_amend_to_db(row[COL_AMENDMENT_NUMBER]) if len(row) > COL_AMENDMENT_NUMBER else None
        if (ref, amend) in existing_keys:
            stats['db_dup'] += 1
            continue

        # Ensure the ID column is always populated in the output.
        if len(row) > COL_ID and not row[COL_ID].strip():
            row = list(row)
            row[COL_ID] = uid

        stats['written'] += 1
        if writer:
            writer.writerow(row)

    in_file.close()
    if out_file:
        out_file.close()

    return stats


def main():
    parser = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('input', help='Path to the grants CSV file')
    parser.add_argument('--output', help='Output path (default: <input>_deduped.csv)')
    parser.add_argument('--no-db-check', action='store_true', help='Skip DB comparison; CSV-only dedup')
    parser.add_argument('--dry-run', action='store_true', help='Print stats only — do not write output')
    args = parser.parse_args()

    if not os.path.isfile(args.input):
        print(f"ERROR: input file not found: {args.input}", file=sys.stderr)
        sys.exit(1)

    if args.output:
        output_path = args.output
    else:
        year_label  = extract_year_label(args.input)
        output_dir  = Path(args.input).parent
        output_path = str(output_dir / f'{year_label}_dedup.csv')

    print(f"Input:  {args.input}")
    if args.dry_run:
        print("Dry-run: no output file will be written.")
    else:
        print(f"Output: {output_path}  (pipe-delimited, fully quoted)")

    existing_keys: set = set()
    if not args.no_db_check:
        conn = connect_db()
        existing_keys = load_existing_keys(conn)
        conn.close()
    else:
        print("Skipping DB check (--no-db-check).")

    print("\nProcessing CSV …", flush=True)
    stats = deduplicate(args.input, output_path, existing_keys, args.dry_run)

    print()
    print("=" * 55)
    print(f"  Rows read:                  {stats['read']:>10,}")
    print(f"  Intra-CSV duplicates:       {stats['csv_dup']:>10,}")
    print(f"  Already in DB (skipped):    {stats['db_dup']:>10,}")
    print(f"  Rows written to output:     {stats['written']:>10,}")
    total_skipped = stats['csv_dup'] + stats['db_dup']
    print(f"  Total skipped:              {total_skipped:>10,}")
    print("=" * 55)

    if not args.dry_run and stats['written'] > 0:
        print(f"\nClean file ready: {output_path}")


if __name__ == '__main__':
    main()
