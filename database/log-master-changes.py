#!/usr/bin/env python3
"""
Step 2 of the 2026-11 official-source rebuild (after rebuild-master-from-official.sql).

  1. Logs every field-level difference between master_table (previous edition) and master_new
     (official edition) into corrections_history, one row per record per field, classified:
       government_update     content changed
       precision_correction  money value differs only by the old export's float32 rounding
       data_cleaning         whitespace/case only, or '' <-> NULL
       null_fill             previously empty, now has a value
       value_cleared         previously had a value, now empty
  2. Writes a before/after manifest (row counts, SUMs, per-department and per-field change counts).
  3. With --swap: renames master_table -> master_table_pre_official_20261117, master_new -> master_table,
     and recreates the normalisation trigger on the new table (a trigger moves with its table on RENAME).

Columns this pipeline DERIVES rather than reads from the source (fiscal_year, quarter_en, year,
owner_org_title, owner_org_en, has_amendments_en, format_en, recipient_city_en) are not logged as
government changes; their before/after counts are in the manifest instead.

Usage:
    python3 database/log-master-changes.py --manifest /path/manifest.json [--swap]
"""

import argparse
import json
import os
import subprocess
import sys
import time

import mysql.connector

MONEY = ['agreement_value', 'foreign_currency_value']
SOURCE_FIELDS = [
    'amendment_date', 'agreement_type', 'recipient_type', 'recipient_business_number', 'recipient_legal_name',
    'recipient_operating_name', 'research_organization_name', 'recipient_country', 'recipient_province',
    'recipient_city', 'recipient_postal_code', 'federal_riding_name_en', 'federal_riding_name_fr',
    'federal_riding_number', 'prog_name_en', 'prog_name_fr', 'prog_purpose_en', 'prog_purpose_fr',
    'agreement_title_en', 'agreement_title_fr', 'agreement_number', 'agreement_value', 'foreign_currency_type',
    'foreign_currency_value', 'agreement_start_date', 'agreement_end_date', 'coverage', 'description_eng',
    'description_fr', 'naics_identifier', 'expected_results_en', 'expected_results_fr',
    'additional_information_en', 'additional_information_fr',
]
DERIVED_FIELDS = ['fiscal_year', 'quarter_en', 'year', 'owner_org_title', 'owner_org_en',
                  'has_amendments_en', 'format_en', 'recipient_city_en']


