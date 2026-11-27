"""Runtime configuration, read from the environment (systemd EnvironmentFile on the loader host)."""

import json
import os
from dataclasses import dataclass
from pathlib import Path

HERE = Path(__file__).resolve().parent.parent          # database/immigration


@dataclass(frozen=True)
class Config:
    db_host: str
    db_port: int
    db_user: str
    db_pass: str
    db_name: str
    raw_dir: Path
    user_agent: str


def load_config() -> Config:
    missing = [k for k in ('IMM_DB_USER', 'IMM_DB_PASS') if not os.environ.get(k)]
    if missing:
        raise SystemExit(f"missing environment: {', '.join(missing)} (see README: /etc/govdata-imm/env)")
    return Config(
        db_host=os.environ.get('IMM_DB_HOST', '127.0.0.1'),
        db_port=int(os.environ.get('IMM_DB_PORT', '3306')),
        db_user=os.environ['IMM_DB_USER'],
        db_pass=os.environ['IMM_DB_PASS'],
        db_name=os.environ.get('IMM_DB_NAME', 'grantconts'),
        raw_dir=Path(os.environ.get('IMM_RAW_DIR', '/var/lib/govdata-imm/raw')),
        user_agent=os.environ.get('IMM_USER_AGENT', 'govdata.ca-immigration-loader/1.0 (+https://www.govdata.ca)'),
    )


def load_sources() -> list[dict]:
    with open(HERE / 'sources.json', encoding='utf-8') as f:
        return json.load(f)['sources']
