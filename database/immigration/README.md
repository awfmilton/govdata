# Immigration data (govdata.ca)

Loads Canadian immigration statistics into `grantconts` (govdata-serving) when they are published, with a
verbatim copy of every download and a log of every revised figure.

## What is loaded

| Source key | Publisher / table | Coverage | Grain | Dimensions |
|---|---|---|---|---|
| `ircc_pr_citz` | IRCC open data `ODP-PR-Citz` | 2015 – latest month | month | country of citizenship |
| `ircc_pr_citz_immcat` | IRCC `ODP-PR-Citz_immcat` | 2015 – | month | country × category (main / group / component) |
| `ircc_pr_pt_immcat` | IRCC `ODP-PR-PT_IMMCAT` | 2015 – | month | province × category |
| `ircc_pr_pt_cma`, `ircc_pr_csd` | IRCC | 2015 – | month | province × CMA; province × census division × subdivision |
| `ircc_pr_pt_noc4` | IRCC | 2015 – | month | province × intended occupation (NOC) |
| `ircc_pr_gender`, `ircc_pr_agegroup` | IRCC | 2015 – | month | province × gender; province × age group |
| `ircc_pr_cob_adoptions` | IRCC | 2015 – | month | adoptions: country of birth × gender |
| `ircc_pr_eng_sp_immcat`, `ircc_pr_fre_sp_*` | IRCC | 2015 – | month | English-speaking (Quebec) / French-speaking (outside Quebec) PRs |
| `ircc_pr_citz_1980_2015` | IRCC `IRCC_PRadmiss_0004` | 1980 – 2015 | year | ~220 countries of citizenship |
| `statcan_17100040` | StatCan 17-10-0040-01 | 1946 – latest quarter | quarter | Canada and provinces × migration component (immigrants, emigrants, …) |
| `statcan_17100010` | StatCan 17-10-0010-01 (archived) | 1955 – 2013 | quarter | ~25 places of last permanent residence |
| `statcan_43100002` | StatCan 43-10-0002-01 (archived) | 1956 – 1976 | year | 32 places of last permanent residence |

Full per-country tables for 1966–1979 exist only in the scanned *Immigration Statistics* reports (MP22-1); they are
extracted separately.

### Caveats you must carry into any chart

- **IRCC open data is rounded to the nearest 5 and values 1–4 are suppressed (`--`).** `rounded = 1`,
  `suppressed = 1`, `value = NULL`. Summing countries undercounts the national total by roughly 0.2–0.5 %; use
  `statcan_17100040` (exact) for national totals.
- **Country bases differ:** IRCC uses *country of citizenship* (or birth, for adoptions); StatCan's historical tables
  use *country of last permanent residence*. They are not the same population and must not be spliced silently.
- **Former states are kept as themselves** (USSR, Yugoslavia, Czechoslovakia, East Germany, Serbia and Montenegro).
  They are never redistributed to successor states.
- IRCC's country × category file publishes **French labels in its English component column**; they are translated to
  IRCC's own English labels on load (`COMPONENT_FR_EN` in `govdata_imm/parsers.py`).
- Calendar years (sum of months/quarters). StatCan's flagship annual table (17-10-0008) uses July–June years and
  differs by more than 2 % in most years.

## Tables

`imm_fact` (one row per published cell), `imm_editions` (each download: sha256, HTTP validators, publisher's modified
time, archived file path), `imm_fact_revisions` (every value a publisher changed, old and new), `imm_fact_removed`
(cells a newer edition dropped — copied, never deleted), `imm_refresh_log` (every check, including "nothing new"),
`imm_country` / `imm_country_alias` (harmonised names), `imm_sources`.

## Running

On govdata-serving, as root:

```
/opt/govdata-imm/run.sh init                   # first time, and after editing country_aliases.csv
/opt/govdata-imm/run.sh refresh                # every enabled source whose publication changed
/opt/govdata-imm/run.sh refresh --source ircc_pr_citz --force
/opt/govdata-imm/run.sh status
```

`run.sh` loads `/etc/govdata-imm/env` (root-only: `IMM_DB_USER`, `IMM_DB_PASS`; the password is also in the
mcp-manager vault) and runs `run.py`. Raw downloads are archived gzip-compressed under `/var/lib/govdata-imm/raw/<source>/`.
A systemd timer (`govdata-imm-refresh.timer`) runs `refresh` daily; a non-zero exit marks the unit failed.

### How a new publication is detected

1. The publisher's own marker: CKAN `metadata_modified` (open.canada.ca), StatCan WDS `releaseTime`, or HTTP
   `ETag`/`Last-Modified`. Unchanged marker + last edition loaded = nothing to do.
2. Otherwise download and compare sha256. Identical bytes = `unchanged`.
3. New bytes: archive, parse, diff against `imm_fact`, record revisions, commit — one transaction per source.

A parser that meets an unknown column stops that source (`LayoutError`) instead of dropping a dimension.

## Setup (once)

```
mysql grantconts < schema.sql                      # as root (auth_socket)
mysql -e "CREATE USER 'imm_load'@'localhost' IDENTIFIED BY '<generated on the server>'"
mysql < grants.sql
```
