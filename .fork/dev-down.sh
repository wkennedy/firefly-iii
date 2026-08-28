#!/usr/bin/env bash
# FORK: tear down the local dev environment started by dev-up.sh.
#   .fork/dev-down.sh              # containers, network, data volume, token — everything
#   .fork/dev-down.sh --keep-data  # keep the PostgreSQL volume for the next dev-up
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
KEEP=0; [[ "${1:-}" == "--keep-data" ]] && KEEP=1
docker rm -f ff-dev-app ff-dev-pg >/dev/null 2>&1 || true
docker network rm ff-dev-net >/dev/null 2>&1 || true
if [[ $KEEP -eq 0 ]]; then docker volume rm ff-dev-pgdata >/dev/null 2>&1 || true; fi
rm -f .fork/dev/token .fork/dev/state
echo "dev environment removed$([[ $KEEP -eq 1 ]] && echo ' (data volume kept)')."
