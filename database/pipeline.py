#!/usr/bin/env python3
"""
GovData CSV pipeline runner.

Manages the two-step cleaning pipeline for government grants CSV files:
  Step 1 — Deduplicate:  source CSV  →  dedup/{year}_dedup.csv
  Step 2 — Clean:        dedup file  →  cleaned/{year}_dedup_cleaned.csv

Fixed directories:
  Source:   C:\\Users\\user\\Documents\\GrantsConts\\
  Dedup:    C:\\Users\\user\\Documents\\GrantsConts\\dedup\\
  Cleaned:  C:\\Users\\user\\Documents\\GrantsConts\\cleaned\\

Usage:
  python3 database/pipeline.py               # show pipeline status
  python3 database/pipeline.py --run         # process all pending files
  python3 database/pipeline.py --run 2023    # process one year only
  python3 database/pipeline.py --step dedup  # run dedup step only (all pending)
  python3 database/pipeline.py --step clean  # run clean step only (all pending)
  python3 database/pipeline.py --no-db-check # dedup without filtering against DB
                                              # (use this for staging workflow)
"""

import argparse
import os
import re
import subprocess
import sys
from pathlib import Path

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')

# ---------------------------------------------------------------------------
# Fixed directory paths
# ---------------------------------------------------------------------------
DIR_SOURCE  = Path(r'C:\Users\user\Documents\GrantsConts')
DIR_DEDUP   = DIR_SOURCE / 'dedup'
DIR_CLEANED = DIR_SOURCE / 'cleaned'

SCRIPT_DIR  = Path(__file__).parent
DEDUP_SCRIPT  = SCRIPT_DIR / 'deduplicate-csv.py'
CLEAN_SCRIPT  = SCRIPT_DIR / 'clean-csv.py'


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def extract_year_label(filename: str) -> str:
    """Extract fiscal-year or calendar-year label from a filename."""
    stem = Path(filename).stem
    m = re.search(r'(19|20)\d{2}[-_](19|20)\d{2}', stem)
    if m:
        return m.group().replace('_', '-')
    m = re.search(r'(19|20)\d{2}', stem)
    if m:
        return m.group()
    return stem


def dedup_path(year_label: str) -> Path:
    return DIR_DEDUP / f'{year_label}_dedup.csv'


def cleaned_path(year_label: str) -> Path:
    return DIR_CLEANED / f'{year_label}_dedup_cleaned.csv'


def fmt_size(path: Path) -> str:
    if not path.exists():
        return '-'
    mb = path.stat().st_size / 1_048_576
    return f'{mb:.1f} MB'


def status_tag(exists: bool) -> str:
    return 'done   ' if exists else 'pending'


def scan_source() -> list[dict]:
    """Return a list of pipeline-state dicts for every CSV in the source dir."""
    files = sorted(DIR_SOURCE.glob('*.csv'))
    results = []
    for src in files:
        label = extract_year_label(src.name)
        dp    = dedup_path(label)
        cp    = cleaned_path(label)
        results.append({
            'src':        src,
            'label':      label,
            'dedup_path': dp,
            'clean_path': cp,
            'has_dedup':  dp.exists(),
            'has_clean':  cp.exists(),
        })
    return results


# ---------------------------------------------------------------------------
# Status display
# ---------------------------------------------------------------------------

def show_status(files: list[dict]):
    print()
    print('=' * 70)
    print(f'  GovData CSV Pipeline')
    print(f'  Source:   {DIR_SOURCE}')
    print(f'  Dedup:    {DIR_DEDUP}')
    print(f'  Cleaned:  {DIR_CLEANED}')
    print('=' * 70)

    if not files:
        print('  No CSV files found in source directory.')
        print('=' * 70)
        return

    print(f'  {"File":<30} {"Size":>8}   {"Dedup":>9}   {"Cleaned":>9}')
    print(f'  {"-"*30} {"-"*8}   {"-"*9}   {"-"*9}')

    pending_dedup  = 0
    pending_clean  = 0
    ready_to_clean = 0

    for f in files:
        size  = fmt_size(f['src'])
        dedup = status_tag(f['has_dedup'])
        clean = status_tag(f['has_clean']) if f['has_dedup'] else '   -   '

        if not f['has_dedup']:
            pending_dedup += 1
        elif not f['has_clean']:
            ready_to_clean += 1
            pending_clean += 1

        print(f'  {f["src"].name:<30} {size:>8}   {dedup}   {clean}')

    print(f'  {"-"*30} {"-"*8}   {"-"*9}   {"-"*9}')

    if pending_dedup == 0 and pending_clean == 0:
        print('  All files are fully processed.')
    else:
        parts = []
        if pending_dedup:
            parts.append(f'{pending_dedup} file(s) need dedup')
        if ready_to_clean:
            parts.append(f'{ready_to_clean} file(s) ready to clean')
        print(f'  Pending: {", ".join(parts)}')

    print('=' * 70)
    print()


# ---------------------------------------------------------------------------
# Pipeline execution
# ---------------------------------------------------------------------------

def run_dedup(f: dict, no_db_check: bool) -> bool:
    print(f'\n>>> DEDUP: {f["src"].name}')
    cmd = [
        sys.executable, str(DEDUP_SCRIPT),
        str(f['src']),
        '--output', str(f['dedup_path']),
    ]
    if no_db_check:
        cmd.append('--no-db-check')
    result = subprocess.run(cmd, env={**os.environ})
    return result.returncode == 0


def run_clean(f: dict) -> bool:
    print(f'\n>>> CLEAN: {f["dedup_path"].name}')
    cmd = [
        sys.executable, str(CLEAN_SCRIPT),
        str(f['dedup_path']),
        '--output',  str(f['clean_path']),
        '--log',     str(DIR_CLEANED / f'{f["label"]}_dedup_cleaning_log.txt'),
    ]
    result = subprocess.run(cmd, env={**os.environ})
    return result.returncode == 0


def run_pipeline(files: list[dict], step: str, filter_label: str, no_db_check: bool):
    errors = []

    for f in files:
        if filter_label and filter_label.lower() not in f['label'].lower():
            continue

        # Step 1 — dedup
        if step in ('all', 'dedup') and not f['has_dedup']:
            ok = run_dedup(f, no_db_check)
            if ok:
                f['has_dedup'] = True
            else:
                errors.append(f'DEDUP FAILED: {f["src"].name}')
                continue

        # Step 2 — clean (only if dedup exists)
        if step in ('all', 'clean') and f['has_dedup'] and not f['has_clean']:
            ok = run_clean(f)
            if ok:
                f['has_clean'] = True
            else:
                errors.append(f'CLEAN FAILED: {f["dedup_path"].name}')

    print()
    if errors:
        print('ERRORS:')
        for e in errors:
            print(f'  {e}')
    else:
        print('All steps completed successfully.')

    show_status(files)


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    parser.add_argument('--run', nargs='?', const='', metavar='YEAR',
                        help='Run pipeline for all pending files, or a specific year label')
    parser.add_argument('--step', choices=['dedup', 'clean', 'all'], default='all',
                        help='Which step to run (default: all)')
    parser.add_argument('--no-db-check', action='store_true',
                        help='Pass --no-db-check to deduplicate step (use for staging workflow)')
    args = parser.parse_args()

    # Ensure output directories exist
    DIR_DEDUP.mkdir(parents=True, exist_ok=True)
    DIR_CLEANED.mkdir(parents=True, exist_ok=True)

    files = scan_source()
    show_status(files)

    if args.run is not None:
        run_pipeline(files, args.step, args.run or '', args.no_db_check)


if __name__ == '__main__':
    main()
