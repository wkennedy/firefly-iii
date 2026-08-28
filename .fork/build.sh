#!/usr/bin/env bash
# FORK: build (and optionally push) the fork's Firefly III image.
#
#   .fork/build.sh                 # build <registry>/firefly:<ver>-fork.1, load into local docker
#   .fork/build.sh -n 2 --push     # build <ver>-fork.2 and push to the registry
#   .fork/build.sh --tag smoke     # arbitrary tag, e.g. for a throwaway local test
#
# <ver> is read from config/firefly.php ('version' => '6.6.6'), so the tag always
# says which upstream release the image is based on. The fork counter (-n) restarts
# at 1 whenever that version changes.
#
# Deployment-specific settings (registry address, deploy repo) come from
# .fork/build.env (gitignored; see build.env.example) or from flags/env vars:
#   FORK_REGISTRY        registry to tag/push to (required)
#   FORK_IMAGE_NAME      image name, default "firefly"
#   FORK_REGISTRY_HINT   printed when the registry is unreachable on --push
#   FORK_DEPLOY_REPO     path of the manifests repo, for the post-push hint
#   FORK_CI_CHECK        name of the CI check-run that must be green on HEAD before
#                        --push (default "tests", the job in .github/workflows/fork-ci.yml;
#                        empty disables). --skip-ci-check overrides once.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

# shellcheck disable=SC1091
[[ -f .fork/build.env ]] && set -a && source .fork/build.env && set +a

REGISTRY="${FORK_REGISTRY:-}"
NAME="${FORK_IMAGE_NAME:-firefly}"
FORK_N=1
TAG=""
PUSH=0
SKIP_CI_CHECK=0
PLATFORM="linux/amd64"
EXTRA_ARGS=()

usage() { sed -n '2,17p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    -n|--fork-number) FORK_N="$2"; shift 2 ;;
    --tag)            TAG="$2"; shift 2 ;;
    --push)           PUSH=1; shift ;;
    --registry)       REGISTRY="$2"; shift 2 ;;
    --name)           NAME="$2"; shift 2 ;;
    --platform)       PLATFORM="$2"; shift 2 ;;
    --no-cache)       EXTRA_ARGS+=(--no-cache); shift ;;
    --skip-ci-check)  SKIP_CI_CHECK=1; shift ;;
    -h|--help)        usage 0 ;;
    *) echo "unknown argument: $1" >&2; usage 1 ;;
  esac
done

[[ -n "$REGISTRY" ]] || { echo "[x] no registry configured: set FORK_REGISTRY in .fork/build.env (see build.env.example) or pass --registry" >&2; exit 1; }

UPSTREAM_VERSION="$(sed -nE "s/^[[:space:]]*'version'[[:space:]]*=>[[:space:]]*'([^']+)'.*/\1/p" config/firefly.php | head -1)"
[[ -n "$UPSTREAM_VERSION" ]] || { echo "could not read 'version' from config/firefly.php" >&2; exit 1; }
[[ -n "$TAG" ]] || TAG="${UPSTREAM_VERSION}-fork.${FORK_N}"

GITREV="$(git rev-parse --short=12 HEAD)"
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  GITREV="${GITREV}-dirty"
  echo "[!] working tree has uncommitted tracked changes; revision label will be '${GITREV}'" >&2
fi
if [[ "$BRANCH" != "custom" && -z "${FORK_ALLOW_BRANCH:-}" ]]; then
  echo "[!] building from branch '${BRANCH}', not 'custom' (set FORK_ALLOW_BRANCH=1 to silence)" >&2
fi

IMAGE="${REGISTRY}/${NAME}:${TAG}"
ISODATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

