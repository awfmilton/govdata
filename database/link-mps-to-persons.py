"""
Task 1 — Add mp_persons_id FK to mps table.

Adds an mp_persons_id column to mps (if not present), then populates it:
  • MP-  rows: matched via mp_persons.ourcommons_id = mps.mp_id
  • HIST- rows: matched via normalised first_name + last_name

Does NOT delete any rows (pt_read can't DROP; we honour the handoff rule).

Run:
  python database/link-mps-to-persons.py [--dry-run]
"""

import os
import sys
import argparse
import mysql.connector

DB = dict(
    host=os.environ.get('DB_HOST', '35.230.186.163'),
    port=int(os.environ.get('DB_PORT', 3306)),
    database=os.environ.get('DB_NAME', 'grantconts'),
    user=os.environ.get('DB_USER', 'pt_read'),
    password=os.environ.get('DB_PASS', '***REMOVED***'),
    charset='utf8mb4',
    collation='utf8mb4_unicode_ci',
)


def norm(s):
    return (s or '').strip().lower()


def log(msg):
    print(msg, flush=True)


def column_exists(cur, table, column):
    cur.execute("""
        SELECT COUNT(*) AS n
          FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = %s
           AND COLUMN_NAME  = %s
    """, (table, column))
    return cur.fetchone()['n'] > 0


def index_exists(cur, table, index_name):
    cur.execute("""
        SELECT COUNT(*) AS n
          FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = %s
           AND INDEX_NAME   = %s
    """, (table, index_name))
    return cur.fetchone()['n'] > 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--dry-run', action='store_true',
                        help='Print plan without writing')
    args = parser.parse_args()
    dry = args.dry_run

    log('DRY RUN — no changes will be written.\n' if dry else
        'LIVE RUN — writing to grantconts.\n')

    cx = mysql.connector.connect(**DB)
    cur = cx.cursor(dictionary=True)

    # ── Step 1: Add column + index if missing ────────────────────────────────

    log('Step 1: ensuring mp_persons_id column and index on mps…')
    has_col = column_exists(cur, 'mps', 'mp_persons_id')
    has_idx = index_exists(cur, 'mps', 'idx_mp_persons_id')

    if has_col:
        log('  Column mp_persons_id already exists — skipping ALTER.')
    else:
        log('  Adding column mp_persons_id INT DEFAULT NULL…')
        if not dry:
            cur.execute(
                'ALTER TABLE mps ADD COLUMN mp_persons_id INT DEFAULT NULL')
            cx.commit()
            log('  Column added.')
        else:
            log('  [dry] Would ALTER TABLE mps ADD COLUMN mp_persons_id INT DEFAULT NULL')

    if has_idx:
        log('  Index idx_mp_persons_id already exists — skipping.')
    else:
        log('  Adding index idx_mp_persons_id…')
        if not dry:
            cur.execute(
                'ALTER TABLE mps ADD INDEX idx_mp_persons_id (mp_persons_id)')
            cx.commit()
            log('  Index added.')
        else:
            log('  [dry] Would ADD INDEX idx_mp_persons_id (mp_persons_id)')

    # ── Step 2: Match MP- rows via ourcommons_id ──────────────────────────────

    log('\nStep 2: linking MP- rows via mp_persons.ourcommons_id…')
    cur.execute("""
        SELECT m.mp_id, m.first_name, m.last_name, p.id AS person_id
          FROM mps m
          JOIN mp_persons p
            ON p.ourcommons_id = CONVERT(m.mp_id USING utf8mb4)
                                   COLLATE utf8mb4_unicode_ci
         WHERE m.mp_id LIKE 'MP%'
    """)
    mp_matches = cur.fetchall()
    log(f'  {len(mp_matches)} MP- rows matched via ourcommons_id.')

    mp_updated = 0
    mp_already_set = 0
    for row in mp_matches:
        if dry:
            mp_updated += 1
            continue
        cur.execute("""
            UPDATE mps SET mp_persons_id = %s
             WHERE mp_id = %s AND (mp_persons_id IS NULL OR mp_persons_id != %s)
        """, (row['person_id'], row['mp_id'], row['person_id']))
        if cur.rowcount:
            mp_updated += 1
        else:
            mp_already_set += 1

    if not dry:
        cx.commit()
    log(f'  Updated: {mp_updated}  |  Already set: {mp_already_set}')

    # ── Step 3: Check for unmatched MP- rows ──────────────────────────────────

    log('\nStep 3: checking for MP- rows with no ourcommons_id match…')
    cur.execute("""
        SELECT m.mp_id, m.first_name, m.last_name
          FROM mps m
         WHERE m.mp_id LIKE 'MP%'
           AND NOT EXISTS (
               SELECT 1 FROM mp_persons p
                WHERE p.ourcommons_id = CONVERT(m.mp_id USING utf8mb4)
                                          COLLATE utf8mb4_unicode_ci
           )
    """)
    unmatched_mp = cur.fetchall()
    if unmatched_mp:
        log(f'  WARNING: {len(unmatched_mp)} MP- rows have no ourcommons_id match:')
        for r in unmatched_mp[:20]:
            log(f'    {r["mp_id"]}  {r["first_name"]} {r["last_name"]}')
    else:
        log('  All MP- rows matched.')

    # ── Step 4: Match HIST- rows via normalised name ───────────────────────────

    log('\nStep 4: linking HIST- rows via normalised first_name + last_name…')
    cur.execute("""
        SELECT mp_id, first_name, last_name
          FROM mps
         WHERE mp_id LIKE 'HIST-%'
    """)
    hist_rows = cur.fetchall()
    log(f'  {len(hist_rows)} HIST- rows to process.')

    cur.execute("SELECT id, first_name, last_name FROM mp_persons")
    persons = cur.fetchall()
    name_to_person_id = {
        (norm(p['first_name']), norm(p['last_name'])): p['id']
        for p in persons
    }

    hist_updated = 0
    hist_no_match = 0

    for row in hist_rows:
        key = (norm(row['first_name']), norm(row['last_name']))
        person_id = name_to_person_id.get(key)
        if person_id is None:
            hist_no_match += 1
            continue
        if dry:
            hist_updated += 1
            continue
        cur.execute("""
            UPDATE mps SET mp_persons_id = %s
             WHERE mp_id = %s AND (mp_persons_id IS NULL OR mp_persons_id != %s)
        """, (person_id, row['mp_id'], person_id))
        if cur.rowcount:
            hist_updated += 1

    if not dry:
        cx.commit()
    log(f'  Linked: {hist_updated}  |  No name match in mp_persons: {hist_no_match}')

    # ── Summary ───────────────────────────────────────────────────────────────

    if not dry:
        cur.execute("SELECT COUNT(*) AS n FROM mps WHERE mp_persons_id IS NOT NULL")
        linked = cur.fetchone()['n']
        cur.execute("SELECT COUNT(*) AS n FROM mps WHERE mp_persons_id IS NULL")
        unlinked = cur.fetchone()['n']
    else:
        linked   = mp_updated + hist_updated
        unlinked = len(unmatched_mp) + hist_no_match

    sep = '-' * 60
    log(f"\n{sep}\n{'Plan' if dry else 'Done'}.\n\n"
        f"  mps rows with mp_persons_id set  : {linked}\n"
        f"  mps rows still NULL              : {unlinked}\n"
        f"{sep}")

    if dry:
        log('Rerun without --dry-run to apply.')

    cur.close()
    cx.close()


if __name__ == '__main__':
    main()
