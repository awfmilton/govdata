#!/usr/bin/env python3
"""govdata.ca immigration loader.

  run.py init                         create reviewed country aliases, register sources, then relink
  run.py relink                       re-resolve rows whose country spelling had no match (after editing aliases)
  run.py refresh [--source KEY ...]   load every source whose publication changed (default: all enabled)
                 [--force]            download and compare even when the publisher signal is unchanged
  run.py status                       latest edition and coverage per source
  run.py parl [--all]                 House divisions + ballots, LEGISinfo bills, Justice Laws amendment history,
                                      immigration-bill tags. Default: the newest sessions; --all: every session.
  run.py countries                    country pages: ISO codes, OECD aid by recipient, parliamentarians' birthplaces,
                                      laws and bills naming a country; then rebuild the page payloads (report)
  run.py report                       rebuild only the page payloads (cty_report) from what is loaded

Exit status is 1 if any source failed, so a systemd timer surfaces it. Every check is written to imm_refresh_log.
"""

import argparse
import os
import secrets
import sys
import time
import traceback
from datetime import datetime, timezone

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import pymysql  # noqa: E402

from govdata_imm import fetch  # noqa: E402
from govdata_imm.config import load_config, load_sources  # noqa: E402
from govdata_imm.country import CountryResolver  # noqa: E402
from govdata_imm.load import apply_edition  # noqa: E402
from govdata_imm.parsers import PARSERS  # noqa: E402
from govdata_imm import parl  # noqa: E402
from govdata_imm import aid_data, countries, report  # noqa: E402


def connect(cfg):
    kw = dict(user=cfg.db_user, password=cfg.db_pass, database=cfg.db_name, charset='utf8mb4', autocommit=False)
    sock = os.environ.get('IMM_DB_SOCKET', '/var/run/mysqld/mysqld.sock')
    if os.path.exists(sock) and cfg.db_host in ('127.0.0.1', 'localhost'):
        kw['unix_socket'] = sock
    else:
        kw.update(host=cfg.db_host, port=cfg.db_port, ssl={'ca': os.environ['IMM_DB_SSL_CA']} if os.environ.get('IMM_DB_SSL_CA') else {})
    return pymysql.connect(**kw)


def now_utc():
    return datetime.now(timezone.utc).replace(tzinfo=None)


def run_id():
    return time.strftime('%Y%m%dT%H%M%S') + secrets.token_hex(5)


def log(cur, rid, key, outcome, edition_id=None, detail=None):
    cur.execute('INSERT INTO imm_refresh_log (run_id, source_key, checked_at, outcome, edition_id, detail) '
                'VALUES (%s, %s, %s, %s, %s, %s)', (rid, key, now_utc(), outcome, edition_id, (detail or '')[:2000]))


def register_sources(cur, sources):
    for s in sources:
        cur.execute(
            'INSERT INTO imm_sources (source_key, publisher, title, download_url, landing_url, detect_method, detect_ref, '
            'grain, coverage_note, rounding_note) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE '
            'publisher=VALUES(publisher), title=VALUES(title), download_url=VALUES(download_url), landing_url=VALUES(landing_url), '
            'detect_method=VALUES(detect_method), detect_ref=VALUES(detect_ref), grain=VALUES(grain), '
            'coverage_note=VALUES(coverage_note), rounding_note=VALUES(rounding_note)',
            (s['key'], s['publisher'], s['title'], s['url'], s.get('landing'), s['detect'], s.get('detect_ref'), s['grain'],
             s.get('coverage_note'),
             'Rounded to the nearest 5; 1-4 suppressed as "--" (IRCC open data)' if s.get('rounded', s['parser'] == 'ircc_odp') else None))


def last_edition(cur, key):
    cur.execute("SELECT edition_id, file_sha256, publisher_modified, status FROM imm_editions WHERE source_key = %s "
                "ORDER BY edition_id DESC LIMIT 1", (key,))
    return cur.fetchone()


