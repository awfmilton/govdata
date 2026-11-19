#!/usr/bin/env python3
"""
Load the official open.canada.ca Grants & Contributions grants.csv VERBATIM into a source table.

Recorded step of the 2026-11 rebuild (G1/G3 refresh). Nothing is cleaned here: empty strings become
NULL, agreement_value / foreign_currency_value parse as exact decimals, every other value is kept as-is.
MySQL LOAD DATA mis-splits this file (quoted fields contain newlines and quotes), so it is read with
Python's csv module instead.

Source: dataset 432527ab-7aac-45b5-81d6-7597107a7013, resource 1d15a62f-5656-49ad-8c88-f40ce689d831.

Usage:
    export WRITE_USER=... WRITE_PASS=...      # WRITE_HOST defaults to 127.0.0.1
    python3 database/load-official-grants.py <grants.csv> <table>
"""

import csv
import decimal
import os
import sys

import mysql.connector

csv.field_size_limit(10**9)

MONEY = ('agreement_value', 'foreign_currency_value')
SHORT = {
    'ref_number', 'amendment_number', 'owner_org', 'agreement_type', 'recipient_type',
    'recipient_province', 'recipient_country', 'federal_riding_number', 'naics_identifier',
    'amendment_date', 'agreement_start_date', 'agreement_end_date', 'recipient_postal_code',
    'recipient_business_number', 'foreign_currency_type',
}


def main():
    path, table = sys.argv[1], sys.argv[2]
    conn = mysql.connector.connect(
        host=os.environ.get('WRITE_HOST', '127.0.0.1'), user=os.environ['WRITE_USER'],
        password=os.environ['WRITE_PASS'], database=os.environ.get('WRITE_DB', 'grantconts'),
        autocommit=False,
    )
    cur = conn.cursor()
    with open(path, encoding='utf-8-sig', newline='') as fh:
        rd = csv.reader(fh)
        cols = [c.strip() for c in next(rd)]
        ddl = ', '.join(
            f'`{c}` ' + ('DECIMAL(20,2) NULL' if c in MONEY else 'VARCHAR(190) NULL' if c in SHORT else 'TEXT NULL')
            for c in cols
        )
        cur.execute(f'DROP TABLE IF EXISTS `{table}`')
        cur.execute(
            f'CREATE TABLE `{table}` (src_row INT UNSIGNED NOT NULL PRIMARY KEY, {ddl}) ENGINE=InnoDB '
            f'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci '
            f'COMMENT="verbatim load of {os.path.basename(path)}"'
        )
        ins = (f'INSERT INTO `{table}` (src_row, {", ".join(f"`{c}`" for c in cols)}) '
               f'VALUES ({", ".join(["%s"] * (len(cols) + 1))})')
        batch, n, bad = [], 0, 0
        for row in rd:
            n += 1
            if len(row) != len(cols):
                bad += 1
                print('BAD ROW', n, len(row), file=sys.stderr)
                continue
            vals = []
            for c, v in zip(cols, row):
                v = v if v != '' else None
                if v is not None and c in MONEY:
                    try:
                        v = str(decimal.Decimal(v.strip()).quantize(decimal.Decimal('0.01')))
                    except decimal.InvalidOperation:
                        print('BAD DECIMAL', n, c, v[:40], file=sys.stderr)
                        v = None
                vals.append(v)
            batch.append([n] + vals)
            if len(batch) >= 2000:
                cur.executemany(ins, batch)
                conn.commit()
                batch.clear()
                if n % 200000 == 0:
                    print(f'  {n:,}', flush=True)
        if batch:
            cur.executemany(ins, batch)
            conn.commit()
    cur.execute(f'ALTER TABLE `{table}` ADD INDEX k_org_ref (owner_org, ref_number, amendment_number), '
                f'ADD INDEX k_ref (ref_number)')
    print(f'rows read {n:,}  loaded {n - bad:,}  bad {bad}')


if __name__ == '__main__':
    main()
