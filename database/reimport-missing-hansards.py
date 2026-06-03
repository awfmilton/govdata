#!/usr/bin/env python3
"""
Re-import the 50 missing op_hansards_statement rows identified by ID comparison.

Usage:
    export WRITE_PASS=yourpassword
    python3 database/reimport-missing-hansards.py
"""

import os, re, sys
import mysql.connector
from mysql.connector import Error as MySQLError

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

SOURCE  = r"C:\Users\user\Downloads\openparliament.public.sql"
HOST    = '35.230.186.163'
DB      = 'grantconts'
USER    = os.environ.get('WRITE_USER', 'pt_read')
PORT    = 3306

MISSING_IDS = {
    1584583,1584585,1584586,1584587,1584588,1584589,1584590,1584591,
    1584594,1584595,1584596,1584597,1584598,1584599,1584600,1584601,
    1584602,1584603,1584604,1584605,1584606,1584607,1584608,1584609,
    1584610,1584611,1584612,1584613,1584614,1584615,1584616,1584617,
    1584618,1584619,1584620,1584621,1584622,1584623,1584624,1584625,
    1584626,1584627,1584628,1584629,1584631,1584632,1584633,1584634,
    1586550,1588232,
}

_TS_TZ = re.compile(r'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})[\.\d]*(?:[+-]\d{2}(?::\d{2})?)?$')

def decode_row(line, col_types):
    fields = line.rstrip('\n').split('\t')
    out = []
    for i, f in enumerate(fields):
        if f == r'\N':
            out.append(None); continue
        f = f.replace('\\\\','\x00BS\x00').replace('\\t','\t').replace('\\n','\n').replace('\\r','\r').replace('\x00BS\x00','\\')
        ctype = col_types[i] if i < len(col_types) else 'TEXT'
        if ctype == 'TINYINT(1)':
            f = 1 if f.lower() in ('t','true','1') else 0
        elif ctype == 'DATETIME':
            m = _TS_TZ.match(f)
            if m: f = m.group(1)
        out.append(f)
    return out

def main():
    password = os.environ.get('WRITE_PASS')
    if not password:
        import getpass
        password = getpass.getpass('MySQL password: ')

    conn = mysql.connector.connect(
        host=HOST, port=PORT, database=DB, user=USER, password=password,
        charset='utf8mb4', ssl_disabled=True, autocommit=False,
    )
    cur = conn.cursor()
    print(f"Connected to {HOST}/{DB}")

    # ── Scan source file for the missing rows ─────────────────────────────────
    re_copy = re.compile(r'^COPY\s+(?:public\.)?hansards_statement\s+\(([^)]+)\)', re.IGNORECASE)
    in_copy = False
    col_names = []
    col_types = []
    rows = []

    # MySQL types for hansards_statement columns (from our import schema)
    TYPE_MAP = {
        'id':'INT','document_id':'INT','time':'DATETIME',
        'h1_en':'VARCHAR','h2_en':'VARCHAR','member_id':'INT',
        'who_en':'VARCHAR','content_en':'LONGTEXT','sequence':'INT',
        'wordcount':'INT','politician_id':'INT','procedural':'TINYINT(1)',
        'h3_en':'VARCHAR','who_hocid':'INT','content_fr':'LONGTEXT',
        'statement_type':'VARCHAR','written_question':'VARCHAR',
        'source_id':'VARCHAR','who_context_en':'VARCHAR','slug':'VARCHAR',
        'urlcache':'VARCHAR','h1_fr':'VARCHAR','h2_fr':'VARCHAR',
        'h3_fr':'VARCHAR','who_fr':'VARCHAR','who_context_fr':'VARCHAR',
        'wordcount_en':'SMALLINT','bill_debate_stage':'VARCHAR',
        'bill_debated_id':'INT',
    }

    print(f"Scanning source file for {len(MISSING_IDS)} missing IDs ...")
    with open(SOURCE, 'r', encoding='utf-8', errors='replace') as f:
        for line in f:
            if not in_copy:
                m = re_copy.match(line)
                if m:
                    col_names = [c.strip().strip('"') for c in m.group(1).split(',')]
                    col_types = [TYPE_MAP.get(c, 'TEXT') for c in col_names]
                    in_copy = True
            else:
                if line.startswith('\\.'):
                    break
                row_id = int(line.split('\t')[0])
                if row_id in MISSING_IDS:
                    rows.append(decode_row(line, col_types))

    print(f"  Found {len(rows)} rows to insert")

    if not rows:
        print("Nothing to insert."); conn.close(); return

    # ── Insert ────────────────────────────────────────────────────────────────
    ph      = ', '.join(['%s'] * len(col_names))
    cols_sql = ', '.join(f'`{c}`' for c in col_names)
    sql     = f"INSERT IGNORE INTO `op_hansards_statement` ({cols_sql}) VALUES ({ph})"
    try:
        cur.executemany(sql, rows)
        conn.commit()
        print(f"  Inserted {cur.rowcount} rows")
    except MySQLError as e:
        conn.rollback()
        sys.exit(f"Insert failed: {e}")

    # ── Verify ────────────────────────────────────────────────────────────────
    cur.execute("SELECT COUNT(*) FROM `op_hansards_statement`")
    total = cur.fetchone()[0]
    print(f"\nFinal count: {total:,} / 3,801,720")
    if total == 3_801_720:
        print("Complete — all rows present.")
    else:
        print(f"Still missing: {3_801_720 - total}")

    conn.close()

if __name__ == '__main__':
    main()
