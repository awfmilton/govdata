#!/usr/bin/env python3
"""
Import the Open Parliament PostgreSQL dump into grantconts (MySQL/Cloud SQL).

- Every table is created as op_<original_name>  →  zero conflict with existing tables.
- Safe to re-run: CREATE TABLE IF NOT EXISTS + INSERT IGNORE.
- Streams the .bz2 without full extraction.
- Translates PostgreSQL types → MySQL types automatically.

Usage:
    export WRITE_PASS=yourpassword
    python3 database/import-parldata.py

    # Dry-run — print CREATE TABLE statements, import nothing:
    python3 database/import-parldata.py --dry-run

    # Create tables but skip data import:
    python3 database/import-parldata.py --schema-only

    # Import specific source tables only (no op_ prefix needed):
    python3 database/import-parldata.py --tables core_politician,core_electedmember,core_riding

    # Override DB connection:
    export WRITE_HOST=35.230.186.163 WRITE_DB=grantconts WRITE_USER=pt_write WRITE_PASS=...

Dependencies:
    pip install mysql-connector-python
"""

import argparse
import bz2
import os
import re
import sys

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
if hasattr(sys.stderr, 'reconfigure'):
    sys.stderr.reconfigure(encoding='utf-8', errors='replace')

try:
    import mysql.connector
    from mysql.connector import Error as MySQLError
except ImportError:
    sys.exit("ERROR: pip install mysql-connector-python")

DUMP_PATH    = r"C:\Users\user\Downloads\openparliament.public.sql.bz2"
DEFAULT_HOST = '35.230.186.163'
DEFAULT_DB   = 'grantconts'
DEFAULT_USER = 'pt_write'
DEFAULT_PORT = 3306
TABLE_PREFIX = 'op_'
BATCH_SIZE        = 500   # default for small/medium tables
BATCH_SIZE_LARGE  = 100   # for tables with LONGTEXT columns

# Tables with large text blobs — use smaller batches to avoid SSL packet limits
LARGE_TEXT_TABLES = {
    'hansards_statement', 'bills_billtext', 'contact_contactmessage',
    'contact_contactthread', 'summaries_summary',
}


# ── PostgreSQL → MySQL type translation ──────────────────────────────────────

def pg_to_mysql(pg: str) -> str:
    t = pg.strip().rstrip(',').lower()
    if t.startswith('character varying('):
        n = re.search(r'\((\d+)\)', t)
        return f"VARCHAR({n.group(1)})" if n else 'TEXT'
    if t.startswith('character varying'):
        return 'TEXT'
    if t.startswith('character('):
        n = re.search(r'\((\d+)\)', t)
        return f"CHAR({n.group(1)})" if n else 'CHAR(1)'
    if t.startswith('numeric(') or t.startswith('decimal('):
        m = re.search(r'\((\d+),\s*(\d+)\)', t)
        return f"DECIMAL({m.group(1)},{m.group(2)})" if m else 'DECIMAL(10,2)'
    if t.startswith('timestamp'):
        return 'DATETIME'
    if t.startswith('time ') or t == 'time':
        return 'TIME'
    return {
        'integer': 'INT', 'int': 'INT',
        'smallint': 'SMALLINT', 'bigint': 'BIGINT',
        'text': 'LONGTEXT', 'boolean': 'TINYINT(1)',
        'date': 'DATE', 'inet': 'VARCHAR(45)',
        'jsonb': 'LONGTEXT', 'json': 'LONGTEXT',
        'double precision': 'DOUBLE', 'real': 'FLOAT',
        'bytea': 'BLOB', 'uuid': 'VARCHAR(36)',
        'serial': 'INT', 'bigserial': 'BIGINT',
    }.get(t, 'TEXT')


# ── Parse one column line from CREATE TABLE ──────────────────────────────────

_SKIP_KW = {'CONSTRAINT', 'PRIMARY', 'UNIQUE', 'FOREIGN', 'CHECK', 'EXCLUDE', 'INDEX'}

