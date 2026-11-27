"""Country page payloads (cty_report): one JSON document per country, an index, and the cross-country analysis.

Everything the pages show is computed here, from tables this loader fills, so the site does no arithmetic and a page
can always say which source and which method produced a number.

Immigration, one series per country and year (the series the comparisons use):
  1964-1979  MP22-1 annual reports, national total per country; the basis printed for most countries that year
             (country of last permanent residence, citizenship or birth -- recorded per year)
  1980-2014  IRCC permanent residents by country of citizenship, 1980-2015 table
  2015-      IRCC open data, permanent residents by country of citizenship (monthly, summed; complete years only)
The share of all immigrants that year uses the same publisher's total: StatCan 17-10-0040 for 1964-1979 (equal to
the printed MP22-1 totals) and the IRCC table's own sum after.

Aid: OECD DAC2a, Canada's gross ODA disbursements to the country, constant US dollars. Share = the country's part
of Canada's gross ODA to all individual countries that year.
"""

import json
import math
import random
from collections import defaultdict
from datetime import date, datetime, timezone

WINDOW = 5                    # years before / after an event
MIN_WINDOW_YEARS = 3          # data needed in each window
PERMUTATIONS = 5000
SEED = 20261126
MP_DATA_FROM = 1964           # first year with per-country immigration


def _now():
    return datetime.now(timezone.utc).replace(tzinfo=None)


# ── reads ───────────────────────────────────────────────────────────────────

def countries(cur) -> dict:
    cur.execute('SELECT i.country_id, i.slug, i.display_name, i.iso2, i.iso3, i.notes, c.name_en FROM imm_country_iso i '
                'JOIN imm_country c USING (country_id)')
    return {r[0]: {'id': r[0], 'slug': r[1], 'name': r[2], 'iso2': r[3], 'iso3': r[4], 'notes': r[5], 'source_name': r[6],
                   'historical': r[3] is None} for r in cur.fetchall()}


NATIONAL = ('province IS NULL AND cma IS NULL AND census_division IS NULL AND census_subdiv IS NULL AND cat_main IS NULL '
            'AND cat_group IS NULL AND cat_component IS NULL AND occupation IS NULL AND gender IS NULL AND age_group IS NULL')


def immigration(cur, ids: set):
    """-> (value[cid][year], basis[year], total[year], by_basis[cid][basis][year], complete_last_year)"""
    by_basis = defaultdict(lambda: defaultdict(dict))
    cur.execute(f"SELECT YEAR(ref_period), country_basis, country_id, value FROM imm_fact WHERE source_key='mp22_1966_1979' "
                f"AND {NATIONAL} AND country_id IS NOT NULL AND value IS NOT NULL")
    count_by = defaultdict(int)
    for y, b, cid, v in cur.fetchall():
        if cid in ids:
            by_basis[cid][b][y] = v
            count_by[(y, b)] += 1
    basis = {}
    for y in range(1964, 1980):
        cands = [(count_by.get((y, b), 0), b) for b in ('last_permanent_residence', 'citizenship', 'birth')]
        n, b = max(cands)
        if n:
            basis[y] = b
    value = defaultdict(dict)
    for cid, bb in by_basis.items():
        for y, b in basis.items():
            if y in bb.get(b, {}):
                value[cid][y] = bb[b][y]
    total = {}
    cur.execute("SELECT YEAR(ref_period), SUM(value) FROM imm_fact WHERE source_key='statcan_17100040' AND population='immigrants' "
                "AND country_id IS NULL AND province IS NULL AND YEAR(ref_period) BETWEEN 1964 AND 1979 GROUP BY 1")
    for y, v in cur.fetchall():
        total[y] = int(v)
    cur.execute(f"SELECT YEAR(ref_period), country_id, SUM(value) FROM imm_fact WHERE source_key='ircc_pr_citz_1980_2015' "
                f"AND {NATIONAL} AND YEAR(ref_period) < 2015 GROUP BY 1, 2")
    for y, cid, v in cur.fetchall():
        total[y] = total.get(y, 0) + int(v or 0)
        if cid in ids:
            value[cid][y] = int(v or 0)
        basis[y] = 'citizenship'
    cur.execute("SELECT YEAR(ref_period), COUNT(DISTINCT MONTH(ref_period)) FROM imm_fact WHERE source_key='ircc_pr_citz' GROUP BY 1")
    complete = sorted(y for y, m in cur.fetchall() if m == 12)
    cur.execute(f"SELECT YEAR(ref_period), country_id, SUM(value) FROM imm_fact WHERE source_key='ircc_pr_citz' AND {NATIONAL} "
                f"GROUP BY 1, 2")
    for y, cid, v in cur.fetchall():
        if y not in complete:
            continue
        total[y] = total.get(y, 0) + int(v or 0)
        if cid in ids:
            value[cid][y] = int(v or 0)
        basis[y] = 'citizenship'
    return value, basis, total, by_basis, max(complete)


