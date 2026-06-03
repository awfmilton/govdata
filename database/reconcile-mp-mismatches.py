"""
Task 2 — Reconcile 20 name-mismatch OurCommons MPs.

The original migrate-mp-schema.py matched by first_name + last_name. 20 MPs
have middle initials or compound names that broke exact matching, leaving their
mp_persons rows with op_politician_id IS NULL.

Strategy:
  1. Extract the slug from mps.source_url (text before the "(nnn)" suffix).
  2. Look up op_core_politician by that slug.
  3. Set mp_persons.op_politician_id where found.
  4. Populate mp_mandates from op_core_electedmember for those politicians
     (same logic as migrate-mp-schema.py step 5).

Run:
  python database/reconcile-mp-mismatches.py [--dry-run]
"""

import os
import re
import argparse
import mysql.connector
from datetime import date

DB = dict(
    host=os.environ.get('DB_HOST', '35.230.186.163'),
    port=int(os.environ.get('DB_PORT', 3306)),
    database=os.environ.get('DB_NAME', 'grantconts'),
    user=os.environ.get('DB_USER', 'pt_read'),
    password=os.environ.get('DB_PASS', '***REMOVED***'),
    charset='utf8mb4',
)

SLUG_RE = re.compile(r'([a-z0-9][a-z0-9\-]+[a-z0-9])\(\d+\)$', re.IGNORECASE)
INITIAL_RE = re.compile(r'-[a-z]-')

# OurCommons slug → actual op_core_politician.slug overrides for cases where
# slug stripping is not enough (nickname, married name, full legal name, etc.)
SLUG_OVERRIDES = {
    'rheal-eloi-fortin':     'rheal-fortin',       # middle name, not initial
    'michelle-rempel-garner':'michelle-rempel',     # married name added later
    'robert-kitchen':        'robert-gordon-kitchen',  # full legal name in OP
    'robert-morrissey':      'bobby-morrissey',     # after initial strip; nickname
    'robert-oliphant':       'rob-oliphant',        # nickname
}


def extract_slug(source_url):
    """Pull 'tony-van-bynen' out of '.../tony-van-bynen(105270)'."""
    if not source_url:
        return None
    last = source_url.rstrip('/').split('/')[-1]
    m = SLUG_RE.search(last)
    return m.group(1).lower() if m else None


def candidate_slugs(raw_slug):
    """Return slugs to try in order: exact, initial-stripped, overrides."""
    candidates = [raw_slug]
    stripped = INITIAL_RE.sub('-', raw_slug)
    if stripped != raw_slug:
        candidates.append(stripped)
    for src, dst in SLUG_OVERRIDES.items():
        if raw_slug == src or stripped == src:
            candidates.append(dst)
            break
    # Always check overrides by exact raw slug too.
    if raw_slug in SLUG_OVERRIDES and SLUG_OVERRIDES[raw_slug] not in candidates:
        candidates.append(SLUG_OVERRIDES[raw_slug])
    return candidates


def to_date(v):
    if isinstance(v, date):
        return v
    if v is None:
        return None
    return date.fromisoformat(str(v)[:10])


