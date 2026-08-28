#!/usr/bin/env bash
# FORK: run the fork image locally against a throwaway PostgreSQL, optionally restored from a
# database dump, on a Docker network with NO egress — so a copy of real data can't call anything.
#
#   .fork/dev-up.sh                                   # empty DB: register a user in the UI
#   .fork/dev-up.sh --seed                            # empty DB + synthetic data + dev login
#   .fork/dev-up.sh --dump ../deploy/firefly.dump     # a copy of production (needs its APP_KEY)
#   .fork/dev-up.sh --dump x.dump --flags FORK_TRANSFER_PAIRING=true,FORK_LIABILITY_TRANSFERS=true
#   .fork/dev-up.sh --egress                          # normal bridge + http://localhost:18080 (no dump!)
#
# Options
#   --dump PATH        restore this pg_dump custom-format archive (copied into the container only)
#   --flags K=V,...    extra environment for the app (FORK_* switches, APP_DEBUG=true, ...)
#   --seed             run firefly-iii:fork:seed-dev-data after boot (creates dev@example.invalid)
#   --months N         months of synthetic history for --seed (default 3)
#   --image IMG        image to run (default <registry>/<name>:dev, built if missing)
#   --app-key KEY      APP_KEY to run with; required for --dump unless FORK_DEV_APP_KEY_CMD is set
#                      in .fork/build.env (a command that prints the key, e.g. reading a k8s secret)
#   --egress           attach to a normal bridge and publish :18080 (refused together with --dump)
#   --rebuild          build the image even if it exists
#
# Afterwards:  .fork/dev-down.sh   removes everything (containers, network, data volume, token).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

# Fail early and clearly when this shell cannot reach the Docker daemon (typically: the user was
# added to the `docker` group but this login session predates it → `newgrp docker` or re-login).
if ! docker info >/dev/null 2>&1; then
  echo "[x] cannot talk to the Docker daemon from this shell." >&2
  if id -nG "$(id -un)" | tr ' ' '\n' | grep -qx docker && [[ -S /var/run/docker.sock ]] && ! groups | tr ' ' '\n' | grep -qx docker; then
    echo "    You are in the 'docker' group, but this session does not have it yet: run 'newgrp docker' or log out and back in." >&2
  else
    echo "    Check: docker ps   (is the daemon running? is your user in the 'docker' group?)" >&2
  fi
  exit 1
fi

# shellcheck disable=SC1091
[[ -f .fork/build.env ]] && set -a && source .fork/build.env && set +a
NET=ff-dev-net; PG=ff-dev-pg; APP=ff-dev-app; VOL=ff-dev-pgdata
SUBNET=10.199.0.0/24; PG_IP=10.199.0.5; APP_IP=10.199.0.10; HOST_PORT=18080
DUMP=""; FLAGS=""; SEED=0; MONTHS=3; IMAGE="${FORK_REGISTRY:-localhost:32000}/${FORK_IMAGE_NAME:-firefly}:dev"; APP_KEY=""; EGRESS=0; REBUILD=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dump)     DUMP="$2"; shift 2 ;;
    --flags)    FLAGS="$2"; shift 2 ;;
    --seed)     SEED=1; shift ;;
    --months)   MONTHS="$2"; shift 2 ;;
    --image)    IMAGE="$2"; shift 2 ;;
    --app-key)  APP_KEY="$2"; shift 2 ;;
    --egress)   EGRESS=1; shift ;;
    --rebuild)  REBUILD=1; shift ;;
    -h|--help)  sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 1 ;;
  esac
done

if [[ -n "$DUMP" && $EGRESS -eq 1 ]]; then
  echo "[x] --egress is refused together with --dump: a copy of real data stays on the internal network." >&2; exit 1
fi
if [[ -n "$DUMP" && ! -f "$DUMP" ]]; then echo "[x] dump not found: $DUMP" >&2; exit 1; fi
if docker ps -a --format '{{.Names}}' | grep -qE "^(${PG}|${APP})$"; then
  echo "[x] a dev environment is already running; run .fork/dev-down.sh first." >&2; exit 1
fi

# APP_KEY: a dump's data is tied to the key that wrote it; a fresh DB gets a random one.
if [[ -n "$DUMP" && -z "$APP_KEY" ]]; then
  if [[ -n "${FORK_DEV_APP_KEY_CMD:-}" ]]; then
    APP_KEY="$(bash -c "$FORK_DEV_APP_KEY_CMD")"
  else
    echo "[x] --dump needs the APP_KEY the dump was written with: pass --app-key, or set FORK_DEV_APP_KEY_CMD in .fork/build.env" >&2; exit 1
  fi
