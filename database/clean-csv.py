#!/usr/bin/env python3
"""
Clean a deduplicated government grants CSV for MySQL master_table import.

Reads the deduped CSV (output of deduplicate-csv.py) and applies per-column
cleaning rules to match the master_table schema:

  - NULL sentinels: "-", "0001-01-01T00:00:00Z", "" → empty (NULL on import)
  - Dates: "2025-11-05T00:00:00Z" → "2025-11-05"
  - Agreement Type: full text → single char code (C/G/O)
  - Recipient Type: full text → single char code (A/F/G/I/N/O/P/S)
  - Province/Territory: full name → 2-char code (ON/QC/BC/…)
  - Amendment Number: "current" → "" (NULL), "00"/"01" → 0/1 (int)
  - Postal Code: trim whitespace; truncate to 7 chars; log rows > 7
  - Foreign Currency Type: correct values landing in wrong column → NULL
  - varchar overflows: log and truncate to column max length
  - HTML entities: unescape (&amp; → &, etc.)
  - All string fields: strip leading/trailing whitespace

Usage:
  python3 database/clean-csv.py input_deduped.csv
  python3 database/clean-csv.py input_deduped.csv --output clean.csv
  python3 database/clean-csv.py input_deduped.csv --dry-run   # stats only

Output format:
  Pipe-delimited (|), all fields quoted — "field1"|"field2"|...
  Input delimiter is auto-detected (reads comma or pipe output from deduplicate-csv.py).

Output files (same directory as input):
  2023-2024_dedup_cleaned.csv      — cleaned data, 39 columns, header preserved
  2023-2024_dedup_cleaning_log.txt — summary + examples of every change made
"""

import argparse
import csv
import html
import os
import re
import sys
from pathlib import Path

# Output format — pipe-delimited, every field quoted.
OUT_DELIMITER = '|'
OUT_QUOTING   = csv.QUOTE_ALL


def detect_delimiter(path: str) -> str:
    """Return '|' or ',' based on which appears more in the first line."""
    with open(path, newline='', encoding='utf-8-sig') as f:
        first = f.readline()
    return '|' if first.count('|') > first.count(',') else ','

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
if hasattr(sys.stderr, 'reconfigure'):
    sys.stderr.reconfigure(encoding='utf-8', errors='replace')

# ---------------------------------------------------------------------------
# CSV column indices (0-based) matching standard government grants export
# ---------------------------------------------------------------------------
C_RECORD_FORMAT       = 0
C_REF_NUMBER          = 1
C_AMENDMENT_NUMBER    = 2
C_AMENDMENT_DATE      = 3
C_AGREEMENT_TYPE      = 4
C_RECIPIENT_TYPE      = 5
C_BUSINESS_NUMBER     = 6
C_RECIPIENT_NAME      = 7
C_RESEARCH_ORG        = 8
C_COUNTRY             = 9
C_PROVINCE            = 10
C_CITY                = 11
C_POSTAL_CODE         = 12
C_RIDING_NAME_EN      = 13
C_RIDING_NUMBER       = 14
C_PROG_NAME_EN        = 15
C_PROG_PURPOSE_EN     = 16
C_AGREEMENT_TITLE_EN  = 17
C_AGREEMENT_NUMBER    = 18
C_AGREEMENT_VALUE_CAD = 19
C_FOREIGN_CURR_TYPE   = 20
C_FOREIGN_CURR_VALUE  = 21
C_START_DATE          = 22
C_CALENDAR_YEAR       = 23
C_END_DATE            = 24
C_COVERAGE            = 25
C_DESCRIPTION_EN      = 26
C_NAICS               = 27
C_EXPECTED_RESULTS_EN = 28
C_ADDITIONAL_INFO_EN  = 29
C_ORGANIZATION        = 30
C_AGREEMENT_VALUE_RNG = 31
C_COVERAGE_EN         = 32
C_AMENDMENTS          = 33
C_REPORT_TYPE         = 34
C_CITY_EN             = 35
C_FISCAL_YEAR         = 36
C_QUARTER             = 37
C_ID                  = 38

