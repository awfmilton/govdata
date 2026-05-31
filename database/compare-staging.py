#!/usr/bin/env python3
"""
Corrected staging vs master_table comparison using 1:1 match filter.

Eliminates false positives from GC-* cross-department ref_number collisions
by only comparing records where exactly one staging row matches each
master_table row on (ref_number, amendment_number).

Ambiguous rows (multiple staging rows per ref+amend) are handled with a
secondary owner_org_en match.
"""

import mysql.connector, sys

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

conn = mysql.connector.connect(
    host='35.230.186.163', port=3306, database='grantconts',
    user='pt_read', password='***REMOVED***',
    connection_timeout=300, autocommit=True
)
cur = conn.cursor()

print('Running corrected comparison (1:1 match filter)...\n', flush=True)

# Helper: subquery that checks this staging row is the only one for its ref+amend
UNIQ = ('(SELECT COUNT(*) FROM staging s2 '
        ' WHERE s2.ref_number = s.ref_number '
        '   AND s2.amendment_number <=> s.amendment_number) = 1')

# ── 1. NEW ──────────────────────────────────────────────────────────────────
print('1. NEW records...', flush=True)

cur.execute(
    'SELECT COUNT(*) FROM staging s '
    'LEFT JOIN master_table m '
    '  ON s.ref_number = m.ref_number AND s.amendment_number <=> m.amendment_number '
    'WHERE m.primary_key IS NULL AND s.ref_number IS NOT NULL AND ' + UNIQ
)
new_unambig = cur.fetchone()[0]

cur.execute(
    'SELECT COUNT(*) FROM staging s '
    'LEFT JOIN master_table m '
    '  ON s.ref_number = m.ref_number '
    ' AND s.amendment_number <=> m.amendment_number '
    ' AND COALESCE(s.owner_org_en,"") = COALESCE(m.owner_org_en,"") '
    'WHERE m.primary_key IS NULL AND s.ref_number IS NOT NULL '
    '  AND (SELECT COUNT(*) FROM staging s2 '
    '       WHERE s2.ref_number = s.ref_number '
    '         AND s2.amendment_number <=> s.amendment_number) > 1'
)
new_ambig = cur.fetchone()[0]

print(f'   Unambiguous new:              {new_unambig:>8,}')
print(f'   Ambiguous new (no org match): {new_ambig:>8,}')
print(f'   Total new:                    {new_unambig + new_ambig:>8,}')

# ── 2. CHANGED ──────────────────────────────────────────────────────────────
print('\n2. CHANGED records (clean 1:1)...', flush=True)

change_cond = (
    'COALESCE(CAST(s.agreement_value AS CHAR),"") != COALESCE(CAST(m.agreement_value AS CHAR),"") '
    'OR COALESCE(CAST(s.agreement_end_date AS CHAR),"") != COALESCE(CAST(m.agreement_end_date AS CHAR),"") '
    'OR COALESCE(CAST(s.agreement_start_date AS CHAR),"") != COALESCE(CAST(m.agreement_start_date AS CHAR),"") '
    'OR COALESCE(s.recipient_legal_name,"") != COALESCE(m.recipient_legal_name,"") '
    'OR COALESCE(s.agreement_type,"") != COALESCE(m.agreement_type,"") '
    'OR COALESCE(s.recipient_province,"") != COALESCE(m.recipient_province,"") '
    'OR COALESCE(CAST(s.amendment_date AS CHAR),"") != COALESCE(CAST(m.amendment_date AS CHAR),"") '
    'OR COALESCE(s.prog_name_eng,"") != COALESCE(m.prog_name_en,"") '
    'OR COALESCE(s.description_eng,"") != COALESCE(m.description_eng,"")'
)

cur.execute(
    'SELECT COUNT(*) FROM staging s '
    'INNER JOIN master_table m '
    '  ON s.ref_number = m.ref_number AND s.amendment_number <=> m.amendment_number '
    'WHERE ' + UNIQ + ' AND (' + change_cond + ')'
)
changed_clean = cur.fetchone()[0]

# Ambiguous matches that differ (matched by org)
cur.execute(
    'SELECT COUNT(*) FROM staging s '
    'INNER JOIN master_table m '
    '  ON s.ref_number = m.ref_number '
    ' AND s.amendment_number <=> m.amendment_number '
    ' AND COALESCE(s.owner_org_en,"") = COALESCE(m.owner_org_en,"") '
    'WHERE (SELECT COUNT(*) FROM staging s2 '
    '       WHERE s2.ref_number = s.ref_number '
    '         AND s2.amendment_number <=> s.amendment_number) > 1 '
    '  AND (COALESCE(CAST(s.agreement_value AS CHAR),"") != COALESCE(CAST(m.agreement_value AS CHAR),"") '
    '   OR COALESCE(s.recipient_legal_name,"") != COALESCE(m.recipient_legal_name,"") '
    '   OR COALESCE(s.agreement_type,"") != COALESCE(m.agreement_type,""))'
)
changed_ambig = cur.fetchone()[0]

