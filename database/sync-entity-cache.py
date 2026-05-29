#!/usr/bin/env python3
"""
Direct entity-cache sync: grantconts → govdata_cache_01.

Reads each registered entity's agreements from the grantconts source database,
builds the same JSON snapshot that Entity_Reports::build_snapshot() produces,
and writes it directly to govdata_cache_01 — bypassing the PHP/WP-CLI path
that was failing on large entities due to max_allowed_packet limits.

Key differences from the PHP refresh script:
  - No stored-procedure call: writes directly via INSERT … ON DUPLICATE KEY UPDATE.
  - snapshot_json omits the 'agreements' array (the 2500-row list) — that data
    lives in entity_agreements, which the REST search endpoint queries directly.
    The compact JSON is well under any reasonable packet limit.
  - Runs outside WordPress/PHP: no memory_limit, no 8-second query timeout.

Usage:
  # Credentials via environment variables (recommended):
  export SRC_PASS=yourpassword DST_PASS=yourpassword
  python3 database/sync-entity-cache.py

  # Or set all creds explicitly:
  export SRC_HOST=35.230.186.163 SRC_DB=grantconts SRC_USER=pt_read SRC_PASS=...
  export DST_HOST=mysql.govdata.ca DST_DB=govdata_cache_01 DST_USER=gdc_01 DST_PASS=...
  python3 database/sync-entity-cache.py

  # Sync a single entity:
  python3 database/sync-entity-cache.py --slug=egale-canada

  # Dry-run (read source, skip all writes):
  python3 database/sync-entity-cache.py --dry-run

Dependencies:
  pip install mysql-connector-python
"""

import argparse
import getpass
import json
import os
import re
import sys
from datetime import datetime, timezone

# Force UTF-8 output on Windows so arrow/tick characters print cleanly.
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')
if hasattr(sys.stderr, 'reconfigure'):
    sys.stderr.reconfigure(encoding='utf-8', errors='replace')

try:
    import mysql.connector
    from mysql.connector import Error as MySQLError
except ImportError:
    print("ERROR: mysql-connector-python is required.", file=sys.stderr)
    print("  pip install mysql-connector-python", file=sys.stderr)
    sys.exit(1)

# ─── Entity registry (mirrors Entity_Reports::registry()) ─────────────────────
# Keep this in sync with publictech-core/inc/class-entity-reports.php.

ENTITIES = [
    {
        'slug': 'canadian-anti-hate-network',
        'name': 'Canadian Anti-Hate Network',
        'legal_names': ['Canadian Anti-Hate Network', 'Canadian Anti Hate Network'],
        'keywords': ['anti-hate', 'hate', 'extremism', 'far-right', 'radicalization'],
        'blurb': 'A non-profit that researches and reports on hate groups and extremism in Canada.',
    },
    {
        'slug': 'egale-canada',
        'name': 'Egale Canada',
        'legal_names': ['Egale Canada', 'Egale Canada Human Rights Trust'],
        'keywords': ['2SLGBTQI', 'LGBTQ', 'gender', 'inclusion', 'homelessness'],
        'blurb': 'A national organisation focused on 2SLGBTQI rights, education and research.',
    },
    {
        'slug': 'national-council-of-canadian-muslims',
        'name': 'National Council of Canadian Muslims',
        'legal_names': ['National Council of Canadian Muslims', 'Canadian Council on American-Islamic Relations'],
        'keywords': ['anti-racism', 'Islamophobia', 'Muslim', 'community', 'civil liberties'],
        'blurb': 'A Muslim civil-liberties and advocacy organisation.',
    },
    {
        'slug': 'fierte-canada-pride',
        'name': 'Fierté Canada Pride',
        'legal_names': ['Fierté Canada Pride', 'Fierte Canada Pride', 'Canada Pride'],
        'keywords': ['Pride', '2SLGBTQI', 'festival', 'community', 'safety'],
        'blurb': 'A national association of Pride festival organisations.',
    },
    {
        'slug': 'canadian-climate-institute',
        'name': 'Canadian Climate Institute',
        'legal_names': ['Canadian Climate Institute', 'Canadian Institute for Climate Choices'],
        'keywords': ['climate', 'emissions', 'net zero', 'environment', 'energy'],
        'blurb': 'A climate-policy research organisation.',
    },
    {
        'slug': 'amnesty-international-canada',
        'name': 'Amnesty International Canada',
        'legal_names': ['Amnesty International Canada', 'Amnesty International Canadian Section'],
        'keywords': ['human rights', 'advocacy', 'refugees', 'justice'],
        'blurb': 'The Canadian section of the global human-rights organisation.',
    },
    {
        'slug': 'canadian-association-of-elizabeth-fry-societies',
        'name': 'Canadian Association of Elizabeth Fry Societies',
        'legal_names': ['Canadian Association of Elizabeth Fry Societies', 'Elizabeth Fry'],
        'keywords': ['women', 'justice', 'incarceration', 'reintegration'],
        'blurb': 'A federation supporting women and gender-diverse people in the justice system.',
    },
    {
        'slug': 'canadian-council-for-refugees',
        'name': 'Canadian Council for Refugees',
        'legal_names': ['Canadian Council for Refugees', 'Conseil canadien pour les réfugiés'],
        'keywords': ['refugees', 'immigration', 'settlement', 'newcomers'],
        'blurb': 'A national network of refugee- and immigrant-serving organisations.',
    },
    {
        'slug': 'centre-for-israel-and-jewish-affairs',
        'name': 'Centre for Israel and Jewish Affairs',
        'legal_names': ['Centre for Israel and Jewish Affairs', 'Centre for Israel & Jewish Affairs'],
        'keywords': ['antisemitism', 'Jewish', 'community', 'security'],
        'blurb': 'The advocacy agent of the Jewish Federations of Canada.',
    },
    {
        'slug': 'pride-toronto',
        'name': 'Pride Toronto',
        'legal_names': ['Pride Toronto'],
        'keywords': ['Pride', '2SLGBTQI', 'festival', 'Toronto'],
        'blurb': "Organiser of Toronto's annual Pride festival.",
    },
]

