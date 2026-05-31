#!/usr/bin/env python3
"""
Load all cleaned year CSVs into the staging table.

Truncates staging first (removes the 616 legacy rows), then bulk-inserts
all cleaned data from GrantsConts/cleaned/*.csv.

Column mapping notes:
  - 5 CSV columns have no staging equivalent and are skipped:
    agreement_value_range_en, has_amendments_en, report_type,
    fiscal_year, quarter_en
  - Staging uses _eng suffix; CSV uses _en — handled explicitly
  - recipient_legal_name and agreement_title_eng truncated to 255
    (staging limit, tighter than master_table)
  - naics_identifier kept as string (staging is varchar(10))
  - Rows with empty ref_number are skipped

Usage:
  export SRC_PASS=yourpassword
  python3 database/load-staging.py

  # Dry-run (no DB writes):
  python3 database/load-staging.py --dry-run
"""

import argparse
import csv
import os
import sys
from decimal import Decimal, InvalidOperation
from pathlib import Path

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

DIR_CLEANED = Path(r'C:\Users\user\Documents\GrantsConts\cleaned')

DB_HOST = '35.230.186.163'
DB_PORT = 3306
DB_NAME = 'grantconts'
DB_USER = os.environ.get('SRC_USER', 'pt_read')
DB_PASS = os.environ.get('SRC_PASS', '***REMOVED***')

BATCH_SIZE = 500

# ---------------------------------------------------------------------------
# CSV column indices (cleaned pipe-delimited files)
# ---------------------------------------------------------------------------
C_FORMAT      = 0
C_REF         = 1
C_AMEND_NUM   = 2
C_AMEND_DATE  = 3
C_AGTYPE      = 4
C_RTYPE       = 5
C_BN          = 6
C_RNAME       = 7
C_RESEARCH    = 8
C_COUNTRY     = 9
C_PROVINCE    = 10
C_CITY        = 11
C_POSTAL      = 12
C_RIDING_EN   = 13
C_RIDING_NUM  = 14
C_PROG_NAME   = 15
C_PROG_PURP   = 16
C_TITLE       = 17
C_AGR_NUM     = 18
C_VALUE       = 19
C_CURR_TYPE   = 20
C_CURR_VAL    = 21
C_SDATE       = 22
C_YEAR        = 23
C_EDATE       = 24
C_COVERAGE    = 25
C_DESC        = 26
C_NAICS       = 27
C_RESULTS     = 28
C_ADDINFO     = 29
C_ORG         = 30
C_VAL_RANGE   = 31
C_COV_EN      = 32
C_AMENDMENTS  = 33
C_REPORT_TYPE = 34
C_CITY_EN     = 35
C_FISCAL_YEAR = 36
C_QUARTER     = 37
C_ID          = 38

# Staging INSERT — all 39 columns
INSERT_SQL = """
INSERT INTO staging (
    ref_number, amendment_number, amendment_date,
    agreement_type, recipient_type,
    recipient_business_number, recipient_legal_name,
    research_organization_name,
    recipient_country, recipient_province,
    recipient_city, recipient_postal_code,
    federal_riding_name_eng, federal_riding_number,
    prog_name_eng, prog_purpose_eng,
    agreement_title_eng, agreement_number,
    agreement_value, foreign_currency_type, foreign_currency_value,
    agreement_start_date, year, agreement_end_date,
    coverage, description_eng,
    naics_identifier, expected_results_eng,
    additional_information_en,
    owner_org_en, coverage_en, format,
    recipient_city_en, id,
    agreement_value_range_en, has_amendments_en,
    fiscal_year, quarter_en, report_type
) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,
          %s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,
          %s,%s,%s,%s,%s)
"""


def n(v, maxlen=None):
    """Return None for empty/dash strings, else stripped value, optionally truncated."""
    s = v.strip() if v else ''
    if not s or s == '-':
        return None
    if maxlen and len(s) > maxlen:
        return s[:maxlen]
    return s


def to_int(v):
    s = v.strip() if v else ''
    if not s or s == '-':
        return None
    try:
        return int(s)
    except ValueError:
        return None


def to_decimal(v):
    s = v.strip() if v else ''
    if not s or s == '-':
        return None
    try:
        return Decimal(s)
    except InvalidOperation:
        return None


def to_date(v):
    s = v.strip() if v else ''
    return s if s else None


