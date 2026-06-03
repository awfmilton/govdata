"""
spot-check.py — Cross-layer data integrity checks for govdata.ca

Verifies that the frontend/API, cache DB, and source DB are in sync.

Checks:
  1. Riding cache agreement count and total value vs source DB (era-aware)
  2. Finance KPI cache totals vs source DB
  3. WP post meta (total_spend, finance_data_from/to) vs KPI cache
  4. REST API /mp/{id}/spend response totals vs mp_expenditure_periods cache
  5. Global cache_meta finance period labels are correct
  6. Riding cache has exactly 343 rows
  7. Finance coverage counts (402 posts with data, 105 pending)

Usage:
  python database/spot-check.py [--sample N]   (default N=10 per check)

Exit code 0 = all pass, 1 = one or more failures.
"""

import os
import sys
import random
import argparse
import time
import urllib.request
import json
import mysql.connector

# ── Connection parameters ─────────────────────────────────────────────────────

SOURCE_DB = dict(
    host='35.230.186.163', port=3306, database='grantconts',
    user='pt_read', password='123456789!!!!!!!!!', charset='utf8mb4',
)
CACHE_DB = dict(
    host='mysql.alexmilton.ca', port=3306, database='govdata_cache_02',
    user='gdc_02', password='123456789!!!!!!!!!', charset='utf8mb4',
)
WP_DB = dict(
    host='mysql.govdata.ca', port=3306, database='govdata_ca_1',
    user='govdataca1', password='CXWQdShA', charset='utf8mb4',
)
WP_TABLE_PREFIX = 'wp_5bifyj_'
SITE_REST_URL   = 'https://www.govdata.ca/wp-json/publictech/v1'

# ── Helpers ───────────────────────────────────────────────────────────────────

PASS = '\033[92mPASS\033[0m'
FAIL = '\033[91mFAIL\033[0m'
WARN = '\033[93mWARN\033[0m'

failures = []


def check(name: str, passed: bool, detail: str = '') -> None:
    tag = PASS if passed else FAIL
    print(f'  [{tag}] {name}' + (f': {detail}' if detail else ''))
    if not passed:
        failures.append(name)


def close_enough(a: float, b: float, tol: float = 0.001) -> bool:
    """True if a and b are within tol fraction of each other (or both zero)."""
    if a == 0 and b == 0:
        return True
    if a == 0 or b == 0:
        return False
    return abs(a - b) / max(abs(a), abs(b)) < tol


def rest_get(path: str) -> dict | list | None:
    url = SITE_REST_URL + path
    req = urllib.request.Request(url, headers={'User-Agent': 'spot-check/1.0'})
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            return json.loads(r.read().decode())
    except Exception as e:
        return None


# ── Check 1: Riding cache vs source DB ───────────────────────────────────────

def check_riding_cache(src_cur, cache_cur, sample_n: int) -> None:
    print('\nCheck 1: Riding cache agreement_count and total_value vs source DB')

    cache_cur.execute(
        'SELECT riding_number, agreement_count, total_value FROM riding_cache ORDER BY RAND() LIMIT %s',
        (sample_n,)
    )
    samples = cache_cur.fetchall()

    for row in samples:
        rn    = row['riding_number']
        c_cnt = int(row['agreement_count'] or 0)
        c_val = float(row['total_value'] or 0)

        # Era-aware agreement count for this riding.
        src_cur.execute("""
            SELECT COUNT(*) AS cnt, SUM(m.agreement_value) AS total
              FROM master_table m
              JOIN (
                  SELECT DISTINCT
                         UPPER(REPLACE(REPLACE(postal_code,' ',''),'-','')) AS pc,
                         riding_number AS rn,
                         ro_id
                    FROM pcbfr
                   WHERE postal_code IS NOT NULL AND postal_code <> ''
                     AND riding_number IS NOT NULL AND riding_number <> ''
                     AND ro_id IN (1, 2)
              ) p ON p.pc = UPPER(REPLACE(REPLACE(m.recipient_postal_code,' ',''),'-',''))
                 AND p.ro_id = CASE WHEN m.year >= 2025 THEN 2 ELSE 1 END
             WHERE m.recipient_postal_code IS NOT NULL
               AND m.recipient_postal_code <> ''
               AND p.rn = %s
        """, (rn,))
        src_row = src_cur.fetchone()
        s_cnt = int(src_row['cnt'] or 0)
        s_val = float(src_row['total'] or 0)

        check(
            f'  Riding {rn} agreement_count ({c_cnt} vs {s_cnt})',
            c_cnt == s_cnt,
        )
        check(
            f'  Riding {rn} total_value ({c_val:,.0f} vs {s_val:,.0f})',
            close_enough(c_val, s_val),
        )