SOURCE_ALIAS = 'pt-grantconts-01'

SOURCE_COLS = [
    'ref_number', 'agreement_value', 'agreement_start_date', 'year',
    'fiscal_year', 'agreement_title_en', 'description_eng', 'prog_name_en',
    'prog_purpose_en', 'owner_org_en', 'recipient_legal_name',
    'recipient_operating_name',
]

DATE_RE = re.compile(r'^\d{4}-\d{2}-\d{2}$')


# ─── Source query ──────────────────────────────────────────────────────────────

def source_query(entity: dict, limit: int = 50000):
    frags, params = [], []
    for needle in entity.get('legal_names', []):
        needle = str(needle).strip()
        if not needle:
            continue
        like = f'%{needle}%'
        frags.append('`recipient_legal_name` LIKE %s')
        params.append(like)
        frags.append('`recipient_operating_name` LIKE %s')
        params.append(like)
    if not frags:
        return '', []
    limit = max(1, min(200000, limit))
    cols = ', '.join(f'`{c}`' for c in SOURCE_COLS)
    sql = (
        f'SELECT {cols} FROM `master_table` WHERE '
        + ' OR '.join(frags)
        + f' ORDER BY `agreement_value` DESC LIMIT {limit}'
    )
    return sql, params


# ─── Snapshot builder ──────────────────────────────────────────────────────────

def _clip(text, length: int) -> str:
    text = re.sub(r'\s+', ' ', str(text or '')).strip()
    if len(text) <= length:
        return text
    return text[:length - 1].rstrip() + '…'


def _date_or_none(d) -> str | None:
    s = str(d).strip() if d else ''
    return s if DATE_RE.match(s) else None