def log(msg):
    print(msg, flush=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--dry-run', action='store_true')
    args = parser.parse_args()
    dry = args.dry_run

    log('DRY RUN — no changes.\n' if dry else 'LIVE RUN — writing to grantconts.\n')

    cx = mysql.connector.connect(**DB)
    cur = cx.cursor(dictionary=True)

    # ── Step 1: Load the 20 unmatched mp_persons rows ─────────────────────────

    log('Step 1: loading unmatched mp_persons (no op_politician_id)…')
    cur.execute("""
        SELECT p.id AS person_id, p.ourcommons_id, p.first_name, p.last_name,
               m.source_url
          FROM mp_persons p
          LEFT JOIN mps m
            ON CONVERT(m.mp_id USING utf8mb4) COLLATE utf8mb4_unicode_ci = p.ourcommons_id
         WHERE p.op_politician_id IS NULL
           AND p.ourcommons_id IS NOT NULL
         ORDER BY p.last_name
    """)
    unmatched = cur.fetchall()
    log(f'  {len(unmatched)} unmatched MPs found.')

    # ── Step 2: Match each one via slug → op_core_politician ──────────────────

    log('\nStep 2: matching via OurCommons URL slug…')
    matched = []
    no_slug = []
    no_op_match = []

    for row in unmatched:
        slug = extract_slug(row['source_url'])
        if not slug:
            no_slug.append(row)
            log(f'  NO SLUG: {row["ourcommons_id"]} {row["first_name"]} {row["last_name"]}')
            continue

        op_row = None
        tried = []
        for candidate in candidate_slugs(slug):
            tried.append(candidate)
            cur.execute(
                "SELECT id, name_given, name_family FROM op_core_politician WHERE slug = %s",
                (candidate,)
            )
            op_row = cur.fetchone()
            if op_row:
                break

        if not op_row:
            no_op_match.append((row, slug))
            log(f'  NO OP MATCH: tried={tried}  ({row["first_name"]} {row["last_name"]})')
            continue

        matched.append((row, op_row))
        log(f'  MATCHED: {row["ourcommons_id"]} {row["first_name"]} {row["last_name"]}'
            f'  ->  op_id={op_row["id"]}  {op_row["name_given"]} {op_row["name_family"]}')

    log(f'\n  Matched: {len(matched)}  |  No slug: {len(no_slug)}'
        f'  |  No OP row: {len(no_op_match)}')

    if no_op_match:
        log('\n  Slugs with no op_core_politician row:')
        for row, slug in no_op_match:
            log(f'    {slug!r}  ({row["first_name"]} {row["last_name"]})')

    # ── Step 3: Link mp_persons rows ──────────────────────────────────────────
    # Two cases:
    #   (a) op_politician_id not yet used by any row → set it on the OC row.
    #   (b) op_politician_id already claimed by a different row (OP-sourced) →
    #       copy ourcommons_id + contact info onto that row; repoint mps.mp_persons_id.

    log('\nStep 3: linking mp_persons rows (merge if needed)…')

    # Load ourcommons contact fields we'll carry across in the merge case.
    cur.execute("""
        SELECT mp_id, photo_url, source_url, email, postal_code,
               const_phone, const_fax, ottawa_phone, ottawa_fax
          FROM mps
         WHERE mp_id LIKE 'MP%'
    """)
    oc_contact = {r['mp_id']: r for r in cur.fetchall()}

    persons_updated = 0
    persons_merged = 0

    for (person, op_row) in matched:
        op_pol_id = op_row['id']

        if dry:
            persons_updated += 1
            continue

        # Is op_politician_id already taken by another mp_persons row?
        cur.execute(
            "SELECT id FROM mp_persons WHERE op_politician_id = %s",
            (op_pol_id,)
        )
        existing = cur.fetchone()

        if not existing:
            # Case (a): safe to set directly.
            cur.execute(
                "UPDATE mp_persons SET op_politician_id = %s WHERE id = %s",
                (op_pol_id, person['person_id'])
            )
            persons_updated += 1
        else:
            # Case (b): merge — copy OurCommons data onto the OP-sourced row.
            target_id = existing['id']
            oc = oc_contact.get(person['ourcommons_id'], {})
            # Clear ourcommons_id from the OC-only row first so the unique
            # key doesn't block the copy onto the canonical row.
            cur.execute("""
                UPDATE mp_persons SET ourcommons_id = NULL WHERE id = %s
            """, (person['person_id'],))

            # Re-run the contact merge now that the unique constraint is free.
            cur.execute("""
                UPDATE mp_persons
                   SET ourcommons_id  = COALESCE(ourcommons_id,  %s),
                       photo_url      = COALESCE(photo_url,      %s),
                       source_url     = COALESCE(source_url,     %s),
                       email          = COALESCE(email,          %s),
                       postal_code    = COALESCE(postal_code,    %s),
                       const_phone    = COALESCE(const_phone,    %s),
                       const_fax      = COALESCE(const_fax,      %s),
                       ottawa_phone   = COALESCE(ottawa_phone,   %s),
                       ottawa_fax     = COALESCE(ottawa_fax,     %s)
                 WHERE id = %s
            """, (
                person['ourcommons_id'],
                oc.get('photo_url') or None,
                oc.get('source_url') or None,
                oc.get('email') or None,
                oc.get('postal_code') or None,
                oc.get('const_phone') or None,
                oc.get('const_fax') or None,
                oc.get('ottawa_phone') or None,
                oc.get('ottawa_fax') or None,
                target_id,
            ))

            # Repoint mps rows that currently reference the OC-only person row.
            cur.execute("""
                UPDATE mps SET mp_persons_id = %s
                 WHERE mp_persons_id = %s
            """, (target_id, person['person_id']))
            log(f'  MERGE: person_id={person["person_id"]} '
                f'({person["first_name"]} {person["last_name"]}) '
                f'-> canonical id={target_id} (op_politician_id={op_pol_id})')
            persons_merged += 1
            # Override the person_id used for mandates insertion.
            person['_canonical_id'] = target_id

    if not dry:
        cx.commit()
    log(f'  Direct updates: {persons_updated}  |  Merges: {persons_merged}')

    # ── Step 4: Pull session era ranges for parliament derivation ─────────────

    log('\nStep 4: loading parliament session eras…')
    cur.execute("""
        SELECT parliamentnum,
               MIN(start)                         AS era_start,
               MAX(COALESCE(`end`, '2099-01-01')) AS era_end
          FROM op_core_session
         WHERE parliamentnum IS NOT NULL
         GROUP BY parliamentnum
         ORDER BY parliamentnum
    """)
    from datetime import timedelta
    eras = []
    for e in cur.fetchall():
        eras.append({
            'num': e['parliamentnum'],
            'start': to_date(e['era_start']),
            'end': to_date(e['era_end']),
        })

    def derive_parliament(start_d):
        if not start_d:
            return None
        for era in eras:
            window_start = era['start'] - timedelta(days=200)
            if window_start <= start_d <= era['end']:
                return era['num']
        return None

    # ── Step 5: Insert mp_mandates for newly-matched politicians ──────────────

    log('\nStep 5: inserting mp_mandates for newly-matched politicians…')

    # Build op_politician_id → mp_persons.id map (including new updates).
    if not dry:
        cur.execute("""
            SELECT id, op_politician_id FROM mp_persons
             WHERE op_politician_id IS NOT NULL
        """)
        op_to_person = {r['op_politician_id']: r['id'] for r in cur.fetchall()}
    else:
        op_to_person = {
            op_row['id']: person.get('_canonical_id', person['person_id'])
            for person, op_row in matched
        }

    mandates_inserted = 0
    mandates_skipped = 0

    for (person, op_row) in matched:
        op_pol_id = op_row['id']
        person_id = op_to_person.get(op_pol_id)
        if not person_id:
            log(f'  WARNING: no person_id for op_politician_id={op_pol_id}')
            continue

        cur.execute("""
            SELECT em.id AS op_member_id, em.riding_id, pa.name_en AS party,
                   em.start_date, em.end_date
              FROM op_core_electedmember em
              JOIN op_core_party pa ON pa.id = em.party_id
             WHERE em.politician_id = %s
             ORDER BY em.start_date
        """, (op_pol_id,))
        mandates = cur.fetchall()

        for m in mandates:
            start_d = to_date(m['start_date'])
            parliament = derive_parliament(start_d)

            if dry:
                mandates_inserted += 1
                continue

            # INSERT IGNORE — safe to re-run; uq_op_member_id prevents dupes.
            cur.execute("""
                INSERT IGNORE INTO mp_mandates
                  (mp_id, op_member_id, riding_id, party, parliament, start_date, end_date)
                VALUES (%s, %s, %s, %s, %s, %s, %s)
            """, (
                person_id,
                m['op_member_id'],
                m['riding_id'],
                m['party'],
                parliament,
                m['start_date'],
                m['end_date'],
            ))
            if cur.rowcount:
                mandates_inserted += 1
            else:
                mandates_skipped += 1

    if not dry:
        cx.commit()
    log(f'  Mandates inserted: {mandates_inserted}  |  Already existed: {mandates_skipped}')

    # ── Summary ───────────────────────────────────────────────────────────────

    if not dry:
        cur.execute("SELECT COUNT(*) AS n FROM mp_persons WHERE op_politician_id IS NULL AND ourcommons_id IS NOT NULL")
        still_unmatched = cur.fetchone()['n']
        cur.execute("SELECT COUNT(*) AS n FROM mp_mandates")
        total_mandates = cur.fetchone()['n']
    else:
        still_unmatched = len(no_slug) + len(no_op_match)
        total_mandates = '?'

    sep = '-' * 60
    log(f'\n{sep}\n{"Plan" if dry else "Done"}.\n\n'
        f'  mp_persons still without op_politician_id: {still_unmatched}\n'
        f'  mp_mandates total                        : {total_mandates}\n'
        f'{sep}')

    if dry:
        log('Rerun without --dry-run to apply.')

    cur.close()
    cx.close()


if __name__ == '__main__':
    main()
