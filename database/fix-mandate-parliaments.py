"""
Back-fill mp_mandates.parliament using start_date proximity to op_core_session.

The op_core_electedmember_sessions join table is empty, so we derive parliament
by finding the session whose start date is closest to (and not after) each
mandate's start_date, with a 200-day forward tolerance to cover the gap between
election day and first sitting.

Run: python database/fix-mandate-parliaments.py
"""

import os
import mysql.connector

DB = dict(
    host=os.environ.get('DB_HOST', '35.230.186.163'),
    port=int(os.environ.get('DB_PORT', 3306)),
    database=os.environ.get('DB_NAME', 'grantconts'),
    user=os.environ.get('DB_USER', 'pt_read'),
    password=os.environ.get('DB_PASS', '123456789!!!!!!!!!'),
    charset='utf8mb4',
)

cx = mysql.connector.connect(**DB)
cur = cx.cursor(dictionary=True)

# Build parliament era ranges from op_core_session.
# For each parliament N: era_start = MIN(session.start) for that parliament,
# era_end   = MAX(session.end) + a small forward buffer.
# We'll accept a mandate whose start_date is up to 200 days before the first
# session (elections are typically 4–8 weeks before first sitting).

cur.execute("""
    SELECT parliamentnum,
           MIN(start)                         AS era_start,
           MAX(COALESCE(`end`, '2099-01-01')) AS era_end
      FROM op_core_session
     WHERE parliamentnum IS NOT NULL
     GROUP BY parliamentnum
     ORDER BY parliamentnum
""")
eras = cur.fetchall()
from datetime import date, timedelta

def to_date(v):
    if isinstance(v, date):
        return v
    return date.fromisoformat(str(v)[:10])

for e in eras:
    e['era_start'] = to_date(e['era_start'])
    e['era_end']   = to_date(e['era_end'])

print("Parliament eras derived from sessions:")
for e in eras:
    print(f"  {e['parliamentnum']:3d}  {e['era_start']} – {e['era_end']}")

# For each mandate with a NULL parliament, find the best-matching parliament.
cur.execute("""
    SELECT mm.id, em.start_date
      FROM mp_mandates mm
      JOIN op_core_electedmember em ON em.id = mm.op_member_id
     WHERE mm.parliament IS NULL AND mm.op_member_id IS NOT NULL
""")
mandates = cur.fetchall()
print(f"\n{len(mandates)} mandates need parliament back-fill.")

updated = 0
unmatched = []

for m in mandates:
    sd = m['start_date']
    best = None

    for era in eras:
        # Accept if start_date is within [era_start - 200 days, era_end].
        window_start = era['era_start'] - timedelta(days=200)
        window_end   = era['era_end']
        if window_start <= sd <= window_end:
            # If multiple eras match (shouldn't happen but safety), prefer highest.
            if best is None or era['parliamentnum'] > best:
                best = era['parliamentnum']

    if best:
        cur.execute("UPDATE mp_mandates SET parliament=%s WHERE id=%s", (best, m['id']))
        updated += 1
    else:
        unmatched.append((m['id'], str(sd)))

cx.commit()
print(f"Updated: {updated}  |  Unmatched: {len(unmatched)}")
if unmatched:
    print("Unmatched mandate IDs + start_dates:")
    for mid, sd in unmatched[:20]:
        print(f"  id={mid}  start={sd}")

# Final distribution.
cur.execute("""
    SELECT parliament, COUNT(*) AS cnt
      FROM mp_mandates
     GROUP BY parliament
     ORDER BY parliament
""")
print("\nmp_mandates parliament distribution:")
for row in cur.fetchall():
    print(f"  parliament {row['parliament']}: {row['cnt']} mandates")

cur.close()
cx.close()