def build_snapshot(rows: list, entity: dict) -> tuple[dict, list]:
    """
    Mirror of Entity_Reports::build_snapshot() in PHP.

    Returns (snapshot_dict, agreements_for_table) where:
      - snapshot_dict has no 'agreements' key (kept compact to avoid packet limits)
      - agreements_for_table is up to 2500 dicts for entity_agreements INSERT
    """
    keywords = [str(k) for k in entity.get('keywords', [])]
    kw_hits = {kw: {'keyword': kw, 'total': 0.0, 'count': 0} for kw in keywords}

    by_year: dict = {}
    by_program: dict = {}
    by_dept: dict = {}
    matched_names: dict = {}

    total = pos = neg = max_val = 0.0
    first_year = last_year = None
    count = 0

    # rows arrive value-DESC from SQL; capture first 2500 and first 25 for tables.
    agreements_for_table: list = []
    top_25: list = []

    for r in rows:
        count += 1
        value = float(r.get('agreement_value') or 0)
        year = int(r.get('year') or 0)
        prog = (str(r.get('prog_name_en') or '').strip()
                or str(r.get('prog_purpose_en') or '').strip()
                or 'Unspecified')
        dept = str(r.get('owner_org_en') or '').strip() or 'Unspecified'
        legal = str(r.get('recipient_legal_name') or '').strip()

        total += value
        if value >= 0:
            pos += value
        else:
            neg += value
        if value > max_val:
            max_val = value

        if 1900 <= year <= 2100:
            if year not in by_year:
                by_year[year] = {
                    'year': year, 'total': 0.0, 'positive': 0.0,
                    'negative': 0.0, 'pos_count': 0, 'neg_count': 0,
                }
            by_year[year]['total'] += value
            if value >= 0:
                by_year[year]['positive'] += value
                by_year[year]['pos_count'] += 1
            else:
                by_year[year]['negative'] += value
                by_year[year]['neg_count'] += 1
            first_year = year if first_year is None else min(first_year, year)
            last_year = year if last_year is None else max(last_year, year)

        by_program.setdefault(prog, {'program': prog, 'total': 0.0, 'count': 0})
        by_program[prog]['total'] += value
        by_program[prog]['count'] += 1

        by_dept.setdefault(dept, {'department': dept, 'total': 0.0, 'count': 0})
        by_dept[dept]['total'] += value
        by_dept[dept]['count'] += 1

        if keywords:
            haystack = ' '.join([
                str(r.get('agreement_title_en') or ''),
                str(r.get('description_eng') or ''),
                prog,
                str(r.get('prog_purpose_en') or ''),
            ]).lower()
            for kw in keywords:
                if kw and kw.lower() in haystack:
                    kw_hits[kw]['total'] += value
                    kw_hits[kw]['count'] += 1

        if legal:
            matched_names[legal] = matched_names.get(legal, 0) + 1

        # rows are value-desc so first N seen are the top N
        if len(top_25) < 25:
            top_25.append(r)
        if len(agreements_for_table) < 2500:
            agreements_for_table.append(r)

    # Sort / trim grouped views
    by_year_list = sorted(by_year.values(), key=lambda r: r['year'])
    for row in by_year_list:
        row['total'] = round(row['total'], 2)
        row['positive'] = round(row['positive'], 2)
        row['negative'] = round(row['negative'], 2)

    by_program_list = sorted(by_program.values(), key=lambda r: r['total'], reverse=True)[:15]
    for row in by_program_list:
        row['total'] = round(row['total'], 2)

    by_dept_list = sorted(by_dept.values(), key=lambda r: r['total'], reverse=True)[:12]
    for row in by_dept_list:
        row['total'] = round(row['total'], 2)

    kw_hits_list = sorted(kw_hits.values(), key=lambda r: r['total'], reverse=True)
    for row in kw_hits_list:
        row['total'] = round(row['total'], 2)

    peak = {'year': 0, 'total': 0.0}
    for row in by_year_list:
        if row['total'] > peak['total']:
            peak = {'year': row['year'], 'total': row['total']}

    matched = sorted(matched_names.items(), key=lambda x: x[1], reverse=True)[:10]
    matched = [{'name': nm, 'count': cnt} for nm, cnt in matched]

    top_agreements = [
        {
            'ref_number': str(r.get('ref_number') or ''),
            'value': round(float(r.get('agreement_value') or 0), 2),
            'year': int(r.get('year') or 0),
            'fiscal_year': str(r.get('fiscal_year') or ''),
            'title': _clip(r.get('agreement_title_en'), 200),
            'program': str(r.get('prog_name_en') or ''),
            'department': str(r.get('owner_org_en') or ''),
            'description': _clip(r.get('description_eng'), 240),
        }
        for r in top_25
    ]

    span = max(1, last_year - first_year + 1) if first_year and last_year else 1

    snapshot = {
        'slug': entity['slug'],
        'entity_name': entity['name'],
        'blurb': entity.get('blurb', ''),
        'generated_at': datetime.now(timezone.utc).isoformat(),
        'source': SOURCE_ALIAS,
        'keywords': keywords,
        'matched_names': matched,
        'totals': {
            'total_funding': round(total, 2),
            'positive_funding': round(pos, 2),
            'negative_funding': round(neg, 2),
            'agreement_count': count,
            'first_year': first_year,
            'last_year': last_year,
            'avg_per_year': round(total / span, 2),
            'max_agreement': round(max_val, 2),
            'peak_year': peak,
        },
        'by_year': by_year_list,
        'by_program': by_program_list,
        'by_department': by_dept_list,
        'keyword_hits': kw_hits_list,
        'top_agreements': top_agreements,
        # 'agreements' intentionally absent — populates entity_agreements table directly,
        # keeping snapshot_json compact and well under max_allowed_packet.
    }

    # Normalise agreements_for_table into INSERT-ready dicts
    table_rows = [
        {
            'ref_number': str(r.get('ref_number') or '')[:50] or None,
            'recipient_legal_name': str(r.get('recipient_legal_name') or '')[:350] or None,
            'recipient_operating_name': str(r.get('recipient_operating_name') or '')[:350] or None,
            'agreement_title': _clip(r.get('agreement_title_en'), 800) or None,
            'description': _clip(r.get('description_eng'), 500) or None,
            'prog_name': _clip(r.get('prog_name_en'), 255) or None,
            'owner_org': _clip(r.get('owner_org_en'), 255) or None,
            'agreement_value': round(float(r.get('agreement_value') or 0), 2),
            'fiscal_year': str(r.get('fiscal_year') or '')[:9] or None,
            'year': (int(r.get('year') or 0) or None),
            'agreement_start_date': _date_or_none(r.get('agreement_start_date')),
        }
        for r in agreements_for_table
    ]

    return snapshot, table_rows


