# .fork — build & deploy tooling for this fork

Fork-owned; nothing here exists upstream, so `/sync-upstream` never conflicts on it.
Deployment-specific values (registry address, manifests repo) live in the gitignored
`build.env`, not in these files.

| File                               | Purpose                                                                                                                                                                                                                       |
|------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `docker/Dockerfile`                | Builds the runtime image from **this checkout** the way upstream builds `fireflyiii/core`: `FROM fireflyiii/base` (pinned by digest) + `vendor/` (composer stage) + `public/build` (Vite stage) + upstream's `entrypoint.sh`. The v1 layout's Laravel-Mix bundles (`public/v1/js/app.js` & co.) are gitignored upstream and are built here from an isolated `resources/assets/v1` install pinned to `vue-loader` 15 (the workspace tree resolves the Vue-3-only 17, which cannot compile Vue 2.7 components). |
| `docker/entrypoint.sh`             | Verbatim copy of upstream's container entrypoint. Re-diff when bumping the base image.                                                                                                                                        |
| `build.sh`                         | `docker buildx build` wrapper. Tags `<registry>/firefly:<upstream-version>-fork.<n>`; `--push` sends it to the registry.                                                                                                      |
| `build.env.example`                | Template for `build.env`: `FORK_REGISTRY`, `FORK_IMAGE_NAME`, `FORK_REGISTRY_HINT`, `FORK_DEPLOY_REPO`.                                                                                                                       |
| `smoke-test.sh`                    | Boots an image with sqlite and checks `/healthcheck` and `/login` answer 200.                                                                                                                                                 |
| `dev-up.sh` / `dev-down.sh` | Local environment: the fork image + a throwaway PostgreSQL on an egress-less Docker network, optionally restored from a database dump (`--dump`), optionally seeded with synthetic data (`--seed`). Prints a UI URL and writes an API token to `dev/token` (gitignored). |
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

## Local development

Three layers, cheapest first:

1. **Tests** (no data needed; sqlite in memory, fixtures from `tests/integration/Traits/CreatesTransactionGroups.php`):
   ```sh
   docker run --rm --entrypoint sh --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD":/var/www/html -w /var/www/html fireflyiii/base:latest -c \
     'composer install --no-scripts && APP_ENV=testing php artisan firefly-iii:laravel-passport-keys && vendor/bin/phpunit -c .fork/phpunit.xml --testsuite fork'
   ```
2. **Built image, synthetic data** — every fork feature has something to act on (both legs of each card/loan payment, Amazon payee fragments, uncategorised rows, external_ids, auto-budgets):
   ```sh
   .fork/dev-up.sh --seed --months 3 --flags FORK_TRANSFER_PAIRING=true,FORK_PAYEE_ALIASES=true
   # UI at the printed URL, login dev@example.invalid / devpassword; API token in .fork/dev/token
   docker exec ff-dev-app php artisan firefly-iii:fork:pair-transfers --dry-run
   .fork/dev-down.sh
   ```
   The dataset is deterministic (`firefly-iii:fork:seed-dev-data`; refuses to run twice without `--append`, refuses in production without `--force`).
3. **Built image, a copy of production** — the honest test for behaviour on real history:
   ```sh
   .fork/dev-up.sh --dump /path/to/firefly.dump --flags FORK_LIABILITY_TRANSFERS=true
   ```
   Needs the `APP_KEY` the dump was written with: `--app-key`, or `FORK_DEV_APP_KEY_CMD` in `build.env` (a command that prints it, e.g. reading a cluster secret). The dump is copied into the database container only, the network is `--internal` (nothing can leave it — the restored webhooks and update checks fail harmlessly), and `--egress` is refused together with `--dump`. Log in with the dump's own users. `dev-down.sh` deletes containers, network, data volume and token; `--keep-data` keeps the restored database for the next `dev-up`.

**Browser smoke test:** `.fork/dev-crawl.sh` (Playwright, installed into `.fork/dev/` on first use) logs in
and visits every major page, failing on HTTP errors, Laravel error pages, JS console/page errors and
failed asset/API requests; report in `.fork/dev/crawl-report.json`, screenshots in `.fork/dev/shots/`.
For a `--dump` environment set a dev password on the copy first, then `DEV_EMAIL=… DEV_PASSWORD=… .fork/dev-crawl.sh`.
Known upstream noise: `/profile` logs two `onfocus` console errors from the Passport widgets on the official image too.

Without `--egress` the UI is reachable at the container's fixed address (`http://10.199.0.10:8080`) from this host only; with `--egress` (no dump) it's published at `http://localhost:18080`.