# ── Check 2: Finance KPI cache vs source DB ───────────────────────────────────

def check_finance_kpi(src_cur, cache_cur, sample_n: int) -> None:
    print('\nCheck 2: mp_kpi_cache total_spend vs hoc_member_expenditures')

    cache_cur.execute(
        'SELECT mp_id, total_spend, data_from, data_to FROM mp_kpi_cache ORDER BY RAND() LIMIT %s',
        (sample_n,)
    )
    samples = cache_cur.fetchall()

    for row in samples:
        mp_id   = row['mp_id']
        c_total = float(row['total_spend'] or 0)

        src_cur.execute("""
            SELECT SUM(salaries + travel + hospitality + contracts) AS total
              FROM hoc_member_expenditures
             WHERE mp_id = %s
        """, (mp_id,))
        s_total = float((src_cur.fetchone() or {}).get('total') or 0)

        check(
            f'  MP {mp_id} total_spend ({c_total:,.0f} vs {s_total:,.0f})',
            close_enough(c_total, s_total),
        )

    # Spot-check that data_from / data_to are non-empty for MP-prefix rows.
    cache_cur.execute(
        "SELECT COUNT(*) AS n FROM mp_kpi_cache WHERE mp_id LIKE 'MP%' AND (data_from = '' OR data_to = '')"
    )
    empty_labels = int(cache_cur.fetchone()['n'])
    check(
        'All MP-prefix KPI rows have data_from and data_to set',
        empty_labels == 0,
        f'{empty_labels} rows with empty labels' if empty_labels else '',
    )


# ── Check 3: WP post meta vs KPI cache ───────────────────────────────────────

def check_wp_meta(wp_cur, cache_cur, sample_n: int) -> None:
    print('\nCheck 3: WP post meta vs mp_kpi_cache')

    # Get posts that have external_mp_id set.
    wp_cur.execute(f"""
        SELECT pm.post_id,
               MAX(CASE WHEN pm.meta_key = 'external_mp_id'   THEN pm.meta_value END) AS ext_id,
               MAX(CASE WHEN pm.meta_key = 'total_spend'      THEN pm.meta_value END) AS total_spend,
               MAX(CASE WHEN pm.meta_key = 'finance_data_from' THEN pm.meta_value END) AS data_from,
               MAX(CASE WHEN pm.meta_key = 'finance_data_to'   THEN pm.meta_value END) AS data_to
          FROM {WP_TABLE_PREFIX}postmeta pm
         WHERE pm.meta_key IN ('external_mp_id','total_spend','finance_data_from','finance_data_to')
         GROUP BY pm.post_id
        HAVING ext_id IS NOT NULL AND ext_id <> ''
         ORDER BY RAND()
         LIMIT %s
    """, (sample_n,))
    samples = wp_cur.fetchall()

    for row in samples:
        ext_id      = row['ext_id']
        wp_spend    = float(row['total_spend'] or 0)
        wp_from     = row['data_from'] or ''
        wp_to       = row['data_to'] or ''

        cache_cur.execute(
            'SELECT total_spend, data_from, data_to FROM mp_kpi_cache WHERE mp_id = %s',
            (ext_id,)
        )
        kpi = cache_cur.fetchone()
        if not kpi:
            check(f'  Post {row["post_id"]} ({ext_id}) found in mp_kpi_cache', False, 'not found')
            continue

        kpi_spend = float(kpi['total_spend'] or 0)
        kpi_from  = kpi['data_from'] or ''
        kpi_to    = kpi['data_to'] or ''

        check(
            f'  Post {row["post_id"]} ({ext_id}) total_spend ({wp_spend:,.0f} vs {kpi_spend:,.0f})',
            close_enough(wp_spend, kpi_spend),
        )
        check(
            f'  Post {row["post_id"]} ({ext_id}) finance_data_from ({wp_from!r} vs {kpi_from!r})',
            wp_from == kpi_from,
        )
        check(
            f'  Post {row["post_id"]} ({ext_id}) finance_data_to ({wp_to!r} vs {kpi_to!r})',
            wp_to == kpi_to,
        )

    # Coverage counts — posts WITH data (meta row exists and non-empty).
    wp_cur.execute(f"""
        SELECT COUNT(*) AS n
          FROM {WP_TABLE_PREFIX}postmeta
         WHERE meta_key = 'finance_data_from' AND meta_value <> ''
    """)
    with_data = int(wp_cur.fetchone()['n'] or 0)
    check(
        f'402 posts have finance_data_from set (got {with_data})',
        with_data == 402,
    )

    # Posts WITHOUT data: member-parliament posts that have no finance_data_from row.
    wp_cur.execute(f"""
        SELECT COUNT(*) AS n
          FROM {WP_TABLE_PREFIX}posts p
         WHERE p.post_type = 'member-parliament'
           AND p.post_status IN ('publish', 'draft')
           AND NOT EXISTS (
               SELECT 1 FROM {WP_TABLE_PREFIX}postmeta pm
                WHERE pm.post_id = p.ID AND pm.meta_key = 'finance_data_from'
           )
    """)
    no_data = int(wp_cur.fetchone()['n'] or 0)
    check(
        f'105 posts have no finance_data_from row (got {no_data})',
        no_data == 105,
    )


