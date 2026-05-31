#!/usr/bin/env python3
"""
Apply staging data to master_table with full corrections_history tracking.

Three operations in order:
  1. INSERT new records   — staging rows with no match in master_table
  2. UPDATE changed records — staging rows that differ from master_table (clean 1:1 matches)
  3. FLAG ambiguous records — GC-* cross-dept collisions logged as needs_review

change_source classification:
  government_update — actual data value changed (not just formatting)
  data_cleaning     — formatting/normalisation only (whitespace, NULL fill, etc.)
  new_record        — record did not exist in master_table
  needs_review      — ambiguous cross-department ref_number collision

Usage:
  python3 database/apply-staging.py
  python3 database/apply-staging.py --dry-run    # log changes, no DB writes
  python3 database/apply-staging.py --step insert # only INSERT new records
  python3 database/apply-staging.py --step update # only UPDATE changed records
  python3 database/apply-staging.py --step flag   # only FLAG ambiguous records
"""

import argparse
import sys
import mysql.connector
from decimal import Decimal

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

DB = dict(host='35.230.186.163', port=3306, database='grantconts',
          user='pt_read', password='***REMOVED***',
          connection_timeout=300, autocommit=False)

BATCH = 500

# Fields to compare and track in corrections_history
# (staging_col, master_col, is_cleaning_if_null_fill, label)
COMPARE_FIELDS = [
    ('agreement_value',      'agreement_value',      False, 'agreement_value'),
    ('agreement_start_date', 'agreement_start_date', False, 'agreement_start_date'),
    ('agreement_end_date',   'agreement_end_date',   False, 'agreement_end_date'),
    ('amendment_date',       'amendment_date',       False, 'amendment_date'),
    ('recipient_legal_name', 'recipient_legal_name', False, 'recipient_legal_name'),
    ('agreement_type',       'agreement_type',       False, 'agreement_type'),
    ('recipient_type',       'recipient_type',       True,  'recipient_type'),
    ('recipient_province',   'recipient_province',   True,  'recipient_province'),
    ('recipient_country',    'recipient_country',    True,  'recipient_country'),
    ('recipient_city',       'recipient_city',       True,  'recipient_city'),
    ('recipient_postal_code','recipient_postal_code',True,  'recipient_postal_code'),
    ('prog_name_eng',        'prog_name_en',         False, 'prog_name_en'),
    ('description_eng',      'description_eng',      False, 'description_eng'),
    ('naics_identifier',     'naics_identifier',     True,  'naics_identifier'),
    ('federal_riding_number','federal_riding_number',True,  'federal_riding_number'),
    ('owner_org_en',         'owner_org_en',         True,  'owner_org_en'),
]

# Staging columns to copy into master_table on INSERT (mapping staging → master)
INSERT_MAP = {
    'ref_number':             'ref_number',
    'amendment_number':       'amendment_number',
    'amendment_date':         'amendment_date',
    'agreement_type':         'agreement_type',
    'recipient_type':         'recipient_type',
    'recipient_business_number': 'recipient_business_number',
    'recipient_legal_name':   'recipient_legal_name',
    'research_organization_name': 'research_organization_name',
    'recipient_country':      'recipient_country',
    'recipient_province':     'recipient_province',
    'recipient_city':         'recipient_city',
    'recipient_postal_code':  'recipient_postal_code',
    'federal_riding_name_eng':'federal_riding_name_en',
    'federal_riding_number':  'federal_riding_number',
    'prog_name_eng':          'prog_name_en',
    'prog_purpose_eng':       'prog_purpose_en',
    'agreement_title_eng':    'agreement_title_en',
    'agreement_number':       'agreement_number',
    'agreement_value':        'agreement_value',
    'foreign_currency_type':  'foreign_currency_type',
    'foreign_currency_value': 'foreign_currency_value',
    'agreement_start_date':   'agreement_start_date',
    'year':                   'year',
    'agreement_end_date':     'agreement_end_date',
    'coverage':               'coverage',
    'description_eng':        'description_eng',
    'naics_identifier':       'naics_identifier',
    'expected_results_eng':   'expected_results_en',
    'additional_information_en': 'additional_information_en',
    'owner_org_en':           'owner_org_en',
    'coverage_en':            'coverage_en',
    'format':                 'format_en',
    'recipient_city_en':      'recipient_city_en',
    'fiscal_year':            'fiscal_year',
    'quarter_en':             'quarter_en',
    'id':                     'id',
}

