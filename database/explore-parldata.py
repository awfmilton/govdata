"""
Explore the Open Parliament PostgreSQL dump without fully extracting it.
Streams openparliament.public.sql.bz2, collects all CREATE TABLE definitions,
and counts rows in each COPY block.

Usage:
    python database/explore-parldata.py
    python database/explore-parldata.py --file "C:/other/path.sql.bz2"
"""

import bz2
import re
import sys
import argparse
from collections import defaultdict

DEFAULT_PATH = r"C:\Users\user\Downloads\openparliament.public.sql.bz2"


def stream_lines(path: str):
    if path.endswith(".bz2"):
        with bz2.open(path, "rt", encoding="utf-8", errors="replace") as f:
            yield from f
    else:
        with open(path, "r", encoding="utf-8", errors="replace") as f:
            yield from f


def parse_dump(path: str):
    # table_name -> list of (col_name, col_type) tuples
    tables: dict[str, list[tuple[str, str]]] = {}
    row_counts: dict[str, int] = defaultdict(int)

    in_create = False
    current_table: str | None = None
    current_cols: list[tuple[str, str]] = []

    in_copy = False
    copy_table: str | None = None
    copy_count = 0

    lines_read = 0

    # PostgreSQL: CREATE TABLE public.tablename (
    re_create = re.compile(
        r"^CREATE TABLE\s+(?:public\.)?\"?(\w+)\"?\s*\(", re.IGNORECASE
    )
    # COPY public.tablename (col, col, ...) FROM stdin;
    re_copy = re.compile(
        r"^COPY\s+(?:public\.)?\"?(\w+)\"?", re.IGNORECASE
    )
    # Column line inside CREATE TABLE:  colname  TYPE
    re_col = re.compile(r'^\s+"?(\w+)"?\s+(\S.*?)(?:,)?\s*$')
    # End of CREATE TABLE block
    re_end_create = re.compile(r"^\s*\);?\s*$")

    print(f"Scanning: {path}")
    print("(Streaming — no full extraction needed)\n")

    for raw_line in stream_lines(path):
        lines_read += 1
        line = raw_line.rstrip("\n")

        if lines_read % 500_000 == 0:
            print(f"  … {lines_read:,} lines, {len(tables)} tables, "
                  f"{sum(row_counts.values()):,} rows so far")

        # ── Inside a COPY block ────────────────────────────────────────────
        if in_copy:
            if line == "\\.":
                row_counts[copy_table] += copy_count
                in_copy = False
                copy_table = None
                copy_count = 0
            else:
                copy_count += 1
            continue

        # ── Inside a CREATE TABLE block ────────────────────────────────────
        if in_create:
            if re_end_create.match(line) and line.strip() in (");", ")"):
                tables[current_table] = current_cols
                in_create = False
                current_table = None
                current_cols = []
            else:
                m = re_col.match(line)
                if m:
                    col_name = m.group(1)
                    raw_type = m.group(2).rstrip(",").strip()
                    # Skip constraint declarations
                    if col_name.upper() not in (
                        "CONSTRAINT", "PRIMARY", "UNIQUE", "FOREIGN",
                        "CHECK", "EXCLUDE",
                    ):
                        current_cols.append((col_name, raw_type))
            continue

        # ── Top-level line ─────────────────────────────────────────────────
        m = re_create.match(line)
        if m:
            current_table = m.group(1)
            current_cols = []
            in_create = True
            continue

        m = re_copy.match(line)
        if m:
            copy_table = m.group(1)
            copy_count = 0
            in_copy = True
            continue

    # ── Final report ───────────────────────────────────────────────────────
    print(f"\n{'='*72}")
    print(f"SCAN COMPLETE  —  {lines_read:,} lines read")
    print(f"{'='*72}\n")

    all_tables = sorted(set(list(tables.keys()) + list(row_counts.keys())))
    print(f"Tables / objects found: {len(all_tables)}\n")

    for tbl in all_tables:
        rows = row_counts.get(tbl, 0)
        cols = tables.get(tbl, [])
        print(f"  TABLE: {tbl}  ({rows:,} rows)")
        for col_name, col_type in cols:
            print(f"    {col_name:<35} {col_type}")
        if not cols:
            print("    (no CREATE TABLE found — view or sequence)")
        print()

    print(f"\n{'='*72}")
    print(f"{'TABLE':<45} {'ROWS':>12}")
    print(f"{'='*72}")
    for tbl in all_tables:
        print(f"  {tbl:<43} {row_counts.get(tbl, 0):>12,}")

    return tables, row_counts


def main():
    parser = argparse.ArgumentParser(description="Explore a PostgreSQL .sql(.bz2) dump.")
    parser.add_argument("--file", default=DEFAULT_PATH)
    args = parser.parse_args()
    parse_dump(args.file)


if __name__ == "__main__":
    main()
