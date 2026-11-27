"""Turn a published file into fact rows. Pure functions over bytes, so they are unit-tested without a database.

A row is a dict with the imm_fact dimension columns plus value / suppressed / rounded. Parsers fail loudly on a
column they do not recognise: a publisher changing its layout must stop the load, not silently drop a dimension.
"""

import csv
import io
import re
import zipfile
from datetime import date

MONTHS = {m: i for i, m in enumerate(['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], 1)}

# IRCC open-data column -> imm_fact column. FR_* and EN_QUARTER are redundant with these and ignored.
IRCC_COLUMNS = {
    'EN_YEAR': 'year', 'EN_MONTH': 'month', 'TOTAL': 'value',
    'EN_COUNTRY_OF_CITIZENSHIP': 'country:citizenship', 'EN_COUNTRY_OF_BIRTH': 'country:birth',
    'EN_PROVINCE_TERRITORY': 'province', 'EN_CENSUS_METROPOLITAN_AREA': 'cma',
    'EN_CENSUS_DIVISION': 'census_division', 'EN_CENSUS_SUBDIVISION': 'census_subdiv',
    'EN_IMMIGRATION_CATEGORY-MAIN_CATEGORY': 'cat_main', 'EN_IMMIGRATION_CATEGORY-GROUP': 'cat_group',
    'EN_IMMIGRATION_CATEGORY-COMPONENT': 'cat_component',
    'EN_OCCUPATION': 'occupation', 'EN_GENDER': 'gender', 'EN_AGE': 'age_group',
}
# IRCC's "Country of Citizenship and Immigration Category" file publishes FRENCH labels in its EN_ component column
# (checked 2026-11-26: all 24 values). The English labels below are IRCC's own, from the same component in
# ODP-PR-PT_IMMCAT. Where the country file lumps components (one "Personnes protégées au Canada" for the whole
# protected-person group; "Toute autre immigration" for H&C / public policy / other), the English label says so.
COMPONENT_FR_EN = {
    'Époux ou conjoints de fait parrainés': 'Sponsored Spouse or Partner',
    'Candidats des provinces ou des territoires': 'Provincial Nominee Program',
    'Travailleurs qualifiés': 'Skilled Worker',
    'Catégorie de l’expérience canadienne': 'Canadian Experience',
    'Personnes protégées au Canada': 'Protected Person in Canada (all components)',
    'Parents ou grands-parents parrainés': 'Sponsored Parent or Grandparent',
    'Toute autre immigration': 'All Other Immigration (all components)',
    'Enfants parrainés': 'Sponsored Children',
    'Réfugiés pris en charge par le gouvernement': 'Government-Assisted Refugee',
    'Voie d’accès de la résidence temporaire à la résidence permanente': 'Temporary Resident to Permanent Resident Pathway',
    'Aides familiaux': 'Caregiver',
    'Réfugiés parrainés par le secteur privé': 'Privately Sponsored Refugee',
    'Programmes pilotes d’immigration au Canada atlantique': 'Atlantic Immigration Pilot Programs',
    'Membres de la famille élargie parrainés': 'Sponsored Extended Family Member',
    'Investisseurs': 'Investor',
    'Programme d’immigration dans les communautés rurales et du Nord': 'Rural and Northern Immigration',
    'Métiers spécialisés': 'Skilled Trade',
    'Travailleurs autonomes': 'Self-Employed',
    'Programmes d’immigration au Canada atlantique': 'Atlantic Immigration Programs',
    "Démarrage d'entreprise": 'Start-up Business',
    'Programme de parrainage mixte des réfugiés': 'Blended Sponsorship Refugee',
    'Pilote sur l’immigration agroalimentaire': 'Agri-Food Pilot',
    'Entrepreneurs': 'Entrepreneur',
    'Projet pilote sur la voie d’accès à la mobilité économique fédéral': 'Federal Economic Mobility Pathways Pilot',
}

DIMS = ('population', 'country_basis', 'country_raw', 'province', 'cma', 'census_division', 'census_subdiv',
        'cat_main', 'cat_group', 'cat_component', 'occupation', 'gender', 'age_group')


class LayoutError(ValueError):
    """The file's layout is not what this parser was written for."""


def _canon_header(h: str) -> str:
    return re.sub(r'[\s/]+', '_', h.strip().lstrip('﻿')).upper()


def _int_or_suppressed(raw: str) -> tuple[int | None, int]:
    s = (raw or '').strip().replace(',', '').replace(' ', '').replace(' ', '')
    if s in ('--', '-', '', '..', 'x', 'X'):
        return None, 1
    if not re.fullmatch(r'-?\d+(\.0+)?', s):
        raise LayoutError(f'not a count: {raw!r}')
    return int(float(s)), 0


def parse_ircc_odp(data: bytes, src: dict) -> list[dict]:
    text = data.decode('utf-8-sig')
    reader = csv.reader(io.StringIO(text), delimiter='\t')
    if src.get('header'):
        header = [_canon_header(h) for h in src['header']]
    else:
        header = [_canon_header(h) for h in next(reader)]
    roles = []
    for h in header:
        if h.startswith('FR_') or h == 'EN_QUARTER':
            roles.append(None)
        elif h in IRCC_COLUMNS:
            roles.append(IRCC_COLUMNS[h])
        else:
            raise LayoutError(f"{src['key']}: unknown column {h!r}; header was {header}")
    if 'year' not in roles or 'month' not in roles or 'value' not in roles:
        raise LayoutError(f"{src['key']}: header lacks year/month/value: {header}")
    rows = []
    for n, rec in enumerate(reader, 2):
        if not rec or all(not c.strip() for c in rec):
            continue
        if len(rec) != len(header):
            raise LayoutError(f"{src['key']} line {n}: {len(rec)} fields, header has {len(header)}")
        row = {k: None for k in DIMS}
        row.update(population=src.get('population', 'permanent_residents'), grain='month',
                   rounded=1 if src.get('rounded', True) else 0)
        year = month = None
        for role, cell in zip(roles, rec):
            if role is None:
                continue
            cell = cell.strip()
            if role == 'year':
                year = int(cell)
            elif role == 'month':
                m = MONTHS.get(cell[:3].lower())
                if not m:
                    raise LayoutError(f"{src['key']} line {n}: month {cell!r}")
                month = m
            elif role == 'value':
                row['value'], row['suppressed'] = _int_or_suppressed(cell)
            elif role.startswith('country:'):
                row['country_basis'], row['country_raw'] = role.split(':', 1)[1], cell
            elif role == 'cat_component':
                row[role] = COMPONENT_FR_EN.get(cell, cell) or None
            else:
                row[role] = cell or None
        row['ref_period'] = date(year, month, 1)
        rows.append(row)
    return rows


def parse_statcan_csv(data: bytes, src: dict) -> list[dict]:
    """StatCan full-table CSV (the zip holds <pid>.csv and <pid>_MetaData.csv)."""
    with zipfile.ZipFile(io.BytesIO(data)) as z:
        name = next(n for n in z.namelist() if n.endswith('.csv') and 'MetaData' not in n)
        text = z.read(name).decode('utf-8-sig')
    reader = csv.DictReader(io.StringIO(text))
    member_col, member_role, geo_role = src.get('member_column'), src.get('member_role'), src.get('geo_role')
    if member_col and member_col not in reader.fieldnames:
        raise LayoutError(f"{src['key']}: column {member_col!r} not in {reader.fieldnames}")
    rows = []
    for rec in reader:
        ref = rec['REF_DATE']
        if src['grain'] == 'year':
            ref_period = date(int(ref[:4]), 1, 1)
        else:
            ref_period = date(int(ref[:4]), int(ref[5:7]), 1)
        row = {k: None for k in DIMS}
        row.update(population=src.get('population', 'immigrants'), grain=src['grain'],
                   rounded=1 if src.get('rounded', False) else 0, ref_period=ref_period)
        geo = rec['GEO'].strip()
        if geo_role == 'province':
            row['province'] = None if geo == 'Canada' else geo
        elif geo_role == 'country':
            row['country_basis'], row['country_raw'] = src.get('country_basis'), geo
        if member_col:
            member = rec[member_col].strip()
            if member_role == 'population':
                row['population'] = re.sub(r'[^a-z0-9]+', '_', member.lower()).strip('_')
            elif member_role == 'country':
                row['country_basis'], row['country_raw'] = src.get('country_basis'), member
        if rec.get('STATUS', '').strip() in ('..', 'x', 'F') and not rec['VALUE'].strip():
            row['value'], row['suppressed'] = None, 1
        else:
            row['value'], row['suppressed'] = _int_or_suppressed(rec['VALUE'])
        rows.append(row)
    return rows


def parse_ircc_hist_xls(data: bytes, src: dict) -> tuple[list[dict], dict]:
    """IRCC_PRadmiss_0004: countries down, years across. Returns (rows, control) where control holds the file's
    own 'Total' row so the loader can check the country rows add up to it."""
    import xlrd
    sh = xlrd.open_workbook(file_contents=data).sheet_by_index(0)
    hdr_row = next((r for r in range(min(sh.nrows, 20)) if str(sh.cell_value(r, 0)).strip() == 'Country of Citizenship'), None)
    if hdr_row is None:
        raise LayoutError(f"{src['key']}: no 'Country of Citizenship' header row")
    years = {}
    for c in range(1, sh.ncols):
        v = sh.cell_value(hdr_row, c)
        if isinstance(v, float) and v.is_integer() and 1900 < v < 2100:
            years[c] = int(v)
    rows, control = [], {}
    for r in range(hdr_row + 1, sh.nrows):
        name = str(sh.cell_value(r, 0)).strip()
        if not name or name.startswith(('*', 'Source', 'Note')):
            continue
        for c, y in years.items():
            raw = sh.cell_value(r, c)
            value, sup = (None, 1) if raw in ('', '--', '-') else _int_or_suppressed(str(raw))
            if name == 'Total':
                control[y] = value
                continue
            row = {k: None for k in DIMS}
            row.update(population=src.get('population'), grain='year', ref_period=date(y, 1, 1),
                       country_basis=src.get('country_basis'), country_raw=name, value=value, suppressed=sup,
                       rounded=1 if src.get('rounded', False) else 0)
            rows.append(row)
    return rows, control


# Province / territory column headings in the MP22-1 reports -> the names IRCC open data uses.
MP22_PROVINCES = {
    'nfld.': 'Newfoundland and Labrador', 'p.e.i.': 'Prince Edward Island', 'n.s.': 'Nova Scotia',
    'n.b.': 'New Brunswick', 'que.': 'Quebec', 'ont.': 'Ontario', 'man.': 'Manitoba', 'sask.': 'Saskatchewan',
    'alta.': 'Alberta', 'b.c.': 'British Columbia', 'n.w.t. yukon': 'Northwest Territories and Yukon',
    'yukon': 'Yukon', 'n.w.t.': 'Northwest Territories', 'not stated': 'Not stated',
}
MP22_BASIS = {'residence': 'last_permanent_residence', 'citizenship': 'citizenship', 'birth': 'birth'}


def parse_mp22_csv(data: bytes, src: dict) -> list[dict]:
    """Tables transcribed from the scanned 'Immigration Statistics' reports (MP22-1, 1966-1979), checked row- and
    column-wise against the printed totals (data/mp22/validate.py). Subtotal rows are dropped (they would double
    count); the printed TOTAL row is kept as a country-less control. Period-range columns (e.g. 1946-1955) are not
    single years and are skipped; a single-year column (1964) becomes that year."""
    rows, seen = [], set()
    for block in data.decode('utf-8').split('#FILE ')[1:]:
        name, _, body = block.partition('\n')
        recs = list(csv.DictReader(io.StringIO(body)))
        # In a period table (columns 1946-1955 ... 1970) the 'Canada' column is the WHOLE-PERIOD total, not the
        # report year: reading it as the year put Australia's 1946-1970 total (35,340) into 1970 (really 2,916).
        period_table = any(re.fullmatch(r'\d{4}(\s*-\s*\d{4})?', r['column'].strip()) for r in recs)
        for rec in recs:
            if rec['row_kind'] == 'subtotal':
                continue
            col = rec['column'].strip()
            if period_table and col == 'Canada':
                continue
            year, province = int(rec['year']), None
            if re.fullmatch(r'\d{4}', col):
                year = int(col)
            elif re.fullmatch(r'\d{4}\s*-\s*\d{4}', col):
                continue
            elif col != 'Canada':
                province = MP22_PROVINCES.get(col.lower())
                if province is None:
                    raise LayoutError(f'{name}: unknown column {col!r}')
            basis = MP22_BASIS.get(rec['basis'])
            if basis is None:
                raise LayoutError(f'{name}: unknown basis {rec["basis"]!r}')
            # Footnote markers printed after a label ("Iran (4)", "Philippines (6)") are not part of the name.
            label = re.sub(r'\s*\(\d+\)$', '', rec['row_label'].strip())
            # One table per (year, basis) is the authority for a cell; a second table repeating the same cell is ignored.
            k = (year, basis, label, province)
            if k in seen:
                continue
            seen.add(k)
            row = {k2: None for k2 in DIMS}
            row.update(population='immigrants', grain='year', ref_period=date(year, 1, 1), country_basis=basis,
                       country_raw=None if rec['row_kind'] == 'total' else label, province=province,
                       value=int(rec['value']), suppressed=0, rounded=0)
            if rec['row_kind'] == 'total':
                row['cat_main'] = 'TOTAL (printed)'
            rows.append(row)
    return rows


PARSERS = {'ircc_odp': parse_ircc_odp, 'statcan_csv': parse_statcan_csv, 'ircc_hist_xls': parse_ircc_hist_xls,
           'mp22_csv': parse_mp22_csv}
