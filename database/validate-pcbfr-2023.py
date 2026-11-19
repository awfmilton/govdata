#!/usr/bin/env python3
"""
Validate and correct PCBFR2023/pcbfr_2023.csv (postal code -> 2023 federal riding), which
build-pcbfr-2023.py makes by placing GeoNames postal-code points (CA_full.txt) into the 2023 FED
boundary polygons. GeoNames coordinates are approximate and sometimes wrong, so some codes land in
the wrong riding (e.g. L2V0J7 Thorold -> Toronto Centre; G7A* Saint-Augustin -> Papineau).

Checks
  1. province   postal-code first letter vs the riding's province (FED number prefix). NOT a certain
                error: cross-border places legitimately mix them (K1A federal codes in Gatineau QC,
                Flin Flon MB/SK, Lloydminster AB/SK, Akwesasne ON/QC). Elections Canada showed 39 of 221
                such "fixes" were wrong (2026-11-18), so this check only SUGGESTS (nearest riding of the
                letter's province from the FSA median) and goes to review like the others.
  2. fsa        (urban FSAs only) the point is far (> FSA_KM) from the median of its forward sortation area (first 3
                characters) — a misplaced coordinate. Suggestion: the riding most codes in that FSA got.
  3. concord    the 2013-RO riding of the code in Statistics Canada's official PCFRF (V2209, 2022 release)
                almost never maps to the assigned 2023 riding among all codes that agree. Suggestion: the
                2023 riding its 2013 riding most often became, within the same FSA when possible.
No check is applied automatically: they go to a review file for the owner. Decisions are
recorded in database/pcbfr-2023-corrections.csv (postal_code,riding_number,note) and applied on every run
(weight_information='M' = decided: Elections Canada or the owner), so decisions survive a rebuild and are
not listed for review again.

Outputs (PCBFR2023/)
  pcbfr_2023_v2.csv          corrected file, same columns/format as pcbfr_2023.csv
  pcbfr_2023_review.csv      flagged codes for review, worst first
  pcbfr_2023_validation.txt  summary

Usage:  python database/validate-pcbfr-2023.py      (run from the repo root)
"""

import csv
import math
import os
from collections import Counter, defaultdict

import geopandas as gpd
import pandas as pd

ROOT = 'PCBFR2023'
FSA_KM = 40.0
CONCORD_MIN_SHARE = 0.02      # an (rn2013 -> rn2023) pairing seen in < 2% of that 2013 riding's codes ...
CONCORD_MIN_COUNT = 3         # ... and fewer than 3 codes is implausible
CORRECTIONS = os.path.join('database', 'pcbfr-2023-corrections.csv')

LETTER_PROV = {'A': 'NL', 'B': 'NS', 'C': 'PE', 'E': 'NB', 'G': 'QC', 'H': 'QC', 'J': 'QC', 'K': 'ON', 'L': 'ON',
               'M': 'ON', 'N': 'ON', 'P': 'ON', 'R': 'MB', 'S': 'SK', 'T': 'AB', 'V': 'BC', 'X': 'NT/NU', 'Y': 'YT'}
FED_PROV = {'10': 'NL', '11': 'PE', '12': 'NS', '13': 'NB', '24': 'QC', '35': 'ON', '46': 'MB', '47': 'SK',
            '48': 'AB', '59': 'BC', '60': 'YT', '61': 'NT/NU', '62': 'NT/NU'}


def km(lat1, lon1, lat2, lon2):
    p = math.pi / 180
    a = (math.sin((lat2 - lat1) * p / 2) ** 2
         + math.cos(lat1 * p) * math.cos(lat2 * p) * math.sin((lon2 - lon1) * p / 2) ** 2)
    return 12742 * math.asin(math.sqrt(a))


