#!/usr/bin/env python3
"""
Rebuild the normalised relational tables from the (new, clean) master_table.

True normalisation with deduplication — replaces the old 1:1-copy bloat:
  recipients : dedup by business_number (when present) else legal_name
  programs   : dedup by prog_name_en
  locations  : dedup by (country, province, city, postal, riding)
  naics      : dedup by naics_identifier
  agreements : one row per current master row (amendment_number IS NULL),
               FK-resolved to the dedup'd lookups via surrogate-key maps
  amendments : one row per amendment master row, linked to its agreement
  agreement_urls : one canonical gov URL per master id

Re-runnable: deterministic from master_table. Safe to re-run.

Steps (FK build order):
  python3 database/normalize.py --step lookups   # recipients, programs, locations, naics
  python3 database/normalize.py --step agreements
  python3 database/normalize.py --step amendments
  python3 database/normalize.py --step urls
  python3 database/normalize.py --step verify
  python3 database/normalize.py --step all
"""

import argparse
import sys
from urllib.parse import quote
import mysql.connector

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

DB = dict(host='35.230.186.163', port=3306, database='grantconts',
          user='pt_read', password='123456789!!!!!!!!!',
          connection_timeout=900, autocommit=True)

# Dedup key expressions (consistent on both master_table and the lookup map build)
DKEY_RECIP = "COALESCE(NULLIF(TRIM(recipient_business_number),''), recipient_legal_name)"
DKEY_PROG  = "prog_name_en"
DKEY_LOC   = ("CONCAT_WS('|',COALESCE(recipient_country,''),COALESCE(recipient_province,''),"
              "COALESCE(recipient_city,''),COALESCE(recipient_postal_code,''),"
              "COALESCE(federal_riding_name_en,''),COALESCE(CAST(federal_riding_number AS CHAR),''))")
DKEY_NAICS = "naics_identifier"


def c():
    return mysql.connector.connect(**DB)


def step_lookups(conn):
    cur = conn.cursor()
    print('=== STEP: lookup tables (recipients, programs, locations, naics) ===', flush=True)
    cur.execute('SET FOREIGN_KEY_CHECKS=0')

    # ---- recipients ----------------------------------------------------------
    print('recipients: truncate + dedup-insert', flush=True)
    cur.execute('TRUNCATE TABLE recipients')
    cur.execute(f'''
        INSERT INTO recipients
          (source_row_id, recipient_business_number, recipient_legal_name,
           recipient_operating_name, recipient_type_code, research_organization_name)
        SELECT MIN(primary_key),
               MAX(NULLIF(TRIM(recipient_business_number),'')),
               MAX(recipient_legal_name),
               MAX(recipient_operating_name),
               MAX(recipient_type),
               MAX(research_organization_name)
        FROM master_table
        WHERE recipient_legal_name IS NOT NULL AND recipient_legal_name <> ''
        GROUP BY {DKEY_RECIP}
    ''')
    print(f'   recipients rows: {cur.rowcount:,}', flush=True)

    # ---- programs ------------------------------------------------------------
    print('programs: truncate + dedup-insert', flush=True)
    cur.execute('TRUNCATE TABLE programs')
    cur.execute(f'''
        INSERT INTO programs (source_row_id, prog_name_en, prog_name_fr, prog_purpose_en, prog_purpose_fr)
        SELECT MIN(primary_key), prog_name_en, MAX(prog_name_fr), MAX(prog_purpose_en), MAX(prog_purpose_fr)
        FROM master_table
        WHERE prog_name_en IS NOT NULL AND prog_name_en <> ''
        GROUP BY {DKEY_PROG}
    ''')
    print(f'   programs rows: {cur.rowcount:,}', flush=True)

    # ---- locations -----------------------------------------------------------
    print('locations: truncate + dedup-insert', flush=True)
    cur.execute('TRUNCATE TABLE locations')
    cur.execute(f'''
        INSERT INTO locations
          (source_row_id, recipient_country, recipient_province, recipient_city,
           recipient_postal_code, federal_riding_name_en, federal_riding_name_fr,
           federal_riding_number, mp_id)
        SELECT MIN(primary_key),
               MAX(recipient_country), MAX(recipient_province), MAX(recipient_city),
               MAX(recipient_postal_code), MAX(federal_riding_name_en), MAX(federal_riding_name_fr),
               MAX(federal_riding_number), NULL
        FROM master_table
        GROUP BY {DKEY_LOC}
    ''')
    print(f'   locations rows: {cur.rowcount:,}', flush=True)

    # ---- naics ---------------------------------------------------------------
    print('naics: truncate + dedup-insert', flush=True)
    cur.execute('TRUNCATE TABLE naics')
    cur.execute(f'''
        INSERT INTO naics (source_row_id, naics_identifier)
        SELECT MIN(primary_key), naics_identifier
        FROM master_table
        WHERE naics_identifier IS NOT NULL
        GROUP BY {DKEY_NAICS}
    ''')
    print(f'   naics rows: {cur.rowcount:,}', flush=True)

    cur.execute('SET FOREIGN_KEY_CHECKS=1')
    cur.close()