def immigration_detail(cur, last_year: int) -> dict:
    """Per country: category mix for the last 5 complete years, French-speaking PRs, adoptions, last 24 months."""
    out = defaultdict(lambda: {'categories': {}, 'francophone': {}, 'adoptions': {}, 'monthly': {}})
    y0 = last_year - 4
    cur.execute("SELECT country_id, cat_main, SUM(value) FROM imm_fact WHERE source_key='ircc_pr_citz_immcat' AND country_id IS NOT NULL "
                "AND YEAR(ref_period) BETWEEN %s AND %s GROUP BY 1, 2", (y0, last_year))
    canada = defaultdict(int)
    for cid, cat, v in cur.fetchall():
        out[cid]['categories'][cat or 'Not stated'] = int(v or 0)
        canada[cat or 'Not stated'] += int(v or 0)
    for key, src in (('francophone', 'ircc_pr_fre_sp_citz'), ('adoptions', 'ircc_pr_cob_adoptions')):
        cur.execute(f"SELECT country_id, YEAR(ref_period), SUM(value) FROM imm_fact WHERE source_key=%s AND country_id IS NOT NULL "
                    f"GROUP BY 1, 2", (src,))
        for cid, y, v in cur.fetchall():
            out[cid][key][y] = int(v or 0)
    cur.execute(f"SELECT country_id, DATE_FORMAT(ref_period, '%%Y-%%m'), SUM(value) FROM imm_fact WHERE source_key='ircc_pr_citz' "
                f"AND {NATIONAL} AND country_id IS NOT NULL AND ref_period >= (SELECT DATE_SUB(MAX(ref_period), INTERVAL 23 MONTH) "
                f"FROM imm_fact WHERE source_key='ircc_pr_citz') GROUP BY 1, 2")
    for cid, ym, v in cur.fetchall():
        out[cid]['monthly'][ym] = int(v or 0)
    return out, dict(canada), (y0, last_year)


def aid(cur) -> tuple:
    """-> (series[cid][year] = {gross, net, grants, humanitarian, gross_current}, base_year, total_gross[year])"""
    names = {('240', 'Q'): 'gross', ('206', 'Q'): 'net', ('201', 'Q'): 'grants', ('216', 'Q'): 'humanitarian',
             ('240', 'V'): 'gross_current', ('206', 'V'): 'net_current'}
    cur.execute('SELECT country_id, measure, price_base, year, usd_millions, base_year FROM cty_oda WHERE country_id IS NOT NULL')
    series = defaultdict(lambda: defaultdict(dict))
    base, total = None, defaultdict(float)
    for cid, m, pb, y, v, by in cur.fetchall():
        k = names.get((m, pb))
        if k is None or v is None:
            continue
        series[cid][y][k] = round(float(v), 4)
        if k == 'gross':
            total[y] += float(v)
            base = base or by
    return series, base, dict(total)


def grants(cur) -> dict:
    out = defaultdict(lambda: {'by_year': [], 'programs': [], 'departments': [], 'total': 0.0, 'count': 0})
    cur.execute("SELECT UPPER(TRIM(recipient_country)), fiscal_year, SUM(GREATEST(agreement_value, 0)), COUNT(*) FROM master_table "
                "WHERE amendment_number IS NULL AND recipient_country IS NOT NULL AND UPPER(TRIM(recipient_country)) <> 'CA' "
                "GROUP BY 1, 2")
    for cc, fy, v, n in cur.fetchall():
        o = out[cc]
        o['by_year'].append([fy or 'not stated', round(float(v or 0), 2), int(n)])
        o['total'] += float(v or 0)
        o['count'] += int(n)
    for col, key in (('prog_name_en', 'programs'), ('owner_org_title', 'departments')):
        cur.execute(f"SELECT UPPER(TRIM(recipient_country)), {col}, SUM(GREATEST(agreement_value, 0)), COUNT(*) FROM master_table "
                    f"WHERE amendment_number IS NULL AND recipient_country IS NOT NULL AND UPPER(TRIM(recipient_country)) <> 'CA' "
                    f"AND {col} IS NOT NULL AND {col} <> '' GROUP BY 1, 2")
        rows = defaultdict(list)
        for cc, name, v, n in cur.fetchall():
            rows[cc].append([name, round(float(v or 0), 2), int(n)])
        for cc, lst in rows.items():
            out[cc][key] = sorted(lst, key=lambda r: -r[1])[:10]
    for o in out.values():
        o['by_year'].sort()
        o['total'] = round(o['total'], 2)
    return out