def main():
    cols = ['country', 'postal_code', 'city', 'prov', 'prov_code', 'county', 'county_code', 'community',
            'community_code', 'latitude', 'longitude', 'accuracy']
    pts = pd.read_csv(f'{ROOT}/CA_full.csv/CA_full.txt', sep='\t', names=cols, dtype=str)
    pts['postal_code'] = pts['postal_code'].str.replace(' ', '', regex=False)
    pts = pts.drop_duplicates('postal_code', keep='first')
    pts['latitude'] = pts['latitude'].astype(float)
    pts['longitude'] = pts['longitude'].astype(float)
    pts = pts.set_index('postal_code')

    cur = pd.read_csv(f'{ROOT}/pcbfr_2023.csv', sep='|', dtype=str)
    shp = gpd.read_file(f'{ROOT}/FED_CA_2023_EN-SHP/FED_CA_2023_EN.shp')
    shp['rn'] = shp['FED_NUM'].astype(int).astype(str).str.zfill(5)
    names = dict(zip(shp['rn'], zip(shp['ED_NAMEE'], shp['ED_NAMEF'])))
    shp_m = shp.to_crs('EPSG:3347')                      # Statistics Canada Lambert, metres

    off = pd.read_csv(f'{ROOT}/pcfrf_fcpcf_V2209_2021_2013RO.csv', dtype=str)
    rn13 = dict(zip(off['postal_code'], off['riding_number'].str.zfill(5)))

    cur['fsa'] = cur['postal_code'].str[:3]
    cur['lat'] = cur['postal_code'].map(pts['latitude'])
    cur['lon'] = cur['postal_code'].map(pts['longitude'])
    cur['city'] = cur['postal_code'].map(pts['city'])
    flags = defaultdict(list)
    suggest = {}

    med = cur.groupby('fsa').agg(mlat=('lat', 'median'), mlon=('lon', 'median'), n=('postal_code', 'size'))

    # 1. province
    cur['prov_pc'] = cur['postal_code'].str[0].map(LETTER_PROV)
    cur['prov_rn'] = cur['riding_number'].str[:2].map(FED_PROV)
    bad = cur[cur['prov_pc'].notna() & (cur['prov_pc'] != cur['prov_rn'])]
    if len(bad):
        # Reference point: the median of the code's FSA when it has enough codes (the code's own coordinate is
        # usually the wrong one), otherwise the code's own point.
        ref = bad.join(med, on='fsa')
        use_med = ref['n'] >= 5
        rlat = ref['mlat'].where(use_med, ref['lat'])
        rlon = ref['mlon'].where(use_med, ref['lon'])
        g = gpd.GeoDataFrame(bad, geometry=gpd.points_from_xy(rlon, rlat), crs='EPSG:4326').to_crs('EPSG:3347')
        for idx, row in g.iterrows():
            cand = shp_m[shp_m['rn'].str[:2].map(FED_PROV) == row['prov_pc']]
            if cand.empty:
                continue
            best = cand.geometry.distance(row.geometry).idxmin()
            suggest[idx] = cand.loc[best, 'rn']
            flags[idx].append('province: postal code is %s, riding is %s' % (row['prov_pc'], row['prov_rn']))

    # 2. FSA outliers
    fsa_mode = cur.groupby('fsa')['riding_number'].agg(lambda s: s.value_counts().index[0])
    for idx, row in cur.iterrows():
        m = med.loc[row['fsa']]
        # Rural FSAs (second character '0') cover very large areas; distance from the FSA median is not a signal there.
        if m['n'] >= 5 and pd.notna(row['lat']) and row['fsa'][1] != '0':
            d = km(row['lat'], row['lon'], m['mlat'], m['mlon'])
            if d > FSA_KM and fsa_mode[row['fsa']] != row['riding_number']:
                flags[idx].append('fsa: %.0f km from the rest of %s' % (d, row['fsa']))
                suggest.setdefault(idx, fsa_mode[row['fsa']])

    # 3. concordance with the official 2013-RO file
    cur['rn13'] = cur['postal_code'].map(rn13)
    pairs = Counter(zip(cur['rn13'], cur['riding_number']))
    per13 = Counter(cur['rn13'])
    best_by_fsa = cur.groupby(['fsa', 'rn13'])['riding_number'].agg(lambda s: s.value_counts().index[0])
    best_by_13 = cur.groupby('rn13')['riding_number'].agg(lambda s: s.value_counts().index[0])
    for idx, row in cur[cur['rn13'].notna()].iterrows():
        n = pairs[(row['rn13'], row['riding_number'])]
        if n < CONCORD_MIN_COUNT and n / max(1, per13[row['rn13']]) < CONCORD_MIN_SHARE:
            sug = best_by_fsa.get((row['fsa'], row['rn13']), best_by_13.get(row['rn13']))
            if sug and sug != row['riding_number']:
                flags[idx].append('concord: official 2013 riding %s rarely becomes %s' % (row['rn13'], row['riding_number']))
                suggest.setdefault(idx, sug)

    # apply: province fixes automatically, owner corrections from the corrections file
    out = cur.copy()
    manual = 0
    if os.path.exists(CORRECTIONS):
        corr = pd.read_csv(CORRECTIONS, dtype=str).dropna(subset=['postal_code', 'riding_number'])
        pos = {pc: i for i, pc in out['postal_code'].items()}
        for _, c in corr.iterrows():
            i = pos.get(c['postal_code'].replace(' ', '').upper())
            if i is not None:
                out.at[i, 'riding_number'] = c['riding_number'].zfill(5)
                out.at[i, 'weight_information'] = 'M'
                manual += 1
    out['riding_name_en'] = out['riding_number'].map(lambda r: names.get(r, ('', ''))[0])
    out['riding_name_fr'] = out['riding_number'].map(lambda r: names.get(r, ('', ''))[1])
    keep = ['postal_code', 'riding_number', 'riding_name_en', 'riding_name_fr', 'unique_FED', 'pop_weight', 'weight_information']
    out[keep].to_csv(f'{ROOT}/pcbfr_2023_v2.csv', sep='|', index=False, quoting=csv.QUOTE_ALL)

    decided = set()
    if os.path.exists(CORRECTIONS):
        decided = set(pd.read_csv(CORRECTIONS, dtype=str)['postal_code'].str.replace(' ', '').str.upper())
    review = []
    for idx, reasons in flags.items():
        r = cur.loc[idx]
        if r['postal_code'] in decided:
            continue                       # already decided (Elections Canada / owner): not for review
        s = suggest.get(idx, '')
        review.append({
            'postal_code': r['postal_code'], 'city': r['city'], 'lat': r['lat'], 'lon': r['lon'],
            'current_riding': r['riding_number'], 'current_name': names.get(r['riding_number'], ('',))[0],
            'suggested_riding': s, 'suggested_name': names.get(s, ('',))[0] if s else '',
            'official_2013_riding': r['rn13'] or '',
            'auto_applied': 'no',
            'reasons': ' | '.join(reasons), 'severity': len(reasons) + (2 if any(x.startswith('province') for x in reasons) else 0),
        })
    review.sort(key=lambda x: (-x['severity'], x['postal_code']))
    pd.DataFrame(review).to_csv(f'{ROOT}/pcbfr_2023_review.csv', index=False)

    kinds = Counter(x.split(':')[0] for v in flags.values() for x in v)
    summary = (f'codes: {len(cur):,}\nflagged codes: {len(flags):,}\nby check: {dict(kinds)}\n'
               f'province mismatches flagged (not applied): {sum(1 for v in flags.values() if any(x.startswith("province") for x in v))}\n'
               f'decisions applied (M): {manual}\nflagged codes still needing review: {len(review)}\ncodes without official 2013 riding: {cur["rn13"].isna().sum():,}\n')
    open(f'{ROOT}/pcbfr_2023_validation.txt', 'w').write(summary)
    print(summary)


if __name__ == '__main__':
    main()