def refresh_one(conn, cfg, src, rid, force):
    key = src['key']
    with conn.cursor() as cur:
        last = last_edition(cur, key)
    signal = fetch.publisher_signal(src, cfg.user_agent)
    if last and last[3] == 'loaded' and not force and signal and last[2] == signal:
        with conn.cursor() as cur:
            log(cur, rid, key, 'unchanged', last[0], f'publisher signal unchanged ({signal})')
        conn.commit()
        return 'unchanged', None
    dl = fetch.download(src, cfg.user_agent, signal)
    with conn.cursor() as cur:
        cur.execute("SELECT edition_id, status FROM imm_editions WHERE source_key = %s AND file_sha256 = %s", (key, dl.sha256))
        same = cur.fetchone()
    if same and same[1] == 'loaded' and not force:
        with conn.cursor() as cur:
            if signal and signal != (last[2] if last else None):
                cur.execute('UPDATE imm_editions SET publisher_modified = %s WHERE edition_id = %s', (signal, same[0]))
            log(cur, rid, key, 'unchanged', same[0], f'identical bytes (sha256 {dl.sha256[:12]}); signal {signal}')
        conn.commit()
        return 'unchanged', None

    raw_path = fetch.archive(cfg.raw_dir, src, dl)
    with conn.cursor() as cur:
        if same:                                     # an earlier attempt at these exact bytes failed: retry it
            edition_id = same[0]
            cur.execute("UPDATE imm_editions SET status='loading', retrieved_at=%s, raw_path=%s, notes=NULL WHERE edition_id=%s",
                        (dl.retrieved_at.replace(tzinfo=None), str(raw_path), edition_id))
        else:
            cur.execute('INSERT INTO imm_editions (source_key, retrieved_at, file_sha256, file_bytes, http_etag, '
                        'http_last_modified, publisher_modified, raw_path, status) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s)',
                        (key, dl.retrieved_at.replace(tzinfo=None), dl.sha256, len(dl.data), dl.etag, dl.last_modified,
                         signal, str(raw_path), 'loading'))
            edition_id = cur.lastrowid
    conn.commit()

    try:
        parsed = PARSERS[src['parser']](dl.data, src)
        rows, control = parsed if isinstance(parsed, tuple) else (parsed, None)
        with conn.cursor() as cur:
            resolver = CountryResolver(cur)
            stats = apply_edition(cur, src, edition_id, rows, resolver)
            notes = []
            if control:
                for y, total in sorted(control.items()):
                    s = sum(r['value'] or 0 for r in rows if r['ref_period'].year == y)
                    if total is not None and s != total:
                        notes.append(f'{y}: countries sum {s} vs file total {total}')
            if resolver.unmatched:
                notes.append('unmatched country spellings: ' + '; '.join(f'{k} ({v})' for k, v in sorted(resolver.unmatched.items())))
            cur.execute("UPDATE imm_editions SET status='loaded', row_count=%s, rows_inserted=%s, rows_revised=%s, "
                        "rows_removed=%s, loaded_at=%s, notes=%s WHERE edition_id=%s",
                        (stats['rows'], stats['inserted'], stats['revised'], stats['removed'], now_utc(),
                         ('; '.join(notes))[:1000] or None, edition_id))
            log(cur, rid, key, 'loaded', edition_id, f"{stats}" + (f"; {'; '.join(notes)}" if notes else ''))
        conn.commit()
        return 'loaded', stats | {'notes': notes}
    except Exception as e:  # noqa: BLE001 -- recorded and re-raised as a failed outcome
        conn.rollback()
        with conn.cursor() as cur:
            cur.execute("UPDATE imm_editions SET status='failed', notes=%s WHERE edition_id=%s", (repr(e)[:1000], edition_id))
            log(cur, rid, key, 'failed', edition_id, traceback.format_exc()[-1800:])
        conn.commit()
        return 'failed', repr(e)


def relink(cur, resolver, sources) -> int:
    """Resolve rows loaded while their spelling had no match. Values are untouched, so this is not a revision."""
    n = 0
    for src in sources:
        may_create = {'ircc_odp': 'all', 'statcan_csv': 'groups', 'mp22_csv': 'groups'}.get(src['parser'], 'none')
        cur.execute('SELECT DISTINCT country_raw FROM imm_fact WHERE source_key = %s AND country_raw IS NOT NULL '
                    'AND country_id IS NULL', (src['key'],))
        for (raw,) in cur.fetchall():
            cid = resolver.resolve(raw, src['key'], may_create)
            if cid:
                n += cur.execute('UPDATE imm_fact SET country_id = %s WHERE source_key = %s AND country_raw = %s '
                                 'AND country_id IS NULL', (cid, src['key'], raw))
    return n


def run_parl(conn, cfg, everything: bool) -> int:
    with conn.cursor() as cur:
        all_sessions = parl.sessions(cur)
    recent = all_sessions if everything else all_sessions[-4:]       # current + the probes for the next session
    return run_steps(conn, [
        ('parl_bills', lambda: parl.load_bills(conn.cursor(), cfg.user_agent, recent)),
        ('law_amendments', lambda: parl.load_laws(conn.cursor(), cfg.user_agent)),
        ('parl_divisions', lambda: parl.load_divisions(conn, cfg.user_agent, recent, refetch_last=0 if everything else 5)),
        ('imm_bill_tags', lambda: parl.tag_bills(conn.cursor())),
        ('imm_ee_draws', lambda: parl.load_ee_draws(conn.cursor(), cfg.user_agent)),
    ])