def parliamentarians(cur, current_from: date) -> dict:
    cur.execute('SELECT wikidata_qid, name, lop_person_id, birth_date, birth_place, birth_country_label, country_id, was_mp, '
                'was_senator, first_mp_start, last_mp_end, first_senate_start, last_senate_end, terms_json, vote_person_id '
                'FROM parl_person_birth WHERE country_id IS NOT NULL')
    cur2_ids = {}
    out = defaultdict(list)
    for r in cur.fetchall():
        p = {'qid': r[0], 'name': r[1], 'lop': r[2], 'birth_date': str(r[3]) if r[3] else None, 'birth_place': r[4],
             'birth_country': r[5], 'mp': bool(r[7]), 'senator': bool(r[8]),
             'mp_from': str(r[9]) if r[9] else None, 'mp_to': str(r[10]) if r[10] else None,
             'senate_from': str(r[11]) if r[11] else None, 'senate_to': str(r[12]) if r[12] else None,
             'terms': json.loads(r[13] or '[]'), 'vote_person_id': r[14]}
        out[r[6]].append(p)
        if r[14]:
            cur2_ids[r[14]] = p
    # Who still votes: the newest roll call. A Wikidata term with no end date is "sitting" only for these.
    cur.execute('SELECT DISTINCT person_id FROM parl_division_votes v JOIN parl_divisions d USING (parliament, session, division_no) '
                'WHERE d.held_at >= %s', (current_from,))
    sitting_now = {r[0] for r in cur.fetchall()}
    for p in cur2_ids.values():
        p['sitting'] = p['vote_person_id'] in sitting_now
    return out


def mp_years(p: dict, last_year: int) -> set:
    """Calendar years in which this person sat in the House. Wikidata terms without an end date end at the next term,
    or now for a member still voting, or four years later (one Parliament) otherwise."""
    terms = sorted([t for t in p['terms'] if t[0] == 'mp' and t[1]], key=lambda t: t[1])
    years = set()
    for i, (_, s, e) in enumerate(terms):
        y0 = int(s[:4])
        if e:
            y1 = int(e[:4])
        elif i + 1 < len(terms):
            y1 = int(terms[i + 1][1][:4])
        elif p.get('sitting'):
            y1 = last_year
        else:
            y1 = y0 + 4
        years.update(range(y0, min(y1, last_year) + 1))
    return years


def laws(cur) -> dict:
    cur.execute("SELECT country_id, ref_kind, ref_id, title, topic, official_number, current_to, url FROM cty_law_tags "
                "WHERE review_status <> 'rejected' ORDER BY ref_kind, title")
    out = defaultdict(lambda: {'laws': [], 'bills': []})
    bill_ids = set()
    for cid, kind, ref, title, topic, num, cur_to, url in cur.fetchall():
        row = {'kind': kind, 'id': ref, 'title': title, 'topic': topic, 'number': num, 'url': url,
               'current_to': str(cur_to) if cur_to else None}
        out[cid]['bills' if kind == 'bill' else 'laws'].append(row)
        if kind == 'bill':
            bill_ids.add(int(ref))
    return out, bill_ids


def bill_details(cur, bill_ids: set) -> tuple[dict, dict]:
    if not bill_ids:
        return {}, {}
    ids = sorted(bill_ids)
    ph = ','.join(['%s'] * len(ids))
    cur.execute(f'SELECT legisinfo_id, parliament, session, number_code, status_en, royal_assent, sponsor_name, house_1r '
                f'FROM parl_bills WHERE legisinfo_id IN ({ph})', ids)
    bills = {r[0]: {'session': f'{r[1]}-{r[2]}', 'number': r[3], 'status': r[4], 'royal_assent': str(r[5])[:10] if r[5] else None,
                    'sponsor': r[6], 'introduced': str(r[7])[:10] if r[7] else None} for r in cur.fetchall()}
    cur.execute(f'SELECT legisinfo_id, parliament, session, division_no, held_at, subject, result, yeas, nays FROM parl_divisions '
                f'WHERE legisinfo_id IN ({ph}) ORDER BY held_at', ids)
    divs = defaultdict(list)
    for bid, p, s, no, at, subj, res, y, n in cur.fetchall():
        divs[bid].append({'key': [p, s, no], 'date': str(at)[:10], 'subject': subj[:300], 'result': res, 'yeas': y, 'nays': n})
    return bills, divs