# ---------------------------------------------------------------------------
# Lookup maps
# ---------------------------------------------------------------------------
NULL_SENTINELS = {'', '-', 'N/A', 'n/a', 'none', 'None', 'null', 'NULL'}
NULL_DATE_SENTINELS = {'0001-01-01T00:00:00Z', '0001-01-01', ''}

AGREEMENT_TYPE_MAP = {
    'Contribution':           'C',
    'Grant':                  'G',
    'Other transfer payment': 'O',
}

RECIPIENT_TYPE_MAP = {
    'Not-for-profit organization or charity': 'N',
    'Not-for-profit organization':            'N',
    'For-profit organization':                'F',
    'Individual or sole proprietorship':      'P',
    'Individual or sole proprietor':          'P',
    'Aboriginal recipient':                   'A',
    'Other':                                  'O',
    'Government':                             'G',
    'Academia':                               'S',
    'International (non-government)':         'I',
}

PROVINCE_MAP = {
    'Ontario':                    'ON',
    'Quebec':                     'QC',
    'British Columbia':           'BC',
    'Alberta':                    'AB',
    'Nova Scotia':                'NS',
    'Saskatchewan':               'SK',
    'New Brunswick':              'NB',
    'Manitoba':                   'MB',
    'Newfoundland & Labrador':    'NL',
    'Newfoundland and Labrador':  'NL',
    'Prince Edward Island':       'PE',
    'Northwest Territories':      'NT',
    'Yukon':                      'YT',
    'Nunavut':                    'NU',
    'Other':                      '',   # indeterminate
}

# ---------------------------------------------------------------------------
# Cleaning helpers
# ---------------------------------------------------------------------------

def to_null(v: str) -> str:
    """Return '' if the value is a known null sentinel, else return stripped value."""
    s = v.strip()
    return '' if s.lower() in {x.lower() for x in NULL_SENTINELS} else s


def clean_date(v: str) -> str:
    """Strip ISO timestamp suffix; convert null-date sentinels to empty."""
    s = v.strip()
    if s in NULL_DATE_SENTINELS or s.lower() in {'', '-'}:
        return ''
    # "2025-11-05T00:00:00Z" → "2025-11-05"
    if 'T' in s:
        s = s.split('T')[0]
    # Basic sanity: must look like YYYY-MM-DD
    if re.match(r'^\d{4}-\d{2}-\d{2}$', s):
        # Reject implausible years (< 1900 or far future)
        year = int(s[:4])
        if year < 1900 or year > 2200:
            return ''
        return s
    return ''


def clean_year(v: str) -> str:
    s = to_null(v)
    if not s:
        return ''
    try:
        y = int(float(s))
        if 1901 <= y <= 2155:
            return str(y)
    except ValueError:
        pass
    return ''


def clean_int(v: str) -> str:
    s = to_null(v)
    if not s:
        return ''
    try:
        return str(int(float(s)))
    except ValueError:
        return ''


def clean_decimal(v: str) -> str:
    s = to_null(v)
    if not s:
        return ''
    # Remove currency symbols, commas
    s = re.sub(r'[,$€£¥]', '', s).strip()
    try:
        f = float(s)
        return f'{f:.2f}'
    except ValueError:
        return ''


def clean_amendment_number(v: str) -> str:
    s = v.strip()
    if s.lower() == 'current' or s in NULL_SENTINELS:
        return ''  # NULL in DB
    try:
        return str(int(s))   # "00"→0, "01"→1, "02"→2, …
    except ValueError:
        return ''


def clean_postal_code(v: str, country: str) -> tuple[str, str | None]:
    """
    Returns (cleaned_value, warning_message | None).
    Truncates to 7 chars (DB is char(7)); warns on truncation.
    """
    s = v.strip()
    if s in NULL_SENTINELS or not s:
        return '', None
    if len(s) > 7:
        truncated = s[:7].strip()
        return truncated, f'truncated postal "{s}" (len={len(s)}) to "{truncated}" (country={country})'
    return s, None


def clean_varchar(v: str, max_len: int) -> tuple[str, str | None]:
    """Returns (cleaned_value, warning | None). Strips and truncates."""
    s = to_null(v)
    if not s:
        return '', None
    if len(s) > max_len:
        truncated = s[:max_len]
        return truncated, f'truncated {len(s)}-char value to {max_len}: "{s[:60]}…"'
    return s, None


