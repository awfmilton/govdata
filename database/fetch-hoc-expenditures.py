#!/usr/bin/env python3
"""
Download the House of Commons Members' Expenditures Reports (Proactive Disclosure), verbatim.

Source: https://www.ourcommons.ca/ProactiveDisclosure/en/members
  - one summary CSV per quarter (Name, Constituency, Caucus, Salaries, Travel, Hospitality, Contracts)
  - per member per quarter, detailed CSVs for travel, hospitality and contracts (transaction level)

Files are saved unchanged under OUT/<fiscal_year>-Q<quarter>/ with a manifest (manifest.jsonl: url, path,
http status, bytes, sha256, fetched_at) so every loaded row can be traced to the file it came from.
Resumable: files already in the manifest with status 200 are skipped. Polite: one request every DELAY
seconds; backs off on 429/5xx; stops after 10 consecutive errors. Read-only against the source.

Usage:  python3 database/fetch-hoc-expenditures.py [OUT_DIR]
        (default OUT_DIR /mnt/govdata_build_data/local/hoc-expenditures)
"""
import hashlib
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request

BASE = 'https://www.ourcommons.ca'
INDEX = BASE + '/proactivedisclosure/en/members'
OUT = sys.argv[1] if len(sys.argv) > 1 else '/mnt/govdata_build_data/local/hoc-expenditures'
UA = 'Mozilla/5.0 (govdata.ca data verification)'
DELAY = 1.5

os.makedirs(OUT, exist_ok=True)
MANIFEST = os.path.join(OUT, 'manifest.jsonl')
done = set()
if os.path.exists(MANIFEST):
    for line in open(MANIFEST, encoding='utf-8'):
        if line.strip():
            r = json.loads(line)
            if r.get('status') == 200:
                done.add(r['url'])
errors = 0


def get(url):
    """Fetch url -> (status, bytes). Retries 429/5xx with backoff."""
    global errors
    for attempt in range(5):
        time.sleep(DELAY)
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': UA}), timeout=60) as r:
                errors = 0
                return r.status, r.read()
        except urllib.error.HTTPError as e:
            if e.code in (429, 500, 502, 503, 504) and attempt < 4:
                time.sleep(20 * (attempt + 1))
                continue
            errors += 1
            return e.code, b''
        except Exception:
            if attempt < 4:
                time.sleep(20 * (attempt + 1))
                continue
            errors += 1
            return 0, b''
    return 0, b''


def save(url, rel, status, body):
    path = os.path.join(OUT, rel)
    if status == 200:
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, 'wb') as f:
            f.write(body)
    rec = {'url': url, 'path': rel, 'status': status, 'bytes': len(body),
           'sha256': hashlib.sha256(body).hexdigest() if body else '',
           'fetched_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())}
    with open(MANIFEST, 'a', encoding='utf-8') as f:
        f.write(json.dumps(rec) + '\n')
    if errors >= 10:
        print('ABORT: 10 consecutive errors, last', url, status, flush=True)
        sys.exit(2)


def html(url):
    status, body = get(url)
    return body.decode('utf-8', 'replace') if status == 200 else ''


index = html(INDEX)
quarters = sorted(set(re.findall(r'/proactivedisclosure/en/members/(\d{4})/(\d)\?summaryId=([0-9a-f-]{36})', index)))
print(f'{len(quarters)} quarters listed', flush=True)

for year, q, summary_id in quarters:
    folder = f'{year}-Q{q}'
    page_url = f'{INDEX}/{year}/{q}?summaryId={summary_id}'
    page = html(page_url)
    if not page:
        print('skip quarter (page failed)', folder, flush=True)
        continue
    # Quarter summary CSV. Its id is also the first path segment of every detail CSV in the quarter:
    #   /members/<kind>/<summaryCsvId>/<memberId>/csv
    csv_ids = sorted(set(re.findall(r'/proactivedisclosure/en/members/([0-9a-f-]{36})/csv', page)))
    if len(csv_ids) != 1:
        print('skip quarter (expected one summary csv, found %d)' % len(csv_ids), folder, flush=True)
        continue
    sid = csv_ids[0]
    url = f'{INDEX}/{sid}/csv'
    if url not in done:
        s, b = get(url)
        save(url, f'{folder}/summary-{sid}.csv', s, b)
    details = sorted(set(re.findall(r'/proactivedisclosure/en/members/(travel|hospitality|contract)/' + year + '/' + q + r'/([0-9a-f-]{36})', page)))
    print(f'{folder}: {len(details)} detail reports', flush=True)
    for n, (kind, member_id) in enumerate(details, 1):
        url = f'{INDEX}/{kind}/{sid}/{member_id}/csv'
        if url in done:
            continue
        s, b = get(url)
        save(url, f'{folder}/{kind}/{member_id}.csv', s, b)
        if s == 200:
            done.add(url)
        if n % 100 == 0:
            print(f'  {folder} {n}/{len(details)}', flush=True)

print('DONE', flush=True)