MASTER_COLS = list(INSERT_MAP.values())
STAGING_COLS = list(INSERT_MAP.keys())
INSERT_SQL = (
    'INSERT INTO master_table (' + ','.join(f'`{c}`' for c in MASTER_COLS) + ') '
    'VALUES (' + ','.join(['%s'] * len(MASTER_COLS)) + ')'
)

HISTORY_SQL = (
    'INSERT INTO corrections_history '
    '(record_id, ref_number, amendment_number, field_name, '
    ' old_value, new_value, change_source) '
    'VALUES (%s,%s,%s,%s,%s,%s,%s)'
)


def classify(field, old_val, new_val, is_null_fill):
    """Classify a change as government_update or data_cleaning."""
    old_s = str(old_val).strip() if old_val is not None else ''
    new_s = str(new_val).strip() if new_val is not None else ''

    # Old had value, new is empty → probably a cleaning normalisation
    if old_s and not new_s:
        return 'data_cleaning'

    # Old was empty, new has value → NULL fill from cleaning
    if not old_s and new_s and is_null_fill:
        return 'data_cleaning'

    # Name differs only by leading/trailing "The " or whitespace → cleaning
    if field == 'recipient_legal_name':
        if old_s.lstrip('The ').strip() == new_s.lstrip('The ').strip():
            return 'data_cleaning'
        if old_s.strip() == new_s.strip():
            return 'data_cleaning'

    # Decimal value change of less than $0.02 → rounding, not real change
    if field == 'agreement_value':
        try:
            if abs(Decimal(str(old_val)) - Decimal(str(new_val))) < Decimal('0.02'):
                return 'data_cleaning'
        except Exception:
            pass

    return 'government_update'


def connect():
    print(f'Connecting to {DB["user"]}@{DB["host"]}/{DB["database"]} ...', flush=True)
    conn = mysql.connector.connect(**DB)
    print('  Connected.', flush=True)
    return conn


# ── STEP 1: INSERT new records ───────────────────────────────────────────────

def step_insert(conn, dry_run):
    cur = conn.cursor(dictionary=True)
    wcur = conn.cursor()
    print('\n=== STEP 1: INSERT new records ===', flush=True)

    # Unambiguous new records (1:1 match, not in master_table)
    # PLUS ambiguous new records (GC-* multi-org rows with no master_table match by ref+amend+org)
    cur.execute(
        'SELECT s.* FROM staging s '
        'LEFT JOIN master_table m '
        '  ON s.ref_number = m.ref_number AND s.amendment_number <=> m.amendment_number '
        'WHERE m.primary_key IS NULL AND s.ref_number IS NOT NULL '
        'UNION ALL '
        # Ambiguous rows that DO have a ref+amend match in master_table but NOT an org match
        # i.e. they belong to a different org — treat as new
        'SELECT s.* FROM staging s '
        'INNER JOIN master_table m '
        '  ON s.ref_number = m.ref_number AND s.amendment_number <=> m.amendment_number '
        'LEFT JOIN master_table m2 '
        '  ON s.ref_number = m2.ref_number '
        ' AND s.amendment_number <=> m2.amendment_number '
        ' AND COALESCE(s.owner_org_en,"") = COALESCE(m2.owner_org_en,"") '
        'WHERE m2.primary_key IS NULL '
        '  AND s.ref_number IS NOT NULL '
        '  AND (SELECT COUNT(*) FROM staging s2 '
        '       WHERE s2.ref_number = s.ref_number '
        '         AND s2.amendment_number <=> s.amendment_number) > 1'
    )

    insert_batch = []
    history_batch = []
    inserted = errors = 0

    for row in cur:
        vals = tuple(row.get(c) for c in STAGING_COLS)
        insert_batch.append(vals)

        history_batch.append((
            row.get('id') or f"{row.get('owner_org_en','')},{row.get('ref_number')},{row.get('amendment_number','current')}",
            row.get('ref_number'),
            row.get('amendment_number'),
            'all_fields',
            None,
            row.get('agreement_value'),
            'new_record',
        ))

        if len(insert_batch) >= BATCH:
            if not dry_run:
                try:
                    wcur.executemany(INSERT_SQL, insert_batch)
                    wcur.executemany(HISTORY_SQL, history_batch)
                    conn.commit()
                    inserted += len(insert_batch)
                except Exception as e:
                    conn.rollback()
                    errors += len(insert_batch)
                    print(f'  ERROR: {e}', file=sys.stderr)
            else:
                inserted += len(insert_batch)
            insert_batch.clear()
            history_batch.clear()

            if inserted % 10_000 == 0:
                print(f'  ... {inserted:,} inserted', flush=True)

    if insert_batch and not dry_run:
        try:
            wcur.executemany(INSERT_SQL, insert_batch)
            wcur.executemany(HISTORY_SQL, history_batch)
            conn.commit()
            inserted += len(insert_batch)
        except Exception as e:
            conn.rollback()
            errors += len(insert_batch)
    elif insert_batch:
        inserted += len(insert_batch)

    print(f'  Inserted: {inserted:,}  Errors: {errors:,}')
    cur.close()
    wcur.close()