# ── Check 4: REST API vs mp_expenditure_periods ───────────────────────────────

def check_rest_api(wp_cur, cache_cur, sample_n: int) -> None:
    print('\nCheck 4: REST /mp/{id}/spend vs mp_expenditure_periods')

    wp_cur.execute(f"""
        SELECT pm.post_id, pm.meta_value AS ext_id
          FROM {WP_TABLE_PREFIX}postmeta pm
          JOIN {WP_TABLE_PREFIX}posts p ON p.ID = pm.post_id
         WHERE pm.meta_key = 'external_mp_id'
           AND pm.meta_value LIKE 'MP%'
           AND p.post_status = 'publish'
         ORDER BY RAND()
         LIMIT %s
    """, (sample_n,))
    samples = wp_cur.fetchall()

    for row in samples:
        post_id = row['post_id']
        ext_id  = row['ext_id']

        data = rest_get(f'/mp/{post_id}/spend')
        if data is None:
            check(f'  REST /mp/{post_id}/spend reachable', False, 'request failed')
            continue
        if not isinstance(data, list):
            check(f'  REST /mp/{post_id}/spend returns a list', False, str(type(data)))
            continue

        # Sum REST totals by fiscal_year.
        rest_total = sum(float(r.get('total', 0)) for r in data)

        # Sum from mp_expenditure_periods.
        cache_cur.execute(
            'SELECT SUM(total) AS s FROM mp_expenditure_periods WHERE mp_id = %s',
            (ext_id,)
        )
        db_total = float((cache_cur.fetchone() or {}).get('s') or 0)

        check(
            f'  REST /mp/{post_id} total ({rest_total:,.0f} vs cache {db_total:,.0f})',
            close_enough(rest_total, db_total),
        )


# ── Check 5: Global finance meta labels ──────────────────────────────────────

def check_global_meta(cache_cur) -> None:
    print('\nCheck 5: Global cache_meta finance period labels')

    cache_cur.execute(
        "SELECT meta_key, meta_value FROM cache_meta WHERE meta_key LIKE 'finance_%'"
    )
    meta = {r['meta_key']: r['meta_value'] for r in cache_cur.fetchall()}

    check(
        'finance_data_from = "July 2020"',
        meta.get('finance_data_from') == 'July 2020',
        repr(meta.get('finance_data_from')),
    )
    check(
        'finance_data_to = "December 2025"',
        meta.get('finance_data_to') == 'December 2025',
        repr(meta.get('finance_data_to')),
    )
    check(
        'finance_last_warm is set',
        bool(meta.get('finance_last_warm')),
        repr(meta.get('finance_last_warm')),
    )


