"""Country / region harmonisation across sources.

IRCC 2015+ spellings are canonical. Other spellings resolve through imm_country_alias: exact, then normalised
(case, accents, punctuation), then the reviewed list in country_aliases.csv. A spelling that matches nothing is
loaded with country_id NULL and reported by run.py -- it is never guessed.
"""

import csv
import re
import unicodedata
from pathlib import Path

ALIASES_CSV = Path(__file__).resolve().parent.parent / 'country_aliases.csv'

# Labels that are totals, regions or residual groups rather than one country.
_GROUP_PAT = re.compile(r'(^total\b|\btotal$|^outside canada$|\bnes$|^other\b|not stated|^stateless$|^unknown)', re.I)
_REGIONS = {'europe', 'africa', 'asia', 'australasia', 'oceania', 'caribbean', 'south america',
            'north and central america', 'central america', 'north america', 'middle east', 'oceania and other'}


def norm(name: str) -> str:
    s = unicodedata.normalize('NFKD', name or '').encode('ascii', 'ignore').decode().lower()
    s = s.replace('&', ' and ')
    s = re.sub(r'[^a-z0-9]+', ' ', s)
    return re.sub(r'\s+', ' ', s).strip()


def guess_kind(name: str) -> str:
    n = norm(name)
    if n in _REGIONS or (n.startswith('total ') and n[6:] in _REGIONS):
        return 'region'
    if n.startswith('total') or n == 'outside canada':
        return 'other'                       # a total row, not a place
    if _GROUP_PAT.search(n):
        return 'group' if ('nes' in n.split() or n.startswith('other ')) else 'other'
    return 'country'


class CountryResolver:
    def __init__(self, cur):
        self.cur = cur
        self.by_alias: dict[str, int] = {}
        self.by_name: dict[str, int] = {}
        cur.execute('SELECT country_id, name_en FROM imm_country')
        for cid, name in cur.fetchall():
            self.by_name[norm(name)] = cid
        cur.execute('SELECT alias_norm, country_id FROM imm_country_alias')
        for a, cid in cur.fetchall():
            self.by_alias[a] = cid
        self.unmatched: dict[str, int] = {}

    def _create(self, name: str, kind: str, notes: str | None = None) -> int:
        self.cur.execute('INSERT INTO imm_country (name_en, kind, notes) VALUES (%s, %s, %s)', (name, kind, notes))
        cid = self.cur.lastrowid
        self.by_name[norm(name)] = cid
        return cid

    def _alias(self, raw: str, cid: int, source_key: str | None, method: str) -> None:
        n = norm(raw)
        self.cur.execute(
            'INSERT IGNORE INTO imm_country_alias (alias_norm, alias_raw, country_id, source_key, method) '
            'VALUES (%s, %s, %s, %s, %s)', (n, raw, cid, source_key, method))
        self.by_alias[n] = cid

    def apply_reviewed_aliases(self) -> None:
        """Load country_aliases.csv (reviewed by a person). Canonical names are created if absent."""
        with open(ALIASES_CSV, encoding='utf-8', newline='') as f:
            for row in csv.DictReader(f):
                canon = self.by_name.get(norm(row['canonical'])) or self._create(row['canonical'], row['kind'] or 'country', row.get('notes') or None)
                if norm(row['alias']) not in self.by_alias:
                    self._alias(row['alias'], canon, None, 'manual')

    def resolve(self, raw: str | None, source_key: str, canonical_source: bool) -> int | None:
        """canonical_source: this source's spellings may DEFINE new countries (IRCC 2015+ and StatCan groups)."""
        if not raw:
            return None
        n = norm(raw)
        if n in self.by_alias:
            return self.by_alias[n]
        if n in self.by_name:
            cid = self.by_name[n]
            self._alias(raw, cid, source_key, 'exact' if raw.strip() == raw else 'normalised')
            return cid
        if canonical_source:
            cid = self._create(raw.strip(), guess_kind(raw))
            self._alias(raw, cid, source_key, 'exact')
            return cid
        self.unmatched[raw] = self.unmatched.get(raw, 0) + 1
        return None