# ── STEP 2: UPDATE changed records ──────────────────────────────────────────

def step_update(conn, dry_run):
    cur = conn.cursor(dictionary=True)
    wcur = conn.cursor()
    print('\n=== STEP 2: UPDATE changed records ===', flush=True)

    change_cond = ' OR '.join(
        f'COALESCE(CAST(s.{sf} AS CHAR),"") != COALESCE(CAST(m.{mf} AS CHAR),"")'
        for sf, mf, _, _ in COMPARE_FIELDS
    )

    # Unambiguous changes (1:1) PLUS org-matched ambiguous changes (223 rows)
    cur.execute(
        'SELECT s.*, m.primary_key as m_pk, '
        + ','.join(f'm.`{mf}` as m_{mf}' for _, mf, _, _ in COMPARE_FIELDS)
        + ' FROM staging s '
        'INNER JOIN master_table m '
        '  ON s.ref_number = m.ref_number AND s.amendment_number <=> m.amendment_number '
        'WHERE ('
        '  (SELECT COUNT(*) FROM staging s2 '
        '   WHERE s2.ref_number = s.ref_number '
        '     AND s2.amendment_number <=> s.amendment_number) = 1 '
        '  OR '
        '  ((SELECT COUNT(*) FROM staging s2 '
        '    WHERE s2.ref_number = s.ref_number '
        '      AND s2.amendment_number <=> s.amendment_number) > 1 '
        '   AND COALESCE(s.owner_org_en,"") = COALESCE(m.owner_org_en,""))'
        ') AND (' + change_cond + ')'
    )

    history_batch = []
    update_batch = []
    updated = errors = 0

    for row in cur:
        record_id = (row.get('id') or
                     f"{row.get('owner_org_en','')},{row.get('ref_number')},{row.get('amendment_number','current')}")
        ref = row.get('ref_number')
        amend = row.get('amendment_number')
        pk = row['m_pk']

        changed_fields = {}
        for sf, mf, is_null_fill, label in COMPARE_FIELDS:
            s_val = row.get(sf)
            m_val = row.get(f'm_{mf}')
            s_str = str(s_val).strip() if s_val is not None else ''
            m_str = str(m_val).strip() if m_val is not None else ''
            if s_str != m_str:
                source = classify(label, m_val, s_val, is_null_fill)
                history_batch.append((record_id, ref, amend, label,
                                      m_str or None, s_str or None, source))
                changed_fields[mf] = s_val

        if changed_fields:
            set_clause = ', '.join(f'`{k}` = %s' for k in changed_fields)
            update_vals = list(changed_fields.values()) + [pk]
            update_batch.append((set_clause, update_vals))

        if len(update_batch) >= BATCH:
            if not dry_run:
                try:
                    for set_clause, vals in update_batch:
                        wcur.execute(
                            f'UPDATE master_table SET {set_clause} WHERE primary_key = %s',
                            vals
                        )
                    wcur.executemany(HISTORY_SQL, history_batch)
                    conn.commit()
                    updated += len(update_batch)
                except Exception as e:
                    conn.rollback()
                    errors += len(update_batch)
                    print(f'  ERROR: {e}', file=sys.stderr)
            else:
                updated += len(update_batch)

            update_batch.clear()
            history_batch.clear()

            if updated % 5_000 == 0:
                print(f'  ... {updated:,} updated', flush=True)

    # Flush remainder
    if update_batch and not dry_run:
        try:
            for set_clause, vals in update_batch:
                wcur.execute(
                    f'UPDATE master_table SET {set_clause} WHERE primary_key = %s', vals
                )
            wcur.executemany(HISTORY_SQL, history_batch)
            conn.commit()
            updated += len(update_batch)
        except Exception as e:
            conn.rollback()
            errors += len(update_batch)
    elif update_batch:
        updated += len(update_batch)

    print(f'  Updated: {updated:,}  Errors: {errors:,}')
    cur.close()
    wcur.close()