def parse_col(line: str):
    """Return (col_name, mysql_type, not_null) or None."""
    s = line.strip().rstrip(',')
    if not s:
        return None
    first = s.split()[0].upper().strip('"')
    if first in _SKIP_KW:
        return None
    m = re.match(r'"?(\w+)"?\s+(.*)', s)
    if not m:
        return None
    col_name = m.group(1)
    rest     = m.group(2)
    not_null = bool(re.search(r'\bNOT\s+NULL\b', rest, re.IGNORECASE))
    type_raw = re.split(r'\bNOT\s+NULL\b|\bDEFAULT\b|\bREFERENCES\b|\bCHECK\b',
                        rest, flags=re.IGNORECASE)[0].strip()
    return col_name, pg_to_mysql(type_raw), not_null


# ── COPY text-format row → list of Python values ─────────────────────────────

_TS_TZ  = re.compile(r'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})[\.\d]*(?:[+-]\d{2}(?::\d{2})?)?$')
_TS_SUB = re.compile(r'(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\.\d+$')

def decode_row(line: str, col_types: list) -> list:
    fields = line.rstrip('\n').split('\t')
    out = []
    for i, f in enumerate(fields):
        if f == r'\N':
            out.append(None)
            continue
        f = f.replace('\\\\', '\x00BS\x00') \
             .replace('\\t', '\t') \
             .replace('\\n', '\n') \
             .replace('\\r', '\r') \
             .replace('\x00BS\x00', '\\')
        ctype = col_types[i] if i < len(col_types) else 'TEXT'
        if ctype == 'TINYINT(1)':
            f = 1 if f.lower() in ('t', 'true', '1') else 0
        elif ctype == 'DATETIME':
            m = _TS_TZ.match(f)
            if m:
                f = m.group(1)
            else:
                m2 = _TS_SUB.match(f)
                if m2:
                    f = m2.group(1)
        out.append(f)
    return out


# ── DDL builder ──────────────────────────────────────────────────────────────