def clean_html(v: str) -> tuple[str, bool]:
    """Unescape HTML entities. Returns (cleaned, changed)."""
    s = to_null(v)
    if not s:
        return '', False
    unescaped = html.unescape(s)
    return unescaped, unescaped != s


def clean_foreign_currency_type(v: str) -> str:
    """
    char(3): accept only known 3-letter ISO currency codes.
    Reject anything that looks like a date or other garbage.
    """
    s = to_null(v)
    if not s:
        return ''
    # If it doesn't look like a 3-letter currency code, nullify it
    if re.match(r'^[A-Z]{3}$', s):
        return s
    return ''


# ---------------------------------------------------------------------------
# Stats tracker
# ---------------------------------------------------------------------------

class Stats:
    def __init__(self):
        self.changes: dict[str, list] = {}  # field → list of (row_num, before, after)

    def record(self, field: str, row_num: int, before: str, after: str):
        if before != after:
            if field not in self.changes:
                self.changes[field] = []
            self.changes[field].append((row_num, before, after))

    def record_warn(self, field: str, row_num: int, message: str):
        if field not in self.changes:
            self.changes[field] = []
        self.changes[field].append((row_num, message, '[see above]'))

    def summary(self) -> str:
        lines = ['=== Cleaning Summary ===', '']
        total = sum(len(v) for v in self.changes.values())
        lines.append(f'Total field changes: {total:,}')
        lines.append('')
        for field, entries in sorted(self.changes.items(), key=lambda x: -len(x[1])):
            lines.append(f'  {field}: {len(entries):,} changes')
        lines.append('')
        lines.append('=== Examples (up to 5 per field) ===')
        lines.append('')
        for field, entries in sorted(self.changes.items(), key=lambda x: -len(x[1])):
            lines.append(f'--- {field} ({len(entries):,} total) ---')
            for row_num, before, after in entries[:5]:
                b = str(before)[:80].replace('\n', '\\n')
                a = str(after)[:80].replace('\n', '\\n')
                lines.append(f'  row {row_num}: [{b}] → [{a}]')
            lines.append('')
        return '\n'.join(lines)


# ---------------------------------------------------------------------------
# Main cleaning loop
# ---------------------------------------------------------------------------

