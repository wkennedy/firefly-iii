#!/usr/bin/env bash
# FORK: browser smoke test of the running dev environment (see dev-up.sh). Installs Playwright
# into .fork/dev/ on first use (Chromium download, ~150 MB), logs in, visits every major page.
#   .fork/dev-crawl.sh                                  # dev@example.invalid / devpassword (--seed environments)
#   DEV_EMAIL=me@example.com DEV_PASSWORD=... .fork/dev-crawl.sh   # a --dump environment (set a dev password on the copy first)
# Exit code 1 when any page has an error; details in .fork/dev/crawl-report.json and .fork/dev/shots/.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/dev"
[[ -f state && -f token ]] || { echo "[x] no dev environment state; run .fork/dev-up.sh first" >&2; exit 1; }
if [[ ! -d node_modules/playwright ]]; then
  echo "==> installing playwright into .fork/dev (one-off)"
  [[ -f package.json ]] || npm init -y >/dev/null
  npm install --no-audit --no-fund playwright >/dev/null
  npx playwright install chromium
fi
node crawl.js