def build_maps(conn):
    """Build surrogate-key map tables: dedup_key -> id, indexed for fast FK join."""
    cur = conn.cursor()
    print('Building surrogate-key maps ...', flush=True)

    cur.execute('DROP TABLE IF EXISTS _map_recip')
    cur.execute(f'''CREATE TABLE _map_recip (dkey VARCHAR(350) NOT NULL, recipient_id INT UNSIGNED,
                    INDEX(dkey)) AS
                    SELECT {DKEY_RECIP} AS dkey, recipient_id FROM recipients''')
    cur.execute('DROP TABLE IF EXISTS _map_prog')
    cur.execute('''CREATE TABLE _map_prog (dkey VARCHAR(255) NOT NULL, program_id INT UNSIGNED,
                   INDEX(dkey)) AS SELECT prog_name_en AS dkey, program_id FROM programs''')
    cur.execute('DROP TABLE IF EXISTS _map_loc')
    cur.execute(f'''CREATE TABLE _map_loc (dkey VARCHAR(600) NOT NULL, location_id INT UNSIGNED,
                    INDEX(dkey(255))) AS
                    SELECT {DKEY_LOC.replace("recipient_country","recipient_country").replace("federal_riding","federal_riding")} AS dkey, location_id FROM locations''')
    cur.execute('DROP TABLE IF EXISTS _map_naics')
    cur.execute('''CREATE TABLE _map_naics (dkey VARCHAR(10) NOT NULL, naics_id INT UNSIGNED,
                   INDEX(dkey)) AS SELECT naics_identifier AS dkey, naics_id FROM naics''')
    print('   maps built.', flush=True)
    cur.close()


def step_agreements(conn):
    cur = conn.cursor()
    print('=== STEP: agreements (current rows, FK-resolved) ===', flush=True)
    build_maps(conn)
    cur.execute('SET FOREIGN_KEY_CHECKS=0')
    cur.execute('TRUNCATE TABLE agreements')

    # Default sentinel rows handle missing lookups (NOT NULL FK columns).
    # Use COALESCE to a guaranteed-present id (MIN) when a lookup is absent.
    cur.execute('SELECT MIN(recipient_id) FROM recipients'); rid0 = cur.fetchone()[0]
    cur.execute('SELECT MIN(program_id) FROM programs');     pid0 = cur.fetchone()[0]
    cur.execute('SELECT MIN(location_id) FROM locations');   lid0 = cur.fetchone()[0]
    cur.execute('SELECT MIN(naics_id) FROM naics');          nid0 = cur.fetchone()[0]

    print('agreements: insert with FK joins', flush=True)
    cur.execute(f'''
        INSERT INTO agreements
          (source_row_id, ref_number, agreement_number, agreement_type, agreement_value,
           foreign_currency_type, foreign_currency_value, agreement_start_date, year,
           agreement_end_date, coverage, description_eng, description_fr, expected_results_en,
           expected_results_fr, additional_information_en, additional_information_fr,
           agreement_title_en, agreement_title_fr, recipient_id, program_id, location_id, naics_id)
        SELECT m.primary_key, m.ref_number, m.agreement_number, m.agreement_type, m.agreement_value,
               m.foreign_currency_type, m.foreign_currency_value, m.agreement_start_date, m.year,
               m.agreement_end_date, m.coverage, m.description_eng, m.description_fr, m.expected_results_en,
               m.expected_results_fr, m.additional_information_en, m.additional_information_fr,
               m.agreement_title_en, m.agreement_title_fr,
               COALESCE(mr.recipient_id, {rid0}),
               COALESCE(mp.program_id, {pid0}),
               COALESCE(ml.location_id, {lid0}),
               COALESCE(mn.naics_id, {nid0})
        FROM master_table m
        LEFT JOIN _map_recip mr ON mr.dkey = {DKEY_RECIP.replace('recipient_business_number','m.recipient_business_number').replace('recipient_legal_name','m.recipient_legal_name')}
        LEFT JOIN _map_prog  mp ON mp.dkey = m.prog_name_en
        LEFT JOIN _map_loc   ml ON ml.dkey = {DKEY_LOC.replace('recipient_country','m.recipient_country').replace('recipient_province','m.recipient_province').replace('recipient_city','m.recipient_city').replace('recipient_postal_code','m.recipient_postal_code').replace('federal_riding_name_en','m.federal_riding_name_en').replace('federal_riding_number','m.federal_riding_number')}
        LEFT JOIN _map_naics mn ON mn.dkey = m.naics_identifier
        WHERE m.amendment_number IS NULL AND m.ref_number IS NOT NULL AND m.ref_number <> ''
    ''')
    print(f'   agreements rows: {cur.rowcount:,}', flush=True)
    cur.execute('SET FOREIGN_KEY_CHECKS=1')
    cur.close()


