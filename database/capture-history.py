#!/usr/bin/env python3
"""
STEP 1 of the master_table rebuild: capture change history (non-destructive).

Diffs the current master_table against staging on CLEAN 1:1 (ref_number,
amendment_number) matches only — i.e. where exactly one staging row and one
master row share the key, so the comparison is reliable. Collision refs
(same ref+amend across departments) are excluded because they cannot be
matched correctly.

Every differing field is logged to corrections_history. Monetary changes
(agreement_value, foreign_currency_value) are ALWAYS logged and classified:
  government_update — values differ by >= $0.02 (a real change)
  data_cleaning     — values differ by < $0.02 (rounding/normalisation)

Writes nothing to master_table. Safe to run before the rebuild.

Usage:
  python3 database/capture-history.py
  python3 database/capture-history.py --dry-run   # count only, no history writes
"""

import argparse
import sys
from decimal import Decimal, InvalidOperation
import mysql.connector

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

DB = dict(host='35.230.186.163', port=3306, database='grantconts',
          user='pt_read', password='REDACTED_DB_PASSWORD',
          connection_timeout=600, autocommit=False)

BATCH = 1000

# (staging_col, master_col, is_null_fill, label, is_monetary)
COMPARE_FIELDS = [
    ('agreement_value',        'agreement_value',        False, 'agreement_value',        True),
    ('foreign_currency_value', 'foreign_currency_value', False, 'foreign_currency_value', True),
    ('agreement_start_date',   'agreement_start_date',   False, 'agreement_start_date',   False),
    ('agreement_end_date',     'agreement_end_date',     False, 'agreement_end_date',     False),
    ('amendment_date',         'amendment_date',         False, 'amendment_date',         False),
    # NOTE: recipient_legal_name (255 in staging vs 350 in master) and
    # agreement_title_eng (255 vs 800) are EXCLUDED here — staging truncated them
    # to 255, so a staging-based diff produces false "changes" for long values.
    # Their real changes are evaluated from the full-width cleaned CSVs at rebuild.
    ('agreement_type',         'agreement_type',         False, 'agreement_type',         False),
    ('recipient_type',         'recipient_type',         True,  'recipient_type',         False),
    ('recipient_province',     'recipient_province',     True,  'recipient_province',     False),
    ('recipient_country',      'recipient_country',      True,  'recipient_country',      False),
    ('naics_identifier',       'naics_identifier',       True,  'naics_identifier',       False),
    ('federal_riding_number',  'federal_riding_number',  True,  'federal_riding_number',  False),
    ('agreement_number',       'agreement_number',       False, 'agreement_number',       False),
    # EXCLUDED free-text fields whose diff is dominated by cleaning differences
    # (HTML-unescape, whitespace/newline normalisation) rather than government
    # rewrites: description_eng, recipient_city, prog_name_eng, recipient_postal_code.
    # These are reformatted at clean time, so a diff here is not a reliable
    # "correction" signal. Monetary + dates + codes below are reliable.
]

HISTORY_SQL = (
    'INSERT INTO corrections_history '
    '(record_id, ref_number, amendment_number, field_name, '
    ' old_value, new_value, change_source) '
    'VALUES (%s,%s,%s,%s,%s,%s,%s)'
)


NUMERIC_FIELDS = {'agreement_value', 'foreign_currency_value',
                  'naics_identifier', 'federal_riding_number'}


def is_real_change(label, old_val, new_val, is_monetary):
    """
    Decide whether old->new is a REAL change worth logging, suppressing
    formatting noise (NULL vs '' vs 0 vs 0.00, whitespace).
    Returns True only for genuine differences.
    """
    old_s = str(old_val).strip() if old_val is not None else ''
    new_s = str(new_val).strip() if new_val is not None else ''

    if is_monetary:
        try:
            o = Decimal(old_s) if old_s else Decimal(0)
            nv = Decimal(new_s) if new_s else Decimal(0)
            return abs(o - nv) >= Decimal('0.02')   # only real >= $0.02 moves
        except InvalidOperation:
            return old_s != new_s

    if label in NUMERIC_FIELDS:
        # Treat NULL / '' / 0 as equivalent
        def norm_num(s):
            if not s:
                return ''
            try:
                d = Decimal(s)
                return '' if d == 0 else str(d)
            except InvalidOperation:
                return s
        return norm_num(old_s) != norm_num(new_s)

    # Text fields: NULL and '' are equivalent; compare trimmed
    return old_s != new_s


