"""
Migration + import script for the 2023 Representation Order PCBFR data.

Steps performed:
  1. Snapshot existing pcbfr -> pcbfr_2013_ro_backup
  2. Create representation_orders table
  3. Seed 2013 and 2023 RO records
  4. Add ro_id column to pcbfr; tag all existing rows as 2013 RO
  5. Bulk-insert 2023 postal-code/riding rows from PCBFR2023/pcbfr_2023.csv
"""

import csv
import os
import sys
import mysql.connector

DB = dict(
    host=os.environ.get('DB_HOST', '35.230.186.163'),
    port=int(os.environ.get('DB_PORT', 3306)),
    database=os.environ.get('DB_NAME', 'grantconts'),
    user=os.environ.get('DB_USER', 'pt_read'),
    password=os.environ.get('DB_PASS', '123456789!!!!!!!!!'),
)

CSV_PATH = os.path.join(os.path.dirname(__file__), '..', 'PCBFR2023', 'pcbfr_2023.csv')
BATCH_SIZE = 5000


def run(cur, sql, params=None):
    cur.execute(sql, params)


def step_backup(cur):
    print("Step 1 — backing up pcbfr -> pcbfr_2013_ro_backup ...")
    cur.execute("""
        SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = %s AND table_name = 'pcbfr_2013_ro_backup'
    """, (DB['database'],))
    if cur.fetchone()[0]:
        print("  pcbfr_2013_ro_backup already exists — skipping.")
        return
    cur.execute("CREATE TABLE pcbfr_2013_ro_backup LIKE pcbfr")
    cur.execute("INSERT INTO pcbfr_2013_ro_backup SELECT * FROM pcbfr")
    cur.execute("SELECT COUNT(*) FROM pcbfr_2013_ro_backup")
    n = cur.fetchone()[0]
    print(f"  Backed up {n:,} rows.")


def step_create_representation_orders(cur):
    print("Step 2 — creating representation_orders table ...")
    cur.execute("""
        CREATE TABLE IF NOT EXISTS representation_orders (
            id              TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
            code            VARCHAR(10)      NOT NULL,
            effective_start DATE             NOT NULL,
            effective_end   DATE             DEFAULT NULL,
            riding_count    SMALLINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ro_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    """)
    print("  Done.")


def step_seed_ros(cur):
    print("Step 3 — seeding representation_orders rows ...")
    cur.execute("""
        INSERT IGNORE INTO representation_orders
            (code, effective_start, effective_end, riding_count)
        VALUES
            ('2013_RO', '2015-10-19', '2025-04-28', 338),
            ('2023_RO', '2025-04-28', NULL,          343)
    """)
    cur.execute("SELECT id, code FROM representation_orders ORDER BY id")
    for row in cur.fetchall():
        print(f"  id={row[0]}  code={row[1]}")


def step_add_ro_id(cur):
    print("Step 4 — adding ro_id column to pcbfr ...")
    # Guard: skip if column already exists
    cur.execute("""
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = %s AND table_name = 'pcbfr' AND column_name = 'ro_id'
    """, (DB['database'],))
    if cur.fetchone()[0]:
        print("  ro_id column already exists — skipping ALTER.")
    else:
        cur.execute("""
            ALTER TABLE pcbfr
            ADD COLUMN ro_id TINYINT UNSIGNED DEFAULT NULL AFTER weight_information,
            ADD KEY idx_pcbfr_ro_id (ro_id)
        """)
        print("  Column added.")

    print("  Tagging all existing rows as 2013 RO ...")
    cur.execute("""
        UPDATE pcbfr SET ro_id = (
            SELECT id FROM representation_orders WHERE code = '2013_RO'
        )
        WHERE ro_id IS NULL
    """)
    cur.execute("SELECT COUNT(*) FROM pcbfr WHERE ro_id = 1")
    n = cur.fetchone()[0]
    print(f"  {n:,} rows tagged as 2013 RO.")


def step_import_2023(cur, conn):
    print("Step 5 — importing 2023 PCBFR rows ...")
    cur.execute("SELECT id FROM representation_orders WHERE code = '2023_RO'")
    ro_id = cur.fetchone()[0]
    print(f"  2023 RO id = {ro_id}")

    insert_sql = """
        INSERT INTO pcbfr
            (postal_code, riding_number, riding_name_en, riding_name_fr,
             unique_FED, pop_weight, weight_information, ro_id)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
    """

    csv_path = os.path.normpath(CSV_PATH)
    total = 0
    batch = []

    with open(csv_path, encoding='utf-8', newline='') as f:
        reader = csv.DictReader(f, delimiter='|', quoting=csv.QUOTE_ALL,
                                quotechar='"')
        for row in reader:
            batch.append((
                row['postal_code'],
                row['riding_number'],
                row['riding_name_en'],
                row['riding_name_fr'],
                row['unique_FED'],
                float(row['pop_weight']),
                row['weight_information'],
                ro_id,
            ))
            if len(batch) >= BATCH_SIZE:
                cur.executemany(insert_sql, batch)
                conn.commit()
                total += len(batch)
                batch = []
                print(f"  {total:,} rows inserted...", end='\r')

    if batch:
        cur.executemany(insert_sql, batch)
        conn.commit()
        total += len(batch)

    print(f"\n  {total:,} rows inserted for 2023 RO.")


def main():
    print(f"Connecting to {DB['host']}:{DB['port']} / {DB['database']} as {DB['user']} ...")
    conn = mysql.connector.connect(**DB)
    cur = conn.cursor()

    try:
        step_backup(cur);               conn.commit()
        step_create_representation_orders(cur); conn.commit()
        step_seed_ros(cur);             conn.commit()
        step_add_ro_id(cur);            conn.commit()
        step_import_2023(cur, conn)

        cur.execute("SELECT ro_id, COUNT(*) FROM pcbfr GROUP BY ro_id ORDER BY ro_id")
        print("\n=== Final pcbfr row counts by ro_id ===")
        for r in cur.fetchall():
            print(f"  ro_id={r[0]}  rows={r[1]:,}")

    except Exception as e:
        conn.rollback()
        print(f"\nERROR: {e}")
        sys.exit(1)
    finally:
        cur.close()
        conn.close()

    print("\nMigration complete.")


if __name__ == '__main__':
    main()
