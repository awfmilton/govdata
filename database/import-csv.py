#!/usr/bin/env python3
"""
Import a cleaned grants CSV into master_table.

Expects the output of clean-csv.py — a 39-column CSV where:
  - Empty strings represent NULL
  - Dates are YYYY-MM-DD or empty
  - Numeric codes (agreement_type, recipient_type, province) are already
    single-char codes
  - amendment_number is empty (NULL) or a plain integer string

The AFTER INSERT trigger on master_table automatically populates the
normalized tables (recipients, programs, locations, naics, agreements,
amendments) for each row inserted here.

Rows are skipped if:
  - ref_number is empty (corrupt/separator rows)
  - The (ref_number, amendment_number) pair already exists in master_table

Usage:
  export WRITE_PASS=yourpassword
  python3 database/import-csv.py cleaned.csv

  # Dry-run (validate + count, no inserts):
  python3 database/import-csv.py cleaned.csv --dry-run

  # Override connection details:
  export WRITE_HOST=35.230.186.163 WRITE_DB=grantconts WRITE_USER=pt_write WRITE_PASS=...

Dependencies:
  pip install mysql-connector-python
"""

import argparse
import csv
import os
import sys
from decimal import Decimal, InvalidOperation
from pathlib import Path

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
if hasattr(sys.stderr, 'reconfigure'):
    sys.stderr.reconfigure(encoding='utf-8', errors='replace')

DEFAULT_HOST = '35.230.186.163'
DEFAULT_DB   = 'grantconts'
DEFAULT_USER = 'pt_write'
DEFAULT_PORT = 3306

# ---------------------------------------------------------------------------
# CSV column index → master_table column name
# Col 34 (Report Type) is discarded — no matching DB column.
# ---------------------------------------------------------------------------
COL_MAP = [
    (0,  'format_en'),
    (1,  'ref_number'),
    (2,  'amendment_number'),
    (3,  'amendment_date'),
    (4,  'agreement_type'),
    (5,  'recipient_type'),
    (6,  'recipient_business_number'),
    (7,  'recipient_legal_name'),
    (8,  'research_organization_name'),
    (9,  'recipient_country'),
    (10, 'recipient_province'),
    (11, 'recipient_city'),
    (12, 'recipient_postal_code'),
    (13, 'federal_riding_name_en'),
    (14, 'federal_riding_number'),
    (15, 'prog_name_en'),
    (16, 'prog_purpose_en'),
    (17, 'agreement_title_en'),
    (18, 'agreement_number'),
    (19, 'agreement_value'),
    (20, 'foreign_currency_type'),
    (21, 'foreign_currency_value'),
    (22, 'agreement_start_date'),
    (23, 'year'),
    (24, 'agreement_end_date'),
    (25, 'coverage'),
    (26, 'description_eng'),
    (27, 'naics_identifier'),
    (28, 'expected_results_en'),
    (29, 'additional_information_en'),
    (30, 'owner_org_en'),
    (31, 'agreement_value_range_en'),
    (32, 'coverage_en'),
    (33, 'has_amendments_en'),
    # 34 → discarded (Report Type)
    (35, 'recipient_city_en'),
    (36, 'fiscal_year'),
    (37, 'quarter_en'),
    (38, 'id'),
]

# DB columns not in the CSV — will be NULL on insert.
# recipient_operating_name, federal_riding_name_fr, prog_name_fr, prog_purpose_fr,
# agreement_title_fr, description_fr, expected_results_fr, additional_information_fr,
# owner_org_title

INT_COLS     = {'amendment_number', 'federal_riding_number', 'naics_identifier', 'year'}
DECIMAL_COLS = {'agreement_value', 'foreign_currency_value'}
DATE_COLS    = {'amendment_date', 'agreement_start_date', 'agreement_end_date'}

INSERT_COLS = [col for _, col in COL_MAP]
PLACEHOLDERS = ', '.join(['%s'] * len(INSERT_COLS))
COL_LIST = ', '.join(f'`{c}`' for c in INSERT_COLS)
INSERT_SQL = f'INSERT INTO master_table ({COL_LIST}) VALUES ({PLACEHOLDERS})'


def val(raw: str, col: str):
    """Convert a cleaned CSV string to the appropriate Python type for MySQL."""
    s = raw.strip()
    if s == '':
        return None
    if col in INT_COLS:
        try:
            return int(s)
        except ValueError:
            return None
    if col in DECIMAL_COLS:
        try:
            return Decimal(s)
        except InvalidOperation:
            return None
    if col in DATE_COLS:
        return s if s else None
    return s


