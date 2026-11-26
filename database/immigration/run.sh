#!/bin/sh
# Wrapper for the loader host: load the root-only environment, then run the loader.
set -e
[ -r /etc/govdata-imm/env ] || { echo "missing /etc/govdata-imm/env" >&2; exit 2; }
set -a; . /etc/govdata-imm/env; set +a
exec /usr/bin/python3 "$(dirname "$0")/run.py" "$@"