fi
[[ -n "$APP_KEY" ]] || APP_KEY="$(head /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9' | head -c 32)"
if [[ ${#APP_KEY} -ne 32 ]]; then echo "[x] APP_KEY must be exactly 32 characters" >&2; exit 1; fi

# image
if [[ $REBUILD -eq 1 ]] || ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
  echo "==> building $IMAGE"
  FORK_ALLOW_BRANCH=1 .fork/build.sh --registry "${IMAGE%%/*}" --name "$(basename "${IMAGE%:*}")" --tag "${IMAGE##*:}"
fi

# network
if [[ $EGRESS -eq 1 ]]; then
  docker network inspect "$NET" >/dev/null 2>&1 || docker network create --subnet "$SUBNET" "$NET" >/dev/null
  APP_URL="http://localhost:${HOST_PORT}"; PUBLISH=(-p "${HOST_PORT}:8080")
else
  docker network inspect "$NET" >/dev/null 2>&1 || docker network create --internal --subnet "$SUBNET" "$NET" >/dev/null
  APP_URL="http://${APP_IP}:8080"; PUBLISH=()
fi

# database
echo "==> starting PostgreSQL"
docker volume create "$VOL" >/dev/null
docker run -d --name "$PG" --network "$NET" --ip "$PG_IP" -v "$VOL":/var/lib/postgresql \
  -e POSTGRES_USER=firefly -e POSTGRES_PASSWORD=dev -e POSTGRES_DB=firefly postgres:18 >/dev/null
for _ in $(seq 1 60); do docker exec "$PG" pg_isready -U firefly -d firefly >/dev/null 2>&1 && break; sleep 1; done
docker exec "$PG" pg_isready -U firefly -d firefly >/dev/null
if [[ -n "$DUMP" ]] && [[ "$(docker exec "$PG" psql -U firefly -d firefly -Atc "select count(*) from information_schema.tables where table_schema='public'")" -gt 0 ]]; then
  echo "==> database volume already holds a restored copy (dev-down.sh --keep-data); skipping restore"
elif [[ -n "$DUMP" ]]; then
  echo "==> restoring $(basename "$DUMP") (copied into the container only)"
  docker cp "$DUMP" "$PG":/tmp/restore.dump
  docker exec "$PG" pg_restore --no-owner --no-privileges -U firefly -d firefly /tmp/restore.dump 2>&1 | grep -vE "^pg_restore: (warning|error): (errors ignored|could not execute query: ERROR:  role)" || true
  docker exec "$PG" rm -f /tmp/restore.dump
fi

# app
ENV_ARGS=(-e APP_ENV=production -e APP_DEBUG=true -e APP_URL="$APP_URL" -e SITE_OWNER=dev@example.invalid -e MAIL_MAILER=log
          -e APP_KEY="$APP_KEY" -e DB_CONNECTION=pgsql -e DB_HOST="$PG_IP" -e DB_PORT=5432 -e DB_DATABASE=firefly -e DB_USERNAME=firefly -e DB_PASSWORD=dev
          -e ALLOW_WEBHOOKS=true -e TRUSTED_PROXIES='**')
IFS=',' read -ra KV <<< "$FLAGS"
for kv in "${KV[@]}"; do [[ -n "$kv" ]] && ENV_ARGS+=(-e "$kv"); done
echo "==> starting Firefly III ($IMAGE)"
docker run -d --name "$APP" --network "$NET" --ip "$APP_IP" "${PUBLISH[@]}" "${ENV_ARGS[@]}" "$IMAGE" >/dev/null
for i in $(seq 1 120); do
  docker exec "$APP" curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/healthcheck 2>/dev/null | grep -q 200 && break
  sleep 2
done
docker exec "$APP" curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/healthcheck | grep -q 200 || { echo "[x] app did not become healthy; docker logs $APP" >&2; exit 1; }
echo "    healthy after ~$((i*2))s"

# synthetic data
if [[ $SEED -eq 1 ]]; then
  echo "==> seeding synthetic data ($MONTHS months)"
  docker exec "$APP" php artisan firefly-iii:fork:seed-dev-data --months="$MONTHS" --force --no-interaction | sed 's/^/    /'
fi

# token
mkdir -p .fork/dev
docker cp .fork/dev/mktoken.php "$APP":/tmp/mktoken.php
TOKEN="$(docker exec "$APP" php /tmp/mktoken.php 2>/dev/null | tail -n1)"
umask 077; printf '%s\n' "$TOKEN" > .fork/dev/token
printf 'APP_URL=%s\nIMAGE=%s\nMODE=%s\n' "$APP_URL" "$IMAGE" "$([[ -n "$DUMP" ]] && echo dump || echo empty)" > .fork/dev/state

cat <<MSG

Dev environment is up.
  UI:      $APP_URL      $([[ $EGRESS -eq 1 ]] || echo "(internal network: reachable from this host only, nothing can leave it)")
  API:     curl -H "Authorization: Bearer \$(cat .fork/dev/token)" -H 'Accept: application/json' $APP_URL/api/v1/about
  Login:   $([[ $SEED -eq 1 ]] && echo "dev@example.invalid / devpassword" || { [[ -n "$DUMP" ]] && echo "the dump's own users/passwords" || echo "register the first user in the UI"; })
  artisan: docker exec $APP php artisan firefly-iii:fork:...
  DB:      docker exec -it $PG psql -U firefly -d firefly
  Logs:    docker logs -f $APP
  Down:    .fork/dev-down.sh
MSG
