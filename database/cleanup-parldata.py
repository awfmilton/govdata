#!/usr/bin/env python3
"""
Drop op_ tables that contain only openparliament.ca website internals
(Django framework, user accounts, alerts, error tracking, etc.) and have
no parliamentary data value.

Safe to run: uses DROP TABLE IF EXISTS, lists what it will do before acting,
and requires --confirm to actually execute.

Usage:
    # Preview what will be dropped:
    python3 database/cleanup-parldata.py

    # Also drop op_hansards_oldsequencemapping (560K URL redirect rows):
    python3 database/cleanup-parldata.py --drop-old-slugs

    # Execute the drops:
    python3 database/cleanup-parldata.py --confirm
    python3 database/cleanup-parldata.py --confirm --drop-old-slugs

Dependencies:
    pip install mysql-connector-python
"""

import argparse
import os
import sys

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

try:
    import mysql.connector
    from mysql.connector import Error as MySQLError
except ImportError:
    sys.exit("ERROR: pip install mysql-connector-python")

DEFAULT_HOST = '35.230.186.163'
DEFAULT_DB   = 'grantconts'
DEFAULT_USER = 'pt_write'
DEFAULT_PORT = 3306

# ── Tables to DROP — openparliament.ca website internals only ─────────────────
# None of these contain parliamentary data. All were empty (0 rows) except
# op_django_migrations (61 rows) and op_django_content_type (59 rows), which
# are Django's own framework tracking tables.

DROP_TABLES = [
    # Django framework internals
    'op_django_migrations',
    'op_django_content_type',
    'op_django_admin_log',
    'op_django_session',
    'op_django_flatpage',
    'op_django_flatpage_sites',
    'op_django_site',
    # Website user accounts & auth
    'op_accounts_user',
    'op_accounts_logintoken',
    'op_auth_user',
    'op_auth_group',
    'op_auth_group_permissions',
    'op_auth_permission',
    'op_auth_message',
    'op_auth_user_groups',
    'op_auth_user_user_permissions',
    # User alert subscriptions
    'op_alerts_seenitem',
    'op_alerts_subscription',
    'op_alerts_topic',
    # User contact threads
    'op_contact_contactmessage',
    'op_contact_contactthread',
    # Financial donations (empty in this dump)
    'op_financials_contribution',
    'op_financials_contributor',
    # App cache / misc
    'op_core_ridingpostcodecache',
    'op_core_sitenews',
    'op_activity_activity',
    'op_indexer_index',
    'op_labs_haiku',
    'op_search_indexingtask',
    'op_bills_bill_similar_bills',
    'op_hansards_oldslugmapping',
    # Error tracking (Sentry)
    'op_sentry_filtervalue',
    'op_sentry_groupedmessage',
    'op_sentry_message',
    'op_south_migrationhistory',
    # Unused features
    'op_summaries_summary',
    'op_summaries_summarypoll',
    'op_text_analysis_textanalysis',
]

# Borderline — only dropped if --drop-old-slugs is passed
OLD_SLUG_TABLE = 'op_hansards_oldsequencemapping'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--confirm',        action='store_true', help='Actually execute the DROPs')
    ap.add_argument('--drop-old-slugs', action='store_true',
                    help='Also drop op_hansards_oldsequencemapping (560K URL redirect rows)')
    ap.add_argument('--host',  default=os.environ.get('WRITE_HOST', DEFAULT_HOST))
    ap.add_argument('--db',    default=os.environ.get('WRITE_DB',   DEFAULT_DB))
    ap.add_argument('--user',  default=os.environ.get('WRITE_USER', DEFAULT_USER))
    ap.add_argument('--port',  type=int, default=DEFAULT_PORT)
    args = ap.parse_args()

    password = os.environ.get('WRITE_PASS')
    if not password:
        import getpass
        password = getpass.getpass('MySQL password: ')

    tables_to_drop = list(DROP_TABLES)
    if args.drop_old_slugs:
        tables_to_drop.append(OLD_SLUG_TABLE)

    conn = mysql.connector.connect(
        host=args.host, port=args.port,
        database=args.db, user=args.user, password=password,
        charset='utf8mb4', ssl_disabled=True,
    )
    cur = conn.cursor()

    # Check which tables actually exist and their row counts
    cur.execute("SHOW TABLES LIKE 'op_%'")
    existing = {t[0] for t in cur.fetchall()}

    print(f"\n{'='*60}")
    print(f"Tables to DROP ({len(tables_to_drop)} total):")
    print(f"{'='*60}")
    total_rows = 0
    found = []
    for t in tables_to_drop:
        if t in existing:
            cur.execute(f"SELECT COUNT(*) FROM `{t}`")
            n = cur.fetchone()[0]
            total_rows += n
            found.append(t)
            print(f"  {t:<50} {n:>8,} rows")
        else:
            print(f"  {t:<50}  (not found — already gone)")

    if not args.drop_old_slugs:
        cur.execute(f"SELECT COUNT(*) FROM `{OLD_SLUG_TABLE}`") if OLD_SLUG_TABLE in existing else None
        n = cur.fetchone()[0] if OLD_SLUG_TABLE in existing else 0
        print(f"\n  NOT dropping (pass --drop-old-slugs to include):")
        print(f"  {OLD_SLUG_TABLE:<50} {n:>8,} rows  [URL redirects only]")

    print(f"\n  Total rows that will be deleted: {total_rows:,}")
    print(f"  Tables that will be dropped:     {len(found)}")

    if not args.confirm:
        print(f"\n[DRY-RUN] No changes made. Re-run with --confirm to execute.")
        conn.close()
        return

    print(f"\nDropping {len(found)} tables ...")
    dropped = 0
    for t in found:
        try:
            cur.execute(f"DROP TABLE IF EXISTS `{t}`")
            conn.commit()
            print(f"  Dropped {t}")
            dropped += 1
        except MySQLError as e:
            print(f"  [WARN] Could not drop {t}: {e}")

    print(f"\nDone. {dropped} tables dropped.")
    conn.close()


if __name__ == '__main__':
    main()