def step_amendments(conn):
    cur = conn.cursor()
    print('=== STEP: amendments (linked to agreements by ref_number) ===', flush=True)
    cur.execute('SET FOREIGN_KEY_CHECKS=0')
    cur.execute('TRUNCATE TABLE amendments')
    # Link each amendment master row to its base agreement. ref_number alone is
    # NON-UNIQUE across departments, so we also match owner_org: join through the
    # agreement's source master row (am) and require the same owner_org_en. This
    # prevents an amendment from attaching to another department's agreement that
    # happens to share the ref_number.
    cur.execute('''
        INSERT INTO amendments (source_row_id, agreement_id, amendment_number, amendment_date, additional_information_en)
        SELECT m.primary_key, a.agreement_id, m.amendment_number, m.amendment_date, m.additional_information_en
        FROM master_table m
        JOIN agreements a   ON a.ref_number = m.ref_number
        JOIN master_table am ON am.primary_key = a.source_row_id
        WHERE m.amendment_number IS NOT NULL AND m.ref_number IS NOT NULL AND m.ref_number <> ''
          AND COALESCE(am.owner_org_en,'') = COALESCE(m.owner_org_en,'')
    ''')
    print(f'   amendments rows: {cur.rowcount:,}', flush=True)
    cur.execute('SET FOREIGN_KEY_CHECKS=1')
    cur.close()


def step_urls(conn):
    cur = conn.cursor()
    rconn = c()                 # separate read connection (avoids unread-result clash)
    rcur = rconn.cursor()
    print('=== STEP: agreement_urls (one detail URL per id) ===', flush=True)
    cur.execute('TRUNCATE TABLE agreement_urls')
    rcur.execute("SELECT id, ref_number, amendment_number FROM master_table WHERE id IS NOT NULL AND id <> ''")
    batch = []
    n = 0
    INS = ('INSERT INTO agreement_urls (record_id, ref_number, amendment_number, url_type, url) '
           'VALUES (%s,%s,%s,%s,%s)')
    for rid, ref, amend in rcur:
        url = 'https://search.open.canada.ca/grants/record/' + quote(rid, safe='')
        batch.append((rid, ref, amend, 'detail', url))
        if len(batch) >= 1000:
            cur.executemany(INS, batch); batch.clear(); n += 1000
            if n % 200000 == 0: print(f'   ... {n:,}', flush=True)
    if batch:
        cur.executemany(INS, batch); n += len(batch)
    print(f'   agreement_urls rows: {n:,}', flush=True)
    cur.close(); rcur.close(); rconn.close()


def step_verify(conn):
    cur = conn.cursor()
    print('=== STEP: verify ===', flush=True)
    for t in ['recipients','programs','locations','naics','agreements','amendments','agreement_urls']:
        cur.execute(f'SELECT COUNT(*) FROM {t}')
        print(f'   {t:<16} {cur.fetchone()[0]:>12,}')
    # FK integrity: any agreements pointing to missing lookups?
    print('   FK orphan checks:')
    for col, tbl, pk in [('recipient_id','recipients','recipient_id'),
                         ('program_id','programs','program_id'),
                         ('location_id','locations','location_id'),
                         ('naics_id','naics','naics_id')]:
        cur.execute(f'SELECT COUNT(*) FROM agreements a LEFT JOIN {tbl} t ON a.{col}=t.{pk} WHERE t.{pk} IS NULL')
        print(f'     agreements.{col} orphans: {cur.fetchone()[0]:,}')
    cur.execute('SELECT COUNT(*) FROM amendments am LEFT JOIN agreements a ON am.agreement_id=a.agreement_id WHERE a.agreement_id IS NULL')
    print(f'     amendments.agreement_id orphans: {cur.fetchone()[0]:,}')
    # Cleanup helper maps
    for t in ['_map_recip','_map_prog','_map_loc','_map_naics']:
        cur.execute(f'DROP TABLE IF EXISTS {t}')
    cur.close()


STEPS = {'lookups':[step_lookups], 'agreements':[step_agreements], 'amendments':[step_amendments],
         'urls':[step_urls], 'verify':[step_verify],
         'all':[step_lookups, step_agreements, step_amendments, step_urls, step_verify]}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--step', choices=list(STEPS), default='all')
    args = ap.parse_args()
    conn = c()
    print(f'Connected {DB["user"]}@{DB["host"]}/{DB["database"]}\n', flush=True)
    for fn in STEPS[args.step]:
        fn(conn); print()
    conn.close()
    print('Done.')


if __name__ == '__main__':
    main()
