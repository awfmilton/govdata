#!/usr/bin/env python3
"""
Load one or more CSV files VERBATIM into MySQL tables, one table per file, and record the
source edition. Used for the Office of the Commissioner of Lobbying open data (2026-11) and
reusable for any other bulk CSV source.

Nothing is cleaned: empty strings become NULL, every value is stored as text exactly as read.
Encoding is detected per file (UTF-8 first, then Windows-1252, which the OCL exports use).

Each load is recorded in `source_editions` (dataset, file, sha256, rows, encoding, edition date),
and each table gets a `src_row` column (1-based data row in the file) so any row can be traced
back to its exact line in the published file.

Usage:
    export WRITE_USER=... WRITE_PASS=...           # WRITE_HOST defaults to 127.0.0.1
    python3 database/load-csv-verbatim.py --dataset lobbying --edition 2026-11-16 \
        --prefix lob_ --source-url https://lobbycanada.gc.ca/en/open-data/ file1.csv file2.csv ...
"""

import argparse
import csv
import hashlib
import os
import re
import sys

import mysql.connector

csv.field_size_limit(10**9)


def detect_encoding(path):
    with open(path, 'rb') as fh:
        data = fh.read()
    try:
        data.decode('utf-8')
        return 'utf-8-sig'
    except UnicodeDecodeError:
        return 'cp1252'


def sha256(path):
    h = hashlib.sha256()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


def table_name(prefix, path):
    base = os.path.splitext(os.path.basename(path))[0]
    base = re.sub(r'Export$', '', base)
    return (prefix + re.sub(r'[^0-9a-zA-Z]+', '_', base)).lower()[:64]


def col_name(c):
    c = re.sub(r'[^0-9a-zA-Z]+', '_', c.strip().lstrip('﻿')).strip('_').lower()
    return c[:64] or 'col'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dataset', required=True)
    ap.add_argument('--edition', required=True, help='publication/export date, YYYY-MM-DD')
    ap.add_argument('--prefix', required=True)
    ap.add_argument('--source-url', default=None)
    ap.add_argument('--suffix', default='', help='optional table-name suffix, e.g. _20261116')
    ap.add_argument('files', nargs='+')
    a = ap.parse_args()

    conn = mysql.connector.connect(
        host=os.environ.get('WRITE_HOST', '127.0.0.1'), user=os.environ['WRITE_USER'],
        password=os.environ['WRITE_PASS'], database=os.environ.get('WRITE_DB', 'grantconts'), autocommit=False)
    cur = conn.cursor()
    cur.execute('''CREATE TABLE IF NOT EXISTS source_editions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        dataset VARCHAR(64) NOT NULL, edition_date DATE NOT NULL, file_name VARCHAR(255) NOT NULL,
        table_name VARCHAR(64) NOT NULL, file_sha256 CHAR(64) NOT NULL, row_count INT UNSIGNED NOT NULL,
        encoding VARCHAR(20) NOT NULL, source_url VARCHAR(500) NULL,
        loaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_edition_file (dataset, edition_date, file_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci''')

    for path in a.files:
        enc = detect_encoding(path)
        tbl = table_name(a.prefix, path) + a.suffix
        with open(path, encoding=enc, newline='') as fh:
            rd = csv.reader(fh)
            header = next(rd)
            cols, seen = [], set()
            for c in header:
                n = col_name(c)
                while n in seen:
                    n += '_'
                seen.add(n)
                cols.append(n)
            cur.execute(f'DROP TABLE IF EXISTS `{tbl}`')
            ddl = ', '.join(f'`{c}` MEDIUMTEXT NULL' for c in cols)
            cur.execute(f'CREATE TABLE `{tbl}` (src_row INT UNSIGNED NOT NULL PRIMARY KEY, {ddl}) ENGINE=InnoDB '
                        f'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci '
                        f'COMMENT="verbatim {a.dataset} {a.edition}: {os.path.basename(path)}"')
            ins = f'INSERT INTO `{tbl}` VALUES ({", ".join(["%s"] * (len(cols) + 1))})'
            batch, n, bad = [], 0, 0
            for row in rd:
                n += 1
                if len(row) != len(cols):
                    bad += 1
                    print(f'  BAD ROW {os.path.basename(path)}:{n} has {len(row)} fields, expected {len(cols)}', file=sys.stderr)
                    row = (row + [None] * len(cols))[:len(cols)]
                batch.append([n] + [v if v != '' else None for v in row])
                if len(batch) >= 2000:
                    cur.executemany(ins, batch)
                    conn.commit()
                    batch.clear()
            if batch:
                cur.executemany(ins, batch)
                conn.commit()
        cur.execute('REPLACE INTO source_editions (dataset, edition_date, file_name, table_name, file_sha256, '
                    'row_count, encoding, source_url) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)',
                    (a.dataset, a.edition, os.path.basename(path), tbl, sha256(path), n, enc, a.source_url))
        conn.commit()
        print(f'{tbl:58s} {n:>9,} rows  enc={enc}  bad={bad}', flush=True)


if __name__ == '__main__':
    main()