def run_countries(conn, cfg, only_report: bool) -> int:
    ua = cfg.user_agent
    steps = [] if only_report else [
        ('imm_country_iso', lambda: countries.apply_iso(conn.cursor())),
        ('cty_oda', lambda: countries.load_oda(conn.cursor(), ua)),
        ('parl_person_birth', lambda: countries.load_births(conn.cursor(), ua)),
        ('parl_person_parlinfo', lambda: countries.load_parlinfo(conn, ua, import_path=cfg.raw_dir / 'parlinfo-import.jsonl')),
        ('parlinfo_merge', lambda: countries.merge_parlinfo(conn.cursor())),
        ('cty_law_tags', lambda: countries.tag_country_laws(conn.cursor(), ua)),
        ('cty_dac1', lambda: countries.load_dac1(conn.cursor(), ua)),
        ('aid_law_tags', lambda: countries.tag_aid_laws(conn.cursor(), ua)),
        ('aid_sria', lambda: aid_data.load_sria(conn, ua, cfg.raw_dir)),
        ('aid_hpds', lambda: aid_data.load_hpds(conn, ua, cfg.raw_dir)),
    ]
    return run_steps(conn, steps + [('cty_report', lambda: report.build_all(conn.cursor()))])


def run_steps(conn, steps) -> int:
    """Each step is logged on its own; one failing (a publisher down) does not stop the others."""
    rid, failed = run_id(), 0
    for key, fn in steps:
        t0 = time.time()
        try:
            info = fn()
            conn.commit()
            outcome = 'loaded'
        except Exception:  # noqa: BLE001 -- logged; the next step still runs
            conn.rollback()
            info, outcome = traceback.format_exc()[-1500:], 'failed'
            failed += 1
        with conn.cursor() as cur:
            log(cur, rid, key, outcome, None, str(info))
        conn.commit()
        print(f'{key}: {outcome} {info} ({time.time() - t0:.1f}s)', flush=True)
    return 1 if failed else 0


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('command', choices=['init', 'relink', 'refresh', 'status', 'parl', 'countries', 'report'])
    ap.add_argument('--all', action='store_true')
    ap.add_argument('--source', action='append')
    ap.add_argument('--force', action='store_true')
    a = ap.parse_args()
    cfg = load_config()
    sources = load_sources()
    if a.source:
        unknown = set(a.source) - {s['key'] for s in sources}
        if unknown:
            raise SystemExit(f'unknown source(s): {", ".join(sorted(unknown))}')
        sources = [s for s in sources if s['key'] in a.source]
    conn = connect(cfg)

    if a.command in ('init', 'relink'):
        with conn.cursor() as cur:
            resolver = CountryResolver(cur)
            if a.command == 'init':
                register_sources(cur, load_sources())
                resolver.apply_reviewed_aliases()
            linked = relink(cur, resolver, sources)
        conn.commit()
        print(('sources registered; reviewed country aliases applied; ' if a.command == 'init' else '') +
              f'relinked {linked} rows; still unmatched: {resolver.unmatched or "none"}')
        return 0

    if a.command == 'status':
        with conn.cursor() as cur:
            cur.execute('SELECT s.source_key, e.edition_id, e.status, e.retrieved_at, e.row_count, e.publisher_modified, '
                        '(SELECT MIN(ref_period) FROM imm_fact f WHERE f.source_key = s.source_key), '
                        '(SELECT MAX(ref_period) FROM imm_fact f WHERE f.source_key = s.source_key) '
                        'FROM imm_sources s LEFT JOIN imm_editions e ON e.edition_id = '
                        '(SELECT MAX(edition_id) FROM imm_editions x WHERE x.source_key = s.source_key) ORDER BY s.source_key')
            for r in cur.fetchall():
                print(' | '.join('' if v is None else str(v) for v in r))
        return 0

    if a.command == 'parl':
        return run_parl(conn, cfg, a.all)
    if a.command in ('countries', 'report'):
        return run_countries(conn, cfg, a.command == 'report')

    rid = run_id()
    with conn.cursor() as cur:
        register_sources(cur, sources)
    conn.commit()
    failed = 0
    for src in sources:
        t0 = time.time()
        try:
            outcome, info = refresh_one(conn, cfg, src, rid, a.force)
        except Exception as e:  # noqa: BLE001 -- network/publisher errors before an edition exists
            conn.rollback()
            with conn.cursor() as cur:
                log(cur, rid, src['key'], 'failed', None, traceback.format_exc()[-1800:])
            conn.commit()
            outcome, info = 'failed', repr(e)
        failed += outcome == 'failed'
        print(f"{src['key']}: {outcome} {info if info else ''} ({time.time() - t0:.1f}s)", flush=True)
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main())
