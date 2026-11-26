"""Apply one parsed edition to imm_fact, keeping every revision.

New cells are inserted; cells whose value or suppression changed are updated and logged in imm_fact_revisions;
cells the new edition no longer contains are copied to imm_fact_removed and then dropped from imm_fact. Unchanged
cells are left alone (their edition_id stays the edition that last changed them). One transaction per source.
"""

import hashlib
from datetime import datetime, timezone

from .parsers import DIMS, LayoutError

BATCH = 5000
FACT_COLS = ('source_key', 'edition_id', 'row_key', 'grain', 'ref_period') + DIMS[:3] + ('country_id',) + DIMS[3:] + \
            ('value', 'suppressed', 'rounded')


def row_key(source_key: str, r: dict) -> bytes:
    parts = [source_key, r['grain'], r['ref_period'].isoformat()] + [r.get(k) or '' for k in DIMS]
    return hashlib.sha1('\x1f'.join(parts).encode('utf-8')).digest()


def _batches(seq, n=BATCH):
    for i in range(0, len(seq), n):
        yield seq[i:i + n]


def apply_edition(cur, src: dict, edition_id: int, rows: list[dict], resolver) -> dict:
    key = src['key']
    may_create = {'ircc_odp': 'all', 'statcan_csv': 'groups', 'mp22_csv': 'groups'}.get(src['parser'], 'none')
    new = {}
    for r in rows:
        k = row_key(key, r)
        if k in new:
            raise LayoutError(f'{key}: two rows with identical dimensions for {r["ref_period"]} '
                              f'{[r.get(d) for d in DIMS if r.get(d)]} -- a subtotal or a layout change?')
        r['country_id'] = resolver.resolve(r.get('country_raw'), key, may_create)
        new[k] = r

    cur.execute('SELECT id, row_key, value, suppressed, edition_id, ref_period, country_raw, country_id '
                'FROM imm_fact WHERE source_key = %s', (key,))
    current = {rk: (fid, v, s, ed, rp, cr, cid) for fid, rk, v, s, ed, rp, cr, cid in cur.fetchall()}
    cur.execute('SELECT DISTINCT row_key FROM imm_fact_removed WHERE source_key = %s', (key,))
    previously_removed = {rk for (rk,) in cur.fetchall()}
    now = datetime.now(timezone.utc).replace(tzinfo=None)

    inserts, updates, revisions, relinks = [], [], [], []
    for k, r in new.items():
        cur_row = current.get(k)
        if cur_row is None:
            inserts.append(r | {'row_key': k})
            if k in previously_removed:
                revisions.append((key, k, r['ref_period'], r.get('country_raw'), 'row_restored', None, r['value'],
                                  None, r['suppressed'], None, edition_id, now))
            continue
        fid, v, s, ed, _rp, _cr, cid = cur_row
        if v == r['value'] and int(s) == int(r['suppressed']):
            if cid != r['country_id']:           # a reviewed alias now resolves this spelling: relink, not a revision
                relinks.append((r['country_id'], fid))
            continue
        kind = 'value_changed' if int(s) == int(r['suppressed']) else 'suppression_changed'
        updates.append((r['value'], r['suppressed'], edition_id, r['country_id'], fid))
        revisions.append((key, k, r['ref_period'], r.get('country_raw'), kind, v, r['value'], s, r['suppressed'],
                          ed, edition_id, now))
    removed_ids = [cur_row[0] for k, cur_row in current.items() if k not in new]
    for k, (fid, v, s, ed, rp, cr, _cid) in current.items():
        if k not in new:
            revisions.append((key, k, rp, cr, 'row_removed', v, None, s, None, ed, edition_id, now))

    cols = ', '.join(FACT_COLS)
    ph = ', '.join(['%s'] * len(FACT_COLS))
    for b in _batches(inserts):
        cur.executemany(f'INSERT INTO imm_fact ({cols}) VALUES ({ph})',
                        [tuple(key if c == 'source_key' else edition_id if c == 'edition_id' else r[c]
                               for c in FACT_COLS) for r in b])
    for b in _batches(updates):
        cur.executemany('UPDATE imm_fact SET value = %s, suppressed = %s, edition_id = %s, country_id = %s '
                        'WHERE id = %s', b)
    for b in _batches(removed_ids):
        ids = ', '.join(str(int(i)) for i in b)
        cur.execute(f'INSERT INTO imm_fact_removed (removed_in_edition_id, removed_at, {cols}) '
                    f'SELECT %s, %s, {cols} FROM imm_fact WHERE id IN ({ids})', (edition_id, now))
        cur.execute(f'DELETE FROM imm_fact WHERE id IN ({ids})')
    for b in _batches(revisions):
        cur.executemany('INSERT INTO imm_fact_revisions (source_key, row_key, ref_period, country_raw, change_kind, '
                        'old_value, new_value, old_suppressed, new_suppressed, prev_edition_id, edition_id, detected_at) '
                        'VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', b)
    for b in _batches(relinks):
        cur.executemany('UPDATE imm_fact SET country_id = %s WHERE id = %s', b)
    return {'rows': len(new), 'inserted': len(inserts), 'revised': len(updates), 'removed': len(removed_ids),
            'relinked': len(relinks)}