def votes_of(cur, person_ids: set, division_keys: list) -> dict:
    """{(p, s, no): {person_id: 'Yea'|'Nay'|...}} for the given people on the given divisions."""
    if not person_ids or not division_keys:
        return {}
    out = defaultdict(dict)
    pids = sorted(person_ids)
    for i in range(0, len(division_keys), 300):
        chunk = division_keys[i:i + 300]
        cond = ' OR '.join(['(parliament=%s AND session=%s AND division_no=%s)'] * len(chunk))
        args = [x for k in chunk for x in k] + pids
        cur.execute(f"SELECT parliament, session, division_no, person_id, vote FROM parl_division_votes WHERE ({cond}) "
                    f"AND person_id IN ({','.join(['%s'] * len(pids))})", args)
        for p, s, no, pid, v in cur.fetchall():
            out[(p, s, no)][pid] = v
    return out


def immigration_loyalty(cur) -> tuple[dict, float, int]:
    """On divisions about immigration bills: how often each MP voted with the majority of their own caucus."""
    cur.execute("SELECT v.parliament, v.session, v.division_no, v.person_id, v.caucus, v.is_yea, v.is_nay FROM parl_division_votes v "
                "JOIN parl_divisions d USING (parliament, session, division_no) "
                "JOIN imm_bill_tags t ON t.legisinfo_id = d.legisinfo_id AND t.review_status <> 'rejected' "
                "WHERE v.is_yea = 1 OR v.is_nay = 1")
    rows = cur.fetchall()
    tally = defaultdict(lambda: [0, 0])
    for p, s, no, pid, caucus, yea, nay in rows:
        tally[(p, s, no, caucus)][0 if yea else 1] += 1
    per = defaultdict(lambda: [0, 0])
    divs = set()
    for p, s, no, pid, caucus, yea, nay in rows:
        y, n = tally[(p, s, no, caucus)]
        if y == n:
            continue
        majority_yea = y > n
        per[pid][1] += 1
        per[pid][0] += int(bool(yea) == majority_yea)
        divs.add((p, s, no))
    agree = sum(a for a, _ in per.values())
    total = sum(n for _, n in per.values())
    return {pid: (a, n) for pid, (a, n) in per.items()}, (agree / total if total else None), len(divs)


# ── statistics (no third-party packages on the loader host) ─────────────────

def _mean(xs):
    return sum(xs) / len(xs) if xs else None


def _median(xs):
    xs = sorted(xs)
    if not xs:
        return None
    m = len(xs) // 2
    return xs[m] if len(xs) % 2 else (xs[m - 1] + xs[m]) / 2


def _window_ratio(series: dict, total: dict, t: int):
    """log(mean share after) - log(mean share before), and the same for the 5 years before that (pre-trend)."""
    def share(y):
        if y in series and total.get(y):
            return series[y] / total[y]
        return None

    def wmean(a, b):
        xs = [share(y) for y in range(a, b + 1)]
        xs = [x for x in xs if x is not None]
        return _mean(xs) if len(xs) >= MIN_WINDOW_YEARS else None

    pre, post, early = wmean(t - WINDOW, t - 1), wmean(t + 1, t + WINDOW), wmean(t - 2 * WINDOW, t - WINDOW - 1)
    if pre is None or post is None or pre <= 0 or post <= 0:     # gross ODA can net to a negative after recoveries
        return None
    return {'pre_share': pre, 'post_share': post, 'delta': math.log(post / pre),
            'pretrend': math.log(pre / early) if early and early > 0 else None}


def _pct(delta):
    return None if delta is None else round((math.exp(delta) - 1) * 100, 1)