def clean_row(row: list, row_num: int, stats: Stats) -> list:
    out = list(row)  # copy

    def apply(col_idx: str, col_name: str, new_val: str):
        old = out[col_idx]
        if old != new_val:
            stats.record(col_name, row_num, old, new_val)
            out[col_idx] = new_val

    def apply_warn(col_idx: int, col_name: str, new_val: str, warning: str | None):
        apply(col_idx, col_name, new_val)
        if warning:
            stats.record_warn(col_name + ' [TRUNCATED]', row_num, warning)

    # --- Simple null/trim fields ---
    for idx, name, maxlen in [
        (C_RECORD_FORMAT,       'format_en',                    255),
        (C_REF_NUMBER,          'ref_number',                    50),
        (C_BUSINESS_NUMBER,     'recipient_business_number',    255),
        (C_RESEARCH_ORG,        'research_organization_name',   255),
        (C_CITY,                'recipient_city',               255),
        (C_PROG_NAME_EN,        'prog_name_en',                 255),
        (C_AGREEMENT_NUMBER,    'agreement_number',             100),
        (C_ORGANIZATION,        'owner_org_en',                9999),  # text
        (C_AGREEMENT_VALUE_RNG, 'agreement_value_range_en',    9999),  # text
        (C_COVERAGE_EN,         'coverage_en',                 9999),  # text
        (C_COVERAGE,            'coverage',                    9999),  # text
        (C_PROG_PURPOSE_EN,     'prog_purpose_en',             9999),  # text
        (C_EXPECTED_RESULTS_EN, 'expected_results_en',         9999),  # text
        (C_ADDITIONAL_INFO_EN,  'additional_information_en',   9999),  # text
        (C_AMENDMENTS,          'has_amendments_en',              3),
        (C_REPORT_TYPE,         'report_type',                  255),
        (C_CITY_EN,             'recipient_city_en',            255),
        (C_FISCAL_YEAR,         'fiscal_year',                    9),
        (C_QUARTER,             'quarter_en',                     2),
        (C_ID,                  'id',                           255),
    ]:
        if len(out) > idx:
            cleaned, warn = clean_varchar(out[idx], maxlen)
            apply_warn(idx, name, cleaned, warn)

    # --- Agreement Title (varchar 800) ---
    if len(out) > C_AGREEMENT_TITLE_EN:
        cleaned, warn = clean_varchar(out[C_AGREEMENT_TITLE_EN], 800)
        apply_warn(C_AGREEMENT_TITLE_EN, 'agreement_title_en', cleaned, warn)

    # --- Recipient Legal Name (varchar 350) ---
    if len(out) > C_RECIPIENT_NAME:
        cleaned, warn = clean_varchar(out[C_RECIPIENT_NAME], 350)
        apply_warn(C_RECIPIENT_NAME, 'recipient_legal_name', cleaned, warn)

    # --- Federal Riding Name (varchar 100) ---
    if len(out) > C_RIDING_NAME_EN:
        cleaned, warn = clean_varchar(out[C_RIDING_NAME_EN], 100)
        apply_warn(C_RIDING_NAME_EN, 'federal_riding_name_en', cleaned, warn)

    # --- Amendment Number ---
    if len(out) > C_AMENDMENT_NUMBER:
        apply(C_AMENDMENT_NUMBER, 'amendment_number', clean_amendment_number(out[C_AMENDMENT_NUMBER]))

    # --- Date fields ---
    for idx, name in [
        (C_AMENDMENT_DATE, 'amendment_date'),
        (C_START_DATE,     'agreement_start_date'),
        (C_END_DATE,       'agreement_end_date'),
    ]:
        if len(out) > idx:
            apply(idx, name, clean_date(out[idx]))

    # --- Agreement Type → char(1) ---
    if len(out) > C_AGREEMENT_TYPE:
        v = out[C_AGREEMENT_TYPE].strip()
        mapped = AGREEMENT_TYPE_MAP.get(v, '' if v in NULL_SENTINELS else None)
        if mapped is None:
            # Unknown value — log and nullify
            stats.record('agreement_type [UNKNOWN]', row_num, v, '')
            mapped = ''
        apply(C_AGREEMENT_TYPE, 'agreement_type', mapped)

    # --- Recipient Type → char(1) ---
    if len(out) > C_RECIPIENT_TYPE:
        v = out[C_RECIPIENT_TYPE].strip()
        mapped = RECIPIENT_TYPE_MAP.get(v, '' if v in NULL_SENTINELS else None)
        if mapped is None:
            stats.record('recipient_type [UNKNOWN]', row_num, v, '')
            mapped = ''
        apply(C_RECIPIENT_TYPE, 'recipient_type', mapped)

    # --- Province → char(2) ---
    if len(out) > C_PROVINCE:
        v = out[C_PROVINCE].strip()
        # Already a 2-char code? Leave it.
        if re.match(r'^[A-Z]{2}$', v):
            apply(C_PROVINCE, 'recipient_province', v)
        else:
            mapped = PROVINCE_MAP.get(v, '' if v in NULL_SENTINELS else None)
            if mapped is None:
                stats.record('recipient_province [UNKNOWN]', row_num, v, '')
                mapped = ''
            apply(C_PROVINCE, 'recipient_province', mapped)

    # --- Country: trim only (already 2-char codes in data) ---
    if len(out) > C_COUNTRY:
        v = out[C_COUNTRY].strip()
        apply(C_COUNTRY, 'recipient_country', '' if v in NULL_SENTINELS else v)

    # --- Postal Code ---
    if len(out) > C_POSTAL_CODE:
        country = out[C_COUNTRY].strip() if len(out) > C_COUNTRY else ''
        cleaned, warn = clean_postal_code(out[C_POSTAL_CODE], country)
        apply(C_POSTAL_CODE, 'recipient_postal_code', cleaned)
        if warn:
            stats.record_warn('recipient_postal_code [TRUNCATED]', row_num, warn)

    # --- Numeric fields ---
    if len(out) > C_RIDING_NUMBER:
        apply(C_RIDING_NUMBER, 'federal_riding_number', clean_int(out[C_RIDING_NUMBER]))

    if len(out) > C_AGREEMENT_VALUE_CAD:
        apply(C_AGREEMENT_VALUE_CAD, 'agreement_value', clean_decimal(out[C_AGREEMENT_VALUE_CAD]))

    if len(out) > C_FOREIGN_CURR_VALUE:
        apply(C_FOREIGN_CURR_VALUE, 'foreign_currency_value', clean_decimal(out[C_FOREIGN_CURR_VALUE]))

    if len(out) > C_CALENDAR_YEAR:
        apply(C_CALENDAR_YEAR, 'year', clean_year(out[C_CALENDAR_YEAR]))

    if len(out) > C_NAICS:
        apply(C_NAICS, 'naics_identifier', clean_int(out[C_NAICS]))

    # --- Foreign Currency Type: char(3), must be valid ISO code ---
    if len(out) > C_FOREIGN_CURR_TYPE:
        apply(C_FOREIGN_CURR_TYPE, 'foreign_currency_type', clean_foreign_currency_type(out[C_FOREIGN_CURR_TYPE]))

    # --- HTML entities in text fields ---
    for idx, name in [
        (C_DESCRIPTION_EN,      'description_eng'),
        (C_EXPECTED_RESULTS_EN, 'expected_results_en'),
        (C_ADDITIONAL_INFO_EN,  'additional_information_en'),
        (C_PROG_PURPOSE_EN,     'prog_purpose_en'),
    ]:
        if len(out) > idx:
            cleaned, changed = clean_html(out[idx])
            if changed:
                apply(idx, name + ' [HTML]', cleaned)

    return out


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('input', help='Path to the deduplicated CSV file')
    parser.add_argument('--output', help='Output CSV path (default: <input>_cleaned.csv)')
    parser.add_argument('--log', help='Log file path (default: <input>_cleaning_log.txt)')
    parser.add_argument('--dry-run', action='store_true', help='Print summary only — do not write files')
    args = parser.parse_args()

    if not os.path.isfile(args.input):
        print(f'ERROR: file not found: {args.input}', file=sys.stderr)
        sys.exit(1)

    p = Path(args.input)
    output_path = args.output or str(p.parent / (p.stem + '_cleaned' + p.suffix))
    log_path    = args.log    or str(p.parent / (p.stem + '_cleaning_log.txt'))

    print(f'Input:  {args.input}  (detected delimiter: {"pipe" if detect_delimiter(args.input) == "|" else "comma"})')
    if args.dry_run:
        print('Dry-run: no files will be written.')
    else:
        print(f'Output: {output_path}  (pipe-delimited, fully quoted)')
        print(f'Log:    {log_path}')

    stats = Stats()
    rows_read = 0

    delim    = detect_delimiter(args.input)
    in_file  = open(args.input, newline='', encoding='utf-8-sig')
    out_file = None if args.dry_run else open(output_path, 'w', newline='', encoding='utf-8')

    reader = csv.reader(in_file, delimiter=delim)
    writer = csv.writer(out_file, delimiter=OUT_DELIMITER, quoting=OUT_QUOTING) if out_file else None

    header = next(reader)
    if writer:
        writer.writerow(header)

    print('Cleaning …', flush=True)
    for row in reader:
        rows_read += 1
        if rows_read % 25_000 == 0:
            print(f'  … {rows_read:,} rows', flush=True)

        cleaned = clean_row(row, rows_read + 1, stats)  # +1 for header
        if writer:
            writer.writerow(cleaned)

    in_file.close()
    if out_file:
        out_file.close()

    summary = stats.summary()
    print()
    print(summary)

    if not args.dry_run:
        with open(log_path, 'w', encoding='utf-8') as lf:
            lf.write(f'Input:  {args.input}\n')
            lf.write(f'Output: {output_path}\n')
            lf.write(f'Rows:   {rows_read:,}\n\n')
            lf.write(summary)
        print(f'\nLog written to: {log_path}')
        print(f'Clean file ready: {output_path}')

    print(f'\nDone. {rows_read:,} rows processed.')


if __name__ == '__main__':
    main()
