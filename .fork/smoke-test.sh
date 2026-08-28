#!/usr/bin/env bash
# FORK: boot a built image against a throwaway sqlite DB and check that the
# entrypoint (migrations, passport keys, config cache) and nginx come up.
#   .fork/smoke-test.sh <registry>/firefly:6.6.6-fork.1
set -euo pipefail
IMAGE="${1:?image name required}"
PORT="${SMOKE_PORT:-18080}"
NAME="ff-smoke-$$"
trap 'docker rm -f "$NAME" >/dev/null 2>&1 || true' EXIT

docker run -d --name "$NAME" -p "${PORT}:8080" \
  -e APP_KEY=SmokeTestSmokeTestSmokeTestSmoke \
  -e DB_CONNECTION=sqlite -e APP_ENV=production -e APP_DEBUG=false \
  -e SITE_OWNER=smoke@example.invalid -e APP_URL="http://localhost:${PORT}" \
  "$IMAGE" >/dev/null

# /healthcheck is what the image's own HEALTHCHECK polls; /login redirects to
# /register on an empty database, so follow redirects and require a final 200.
echo "==> waiting for http://localhost:${PORT}/healthcheck"
for i in $(seq 1 60); do
  code="$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${PORT}/healthcheck" || true)"
  if [[ "$code" == "200" ]]; then
    echo "==> /healthcheck -> 200 after ${i}x2s"
    login="$(curl -sL -o /dev/null -w '%{http_code} %{url_effective}' "http://localhost:${PORT}/login")"
    echo "==> /login -> ${login}"
    [[ "$login" == 200* ]] || { echo "==> FAILED: login page did not render"; docker logs "$NAME" 2>&1 | tail -40; exit 1; }
    echo "==> entrypoint warnings/errors (DB_*/MAIL_* warnings are expected with sqlite):"
    docker logs "$NAME" 2>&1 | grep -E '^\s*\[(!|x)\]|ERROR|Exception' | grep -v 'aborted connection' || echo "    (none)"
    exit 0
  fi
  sleep 2
done
echo "==> FAILED: last status '${code}'. Container log:"; docker logs "$NAME" 2>&1 | tail -60
exit 1