# ── STEP 3: FLAG ambiguous records ──────────────────────────────────────────

def step_flag(conn, dry_run):
    cur = conn.cursor(dictionary=True)
    wcur = conn.cursor()
    print('\n=== STEP 3: FLAG ambiguous (needs_review) ===', flush=True)

    # Truly ambiguous: ref+amend has multiple staging rows AND master_table has
    # a match, BUT no staging row for that ref+amend can be org-matched to
    # master_table — so we genuinely cannot determine which staging row
    # corresponds to the existing master_table record.
    cur.execute(
        'SELECT s.id, s.ref_number, s.amendment_number, s.owner_org_en, '
        '  s.agreement_value, s.recipient_legal_name '
        'FROM staging s '
        'WHERE s.ref_number IS NOT NULL '
        # Must be ambiguous
        '  AND (SELECT COUNT(*) FROM staging s2 '
        '       WHERE s2.ref_number = s.ref_number '
        '         AND s2.amendment_number <=> s.amendment_number) > 1 '
        # Must have a master_table match on ref+amend
        '  AND EXISTS ('
        '    SELECT 1 FROM master_table m '
        '    WHERE m.ref_number = s.ref_number '
        '      AND m.amendment_number <=> s.amendment_number) '
        # But NO staging row for this ref+amend can be org-matched to master_table
        # (if one CAN be matched, step_update handles it and those others are new records)
        '  AND NOT EXISTS ('
        '    SELECT 1 FROM staging s3 '
        '    INNER JOIN master_table m '
        '      ON s3.ref_number = m.ref_number '
        '     AND s3.amendment_number <=> m.amendment_number '
        '    WHERE s3.ref_number = s.ref_number '
        '      AND s3.amendment_number <=> s.amendment_number '
        '      AND COALESCE(s3.owner_org_en,"") = COALESCE(m.owner_org_en,"") '
        '      AND COALESCE(s3.owner_org_en,"") != ""'
        '  )'
    )

    history_batch = []
    flagged = 0

    for row in cur:
        record_id = (row.get('id') or
                     f"{row.get('owner_org_en','')},{row.get('ref_number')},{row.get('amendment_number','current')}")
        history_batch.append((
            record_id,
            row.get('ref_number'),
            row.get('amendment_number'),
            'all_fields',
            None,
            None,
            'needs_review',
        ))
        flagged += 1

        if len(history_batch) >= BATCH:
            if not dry_run:
                try:
                    wcur.executemany(HISTORY_SQL, history_batch)
                    conn.commit()
                except Exception as e:
                    conn.rollback()
                    print(f'  ERROR: {e}', file=sys.stderr)
            history_batch.clear()

    if history_batch and not dry_run:
        wcur.executemany(HISTORY_SQL, history_batch)
        conn.commit()

    print(f'  Flagged for review: {flagged:,}')
    cur.close()
    wcur.close()


# ── Main ─────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--dry-run', action='store_true',
                        help='Log what would change, make no DB writes')
    parser.add_argument('--step', choices=['insert', 'update', 'flag', 'all'],
                        default='all', help='Which step to run (default: all)')
    args = parser.parse_args()

    if args.dry_run:
        print('DRY-RUN: no changes will be written to the database.')

    conn = connect()

    if args.step in ('insert', 'all'):
        step_insert(conn, args.dry_run)

    if args.step in ('update', 'all'):
        step_update(conn, args.dry_run)

    if args.step in ('flag', 'all'):
        step_flag(conn, args.dry_run)

    conn.close()
    print('\nDone.')


if __name__ == '__main__':
    main()