def event_study(series_by_c: dict, total: dict, events: list, quiet: dict, years: range) -> dict:
    """events: [(cid, year)]; quiet[cid] = set of years with an MP born there sitting. The placebo pool is every
    other (country, year) with no such MP within the window."""
    ev = []
    for cid, t in events:
        r = _window_ratio(series_by_c.get(cid, {}), total, t)
        if r:
            ev.append((cid, t, r))
    pool = []
    for cid, s in series_by_c.items():
        busy = quiet.get(cid, set())
        for t in years:
            if any(y in busy for y in range(t - WINDOW, t + WINDOW + 1)):
                continue
            r = _window_ratio(s, total, t)
            if r:
                pool.append(r)
    if not ev or len(pool) < 50:
        return {'events': [], 'n_events': len(ev), 'n_placebo': len(pool)}
    pdeltas = sorted(p['delta'] for p in pool)
    obs = _mean([r['delta'] for _, _, r in ev])
    base = _mean(pdeltas)
    rng = random.Random(SEED)
    k = len(ev)
    extreme = sum(1 for _ in range(PERMUTATIONS) if abs(_mean(rng.sample(pdeltas, k) if k <= len(pdeltas) else rng.choices(pdeltas, k=k)) - base) >= abs(obs - base))
    pre_ev = [r['pretrend'] for _, _, r in ev if r['pretrend'] is not None]
    pre_pool = [p['pretrend'] for p in pool if p['pretrend'] is not None]

    def pctile(d):
        lo = sum(1 for x in pdeltas if x < d)
        return round(100 * lo / len(pdeltas))

    return {
        'n_events': k, 'n_placebo': len(pool),
        'event_mean_pct': _pct(obs), 'placebo_mean_pct': _pct(base), 'difference_pct': _pct(obs - base),
        'p_value': round((extreme + 1) / (PERMUTATIONS + 1), 4),
        # Medians: one country's founding or a refugee crisis (Croatia 1993, Syria 2015) can move a mean by itself.
        'event_median_pct': _pct(_median([r['delta'] for _, _, r in ev])), 'placebo_median_pct': _pct(_median(pdeltas)),
        'share_up_pct': round(100 * sum(1 for _, _, r in ev if r['delta'] > 0) / k, 1),
        'placebo_share_up_pct': round(100 * sum(1 for d in pdeltas if d > 0) / len(pdeltas), 1),
        'event_pretrend_pct': _pct(_mean(pre_ev)), 'placebo_pretrend_pct': _pct(_mean(pre_pool)),
        'events': [{'country_id': cid, 'year': t, 'pre_share': r['pre_share'], 'post_share': r['post_share'],
                    'change_pct': _pct(r['delta']), 'pretrend_pct': _pct(r['pretrend']), 'percentile': pctile(r['delta'])}
                   for cid, t, r in ev],
    }


def two_way_fe(series_by_c: dict, total: dict, x_by_c: dict, years: range, lead: int = 0) -> dict | None:
    """log(share) on 'an MP born there sits in year t+lead', with country and year fixed effects; standard error
    clustered by country. lead > 0 asks whether FUTURE MPs 'predict' today's share (it should not if MPs cause it)."""
    obs = []
    for cid, s in series_by_c.items():
        xs = x_by_c.get(cid, set())
        for y in years:
            if y in s and s[y] > 0 and total.get(y):
                obs.append([cid, y, 1.0 if (y + lead) in xs else 0.0, math.log(s[y] / total[y])])
    if len(obs) < 200 or not any(o[2] for o in obs):
        return None
    xd = [o[2] for o in obs]
    yd = [o[3] for o in obs]
    for _ in range(60):                                       # alternating projections
        for idx in (0, 1):
            for arr in (xd, yd):
                g = defaultdict(lambda: [0.0, 0])
                for o, v in zip(obs, arr):
                    g[o[idx]][0] += v
                    g[o[idx]][1] += 1
                for i, o in enumerate(obs):
                    s_, n_ = g[o[idx]]
                    arr[i] -= s_ / n_
    sxx = sum(x * x for x in xd)
    if sxx <= 1e-9:
        return None
    beta = sum(x * y for x, y in zip(xd, yd)) / sxx
    cl = defaultdict(float)
    for o, x, y in zip(obs, xd, yd):
        cl[o[0]] += x * (y - beta * x)
    se = math.sqrt(sum(v * v for v in cl.values())) / sxx
    return {'beta': round(beta, 4), 'se': round(se, 4), 'pct': _pct(beta), 'n_obs': len(obs), 'n_countries': len(cl),
            'z': round(beta / se, 2) if se else None}


def lead_lag(a: dict, b: dict) -> list:
    """Correlation of year-on-year log changes: lag k = aid in year t vs immigration in year t+k."""
    def dlog(s):
        return {y: math.log(s[y] / s[y - 1]) for y in s if (y - 1) in s and s[y] > 0 and s[y - 1] > 0}
    da, db = dlog(a), dlog(b)
    out = []
    for k in range(-3, 4):
        pairs = [(da[y], db[y + k]) for y in da if (y + k) in db]
        if len(pairs) < 10:
            continue
        xs, ys = zip(*pairs)
        mx, my = _mean(xs), _mean(ys)
        vx = sum((x - mx) ** 2 for x in xs)
        vy = sum((y - my) ** 2 for y in ys)
        if vx and vy:
            out.append([k, round(sum((x - mx) * (y - my) for x, y in pairs) / math.sqrt(vx * vy), 3), len(pairs)])
    return out