print(f'   Changed (clean):              {changed_clean:>8,}')
print(f'   Changed (ambig, org-matched): {changed_ambig:>8,}')

# ── 3. UNCHANGED ────────────────────────────────────────────────────────────
cur.execute(
    'SELECT COUNT(*) FROM staging s '
    'INNER JOIN master_table m '
    '  ON s.ref_number = m.ref_number AND s.amendment_number <=> m.amendment_number '
    'WHERE ' + UNIQ + ' '
    '  AND COALESCE(CAST(s.agreement_value AS CHAR),"") = COALESCE(CAST(m.agreement_value AS CHAR),"") '
    '  AND COALESCE(CAST(s.agreement_end_date AS CHAR),"") = COALESCE(CAST(m.agreement_end_date AS CHAR),"") '
    '  AND COALESCE(CAST(s.agreement_start_date AS CHAR),"") = COALESCE(CAST(m.agreement_start_date AS CHAR),"") '
    '  AND COALESCE(s.recipient_legal_name,"") = COALESCE(m.recipient_legal_name,"") '
    '  AND COALESCE(s.agreement_type,"") = COALESCE(m.agreement_type,"")'
)
unchanged = cur.fetchone()[0]
print(f'\n3. UNCHANGED (exact match):       {unchanged:>8,}')

# ── 4. MASTER-ONLY ──────────────────────────────────────────────────────────
print('\n4. MASTER-ONLY (not in staging)...', flush=True)
cur.execute(
    'SELECT COUNT(*) FROM master_table m '
    'LEFT JOIN staging s '
    '  ON m.ref_number = s.ref_number AND m.amendment_number <=> s.amendment_number '
    'WHERE s.ref_number IS NULL AND m.ref_number IS NOT NULL'
)
master_only = cur.fetchone()[0]
print(f'   Master-only:                  {master_only:>8,}')

# ── 5. MASTER-ONLY by year ───────────────────────────────────────────────────
print('\n5. Master-only breakdown by year...')
cur.execute(
    'SELECT m.year, COUNT(*) as n FROM master_table m '
    'LEFT JOIN staging s '
    '  ON m.ref_number = s.ref_number AND m.amendment_number <=> s.amendment_number '
    'WHERE s.ref_number IS NULL AND m.ref_number IS NOT NULL '
    'GROUP BY m.year ORDER BY m.year'
)
for r in cur.fetchall():
    print(f'   {str(r[0]):<6} {r[1]:>7,}')

# ── 6. Field breakdown ───────────────────────────────────────────────────────
print('\n6. Field-level breakdown of clean changed records...')
fields = [
    ('agreement_value',
     'COALESCE(CAST(s.agreement_value AS CHAR),"") != COALESCE(CAST(m.agreement_value AS CHAR),"")'),
    ('recipient_legal_name',
     'COALESCE(s.recipient_legal_name,"") != COALESCE(m.recipient_legal_name,"")'),
    ('agreement_start_date',
     'COALESCE(CAST(s.agreement_start_date AS CHAR),"") != COALESCE(CAST(m.agreement_start_date AS CHAR),"")'),
    ('agreement_end_date',
     'COALESCE(CAST(s.agreement_end_date AS CHAR),"") != COALESCE(CAST(m.agreement_end_date AS CHAR),"")'),
    ('agreement_type',
     'COALESCE(s.agreement_type,"") != COALESCE(m.agreement_type,"")'),
    ('recipient_province',
     'COALESCE(s.recipient_province,"") != COALESCE(m.recipient_province,"")'),
    ('amendment_date',
     'COALESCE(CAST(s.amendment_date AS CHAR),"") != COALESCE(CAST(m.amendment_date AS CHAR),"")'),
    ('prog_name_eng',
     'COALESCE(s.prog_name_eng,"") != COALESCE(m.prog_name_en,"")'),
    ('description_eng',
     'COALESCE(s.description_eng,"") != COALESCE(m.description_eng,"")'),
]
for field, cond in fields:
    cur.execute(
        'SELECT COUNT(*) FROM staging s '
        'INNER JOIN master_table m '
        '  ON s.ref_number = m.ref_number AND s.amendment_number <=> m.amendment_number '
        'WHERE ' + UNIQ + ' AND ' + cond
    )
    n = cur.fetchone()[0]
    if n:
        print(f'   {field:<30} {n:>8,}')

total_new = new_unambig + new_ambig
print('\n' + '=' * 60)
print(f'  New records:              {total_new:>10,}')
print(f'  Changed (clean 1:1):      {changed_clean:>10,}')
print(f'  Changed (ambig+org):      {changed_ambig:>10,}')
print(f'  Unchanged:                {unchanged:>10,}')
print(f'  Master-only:              {master_only:>10,}')
print(f'  Ambiguous (needs review): {66567 - new_ambig - changed_ambig:>10,}')
print('=' * 60)

conn.close()