def classify(f):
    blank_o = f"NULLIF(TRIM(CAST(o.`{f}` AS CHAR)), '')"
    blank_n = f"NULLIF(TRIM(CAST(n.`{f}` AS CHAR)), '')"
    money = (f"WHEN ABS(o.`{f}` - n.`{f}`) <= GREATEST(ABS(n.`{f}`) * 1.2e-7, 0.01) THEN 'precision_correction' "
             if f in MONEY else '')
    return (f"CASE WHEN {blank_o} IS NULL AND {blank_n} IS NULL THEN 'data_cleaning' "
            f"WHEN {blank_o} IS NULL THEN 'null_fill' "
            f"WHEN {blank_n} IS NULL THEN 'value_cleared' "
            f"{money}"
            f"WHEN LOWER(REGEXP_REPLACE(TRIM(CAST(o.`{f}` AS CHAR)), '[[:space:]]+', ' ')) = "
            f"LOWER(REGEXP_REPLACE(TRIM(CAST(n.`{f}` AS CHAR)), '[[:space:]]+', ' ')) THEN 'data_cleaning' "
            f"ELSE 'government_update' END")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--manifest', required=True)
    ap.add_argument('--swap', action='store_true')
    ap.add_argument('--old-table', default='master_table', help='previous edition (default master_table)')
    ap.add_argument('--new-table', default='master_new', help='new edition (default master_new)')
    args = ap.parse_args()

    conn = mysql.connector.connect(host=os.environ.get('WRITE_HOST', '127.0.0.1'), user=os.environ['WRITE_USER'],
                                   password=os.environ['WRITE_PASS'], database=os.environ.get('WRITE_DB', 'grantconts'),
                                   autocommit=True, connection_timeout=3600)
    cur = conn.cursor()

    def one(sql):
        cur.execute(sql)
        return cur.fetchall()

    ed = one("SELECT edition_id FROM grants_source_editions WHERE edition_code='2026-11-17_open-canada-grants-csv'")[0][0]
    prev = one("SELECT edition_id FROM grants_source_editions WHERE edition_code='2026-05-30_search-portal-export'")[0][0]

    # Re-runnable: clear this edition's field-level rows before logging them again.
    cur.execute(f"DELETE FROM corrections_history WHERE edition_id={ed} AND field_name<>'*' "
                f"AND change_source<>'needs_review'")
    per_field = {}
    for f in SOURCE_FIELDS:
        t0 = time.time()
        cur.execute(
            f"INSERT INTO corrections_history (record_id, ref_number, amendment_number, field_name, old_value, "
            f"new_value, change_source, edition_id, prev_edition_id) "
            f"SELECT n.id, n.ref_number, n.amendment_number, '{f}', CAST(o.`{f}` AS CHAR), CAST(n.`{f}` AS CHAR), "
            f"{classify(f)}, {ed}, {prev} "
            f"FROM `{args.new_table}` n JOIN `{args.old_table}` o ON o.id = n.id WHERE NOT (o.`{f}` <=> n.`{f}`)")
        per_field[f] = cur.rowcount
        print(f'  {f}: {cur.rowcount:,} changes ({time.time() - t0:.0f}s)', flush=True)

    m = {'edition_id': ed, 'prev_edition_id': prev, 'logged_changes_per_field': per_field}
    m['rows'] = dict(zip(['before', 'after'], one(
        f"SELECT (SELECT COUNT(*) FROM `{args.old_table}`), (SELECT COUNT(*) FROM `{args.new_table}`)")[0]))
    m['sum_agreement_value'] = {
        'before_all_rows': str(one(f"SELECT SUM(agreement_value) FROM `{args.old_table}`")[0][0]),
        'after_all_rows': str(one(f"SELECT SUM(agreement_value) FROM `{args.new_table}`")[0][0]),
        'before_current_only': str(one(f"SELECT SUM(agreement_value) FROM `{args.old_table}` WHERE amendment_number IS NULL")[0][0]),
        'after_current_only': str(one(f"SELECT SUM(agreement_value) FROM `{args.new_table}` WHERE amendment_number IS NULL")[0][0]),
    }
    m['change_source_counts'] = {k: v for k, v in one(
        f"SELECT change_source, COUNT(*) FROM corrections_history WHERE edition_id={ed} GROUP BY 1")}
    m['government_updates_by_department'] = {k: v for k, v in one(
        f"SELECT SUBSTRING_INDEX(record_id, ',', 1), COUNT(*) FROM corrections_history WHERE edition_id={ed} "
        f"AND change_source='government_update' GROUP BY 1 ORDER BY 2 DESC")}
    m['value_delta_by_department_current'] = {k: str(v) for k, v in one(
        "SELECT SUBSTRING_INDEX(n.id, ',', 1), SUM(n.agreement_value) - SUM(IFNULL(o.agreement_value, 0)) "
        f"FROM `{args.new_table}` n LEFT JOIN `{args.old_table}` o ON o.id = n.id WHERE n.amendment_number IS NULL "
        "GROUP BY 1 ORDER BY ABS(SUM(n.agreement_value) - SUM(IFNULL(o.agreement_value, 0))) DESC")}
    m['removed_records'] = one(f"SELECT COUNT(*) FROM grants_removed_records WHERE removed_in_edition_id={ed}")[0][0]
    m['derived_fields_non_null'] = {f: dict(zip(['before', 'after'], one(
        f"SELECT (SELECT COUNT(`{f}`) FROM `{args.old_table}`), (SELECT COUNT(`{f}`) FROM `{args.new_table}`)")[0]))
        for f in DERIVED_FIELDS}

    if args.swap:
        cur.execute('DROP TRIGGER IF EXISTS after_master_table_insert')
        cur.execute('RENAME TABLE master_table TO master_table_pre_official_20261117, master_new TO master_table')
        m['swapped'] = True
    with open(args.manifest, 'w') as fh:
        json.dump(m, fh, indent=2, default=str)
    print(json.dumps(m, indent=2, default=str)[:4000])
    if args.swap:
        # The trigger lived on the old table; recreate it on the new master_table.
        subprocess.run(['bash', '-c', 'mysql grantconts < /opt/govdata/database/normalization-triggers.sql'], check=True)
        print('trigger recreated on new master_table')


if __name__ == '__main__':
    sys.exit(main())