def spearman(pairs: list) -> float | None:
    """Rank correlation (average ranks for ties)."""
    if len(pairs) < 10:
        return None

    def ranks(vals):
        order = sorted(range(len(vals)), key=lambda i: vals[i])
        r = [0.0] * len(vals)
        i = 0
        while i < len(order):
            j = i
            while j + 1 < len(order) and vals[order[j + 1]] == vals[order[i]]:
                j += 1
            for k in range(i, j + 1):
                r[order[k]] = (i + j) / 2 + 1
            i = j + 1
        return r
    a, b = ranks([p[0] for p in pairs]), ranks([p[1] for p in pairs])
    ma, mb = _mean(a), _mean(b)
    cov = sum((x - ma) * (y - mb) for x, y in zip(a, b))
    va, vb = sum((x - ma) ** 2 for x in a), sum((y - mb) ** 2 for y in b)
    return round(cov / math.sqrt(va * vb), 3) if va and vb else None


# ── build ───────────────────────────────────────────────────────────────────

def build_all(cur) -> dict:
    C = countries(cur)
    ids = set(C)
    imm, basis, total, by_basis, last_full = immigration(cur, ids)
    detail, canada_mix, mix_years = immigration_detail(cur, last_full)
    aid_s, aid_base, aid_total = aid(cur)
    aid_last = max(aid_total) if aid_total else None
    g = grants(cur)
    cur.execute('SELECT MAX(held_at) FROM parl_divisions')
    newest_vote = cur.fetchone()[0]
    people = parliamentarians(cur, (newest_vote.date() if newest_vote else date.today()).replace(day=1))
    law, bill_ids = laws(cur)
    bills, divs = bill_details(cur, bill_ids)
    loyalty, loyalty_avg, loyalty_divs = immigration_loyalty(cur)
    now_year = date.today().year

    # Sitting House members born in each country, by year; events = a year the count rises from zero.
    sitting = defaultdict(lambda: defaultdict(list))
    for cid, plist in people.items():
        for p in plist:
            if p['mp']:
                for y in mp_years(p, now_year):
                    sitting[cid][y].append(p['name'])
    sit_years = {cid: set(ys) for cid, ys in sitting.items()}
    imm_events = [(cid, y) for cid, ys in sit_years.items() for y in sorted(ys)
                  if (y - 1) not in ys and MP_DATA_FROM + WINDOW <= y <= last_full - WINDOW]
    aid_events = [(cid, y) for cid, ys in sit_years.items() for y in sorted(ys)
                  if (y - 1) not in ys and aid_last and 1960 + WINDOW <= y <= aid_last - WINDOW]
    aid_gross = {cid: {y: v['gross'] for y, v in s.items() if 'gross' in v} for cid, s in aid_s.items()}
    imm_years = range(MP_DATA_FROM + WINDOW, last_full - WINDOW + 1)
    aid_years = range(1960 + WINDOW, (aid_last or 1960) - WINDOW + 1)
    imm_study = event_study(imm, total, imm_events, sit_years, imm_years)
    aid_study = event_study(aid_gross, aid_total, aid_events, sit_years, aid_years)
    fe = {
        'immigration': two_way_fe(imm, total, sit_years, range(MP_DATA_FROM, last_full + 1)),
        'immigration_lead3': two_way_fe(imm, total, sit_years, range(MP_DATA_FROM, last_full + 1), lead=3),
        'aid': two_way_fe(aid_gross, aid_total, sit_years, range(1960, (aid_last or 1960) + 1)),
        'aid_lead3': two_way_fe(aid_gross, aid_total, sit_years, range(1960, (aid_last or 1960) + 1), lead=3),
    }

    # Ranks over the last 10 complete years.
    def last10(series, last):
        return sum(v for y, v in series.items() if last - 9 <= y <= last)
    imm10 = {cid: last10(imm.get(cid, {}), last_full) for cid in ids}
    aid10 = {cid: last10(aid_gross.get(cid, {}), aid_last) for cid in ids} if aid_last else {}
    rank = lambda d: {cid: i + 1 for i, (cid, v) in enumerate(sorted(((c, v) for c, v in d.items() if v > 0), key=lambda t: -t[1]))}  # noqa: E731
    imm_rank, aid_rank = rank(imm10), rank(aid10)
    imm_since = {cid: sum(imm.get(cid, {}).values()) for cid in ids}
    mps_since = {cid: sum(1 for p in people.get(cid, []) if p['mp'] and p['mp_from'] and int(p['mp_from'][:4]) >= MP_DATA_FROM + 4)
                 for cid in ids}
    tot_imm_since = sum(imm_since.values())
    tot_mps_since = sum(mps_since.values())
    rep_avg = tot_mps_since / tot_imm_since * 1e5 if tot_imm_since else None

    # Votes of members born in each country on the bills that name it.
    vote_people = {p['vote_person_id'] for plist in people.values() for p in plist if p.get('vote_person_id')}
    all_div_keys = [tuple(d['key']) for lst in divs.values() for d in lst]
    vmap = votes_of(cur, vote_people, all_div_keys)

    imm_ev_by_c = defaultdict(list)
    for e in imm_study.get('events', []):
        imm_ev_by_c[e['country_id']].append(e)
    aid_ev_by_c = defaultdict(list)
    for e in aid_study.get('events', []):
        aid_ev_by_c[e['country_id']].append(e)

    generated = _now().replace(microsecond=0)        # DATETIME has no fraction; the stale-row DELETE compares to it
    index_rows = []
    written = 0
    for cid, c in C.items():
        s = imm.get(cid, {})
        a = aid_s.get(cid, {})
        gr = g.get(c['iso2']) if c['iso2'] else None
        plist = sorted(people.get(cid, []), key=lambda p: (p['mp_from'] or p['senate_from'] or '9999'))
        for p in plist:
            if p.get('vote_person_id') and p['vote_person_id'] in loyalty:
                agree, n = loyalty[p['vote_person_id']]
                p['immigration_votes'] = {'n': n, 'with_caucus_pct': round(100 * agree / n, 1)}
        born_ids = {p['vote_person_id']: p['name'] for p in plist if p.get('vote_person_id')}
        lw = law.get(cid, {'laws': [], 'bills': []})
        bill_rows = []
        for b in lw['bills']:
            info = bills.get(int(b['id']), {})
            dl = []
            for d in divs.get(int(b['id']), []):
                k = tuple(d['key'])
                dl.append({**d, 'born_here': [[born_ids[pid], v] for pid, v in vmap.get(k, {}).items() if pid in born_ids]})
            bill_rows.append({**b, **info, 'divisions': dl})
        events = []
        for e in imm_ev_by_c.get(cid, []):
            events.append({'year': e['year'], 'members': sorted(set(sitting[cid][e['year']])), 'immigration': e,
                           'aid': next((x for x in aid_ev_by_c.get(cid, []) if x['year'] == e['year']), None)})
        for e in aid_ev_by_c.get(cid, []):
            if not any(x['year'] == e['year'] for x in events):
                events.append({'year': e['year'], 'members': sorted(set(sitting[cid][e['year']])), 'immigration': None, 'aid': e})
        events.sort(key=lambda e: e['year'])
        d = detail.get(cid, {})
        cats = d.get('categories', {}) if d else {}
        ctot, ntot = sum(cats.values()), sum(canada_mix.values())
        aid_tot = sum(v.get('gross', 0) for v in a.values())
        overlap_imm = sum(v for y, v in s.items() if y in a and a[y].get('gross'))
        overlap_aid = sum(a[y]['gross'] for y in s if y in a and a[y].get('gross'))
        n_mps = sum(1 for p in plist if p['mp'])
        n_sen = sum(1 for p in plist if p['senator'])
        payload = {
            'country': c, 'generated_at': generated.isoformat() + 'Z',
            'immigration': {
                'series': [[y, s[y], round(s[y] / total[y], 6) if total.get(y) else None, basis.get(y)] for y in sorted(s)],
                'last_full_year': last_full, 'last10': imm10[cid], 'rank_last10': imm_rank.get(cid),
                'since_1964': imm_since[cid],
                'mp22_bases': {b: sorted(v.items()) for b, v in by_basis.get(cid, {}).items()},
                'monthly_24': sorted(d.get('monthly', {}).items()) if d else [],
                'categories': {'years': list(mix_years), 'mix': sorted(
                    [[k, v, round(100 * v / ctot, 1) if ctot else None, round(100 * canada_mix.get(k, 0) / ntot, 1) if ntot else None]
                     for k, v in cats.items()], key=lambda r: -r[1])},
                'francophone': sorted(d.get('francophone', {}).items()) if d else [],
                'adoptions': sorted(d.get('adoptions', {}).items()) if d else [],
            },
            'aid': {
                'base_year': aid_base, 'last_year': aid_last,
                'series': [[y, v.get('gross'), v.get('net'), v.get('grants'), v.get('humanitarian'), v.get('gross_current')]
                           for y, v in sorted(a.items())],
                'share': [[y, round(v['gross'] / aid_total[y], 6)] for y, v in sorted(a.items()) if v.get('gross') is not None and aid_total.get(y)],
                'last10_gross': round(aid10.get(cid, 0), 3) if aid10 else None, 'rank_last10': aid_rank.get(cid),
                'total_gross': round(aid_tot, 3),
                'immigrants_per_musd': round(overlap_imm / overlap_aid, 1) if overlap_aid > 0 else None,
                'lead_lag': lead_lag(aid_gross.get(cid, {}), s),
            },
            'grants': gr,
            'parliamentarians': plist,
            'representation': {'mps_since_1968': mps_since[cid], 'immigrants_since_1964': imm_since[cid],
                               'per_100k': round(mps_since[cid] / imm_since[cid] * 1e5, 2) if imm_since[cid] else None,
                               'all_countries_per_100k': round(rep_avg, 2) if rep_avg else None},
            'sitting_by_year': sorted([y, len(set(n))] for y, n in sitting.get(cid, {}).items()),
            'events': events,
            'laws': lw['laws'], 'bills': bill_rows,
            'immigration_vote_baseline': {'with_caucus_pct': round(100 * loyalty_avg, 1) if loyalty_avg else None,
                                          'divisions': loyalty_divs},
        }
        has_data = bool(s or a or gr or plist or lw['laws'] or lw['bills'])
        if not has_data:
            continue
        cur.execute('REPLACE INTO cty_report (report_key, country_id, payload, generated_at) VALUES (%s,%s,%s,%s)',
                    ('country:' + c['slug'], cid, json.dumps(payload, separators=(',', ':'), default=str), generated))
        written += 1
        index_rows.append({
            'slug': c['slug'], 'name': c['name'], 'iso2': c['iso2'], 'historical': c['historical'],
            'imm_last10': imm10[cid], 'imm_rank': imm_rank.get(cid), 'imm_since_1964': imm_since[cid],
            'aid_last10': round(aid10.get(cid, 0), 2) if aid10 else None, 'aid_rank': aid_rank.get(cid),
            'grants_total': gr['total'] if gr else 0, 'mps': n_mps, 'senators': n_sen,
            'laws': len(lw['laws']), 'bills': len(lw['bills']),
            'events': len(events),
        })

    name = {cid: c['name'] for cid, c in C.items()}
    slug = {cid: c['slug'] for cid, c in C.items()}
    for st in (imm_study, aid_study):
        for e in st.get('events', []):
            e['country'] = name[e['country_id']]
            e['slug'] = slug[e['country_id']]
            e['members'] = sorted(set(sitting[e['country_id']][e['year']]))
    analysis = {
        'generated_at': generated.isoformat() + 'Z',
        'window_years': WINDOW, 'min_window_years': MIN_WINDOW_YEARS, 'permutations': PERMUTATIONS,
        'immigration_years': [MP_DATA_FROM, last_full], 'aid_years': [1960, aid_last], 'aid_base_year': aid_base,
        'immigration': imm_study, 'aid': aid_study, 'fixed_effects': fe,
        'immigration_vote_baseline': {'with_caucus_pct': round(100 * loyalty_avg, 1) if loyalty_avg else None, 'divisions': loyalty_divs},
        'representation_avg_per_100k': round(rep_avg, 2) if rep_avg else None,
        'scatter': [[r['slug'], r['name'], r['imm_last10'], r['aid_last10'], r['mps']] for r in index_rows
                    if r['imm_last10'] and r['aid_last10']],
    }
    analysis['scatter_spearman'] = spearman([(r[2], r[3]) for r in analysis['scatter']])
    analysis['scatter_n'] = len(analysis['scatter'])
    totals = {'countries': len(index_rows), 'immigration_last_full_year': last_full, 'aid_last_year': aid_last,
              'aid_base_year': aid_base,
              'parliamentarians_born_abroad': sum(r['mps'] + r['senators'] for r in index_rows),
              'laws': sum(r['laws'] for r in index_rows), 'bills': sum(r['bills'] for r in index_rows)}
    cur.execute('REPLACE INTO cty_report (report_key, country_id, payload, generated_at) VALUES (%s,%s,%s,%s)',
                ('index', None, json.dumps({'generated_at': generated.isoformat() + 'Z', 'totals': totals,
                                            'countries': sorted(index_rows, key=lambda r: r['name'])}, separators=(',', ':')), generated))
    cur.execute('REPLACE INTO cty_report (report_key, country_id, payload, generated_at) VALUES (%s,%s,%s,%s)',
                ('analysis', None, json.dumps(analysis, separators=(',', ':'), default=str), generated))
    cur.execute('DELETE FROM cty_report WHERE generated_at < %s', (generated,))
    return {'country_pages': written, 'imm_events': imm_study.get('n_events'), 'aid_events': aid_study.get('n_events'),
            'imm_diff_pct': imm_study.get('difference_pct'), 'imm_p': imm_study.get('p_value'),
            'aid_diff_pct': aid_study.get('difference_pct'), 'aid_p': aid_study.get('p_value'),
            'fe_imm': fe['immigration'], 'fe_aid': fe['aid']}