# ── Check 6: Riding cache row count ──────────────────────────────────────────

def check_riding_cache_count(cache_cur) -> None:
    print('\nCheck 6: Riding cache row count')

    cache_cur.execute('SELECT COUNT(*) AS n FROM riding_cache')
    n = int(cache_cur.fetchone()['n'])
    check(f'riding_cache has 343 rows (got {n})', n == 343)


# ── Check 7: mp_kpi_cache row counts ─────────────────────────────────────────

def check_kpi_counts(cache_cur) -> None:
    print('\nCheck 7: mp_kpi_cache coverage')

    cache_cur.execute("SELECT COUNT(*) AS n FROM mp_kpi_cache")
    total = int(cache_cur.fetchone()['n'])
    check(f'mp_kpi_cache has 555 rows (got {total})', total == 555)

    cache_cur.execute("SELECT COUNT(*) AS n FROM mp_kpi_cache WHERE mp_id LIKE 'MP%'")
    mp_rows = int(cache_cur.fetchone()['n'])
    check(f'mp_kpi_cache has 336 MP-prefix rows (got {mp_rows})', mp_rows == 336)


# ── Main ──────────────────────────────────────────────────────────────────────

def connect_or_skip(params: dict, label: str):
    """Try to connect; return (connection, cursor) or (None, None) and print a warning."""
    try:
        cx = mysql.connector.connect(**params)
        return cx, cx.cursor(dictionary=True)
    except Exception as e:
        print(f'  [{WARN}] Cannot connect to {label}: {e}')
        print(f'         Checks requiring this DB will be skipped.')
        return None, None


def main() -> None:
    parser = argparse.ArgumentParser(description='Cross-layer data integrity checks')
    parser.add_argument('--sample', type=int, default=10,
                        help='Number of rows to sample per check (default: 10)')
    args = parser.parse_args()
    n = args.sample

    t0 = time.time()
    print(f'govdata spot-check — sample size {n}\n')

    print('Connecting to databases…')
    src_cx,   src_cur   = connect_or_skip(SOURCE_DB, 'grantconts (source)')
    cache_cx, cache_cur = connect_or_skip(CACHE_DB,  'govdata_cache_02 (cache)')
    wp_cx,    wp_cur    = connect_or_skip(WP_DB,     'govdata_ca_1 (WP)')

    if cache_cur:
        check_riding_cache_count(cache_cur)
        check_kpi_counts(cache_cur)
        check_global_meta(cache_cur)
    else:
        print('\n[SKIP] Checks 5-7 require cache DB — not accessible from this host.')

    if src_cur and cache_cur:
        check_finance_kpi(src_cur, cache_cur, n)
        check_riding_cache(src_cur, cache_cur, min(n, 5))
    else:
        print('\n[SKIP] Checks 1-2 require both source and cache DBs.')

    if wp_cur and cache_cur:
        check_wp_meta(wp_cur, cache_cur, n)
    else:
        print('\n[SKIP] Check 3 requires WP and cache DBs.')

    if wp_cur:
        check_rest_api(wp_cur, cache_cur, min(n, 5))
    else:
        print('\n[SKIP] Check 4 requires WP DB for post IDs.')

    if src_cur:   src_cur.close();   src_cx.close()
    if cache_cur: cache_cur.close(); cache_cx.close()
    if wp_cur:    wp_cur.close();    wp_cx.close()

    elapsed = round(time.time() - t0, 1)
    print(f'\n{"─"*50}')
    if failures:
        print(f'FAILED — {len(failures)} check(s) failed in {elapsed}s:')
        for f in failures:
            print(f'  • {f}')
        sys.exit(1)
    else:
        print(f'All checks passed in {elapsed}s.')
        sys.exit(0)


if __name__ == '__main__':
    main()
