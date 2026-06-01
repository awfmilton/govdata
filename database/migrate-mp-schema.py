"""
Phase 3 — MP schema migration.

Creates two new tables in grantconts alongside the existing mps table:

  mp_persons   — one row per human being (normalized)
  mp_mandates  — one row per elected term (one person can have many)

Sources used (in priority order for each person):
  1. op_core_politician + op_core_electedmember  — full OP historical dataset
  2. mps (MP-prefixed OurCommons records)        — adds contact info + current photo
  3. mps (HIST-prefixed records, deduplicated)   — 61 people not yet in OP data

Run from the repo root:
  python database/migrate-mp-schema.py [--dry-run]

The --dry-run flag prints SQL and counts without writing anything.
"""

import os
import sys
import argparse
import mysql.connector
from collections import defaultdict

# ── Connection ────────────────────────────────────────────────────────────────

DB = dict(
    host=os.environ.get('DB_HOST', '35.230.186.163'),
    port=int(os.environ.get('DB_PORT', 3306)),
    database=os.environ.get('DB_NAME', 'grantconts'),
    user=os.environ.get('DB_USER', 'pt_read'),
    password=os.environ.get('DB_PASS', '123456789!!!!!!!!!'),
    charset='utf8mb4',
    collation='utf8mb4_unicode_ci',
)

# ── Schema ────────────────────────────────────────────────────────────────────

DDL_PERSONS = """
CREATE TABLE IF NOT EXISTS mp_persons (
  id               INT          NOT NULL AUTO_INCREMENT,
  op_politician_id INT                   DEFAULT NULL COMMENT 'op_core_politician.id — NULL for MPs not in OP dataset',
  ourcommons_id    VARCHAR(10)           DEFAULT NULL COMMENT 'Legacy MP-xxx key from OurCommons scraper',
  first_name       VARCHAR(100) NOT NULL,
  last_name        VARCHAR(100) NOT NULL,
  gender           CHAR(1)               DEFAULT NULL,
  photo_url        TEXT                  DEFAULT NULL,
  source_url       TEXT                  DEFAULT NULL,
  email            VARCHAR(255)          DEFAULT NULL,
  postal_code      VARCHAR(7)            DEFAULT NULL,
  const_phone      VARCHAR(20)           DEFAULT NULL,
  const_fax        VARCHAR(20)           DEFAULT NULL,
  ottawa_phone     VARCHAR(20)           DEFAULT NULL,
  ottawa_fax       VARCHAR(20)           DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_op_politician_id (op_politician_id),
  UNIQUE KEY uq_ourcommons_id    (ourcommons_id),
  KEY idx_name (last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One row per person. Populated from op_core_politician + OurCommons scraper.';
"""

DDL_MANDATES = """
CREATE TABLE IF NOT EXISTS mp_mandates (
  id           INT       NOT NULL AUTO_INCREMENT,
  mp_id        INT       NOT NULL COMMENT 'FK → mp_persons.id',
  op_member_id INT                DEFAULT NULL COMMENT 'FK → op_core_electedmember.id',
  riding_id    INT                DEFAULT NULL COMMENT 'FK → op_core_riding.id',
  party        VARCHAR(100)       DEFAULT NULL,
  parliament   SMALLINT           DEFAULT NULL,
  start_date   DATE               DEFAULT NULL,
  end_date     DATE               DEFAULT NULL COMMENT 'NULL = currently serving',
  PRIMARY KEY (id),
  UNIQUE KEY uq_op_member_id (op_member_id),
  KEY idx_mp_id    (mp_id),
  KEY idx_riding   (riding_id),
  KEY idx_parl     (parliament),
  KEY idx_dates    (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One row per elected term. Sourced from op_core_electedmember; extended with HIST/scraper data.';
"""

# ── Helpers ───────────────────────────────────────────────────────────────────

def norm(s):
    """Lowercase + strip for name matching."""
    return (s or '').strip().lower()


def log(msg):
    print(msg, flush=True)


# ── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--dry-run', action='store_true', help='Print plan without writing')
    args = parser.parse_args()
    dry = args.dry_run

    if dry:
        log('DRY RUN — no changes will be written.\n')
    else:
        log('LIVE RUN — writing to grantconts.\n')

    cx = mysql.connector.connect(**DB)
    cur = cx.cursor(dictionary=True)

    # ── Step 1: Create tables ─────────────────────────────────────────────────

    log('Step 1: creating tables (IF NOT EXISTS)…')
    if not dry:
        cur.execute(DDL_PERSONS)
        cur.execute(DDL_MANDATES)
        cx.commit()
        log('  Tables ready.')
    else:
        log('  [dry] Would CREATE TABLE mp_persons, mp_mandates.')

    # ── Step 2: Populate mp_persons from op_core_politician ───────────────────
    # Only politicians that have at least one op_core_electedmember record.

    log('\nStep 2: loading OP politicians with at least one mandate…')
    cur.execute("""
        SELECT DISTINCT p.id, p.name_given, p.name_family, p.gender,
               p.headshot, p.slug
          FROM op_core_politician p
          JOIN op_core_electedmember em ON em.politician_id = p.id
    """)
    op_politicians = cur.fetchall()
    log(f'  {len(op_politicians)} OP politicians found.')

    # Build name → op_politician_id lookup for later cross-referencing.
    op_name_to_id = {}
    for row in op_politicians:
        key = (norm(row['name_given']), norm(row['name_family']))
        op_name_to_id[key] = row['id']

    persons_inserted = 0
    op_id_to_person_id = {}  # op_politician_id → new mp_persons.id
    _dry_id_counter = [0]

    def next_dry_id():
        _dry_id_counter[0] += 1
        return _dry_id_counter[0]

    for p in op_politicians:
        photo = p['headshot'] or None
        if photo:
            photo = f"https://cdn.openparliament.ca/thumbnails/{photo}"

        if dry:
            persons_inserted += 1
            op_id_to_person_id[p['id']] = next_dry_id()
            continue

        cur.execute("""
            INSERT IGNORE INTO mp_persons
              (op_politician_id, first_name, last_name, gender, photo_url)
            VALUES (%s, %s, %s, %s, %s)
        """, (p['id'], p['name_given'], p['name_family'],
              p['gender'] if p['gender'] in ('M', 'F') else None, photo))

        if cur.rowcount:
            persons_inserted += 1
            # Fetch back the inserted id.
            cur.execute("SELECT id FROM mp_persons WHERE op_politician_id=%s", (p['id'],))
            row = cur.fetchone()
            op_id_to_person_id[p['id']] = row['id']
        else:
            # Already existed.
            cur.execute("SELECT id FROM mp_persons WHERE op_politician_id=%s", (p['id'],))
            row = cur.fetchone()
            if row:
                op_id_to_person_id[p['id']] = row['id']

    if not dry:
        cx.commit()
    log(f'  Inserted {persons_inserted} person rows from OP data.')

    # ── Step 3: Cross-reference OurCommons MP-prefixed records ───────────────
    # Adds contact info + ourcommons_id to matching mp_persons rows.
    # Unmatched OurCommons records are inserted as new mp_persons rows.

    log('\nStep 3: cross-referencing OurCommons (MP-prefixed) records…')
    cur.execute("""
        SELECT mp_id, first_name, last_name, gender, photo_url, source_url,
               email, postal_code, const_phone, const_fax, ottawa_phone, ottawa_fax
          FROM mps
         WHERE mp_id LIKE 'MP%'
         ORDER BY mp_id
    """)
    oc_records = cur.fetchall()
    log(f'  {len(oc_records)} OurCommons records loaded.')

    oc_matched = 0
    oc_inserted = 0

    for oc in oc_records:
        key = (norm(oc['first_name']), norm(oc['last_name']))
        op_pol_id = op_name_to_id.get(key)

        if dry:
            if op_pol_id:
                oc_matched += 1
            else:
                oc_inserted += 1
            continue

        if op_pol_id:
            # Update existing mp_persons row with contact info.
            cur.execute("""
                UPDATE mp_persons
                   SET ourcommons_id = %s,
                       gender        = COALESCE(gender, %s),
                       photo_url     = COALESCE(%s, photo_url),
                       source_url    = COALESCE(%s, source_url),
                       email         = %s,
                       postal_code   = %s,
                       const_phone   = %s,
                       const_fax     = %s,
                       ottawa_phone  = %s,
                       ottawa_fax    = %s
                 WHERE op_politician_id = %s
            """, (
                oc['mp_id'],
                oc['gender'] if oc['gender'] in ('M', 'F') else None,
                oc['photo_url'] or None,
                oc['source_url'] or None,
                oc['email'] or None,
                oc['postal_code'] or None,
                oc['const_phone'] or None,
                oc['const_fax'] or None,
                oc['ottawa_phone'] or None,
                oc['ottawa_fax'] or None,
                op_pol_id,
            ))
            oc_matched += 1
        else:
            # MP not in OP dataset — insert as new person.
            cur.execute("""
                INSERT IGNORE INTO mp_persons
                  (ourcommons_id, first_name, last_name, gender,
                   photo_url, source_url, email, postal_code,
                   const_phone, const_fax, ottawa_phone, ottawa_fax)
                VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            """, (
                oc['mp_id'], oc['first_name'], oc['last_name'],
                oc['gender'] if oc['gender'] in ('M', 'F') else None,
                oc['photo_url'] or None, oc['source_url'] or None,
                oc['email'] or None, oc['postal_code'] or None,
                oc['const_phone'] or None, oc['const_fax'] or None,
                oc['ottawa_phone'] or None, oc['ottawa_fax'] or None,
            ))
            if cur.rowcount:
                oc_inserted += 1

    if not dry:
        cx.commit()
    log(f'  Matched to OP: {oc_matched}  |  New rows (not in OP): {oc_inserted}')

    # ── Step 4: Add unique HIST- people not already in mp_persons ─────────────

    log('\nStep 4: adding unique HIST- people not already in mp_persons…')
    cur.execute("""
        SELECT MIN(mp_id) as hist_id, first_name, last_name,
               ANY_VALUE(district_name) AS district_name,
               ANY_VALUE(party) AS party
          FROM mps
         WHERE mp_id LIKE 'HIST-%'
         GROUP BY first_name, last_name
    """)
    hist_unique = cur.fetchall()
    log(f'  {len(hist_unique)} unique HIST- people found.')

    # Reload op_name_to_id including any new inserts.
    if not dry:
        cur.execute("SELECT op_politician_id, first_name, last_name FROM mp_persons WHERE op_politician_id IS NOT NULL")
        for row in cur.fetchall():
            key = (norm(row['first_name']), norm(row['last_name']))
            op_name_to_id[key] = row['op_politician_id']

    # Also build a set of all names already in mp_persons for the NOT IN check.
    if not dry:
        cur.execute("SELECT first_name, last_name FROM mp_persons")
        existing_names = {(norm(r['first_name']), norm(r['last_name'])) for r in cur.fetchall()}
    else:
        existing_names = set(op_name_to_id.keys())
        # Add OurCommons names to existing set.
        for oc in oc_records:
            existing_names.add((norm(oc['first_name']), norm(oc['last_name'])))

    hist_inserted = 0
    for h in hist_unique:
        key = (norm(h['first_name']), norm(h['last_name']))
        if key in existing_names:
            continue  # already in mp_persons via OP or OurCommons

        if dry:
            hist_inserted += 1
            continue

        cur.execute("""
            INSERT IGNORE INTO mp_persons (first_name, last_name)
            VALUES (%s, %s)
        """, (h['first_name'], h['last_name']))
        if cur.rowcount:
            hist_inserted += 1
            existing_names.add(key)

    if not dry:
        cx.commit()
    log(f'  Inserted {hist_inserted} HIST-only people not found in OP dataset.')

    # ── Step 5: Populate mp_mandates from op_core_electedmember ──────────────

    log('\nStep 5: populating mp_mandates from OP elected members…')
    cur.execute("""
        SELECT em.id           AS op_member_id,
               em.politician_id,
               em.riding_id,
               pa.name_en      AS party,
               em.start_date,
               em.end_date,
               MIN(s.parliamentnum) AS parliament
          FROM op_core_electedmember em
          JOIN op_core_party pa ON pa.id = em.party_id
          LEFT JOIN op_core_electedmember_sessions ems ON ems.electedmember_id = em.id
          LEFT JOIN op_core_session s ON s.id = ems.session_id
         GROUP BY em.id
         ORDER BY em.start_date
    """)
    op_mandates = cur.fetchall()
    log(f'  {len(op_mandates)} OP mandates loaded.')

    mandates_inserted = 0
    mandates_skipped = 0

    if not dry:
        # Build op_politician_id → mp_persons.id map (full, after all inserts).
        cur.execute("SELECT id, op_politician_id FROM mp_persons WHERE op_politician_id IS NOT NULL")
        op_id_to_person_id = {r['op_politician_id']: r['id'] for r in cur.fetchall()}

    for m in op_mandates:
        person_id = op_id_to_person_id.get(m['politician_id'])
        if not person_id:
            mandates_skipped += 1
            continue

        if dry:
            mandates_inserted += 1
            continue

        cur.execute("""
            INSERT IGNORE INTO mp_mandates
              (mp_id, op_member_id, riding_id, party, parliament, start_date, end_date)
            VALUES (%s, %s, %s, %s, %s, %s, %s)
        """, (
            person_id,
            m['op_member_id'],
            m['riding_id'],
            m['party'],
            m['parliament'],
            m['start_date'],
            m['end_date'],
        ))
        if cur.rowcount:
            mandates_inserted += 1

    if not dry:
        cx.commit()
    log(f'  Inserted {mandates_inserted}  |  Skipped (person not found): {mandates_skipped}')

    # ── Step 6: Add mandate rows for HIST-only people (parliament 45) ─────────

    log('\nStep 6: adding parliament-45 mandate rows for HIST-only people…')

    if not dry:
        cur.execute("""
            SELECT p.id AS person_id, p.first_name, p.last_name
              FROM mp_persons p
              LEFT JOIN mp_mandates m ON m.mp_id = p.id
             WHERE m.id IS NULL
        """)
        no_mandate = cur.fetchall()
    else:
        no_mandate = [h for h in hist_unique]  # approximate for dry run

    hist_mandates = 0
    if not dry:
        # Get riding_id for each HIST- person from the mps table.
        cur.execute("""
            SELECT first_name, last_name,
                   ANY_VALUE(district_name) AS district_name,
                   ANY_VALUE(party) AS party
              FROM mps WHERE mp_id LIKE 'HIST-%'
             GROUP BY first_name, last_name
        """)
        hist_data = {(norm(r['first_name']), norm(r['last_name'])): r for r in cur.fetchall()}

        for person in no_mandate:
            key = (norm(person['first_name']), norm(person['last_name']))
            h = hist_data.get(key, {})
            cur.execute("""
                INSERT IGNORE INTO mp_mandates
                  (mp_id, party, parliament, start_date)
                VALUES (%s, %s, 45, '2025-04-28')
            """, (person['person_id'], h.get('party') or None))
            if cur.rowcount:
                hist_mandates += 1

        cx.commit()
    else:
        hist_mandates = len(no_mandate)

    log(f'  Added {hist_mandates} parliament-45 mandate placeholders.')

    # ── Summary ───────────────────────────────────────────────────────────────

    if not dry:
        cur.execute("SELECT COUNT(*) AS n FROM mp_persons")
        total_persons = cur.fetchone()['n']
        cur.execute("SELECT COUNT(*) AS n FROM mp_mandates")
        total_mandates = cur.fetchone()['n']
        cur.execute("SELECT COUNT(*) AS n FROM mp_mandates WHERE end_date IS NULL")
        active_mandates = cur.fetchone()['n']
        cur.execute("SELECT MIN(parliament) AS lo, MAX(parliament) AS hi FROM mp_mandates WHERE parliament IS NOT NULL")
        parl_range = cur.fetchone()
    else:
        total_persons  = len(op_politicians) + oc_inserted + hist_inserted
        total_mandates = len(op_mandates) + hist_mandates
        active_mandates = '?'
        parl_range = {'lo': '?', 'hi': 45}

    log(f"""
{'─'*60}
Migration {'plan' if dry else 'complete'}.

  mp_persons  rows : {total_persons}
  mp_mandates rows : {total_mandates}
    active (no end_date): {active_mandates}
    parliament range    : {parl_range['lo']} – {parl_range['hi']}
{'─'*60}""")

    if dry:
        log('Rerun without --dry-run to apply.')

    cur.close()
    cx.close()


if __name__ == '__main__':
    main()