def build_ddl(table: str, cols: list) -> str:
    mysql_table = TABLE_PREFIX + table
    defs = []
    has_id = any(c[0] == 'id' for c in cols)
    for col_name, mysql_type, not_null in cols:
        nn = ' NOT NULL' if not_null else ''
        defs.append(f"  `{col_name}` {mysql_type}{nn}")
    if has_id:
        defs.append("  PRIMARY KEY (`id`)")
    return (
        f"CREATE TABLE IF NOT EXISTS `{mysql_table}` (\n"
        + ",\n".join(defs)
        + "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    )


# ── Streaming helpers ─────────────────────────────────────────────────────────

def stream_lines(path: str):
    opener = bz2.open if path.endswith('.bz2') else open
    with opener(path, 'rt', encoding='utf-8', errors='replace') as f:
        yield from f


# ── Main ─────────────────────────────────────────────────────────────────────

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--file',        default=DUMP_PATH)
    ap.add_argument('--dry-run',     action='store_true', help='Print DDL, import nothing')
    ap.add_argument('--schema-only', action='store_true', help='Create tables, skip data')
    ap.add_argument('--tables',      help='Comma-separated source table names to import')
    ap.add_argument('--host',        default=os.environ.get('WRITE_HOST', DEFAULT_HOST))
    ap.add_argument('--db',          default=os.environ.get('WRITE_DB',   DEFAULT_DB))
    ap.add_argument('--user',        default=os.environ.get('WRITE_USER', DEFAULT_USER))
    ap.add_argument('--port',        type=int, default=DEFAULT_PORT)
    args = ap.parse_args()

    password = os.environ.get('WRITE_PASS')
    if not password and not args.dry_run:
        import getpass
        password = getpass.getpass('MySQL password: ')

    only_tables = set(t.strip() for t in args.tables.split(',')) if args.tables else None

    # ── Connect / reconnect ───────────────────────────────────────────────────
    conn = cur = None

    def connect():
        nonlocal conn, cur
        import time
        for attempt in range(6):
            try:
                if conn:
                    try: conn.close()
                    except Exception: pass
                conn = mysql.connector.connect(
                    host=args.host, port=args.port,
                    database=args.db, user=args.user, password=password,
                    charset='utf8mb4', autocommit=False,
                    connection_timeout=60,
                    ssl_disabled=True,
                )
                cur = conn.cursor()
                cur.execute("SET SESSION net_read_timeout  = 3600")
                cur.execute("SET SESSION net_write_timeout = 3600")
                cur.execute("SET SESSION wait_timeout      = 28800")
                cur.execute("SET SESSION interactive_timeout = 28800")
                return
            except MySQLError as e:
                if attempt == 5:
                    sys.exit(f"Could not connect after 6 attempts: {e}")
                wait = 2 ** attempt
                print(f"  [RETRY] Connect failed ({e}), retrying in {wait}s …", flush=True)
                time.sleep(wait)

    if not args.dry_run:
        connect()
        print(f"Connected to {args.host}/{args.db}\n")

    # ── State machine ─────────────────────────────────────────────────────────
    in_create       = False
    current_table   = None
    current_cols    = []          # list of (col_name, mysql_type, not_null)

    in_copy          = False
    copy_table       = None
    copy_col_names   = []
    copy_col_types   = []
    copy_batch       = []
    copy_batch_size  = BATCH_SIZE
    copy_table_rows  = 0          # rows inserted for current table
    copy_skip_rows   = 0          # rows to skip at start of COPY block (resume)
    total_rows       = 0          # grand total across all tables

    # Store completed schemas so COPY blocks can look up types
    schema: dict[str, list] = {}  # table_name → list of (col_name, mysql_type, not_null)

    re_create = re.compile(r'^CREATE TABLE\s+(?:public\.)?\"?(\w+)\"?\s*\(', re.IGNORECASE)
    re_copy   = re.compile(r'^COPY\s+(?:public\.)?\"?(\w+)\"?\s*\(([^)]+)\)', re.IGNORECASE)
    re_end_c  = re.compile(r'^\s*\);\s*$')

    def flush(table, col_names, batch):
        if not batch or not conn:
            return 0
        mysql_table = TABLE_PREFIX + table
        ph       = ', '.join(['%s'] * len(col_names))
        cols_sql = ', '.join(f'`{c}`' for c in col_names)
        sql      = f"INSERT IGNORE INTO `{mysql_table}` ({cols_sql}) VALUES ({ph})"
        # Ping before every batch to catch stale connections early
        try:
            conn.ping(reconnect=True, attempts=5, delay=3)
        except Exception:
            connect()
        for attempt in range(5):
            try:
                cur.executemany(sql, batch)
                conn.commit()
                return len(batch)
            except MySQLError as e:
                errno = getattr(e, 'errno', None)
                print(f"\n  [WARN] Insert error on {mysql_table} (attempt {attempt+1}): {e}", flush=True)
                try: conn.rollback()
                except Exception: pass
                if errno in (2006, 2013, 2055) or 'Lost connection' in str(e) or 'EOF' in str(e) or 'timed out' in str(e).lower():
                    import time
                    time.sleep(3 * (attempt + 1))
                    print(f"  [RECONNECT] Reconnecting …", flush=True)
                    connect()
                else:
                    return 0
        print(f"  [SKIP] Batch dropped after 5 failed attempts on {mysql_table}", flush=True)
        return 0

    lines_read = 0
    tables_created = 0

    print(f"Streaming: {args.file}")
    print(f"Mode: {'DRY-RUN' if args.dry_run else ('SCHEMA-ONLY' if args.schema_only else 'FULL IMPORT')}")
    if only_tables:
        print(f"Tables filter: {', '.join(sorted(only_tables))}")
    print()

    for raw_line in stream_lines(args.file):
        lines_read += 1
        line = raw_line.rstrip('\n')

        if lines_read % 500_000 == 0:
            print(f"  … {lines_read:,} lines | {tables_created} tables | {total_rows:,} rows inserted", flush=True)

        # ── COPY data block ───────────────────────────────────────────────────
        if in_copy:
            if line == '\\.':
                # End of this table's COPY block — flush remainder
                if copy_batch and not args.dry_run and not args.schema_only:
                    n = flush(copy_table, copy_col_names, copy_batch)
                    copy_table_rows += n
                    total_rows += n
                    copy_batch = []
                print(f"    → {copy_table_rows:,} rows  (op_{copy_table})", flush=True)
                in_copy         = False
                copy_table      = None
                copy_table_rows = 0
                copy_skip_rows  = 0
            else:
                if args.dry_run or args.schema_only:
                    copy_table_rows += 1
                    continue
                if only_tables and copy_table not in only_tables:
                    copy_table_rows += 1
                    continue
                # Resume: skip rows already in the DB
                if copy_skip_rows > 0:
                    copy_skip_rows -= 1
                    copy_table_rows += 1
                    continue
                row = decode_row(line, copy_col_types)
                copy_batch.append(row)
                if len(copy_batch) >= copy_batch_size:
                    n = flush(copy_table, copy_col_names, copy_batch)
                    copy_table_rows += n
                    total_rows += n
                    copy_batch = []
            continue

        # ── CREATE TABLE block ────────────────────────────────────────────────
        if in_create:
            if re_end_c.match(line):
                schema[current_table] = current_cols
                want = (only_tables is None or current_table in only_tables)
                ddl  = build_ddl(current_table, current_cols)
                if args.dry_run:
                    if want:
                        print(ddl + ";\n")
                elif want:
                    try:
                        cur.execute(ddl)
                        conn.commit()
                        tables_created += 1
                        print(f"  + op_{current_table}  ({len(current_cols)} cols)", flush=True)
                    except MySQLError as e:
                        print(f"  [WARN] Could not create op_{current_table}: {e}", file=sys.stderr)
                in_create     = False
                current_table = None
                current_cols  = []
            else:
                parsed = parse_col(line)
                if parsed:
                    current_cols.append(parsed)
            continue

        # ── Top-level line ────────────────────────────────────────────────────
        m = re_create.match(line)
        if m:
            current_table = m.group(1)
            current_cols  = []
            in_create     = True
            continue

        m = re_copy.match(line)
        if m:
            tbl      = m.group(1)
            col_list = [c.strip().strip('"') for c in m.group(2).split(',')]
            col_map  = {name: mtype for name, mtype, _ in schema.get(tbl, [])}
            batch_sz = BATCH_SIZE_LARGE if tbl in LARGE_TEXT_TABLES else BATCH_SIZE
            # Resume: count existing rows so we can skip them
            already = 0
            if not args.dry_run and not args.schema_only and (only_tables is None or tbl in only_tables):
                try:
                    cur.execute(f"SELECT COUNT(*) FROM `{TABLE_PREFIX}{tbl}`")
                    already = cur.fetchone()[0]
                except MySQLError:
                    already = 0
            in_copy          = True
            copy_table       = tbl
            copy_col_names   = col_list
            copy_col_types   = [col_map.get(c, 'TEXT') for c in col_list]
            copy_batch       = []
            copy_table_rows  = 0
            copy_skip_rows   = already
            copy_batch_size  = batch_sz
            if not args.dry_run:
                skip_msg   = ' [SKIPPED]' if (only_tables and tbl not in only_tables) else ''
                resume_msg = f', resuming from row {already:,}' if already > 0 else ''
                print(f"  Importing op_{tbl}{skip_msg} (batch={batch_sz}{resume_msg}) ...", flush=True)
            continue

    # ── Summary ───────────────────────────────────────────────────────────────
    print(f"\n{'='*60}")
    print(f"Scan complete — {lines_read:,} lines processed")
    print(f"Tables in schema: {len(schema)}")
    if not args.dry_run:
        print(f"Tables created:   {tables_created}")
        print(f"Total rows inserted: {total_rows:,}")
    if conn:
        conn.close()


if __name__ == '__main__':
    main()
