"""Detect a new publication and download it.

Detection is two-stage: a cheap publisher signal first (CKAN metadata_modified, StatCan WDS releaseTime, or HTTP
ETag/Last-Modified), then the sha256 of the bytes. A changed signal with identical bytes is "unchanged"; an
unchanged signal is trusted only when the last edition loaded successfully.
"""

import gzip
import hashlib
import json
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path


@dataclass
class Download:
    data: bytes
    sha256: str
    etag: str | None
    last_modified: str | None
    publisher_modified: str | None
    retrieved_at: datetime


def _request(url: str, ua: str, method: str = 'GET', body: bytes | None = None, headers: dict | None = None,
             timeout: int = 300):
    h = {'User-Agent': ua, **(headers or {})}
    req = urllib.request.Request(url, data=body, method=method, headers=h)
    return urllib.request.urlopen(req, timeout=timeout)


_ckan_cache: dict[str, str | None] = {}


def publisher_signal(src: dict, ua: str) -> str | None:
    """The publisher's own 'last changed' marker, or None when it has none or does not answer (then the download and
    its sha256 decide). A slow metadata service must never fail a load: StatCan WDS took over 60 s on 2026-11-26."""
    try:
        return _publisher_signal(src, ua)
    except Exception as e:  # noqa: BLE001 -- the signal is an optimisation, the sha256 comparison is the authority
        print(f"{src['key']}: publisher signal unavailable ({e!r}); comparing the download instead", flush=True)
        return None


def _publisher_signal(src: dict, ua: str) -> str | None:
    method, ref = src.get('detect'), src.get('detect_ref')
    if method == 'ckan' and ref:
        if ref not in _ckan_cache:
            with _request(f'https://open.canada.ca/data/api/action/package_show?id={ref}', ua, timeout=60) as r:
                _ckan_cache[ref] = json.load(r)['result'].get('metadata_modified')
        return _ckan_cache[ref]
    if method == 'statcan_wds' and ref:
        body = json.dumps([{'productId': int(ref)}]).encode()
        with _request('https://www150.statcan.gc.ca/t1/wds/rest/getCubeMetadata', ua, 'POST', body,
                      {'Content-Type': 'application/json'}, timeout=120) as r:
            obj = json.load(r)[0].get('object') or {}
        return obj.get('releaseTime')
    return None


def download(src: dict, ua: str, signal: str | None) -> Download:
    with _request(src['url'], ua) as r:
        data = r.read()
        etag, lm = r.headers.get('ETag'), r.headers.get('Last-Modified')
    return Download(data, hashlib.sha256(data).hexdigest(), etag, lm, signal, datetime.now(timezone.utc))


def archive(raw_dir: Path, src: dict, dl: Download) -> Path:
    """Keep the exact bytes published (gzip). The file name carries the date and hash, so nothing is overwritten."""
    ext = Path(src['url'].split('?')[0]).suffix or '.bin'
    d = raw_dir / src['key']
    d.mkdir(parents=True, exist_ok=True)
    p = d / f"{dl.retrieved_at:%Y%m%dT%H%M%SZ}-{dl.sha256[:12]}{ext}.gz"
    if not p.exists():
        tmp = p.with_suffix(p.suffix + '.tmp')
        with gzip.open(tmp, 'wb', compresslevel=6) as f:
            f.write(dl.data)
        tmp.replace(p)
    return p