def classify(label, old_val, new_val, is_null_fill, is_monetary):
    old_s = str(old_val).strip() if old_val is not None else ''
    new_s = str(new_val).strip() if new_val is not None else ''

    if is_monetary:
        # Reaching here means is_real_change already confirmed >= $0.02 move
        return 'government_update'

    # Old had value, new empty → cleaning normalisation
    if old_s and not new_s:
        return 'data_cleaning'
    # Old empty, new has value, null-fill field → cleaning
    if not old_s and new_s and is_null_fill:
        return 'data_cleaning'
    # Legal name differing only by 'The ' / whitespace → cleaning
    if label == 'recipient_legal_name':
        if old_s.strip().lstrip('The ').strip() == new_s.strip().lstrip('The ').strip():
            return 'data_cleaning'
    return 'government_update'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dry-run', action='store_true')
    args = ap.parse_args()

    print(f'Connecting to {DB["user"]}@{DB["host"]}/{DB["database"]} ...', flush=True)
    conn = mysql.connector.connect(**DB)          # read connection (streams SELECT)
    wconn = mysql.connector.connect(**DB)         # separate write connection
    cur = conn.cursor(dictionary=True)
    wcur = wconn.cursor()
    print('  Connected.', flush=True)
    if args.dry_run:
        print('DRY-RUN: counting only, no history writes.\n')

    change_cond = ' OR '.join(
        f'COALESCE(CAST(s.{sf} AS CHAR),"") != COALESCE(CAST(m.{mf} AS CHAR),"")'
        for sf, mf, _, _, _ in COMPARE_FIELDS
    )

    print('Selecting clean 1:1 matches that differ ...', flush=True)
    cur.execute(
        'SELECT s.id as s_id, s.ref_number as s_ref, s.amendment_number as s_amend, '
        '  s.owner_org_en as s_org, '
        + ','.join(f's.`{sf}` as s_{sf}' for sf, _, _, _, _ in COMPARE_FIELDS) + ', '
        + ','.join(f'm.`{mf}` as m_{mf}' for _, mf, _, _, _ in COMPARE_FIELDS)
        + ' FROM staging s '
        'INNER JOIN master_table m '
        '  ON s.ref_number = m.ref_number AND s.amendment_number <=> m.amendment_number '
        'WHERE (SELECT COUNT(*) FROM staging s2 '
        '       WHERE s2.ref_number = s.ref_number '
        '         AND s2.amendment_number <=> s.amendment_number) = 1 '
        'AND (SELECT COUNT(*) FROM master_table m2 '
        '     WHERE m2.ref_number = s.ref_number '
        '       AND m2.amendment_number <=> s.amendment_number) = 1 '
        'AND (' + change_cond + ')'
    )

    history_batch = []
    rows_changed = 0
    field_changes = 0
    monetary_changes = 0
    by_source = {'government_update': 0, 'data_cleaning': 0}

    for row in cur:
        record_id = (row['s_id'] or
                     f"{row['s_org'] or ''},{row['s_ref']},{row['s_amend'] if row['s_amend'] is not None else 'current'}")
        ref = row['s_ref']
        amend = row['s_amend']
        row_had_change = False

        for sf, mf, is_null_fill, label, is_monetary in COMPARE_FIELDS:
            s_val = row[f's_{sf}']
            m_val = row[f'm_{mf}']
            # Gate: only log genuine changes, suppressing NULL/0/'' formatting noise
            if is_real_change(label, m_val, s_val, is_monetary):
                s_str = str(s_val).strip() if s_val is not None else ''
                m_str = str(m_val).strip() if m_val is not None else ''
                source = classify(label, m_val, s_val, is_null_fill, is_monetary)
                history_batch.append((record_id, ref, amend, label,
                                      m_str or None, s_str or None, source))
                field_changes += 1
                by_source[source] = by_source.get(source, 0) + 1
                if is_monetary:
                    monetary_changes += 1
                row_had_change = True

        if row_had_change:
            rows_changed += 1

        if len(history_batch) >= BATCH:
            if not args.dry_run:
                wcur.executemany(HISTORY_SQL, history_batch)
                wconn.commit()
            history_batch.clear()
            if rows_changed % 20000 == 0:
                print(f'  ... {rows_changed:,} rows, {field_changes:,} field changes', flush=True)

    if history_batch and not args.dry_run:
        wcur.executemany(HISTORY_SQL, history_batch)
        wconn.commit()

    cur.close()
    wcur.close()
    conn.close()
    wconn.close()

    print()
    print('=' * 55)
    print(f'  Rows with >=1 change:     {rows_changed:>10,}')
    print(f'  Total field changes:      {field_changes:>10,}')
    print(f'  Monetary changes:         {monetary_changes:>10,}')
    print(f'  -> government_update:     {by_source.get("government_update",0):>10,}')
    print(f'  -> data_cleaning:         {by_source.get("data_cleaning",0):>10,}')
    print('=' * 55)
    if args.dry_run:
        print('\nDRY-RUN complete — nothing written.')
    else:
        print('\nHistory captured in corrections_history.')


if __name__ == '__main__':
    main()
