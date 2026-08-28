# .fork — build & deploy tooling for this fork

Fork-owned; nothing here exists upstream, so `/sync-upstream` never conflicts on it.
Deployment-specific values (registry address, manifests repo) live in the gitignored
`build.env`, not in these files.

| File                               | Purpose                                                                                                                                                                                                                       |
|------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `docker/Dockerfile`                | Builds the runtime image from **this checkout** the way upstream builds `fireflyiii/core`: `FROM fireflyiii/base` (pinned by digest) + `vendor/` (composer stage) + `public/build` (Vite stage) + upstream's `entrypoint.sh`. |
| `docker/entrypoint.sh`             | Verbatim copy of upstream's container entrypoint. Re-diff when bumping the base image.                                                                                                                                        |
| `build.sh`                         | `docker buildx build` wrapper. Tags `<registry>/firefly:<upstream-version>-fork.<n>`; `--push` sends it to the registry.                                                                                                      |
| `build.env.example`                | Template for `build.env`: `FORK_REGISTRY`, `FORK_IMAGE_NAME`, `FORK_REGISTRY_HINT`, `FORK_DEPLOY_REPO`.                                                                                                                       |
| `smoke-test.sh`                    | Boots an image with sqlite and checks `/healthcheck` and `/login` answer 200.                                                                                                                                                 |
| `phpunit.xml`                      | Upstream's PHPUnit config with stop-on-failure **off** plus a `fork` suite (`tests/{unit,integration}/Fork`, excluded from unit/integration). `vendor/bin/phpunit -c .fork/phpunit.xml --testsuite fork`.                                                     |
| `../.github/workflows/fork-ci.yml` | Fork CI (new file, never conflicts): format/lint of fork paths, isolation guard rails, unit + integration suites, image build + smoke test. `build.sh --push` requires its `tests` check to be green on HEAD.                 |

Release flow:

```sh
cp .fork/build.env.example .fork/build.env && $EDITOR .fork/build.env   # once
.fork/build.sh -n 1                                   # build + load locally
.fork/smoke-test.sh <registry>/firefly:6.6.6-fork.1
.fork/build.sh -n 1 --push
git tag v6.6.6-fork.1                                 # on custom, once deployed
# then pin the tag in your deployment manifests and apply
```

Deployment details (manifests, DB backup before an upgrade, rollout checks) belong in the deployment repo, not here.

## GitHub Actions on the fork

Forks start with Actions **disabled**. Enabling it (Settings → Actions → General) is required for
`fork-ci.yml`, but it also activates upstream's workflows. None of them should run here — they manage
upstream's issues/PRs/releases and some would try to commit, tag or comment. Disable each in
**Actions → (workflow) → ⋯ → Disable workflow** (a repo setting: survives syncs, no file edits):

| Workflow                                                                                                                        | Why it must stay off                                                                                      |
|---------------------------------------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------|
| `release.yml`                                                                                                                   | Weekly cron + manual: reformats, commits, tags and **creates a GitHub release** on whatever repo runs it. |
| `run-ci.yml`                                                                                                                    | Weekly cron: checks out `origin/develop` (absent here) and reformats.                                     |
| `stale.yml`, `lock.yml`, `cleanup.yml`                                                                                          | Daily crons that close/lock/delete issues, discussions and PRs.                                           |
| `closed-issues.yml`, `issues-reply-old-versions.yml`, `close-duplicates.yml`, `label-actions.yml`, `pr-reply-no-disclosure.yml` | Issue/PR bots posting upstream's canned replies.                                                          |
| `depsreview.yml`                                                                                                                | Harmless dependency review on PRs; optional.                                                              |

GitHub auto-disables *scheduled* workflows on forks, but not the event-triggered ones. Keep
**Dependabot off** too (`.github/dependabot.yml` targets a `develop` branch this fork lacks);
`mergify.yml` and `CODEOWNERS` are inert without the app / those collaborators.