# ─── Credential resolution ─────────────────────────────────────────────────────

def _env_or_prompt(var: str, prompt: str, secret: bool = False) -> str:
    val = os.environ.get(var, '')
    if val:
        return val
    if secret:
        return getpass.getpass(f'{prompt}: ')
    return input(f'{prompt}: ')


def resolve_creds(prefix: str, defaults: dict) -> dict:
    host = os.environ.get(f'{prefix}_HOST') or defaults['host']
    port = int(os.environ.get(f'{prefix}_PORT') or defaults.get('port', 3306))
    db   = os.environ.get(f'{prefix}_DB')   or defaults['db']
    user = os.environ.get(f'{prefix}_USER') or defaults['user']
    pw   = os.environ.get(f'{prefix}_PASS') or getpass.getpass(f'{prefix} password (user={user}): ')
    return {'host': host, 'port': port, 'database': db, 'user': user, 'password': pw}


# ─── Write helpers ─────────────────────────────────────────────────────────────

_SNAPSHOT_SQL = """
INSERT INTO `entity_snapshots`
    (`slug`, `entity_name`, `source_alias`, `query_keywords`,
     `total_funding`, `positive_funding`, `negative_funding`,
     `agreement_count`, `first_year`, `last_year`, `snapshot_json`)
VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
ON DUPLICATE KEY UPDATE
    `entity_name`      = VALUES(`entity_name`),
    `source_alias`     = VALUES(`source_alias`),
    `query_keywords`   = VALUES(`query_keywords`),
    `total_funding`    = VALUES(`total_funding`),
    `positive_funding` = VALUES(`positive_funding`),
    `negative_funding` = VALUES(`negative_funding`),
    `agreement_count`  = VALUES(`agreement_count`),
    `first_year`       = VALUES(`first_year`),
    `last_year`        = VALUES(`last_year`),
    `snapshot_json`    = VALUES(`snapshot_json`)
"""

_AGREEMENTS_SQL = """
INSERT INTO `entity_agreements`
    (`slug`, `ref_number`, `recipient_legal_name`, `recipient_operating_name`,
     `agreement_title`, `description`, `prog_name`, `owner_org`,
     `agreement_value`, `fiscal_year`, `year`, `agreement_start_date`)
VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
"""