def connect(dry_run: bool):
    try:
        import mysql.connector
    except ImportError:
        print('ERROR: pip install mysql-connector-python', file=sys.stderr)
        sys.exit(1)

    host     = os.environ.get('WRITE_HOST', DEFAULT_HOST)
    db       = os.environ.get('WRITE_DB',   DEFAULT_DB)
    user     = os.environ.get('WRITE_USER', DEFAULT_USER)
    port     = int(os.environ.get('WRITE_PORT', str(DEFAULT_PORT)))
    password = os.environ.get('WRITE_PASS')

    if not password and not dry_run:
        import getpass
        password = getpass.getpass(f'Password for {user}@{host}/{db}: ')

    if dry_run:
        print(f'Dry-run: would connect to {user}@{host}:{port}/{db}')
        return None

    print(f'Connecting to {user}@{host}:{port}/{db} …', flush=True)
    conn = mysql.connector.connect(
        host=host, port=port, database=db,
        user=user, password=password,
        connection_timeout=30,
        autocommit=False,
    )
    print('  Connected.', flush=True)
    return conn


def load_existing_keys(conn) -> set:
    cur = conn.cursor()
    print('Loading existing (ref_number, amendment_number) keys …', flush=True)
    cur.execute('SELECT ref_number, amendment_number FROM master_table WHERE ref_number IS NOT NULL')
    keys = set()
    for ref, amend in cur:
        keys.add((ref, amend))
    cur.close()
    print(f'  {len(keys):,} existing records.', flush=True)
    return keys


def main():
    parser = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('input', help='Path to cleaned CSV file')
    parser.add_argument('--dry-run', action='store_true',
                        help='Validate and count rows only — do not insert')
    parser.add_argument('--batch', type=int, default=200,
                        help='Rows per commit batch (default: 200)')
    args = parser.parse_args()

    if not os.path.isfile(args.input):
        print(f'ERROR: file not found: {args.input}', file=sys.stderr)
        sys.exit(1)

    conn = connect(args.dry_run)
    existing_keys = load_existing_keys(conn) if conn else set()

    stats = {
        'read':          0,
        'skipped_empty': 0,  # ref_number is empty
        'skipped_dup':   0,  # already in master_table
        'inserted':      0,
        'errors':        0,
    }

    cur = conn.cursor() if conn else None

    with open(args.input, newline='', encoding='utf-8') as f:
        reader = csv.reader(f)
        header = next(reader)

        for row in reader:
            stats['read'] += 1

            if stats['read'] % 5_000 == 0:
                print(f'  … {stats["read"]:,} read  '
                      f'{stats["inserted"]:,} inserted  '
                      f'{stats["skipped_dup"]:,} dup  '
                      f'{stats["errors"]:,} errors', flush=True)

            if len(row) < 39:
                stats['skipped_empty'] += 1
                continue

            ref_number = row[1].strip()
            if not ref_number:
                stats['skipped_empty'] += 1
                continue

            amend_raw  = row[2].strip()
            amend_int  = int(amend_raw) if amend_raw else None
            dup_key    = (ref_number, amend_int)

            if dup_key in existing_keys:
                stats['skipped_dup'] += 1
                continue

            params = tuple(val(row[idx], col) for idx, col in COL_MAP)

            if args.dry_run:
                existing_keys.add(dup_key)
                stats['inserted'] += 1
                continue

            try:
                cur.execute(INSERT_SQL, params)
                existing_keys.add(dup_key)
                stats['inserted'] += 1

                if stats['inserted'] % args.batch == 0:
                    conn.commit()

            except Exception as e:
                stats['errors'] += 1
                conn.rollback()
                print(f'  ERROR row {stats["read"]} ref={ref_number}: {e}',
                      file=sys.stderr)

    if conn and stats['inserted'] % args.batch != 0:
        conn.commit()

    if cur:
        cur.close()
    if conn:
        conn.close()

    print()
    print('=' * 55)
    print(f'  Rows read:              {stats["read"]:>10,}')
    print(f'  Skipped (empty ref):    {stats["skipped_empty"]:>10,}')
    print(f'  Skipped (already in DB):{stats["skipped_dup"]:>10,}')
    print(f'  Inserted:               {stats["inserted"]:>10,}')
    print(f'  Errors:                 {stats["errors"]:>10,}')
    print('=' * 55)

    if args.dry_run:
        print('\nDry-run complete — nothing was written.')
    else:
        print('\nImport complete. The normalization trigger populated')
        print('recipients, programs, locations, naics, agreements,')
        print('and amendments automatically for each inserted row.')
        print()
        print('Run backfill for rows that pre-date this import:')
        print('  CALL sp_backfill_normalization(500, @done); SELECT @done;')


if __name__ == '__main__':
    main()