def row_to_params(row):
    """Map one cleaned CSV row to the 34-value staging INSERT tuple."""
    def g(idx):
        return row[idx] if len(row) > idx else ''

    return (
        n(g(C_REF), 50),                    # ref_number
        to_int(g(C_AMEND_NUM)),              # amendment_number
        to_date(g(C_AMEND_DATE)),            # amendment_date
        n(g(C_AGTYPE), 1),                   # agreement_type
        n(g(C_RTYPE), 1),                    # recipient_type
        n(g(C_BN), 255),                     # recipient_business_number
        n(g(C_RNAME), 255),                  # recipient_legal_name  (re-truncated to 255)
        n(g(C_RESEARCH), 255),               # research_organization_name
        n(g(C_COUNTRY), 2),                  # recipient_country
        n(g(C_PROVINCE), 2),                 # recipient_province
        n(g(C_CITY), 255),                   # recipient_city
        n(g(C_POSTAL), 10),                  # recipient_postal_code
        n(g(C_RIDING_EN), 255),              # federal_riding_name_eng
        to_int(g(C_RIDING_NUM)),             # federal_riding_number
        n(g(C_PROG_NAME), 255),              # prog_name_eng
        n(g(C_PROG_PURP)),                   # prog_purpose_eng (text, no limit)
        n(g(C_TITLE), 255),                  # agreement_title_eng (re-truncated to 255)
        n(g(C_AGR_NUM), 255),               # agreement_number
        to_decimal(g(C_VALUE)),              # agreement_value
        n(g(C_CURR_TYPE), 3),               # foreign_currency_type
        to_decimal(g(C_CURR_VAL)),          # foreign_currency_value
        to_date(g(C_SDATE)),                # agreement_start_date
        to_int(g(C_YEAR)),                  # year
        to_date(g(C_EDATE)),                # agreement_end_date
        n(g(C_COVERAGE)),                   # coverage (text)
        n(g(C_DESC)),                       # description_eng (text)
        n(g(C_NAICS), 10),                  # naics_identifier (varchar in staging)
        n(g(C_RESULTS)),                    # expected_results_eng (text)
        n(g(C_ADDINFO)),                    # additional_information_en (text)
        n(g(C_ORG), 255),                   # owner_org_en
        n(g(C_COV_EN), 255),               # coverage_en
        n(g(C_FORMAT), 255),               # format  (Record Format col)
        n(g(C_CITY_EN), 255),              # recipient_city_en
        n(g(C_ID), 255),                   # id
        n(g(C_VAL_RANGE)),                 # agreement_value_range_en
        n(g(C_AMENDMENTS), 3),             # has_amendments_en
        n(g(C_FISCAL_YEAR), 9),            # fiscal_year
        n(g(C_QUARTER), 2),                # quarter_en
        n(g(C_REPORT_TYPE), 255),          # report_type
    )


def connect():
    try:
        import mysql.connector
    except ImportError:
        print('ERROR: pip install mysql-connector-python', file=sys.stderr)
        sys.exit(1)
    print(f'Connecting to {DB_USER}@{DB_HOST}/{DB_NAME} …', flush=True)
    conn = mysql.connector.connect(
        host=DB_HOST, port=DB_PORT, database=DB_NAME,
        user=DB_USER, password=DB_PASS,
        connection_timeout=30, autocommit=False,
    )
    print('  Connected.', flush=True)
    return conn


def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--dry-run', action='store_true',
                        help='Parse and map rows but make no DB changes')
    args = parser.parse_args()

    cleaned_files = sorted(DIR_CLEANED.glob('*_dedup_cleaned.csv'))
    if not cleaned_files:
        print(f'No cleaned files found in {DIR_CLEANED}')
        sys.exit(1)

    print(f'Found {len(cleaned_files)} cleaned files to load.')

    if args.dry_run:
        print('Dry-run: no DB changes will be made.')
        conn = cur = None
    else:
        conn = connect()
        cur = conn.cursor()
        print('Truncating staging …', flush=True)
        cur.execute('TRUNCATE TABLE staging')
        conn.commit()
        print('  Done.', flush=True)

    total_rows    = 0
    total_skipped = 0
    total_errors  = 0
    batch         = []

    def flush_batch():
        nonlocal total_errors
        if not batch or args.dry_run:
            return
        try:
            cur.executemany(INSERT_SQL, batch)
            conn.commit()
        except Exception as e:
            conn.rollback()
            print(f'  BATCH ERROR ({len(batch)} rows): {e}', file=sys.stderr)
            total_errors += len(batch)
        batch.clear()

    for cln_path in cleaned_files:
        year_label = cln_path.stem.replace('_dedup_cleaned', '')
        file_rows = file_skipped = 0

        with open(cln_path, newline='', encoding='utf-8') as f:
            reader = csv.reader(f, delimiter='|')
            next(reader)  # skip header
            for row in reader:
                ref = row[C_REF].strip() if len(row) > C_REF else ''
                if not ref:
                    total_skipped += 1
                    file_skipped  += 1
                    continue

                params = row_to_params(row)
                batch.append(params)
                total_rows += 1
                file_rows  += 1

                if len(batch) >= BATCH_SIZE:
                    flush_batch()

        flush_batch()
        print(f'  {year_label:<12} {file_rows:>8,} rows loaded'
              + (f'  ({file_skipped} skipped)' if file_skipped else ''),
              flush=True)

    flush_batch()

    if conn:
        conn.close()

    print()
    print('=' * 55)
    print(f'  Total rows inserted:  {total_rows:>10,}')
    print(f'  Skipped (no ref):     {total_skipped:>10,}')
    print(f'  Errors:               {total_errors:>10,}')
    print('=' * 55)

    if not args.dry_run:
        print(f'\nStaging table loaded. Verify with:')
        print(f'  SELECT COUNT(*) FROM staging;')
        print(f'  SELECT owner_org_en, COUNT(*) FROM staging GROUP BY owner_org_en LIMIT 20;')


if __name__ == '__main__':
    main()
