"""Check a transcribed MP22-1 table (long CSV) against the table's own totals.

CSV columns: year,table,basis,row_label,row_kind,column,value,page
  basis     residence | citizenship | birth
  row_kind  country | subtotal | total        (subtotal: a heading row whose value is the sum of indented rows under it,
                                               e.g. Britain = England + N. Ireland + Scotland + Wales + Lesser Br. Isles)
  column    'Canada' for the all-Canada column, otherwise the column heading as printed (Nfld., P.E.I., ...)
  value     integer; '..' or '-' in the source = 0
Checks: (1) each row: sum of non-'Canada' columns == 'Canada' (when the table has province columns);
        (2) each column: sum of row_kind=country rows == the total row.
Usage: python validate.py file.csv [...]   -> prints every mismatch; exit 1 if any.
"""
import csv, sys, collections

bad = 0
for path in sys.argv[1:]:
    rows = list(csv.DictReader(open(path, encoding='utf-8')))
    by_table = collections.defaultdict(list)
    for r in rows:
        by_table[(r['year'], r['table'], r['basis'])].append(r)
    for key, rs in by_table.items():
        cells = collections.defaultdict(dict)
        kinds = {}
        for r in rs:
            cells[r['row_label']][r['column']] = int(r['value'])
            kinds[r['row_label']] = r['row_kind']
        cols = sorted({c for d in cells.values() for c in d})
        prov = [c for c in cols if c != 'Canada']
        if prov and 'Canada' in cols:
            for label, d in cells.items():
                s = sum(d.get(c, 0) for c in prov)
                if s != d.get('Canada', 0):
                    print(f'{path} {key} ROW {label!r}: provinces sum {s} != Canada {d.get("Canada")}'); bad += 1
        totals = [l for l, k in kinds.items() if k == 'total']
        if len(totals) != 1:
            print(f'{path} {key}: expected exactly one total row, found {totals}'); bad += 1; continue
        t = totals[0]
        for c in cols:
            s = sum(d.get(c, 0) for l, d in cells.items() if kinds[l] == 'country')
            if s != cells[t].get(c, 0):
                print(f'{path} {key} COLUMN {c!r}: countries sum {s} != total {cells[t].get(c)}'); bad += 1
        print(f'{path} {key}: {sum(1 for k in kinds.values() if k=="country")} country rows, {len(cols)} columns checked')
sys.exit(1 if bad else 0)