# Only tested, pushed commits reach the registry. A pushed image is what runs in
# production; nothing else checks it.
ci_check() {
  local check="${FORK_CI_CHECK-tests}"
  [[ -z "$check" || $SKIP_CI_CHECK -eq 1 ]] && return 0
  if [[ "$GITREV" == *-dirty ]]; then
    echo "[x] --push refuses a dirty working tree (commit first, or --skip-ci-check)" >&2; return 1
  fi
  local sha; sha="$(git rev-parse HEAD)"
  if ! git branch -r --contains "$sha" 2>/dev/null | grep -q 'origin/'; then
    echo "[x] HEAD ${sha:0:12} is not on any origin branch; push it so CI can run it (or --skip-ci-check)" >&2; return 1
  fi
  local url slug; url="$(git remote get-url origin)"
  slug="$(printf '%s' "$url" | sed -E 's#^(git@github\.com:|https://github\.com/)##; s#\.git$##')"
  if [[ "$slug" == "$url" ]]; then
    echo "[!] origin is not a github.com remote; cannot query check-runs, skipping CI check" >&2; return 0
  fi
  local api="https://api.github.com/repos/${slug}/commits/${sha}/check-runs?check_name=${check}&per_page=10"
  local json; json="$(curl -fsS -m 10 -H 'Accept: application/vnd.github+json' "$api" 2>/dev/null)" || {
    echo "[x] could not query ${api} (offline? private repo?) — use --skip-ci-check to override" >&2; return 1
  }
  local verdict; verdict="$(printf '%s' "$json" | python3 -c '
import json,sys
runs=[r for r in json.load(sys.stdin).get("check_runs",[])]
if not runs: print("missing")
else:
    r=sorted(runs,key=lambda r:r.get("completed_at") or "")[-1]
    print("success" if r.get("conclusion")=="success" else (r.get("conclusion") or r.get("status")))')"
  case "$verdict" in
    success) echo "    CI check '${check}' is green for ${sha:0:12}";;
    missing) echo "[x] no '${check}' check-run for ${sha:0:12} yet (is Actions enabled? has fork-ci.yml run?) — wait, or --skip-ci-check" >&2; return 1;;
    *)       echo "[x] CI check '${check}' for ${sha:0:12} is '${verdict}', refusing to push" >&2; return 1;;
  esac
}

if [[ $PUSH -eq 1 ]]; then
  ci_check || exit 1
  if ! curl -fsS -m 3 "http://${REGISTRY}/v2/" >/dev/null 2>&1; then
    echo "[x] registry ${REGISTRY} is not reachable." >&2
    [[ -n "${FORK_REGISTRY_HINT:-}" ]] && echo "    hint: ${FORK_REGISTRY_HINT}" >&2
    exit 1
  fi
  if curl -fsS -m 3 "http://${REGISTRY}/v2/${NAME}/manifests/${TAG}" \
       -H 'Accept: application/vnd.docker.distribution.manifest.v2+json' -o /dev/null 2>/dev/null; then
    echo "[x] ${IMAGE} already exists in the registry; bump -n or choose another --tag" >&2
    exit 1
  fi
fi

echo "==> building ${IMAGE}"
echo "    upstream ${UPSTREAM_VERSION}  rev ${GITREV}  branch ${BRANCH}  platform ${PLATFORM}"

OUTPUT=(--load)
[[ $PUSH -eq 1 ]] && OUTPUT=(--push)

# --provenance/--sbom off: keeps the pushed artifact a plain image manifest, which
# older registries (registry:2 etc.) accept without OCI-index support.
docker buildx build \
  --file .fork/docker/Dockerfile \
  --platform "$PLATFORM" \
  --provenance=false --sbom=false \
  --build-arg "VERSION=${TAG}" \
  --build-arg "ISODATE=${ISODATE}" \
  --build-arg "GITREVISION=${GITREV}" \
  --tag "$IMAGE" \
  "${EXTRA_ARGS[@]}" \
  "${OUTPUT[@]}" \
  .

echo "==> done: ${IMAGE}"
if [[ $PUSH -eq 1 ]]; then
  echo "    deploy: pin tag '${TAG}' in ${FORK_DEPLOY_REPO:-your deployment manifests} and apply"
else
  echo "    smoke test: .fork/smoke-test.sh ${IMAGE}"
fi