def write_entity(cursor, entity: dict, snapshot: dict, table_rows: list, dry_run: bool):
    totals = snapshot['totals']
    snap_json = json.dumps(snapshot, ensure_ascii=False)

    if dry_run:
        print(f"    [dry-run] entity_snapshots upsert  slug={entity['slug']}  "
              f"json={len(snap_json):,} bytes")
        print(f"    [dry-run] entity_agreements delete+insert  rows={len(table_rows)}")
        return

    cursor.execute(_SNAPSHOT_SQL, (
        entity['slug'],
        entity['name'],
        SOURCE_ALIAS,
        ', '.join(entity.get('keywords', [])),
        totals['total_funding'],
        totals['positive_funding'],
        totals['negative_funding'],
        totals['agreement_count'],
        totals['first_year'],
        totals['last_year'],
        snap_json,
    ))

    cursor.execute('DELETE FROM `entity_agreements` WHERE `slug` = %s', (entity['slug'],))

    batch_size = 200
    for i in range(0, len(table_rows), batch_size):
        batch = table_rows[i:i + batch_size]
        cursor.executemany(_AGREEMENTS_SQL, [
            (
                entity['slug'],
                a['ref_number'],
                a['recipient_legal_name'],
                a['recipient_operating_name'],
                a['agreement_title'],
                a['description'],
                a['prog_name'],
                a['owner_org'],
                a['agreement_value'],
                a['fiscal_year'],
                a['year'],
                a['agreement_start_date'],
            )
            for a in batch
        ])


# ─── Main ──────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        description='Sync entity snapshots directly from grantconts to govdata_cache_01.'
    )
    parser.add_argument('--slug', help='Sync only this entity slug (default: all)')
    parser.add_argument('--dry-run', action='store_true',
                        help='Read source data but skip all writes to the cache DB')
    args = parser.parse_args()

    src_creds = resolve_creds('SRC', {
        'host': '35.230.186.163',
        'db':   'grantconts',
        'user': 'pt_read',
    })
    dst_creds = resolve_creds('DST', {
        'host': 'mysql.govdata.ca',
        'db':   'govdata_cache_01',
        'user': 'gdc_01',
    })

    entities = ENTITIES
    if args.slug:
        entities = [e for e in entities if e['slug'] == args.slug]
        if not entities:
            print(f"ERROR: no registered entity with slug '{args.slug}'", file=sys.stderr)
            sys.exit(1)

    print(f"Source  : {src_creds['user']}@{src_creds['host']}/{src_creds['database']}")
    print(f"Cache   : {dst_creds['user']}@{dst_creds['host']}/{dst_creds['database']}")
    print(f"Entities: {len(entities)}")
    if args.dry_run:
        print("Mode    : DRY RUN — no writes")
    print()

    try:
        print("Connecting to source DB…", end=' ', flush=True)
        src_conn = mysql.connector.connect(**src_creds, connection_timeout=30, charset='utf8mb4')
        print("OK")
        print("Connecting to cache DB…", end=' ', flush=True)
        dst_conn = mysql.connector.connect(**dst_creds, connection_timeout=30, charset='utf8mb4')
        print("OK\n")
    except MySQLError as e:
        print(f"\nERROR: could not connect: {e}", file=sys.stderr)
        sys.exit(1)

    src_cur = src_conn.cursor(dictionary=True)
    dst_cur = dst_conn.cursor()

    ok_count = fail_count = 0

    for entity in entities:
        print(f"  → {entity['name']}")

        sql, params = source_query(entity)
        if not sql:
            print(f"    SKIP: no legal_names defined")
            fail_count += 1
            continue

        try:
            src_cur.execute(sql, params)
            rows = src_cur.fetchall()
        except MySQLError as e:
            print(f"    ERROR reading source: {e}", file=sys.stderr)
            fail_count += 1
            continue

        print(f"    source rows : {len(rows):,}")

        if not rows:
            print(f"    SKIP: no matching rows in master_table")
            fail_count += 1
            continue

        snapshot, table_rows = build_snapshot(rows, entity)
        totals = snapshot['totals']
        snap_json = json.dumps(snapshot, ensure_ascii=False)
        print(f"    net total   : ${totals['total_funding']:,.2f}")
        print(f"    agreements  : {len(table_rows)} (entity_agreements table)")
        print(f"    snapshot    : {len(snap_json):,} bytes (compact — no agreements array)")

        try:
            write_entity(dst_cur, entity, snapshot, table_rows, args.dry_run)
            if not args.dry_run:
                dst_conn.commit()
            ok_count += 1
            print(f"    ✓ done")
        except MySQLError as e:
            if not args.dry_run:
                dst_conn.rollback()
            print(f"    ✗ write failed: {e}", file=sys.stderr)
            fail_count += 1

        print()

    src_conn.close()
    dst_conn.close()

    status = 'success' if not fail_count else ('partial' if ok_count else 'failed')
    print(f"Done [{status}]. Synced: {ok_count}  Failed: {fail_count}")
    sys.exit(0 if not fail_count else 1)


if __name__ == '__main__':
    main()
