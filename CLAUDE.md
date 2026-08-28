# Firefly III — fork working notes

This is a **fork** of [firefly-iii/firefly-iii](https://github.com/firefly-iii/firefly-iii) (personal-finance manager; Laravel 13, PHP ≥ 8.5, AGPL-3.0). We build private customisations on top of it while continuing to pull in upstream releases. Everything below is in service of two goals: change what we need, and keep upstream merges cheap.

## Git model — read this first

| Branch             | Role                                                                                  | Rule                                           |
|--------------------|---------------------------------------------------------------------------------------|------------------------------------------------|
| `upstream/main`    | Upstream **releases only** (currently `v6.6.6`). Moves only when a version is tagged. | Read-only.                                     |
| `upstream/develop` | Upstream's daily work. Churns constantly.                                             | Never merge. Cherry-pick (`-x`) only if asked. |
| `main`             | Pristine mirror of `upstream/main`.                                                   | **Fast-forward only. Never commit here.**      |
| `custom`           | All fork work. Long-lived. The deployable branch.                                     | Merge `main` into it. **Never rebase it.**     |
| `feature/*`        | Short-lived, branched from `custom`.                                                  | Rebase/squash freely; merge into `custom`.     |

- Sync procedure: `/sync-upstream` (`.claude/skills/sync-upstream/SKILL.md`). Summary: `git fetch upstream --tags` → `main` ff to `upstream/main` → `git merge main` into `custom` → resolve → verify → push.
- Before merging a feature into `custom`, run the **`fork-reviewer`** agent (`.claude/agents/fork-reviewer.md`) on the diff. It grades every touched file by conflict risk.
- Upstream commit style for reference: they require `Assisted-by: <Model> via <Tool>` footers and 🍌🍌🍌 in PR titles for AI-authored work (`agents.md`). That applies **only if we send something upstream** — and upstream PRs go to `develop`, never `main`.

## Fork isolation rules (keep upstream merges cheap)

Conflicts only happen where our edits overlap upstream's. So:

1. **Add, don't edit.** New classes go in fork-owned paths: `app/Fork/…` (namespace `FireflyIII\Fork\…`, already covered by the `FireflyIII\` PSR-4 root), `app/Providers/Fork*ServiceProvider.php`, `resources/views/fork/…`, `public/fork/…` (static assets: the v1 CSS overlay `public/fork/css/overlay*.css` and the Chart.js restyle `public/fork/js/charts-overlay.js`, switch `FORK_UI_OVERLAY`, default on, two one-line hooks in `layout/default.twig`), `database/migrations/<ts>_fork_*.php`. Build/deploy tooling lives in `.fork/` (+ root `.dockerignore`). **`app/Providers/ForkServiceProvider.php`** (registered last in `bootstrap/providers.php`) is where fork bindings and artisan commands are wired: today it rebinds `steam` (`FireflyIII\Fork\Support\Steam`, floatalize fix) and `TransactionJournalFactory` (`app/Fork/Factory`, wraps creation in a DB transaction), attaches `Fork\Dedup\ExternalIdObserver`, and registers the `firefly-iii:fork:*` commands (`app/Fork/Console/Commands/`). Feature switches live in `config/fork.php` (env `FORK_*`, all default off except the purely visual `FORK_UI_OVERLAY`, which defaults on). Fork API routes live in `routes/fork.php`, registered by the provider under `/api/v1/fork/*` with the `api` middleware group (route names `api.v1.fork.<resource>.<action>`, plain JSON, controllers in `app/Fork/Http/Controllers/`). Add new overrides there, not in upstream providers.
2. When an upstream file *must* be touched, keep it to a one-line hook that delegates to fork code, and mark it:
   `// FORK: <why>` in PHP, `{# FORK: <why> #}` in Twig, `{{-- FORK: <why> --}}` in Blade. The marker tells whoever resolves the next merge conflict which side is intentional.
3. **Never edit an existing migration.** Add a new one with a later timestamp. Prefer new tables (`fork_*`) over new columns on upstream tables.
4. **Never hand-edit lockfiles.** `composer.lock` / `package-lock.json` change only via `composer`/`npm`. On merge conflict, take upstream's lockfile and re-run the package manager.
5. **Never touch** `.github/workflows/*` (upstream CI — add fork CI as new, differently named files), `resources/lang/<non-en_US>/` (Crowdin-managed), or `changelog.md`/`releases.md`.
6. **No drive-by reformatting** of upstream files. Formatting-only diffs are pure conflict fuel.
7. Prefer the container over edits: every repository is resolved through a `…RepositoryInterface` binding, so behaviour can be replaced by rebinding in a fork provider instead of editing the class. Listeners in `app/Listeners/` are auto-discovered; observers in `app/Handlers/Observer/`; the rule engine, webhook generators/sender, and `RuleEngineInterface` are all swappable bindings in `app/Providers/FireflyServiceProvider.php`.
8. Append fork routes at the **end** of `routes/web.php` / `routes/api.php` (or register a separate `routes/fork.php` from `bootstrap/app.php`) — never in the middle of upstream route groups.

## Environment

- **Local toolchain (verified 2026-08-28):** `docker` + buildx and `kubectl` are installed. `php` 8.5.4 CLI exists but lacks bcmath/intl/mbstring/pdo_sqlite/xml, and there is no `composer` — so run PHP-side commands inside the runtime base image instead of natively:
  `docker run --rm -it --entrypoint sh --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -e HOME=/tmp -v "$PWD":/var/www/html -w /var/www/html fireflyiii/base:latest -c "composer install"` (`--entrypoint sh` is required — the image's default entrypoint runs the web-server boot hooks and never executes your command; `--user` keeps `vendor/` owned by you) (PHP 8.5.9, all required extensions, composer 2.10; **no node** — frontend builds use Node 24 / npm 11 on the host or the Dockerfile's node stage).
- First-time setup: `cp .env.example .env && composer install && php artisan key:generate && php artisan migrate --seed && php artisan firefly-iii:upgrade-database`. Use `DB_CONNECTION=sqlite` for local dev.
- The `php-qa` agent (`.claude/agents/php-qa.md`) knows how to detect what's available and run the right subset.

## Build & deploy (fork image)

Upstream builds `fireflyiii/core` out-of-tree (Azure DevOps): `FROM fireflyiii/base` + release zip + `entrypoint.sh`. We replicate that from this checkout in `.fork/` (see `.fork/README.md`):

```bash
.fork/build.sh -n 1                # → <FORK_REGISTRY>/firefly:<config/firefly.php version>-fork.1
.fork/smoke-test.sh <image>        # sqlite boot, /healthcheck and /login must answer 200
.fork/build.sh -n 1 --push         # registry + hints come from the gitignored .fork/build.env
```

**CI:** `.github/workflows/fork-ci.yml` (fork-owned, new file) runs on pushes to `custom`/`feature/**` and PRs to `custom`: mago format/lint on fork paths, the isolation guard rails (lockfiles, workflows, migrations, FORK markers), unit + integration suites via `.fork/phpunit.xml`, then builds and smoke-tests the image. `.fork/build.sh --push` refuses unless the commit is clean, on `origin`, and its `tests` check-run is green (`--skip-ci-check` / `FORK_CI_CHECK=` override). Upstream's own workflows should stay **disabled** in the fork's GitHub Actions settings (see `.fork/README.md`).

Local development: `.fork/dev-up.sh [--seed | --dump <pg_dump>] [--flags K=V,…]` runs the image against a throwaway PostgreSQL on an egress-less Docker network (`.fork/dev-down.sh` removes it); `firefly-iii:fork:seed-dev-data` makes deterministic bank-feed-shaped data. See `.fork/README.md` → Local development.

Registry address, deployment-repo path and any cluster-specific commands live **only** in `.fork/build.env` (gitignored) and in the private deployment repo it points to — keep this public repo free of network/host specifics. The deployment repo pins the image tag with a kustomize `images:` override, so its manifests keep naming `fireflyiii/core`. After `/sync-upstream`, bump `BASE_IMAGE` in `.fork/docker/Dockerfile` (digest of the current `fireflyiii/base:latest`) and re-diff `.fork/docker/entrypoint.sh` against upstream's.

## Commands

```bash
# tests (sqlite in-memory). Upstream's phpunit.xml has stopOnFailure=true and PHPUnit 13 can't negate it on the CLI —
# use the fork config to see every failure: vendor/bin/phpunit -c .fork/phpunit.xml --testsuite unit|integration|fork
# prerequisite once per checkout: APP_ENV=testing php artisan firefly-iii:laravel-passport-keys   (integration tests 500 with "Invalid key supplied" without storage/oauth-*.key)
# fork suite: vendor/bin/phpunit -c .fork/phpunit.xml --testsuite fork   (own tests; excluded from unit/integration in that config so nothing runs twice)
# fork test support (tests/integration/Traits/): ForkTestSupport is hooked into TestCase — actingAs() also authenticates the Passport `api` guard and oauth keys are generated on demand; CreatesTransactionGroups gives createUser/createAccount/createWithdrawal|Deposit|Transfer/createTransactionGroup/createRule/createWebhook. Fork tests live in tests/{unit,integration}/Fork/.
# known-failing upstream tests (v6.6.6, not ours): Api/Chart/{Budget,Category}ControllerTest::testGetOverviewChartFails expect 422 on a missing range but DateRangeRequest no longer requires it → 200
composer unit-test            # tests/unit
composer integration-test     # tests/integration  (seeds the DB via $seed = true)
vendor/bin/phpunit -c phpunit.xml --testsuite feature --no-coverage
vendor/bin/phpunit -c phpunit.xml --filter SomeTest

# static analysis & style (this is what upstream CI runs, in this order)
vendor/bin/mago format        # formatter: print-width 160, aligned => and =, sorted class methods
.ci/phpcs.sh                  # php-cs-fixer (installs its own vendor in .ci/php-cs-fixer/)
vendor/bin/mago lint
.ci/phpstan.sh                # PHPStan level 6 + larastan + strict/deprecation/ergebnis rules → phpstan-report.txt
.ci/rector.sh --dry-run       # optional
php artisan blade:lint         # run by upstream CI; provided by a vendor package, skip if the command is unknown

# frontend (npm workspaces: resources/assets/v1 and v2)
npm install                                   # root; runs patch-package (patches/admin-lte+4.0.0.patch)
npm --workspace resources/assets/v1 run prod  # Laravel Mix → public/v1 — FAILS from the workspace tree (vue-loader 17 is Vue-3-only);
#   use: cd resources/assets/v1 && npm install --no-save --workspaces=false vue-loader@^15.11.1 && npm run prod   (what .fork/docker/Dockerfile does)
npm --workspace resources/assets/v2 run build # Vite     → public/build

# app
php artisan serve
php artisan firefly-iii:upgrade-database      # post-migration data upgrades (app/Console/Commands/Upgrade)
php artisan firefly-iii:correct-database      # data corrections (app/Console/Commands/Correction)
php artisan firefly-iii:verify-security-alerts
```

## Architecture map

Request flow: `routes/*.php` → controller → `…RepositoryInterface` / `Services\Internal\*` / `Factory\*` → Eloquent model → `Transformers\*` (Fractal, API) or Twig/Blade view (web).

| Path                                                                | What                                                                                                                                                                                                                                                                 |
|---------------------------------------------------------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `app/Api/V1/Controllers/`                                           | JSON:API controllers, `final`, one class per verb (`ShowController`, `StoreController`, `UpdateController`, `DestroyController`, `ListController`). Base: `Controller.php` with `ValidatesUserGroupTrait`. **There is no API v2.**                                   |
| `app/Api/V1/Requests/`                                              | API form requests. Web form requests are in `app/Http/Requests/`.                                                                                                                                                                                                    |
| `app/Http/Controllers/`                                             | Web controllers (~128), grouped by domain. Middleware in `app/Http/Middleware/`.                                                                                                                                                                                     |
| `app/Repositories/<Domain>/`                                        | `XRepository` + `XRepositoryInterface`; bound in `app/Providers/<Domain>ServiceProvider.php` with `setUser(auth()->user())`. `app/Providers/AccountServiceProvider.php` is the canonical example.                                                                    |
| `app/Providers/FireflyServiceProvider.php`                          | Catch-all bindings: `preferences`, `amount`, `steam`, `navigation`, form builders, rule engine, webhook services, `NetWorthInterface`…                                                                                                                               |
| `app/Factory/`                                                      | Domain object creation (`TransactionJournalFactory`, `AccountFactory`…). Not test factories.                                                                                                                                                                         |
| `app/Services/Internal/{Create,Update,Destroy,Recalculate,Support}` | Write-side services. Guarded by mago: `FireflyIII\Services` may only depend on itself, native PHP, and `FireflyIII\Models`. Same guard for `FireflyIII\Transformers`.                                                                                                |
| `app/Helpers/Collector/GroupCollector`                              | The central transaction query builder. Use it for any transaction listing/aggregation; don't hand-roll joins.                                                                                                                                                        |
| `app/TransactionRules/`                                             | Rule engine: `Engine/SearchRuleEngine`, `Actions/*` implement `ActionInterface`, wired by `Factory/ActionFactory` + `config/firefly.php`.                                                                                                                            |
| `app/Handlers/Observer/`                                            | Eloquent observers (21). `app/Events/` + `app/Listeners/` (auto-discovered) handle side effects incl. webhooks.                                                                                                                                                      |
| `app/Jobs/`                                                         | `CreateRecurringTransactions`, `DownloadExchangeRates`, `SendWebhookMessage`, `WarnAboutBills`, `CreateAutoBudgetLimits`, `MailError`. Cron entry points in `app/Support/Cronjobs/`.                                                                                 |
| `app/Support/`                                                      | Facades (`Preferences`, `Steam`, `Amount`, `Navigation`, `AppConfiguration`), `Binder/` (route-model binding via `config/bindables.php`), `Twig/` extensions, `Search/`, `JsonApi/Enrichments/`.                                                                     |
| `app/Models/` (+ `app/User.php`)                                    | 50 Eloquent models. Money: `TransactionGroup` → `TransactionJournal` → ≥2 `Transaction` rows (negative source, positive destination). Amounts are **strings**, use `bcmath`, never floats.                                                                           |
| `app/Console/Commands/{Upgrade,Correction,Integrity,System,Tools}`  | Artisan commands; `UpgradeSkeleton.php.stub` / `CorrectionSkeleton.php.stub` are templates for new ones.                                                                                                                                                             |
| `bootstrap/app.php`, `bootstrap/providers.php`                      | Middleware groups (`web`, `api`, `user-full-auth`, `admin`, …), event discovery, provider list. `RouteServiceProvider` is a no-op stub.                                                                                                                              |
| `config/firefly.php`                                                | The big one: type matrices (`expected_source_types`, `allowed_opposing_types`, `account_to_transaction`), `db_version`/`api_version`, feature flags, sort/filter whitelists.                                                                                         |
| `resources/views/`                                                  | Twig templates for the default **v1** layout (AdminLTE 2, Vue 2.7 + jQuery, built by Laravel Mix from `resources/assets/v1`). `resources/views/v2/` = Blade + Alpine.js + AdminLTE 4 built by Vite from `resources/assets/v2`; enabled with `FIREFLY_III_LAYOUT=v2`. |
| `resources/lang/en_US/`                                             | Only editable locale. Strings via `trans('firefly.key')` / `__()`; add new keys at the end of the relevant file.                                                                                                                                                     |
| `database/migrations/`                                              | 60 files; recent upstream ones are batched per month (`2026_04_13_185808_migrations_04_2026.php`). Seeders in `database/seeders/` (types, currencies, roles, link types).                                                                                            |
| `tests/`                                                            | `unit/`, `integration/`, `feature/` = PHPUnit suites. Base class `tests/integration/TestCase.php` (`RefreshDatabase`, `$seed = true`, `createAuthenticatedUser()`). Coverage is thin (~38 test files), mostly `tests/integration/Api/`.                              |

### Multi-tenancy ("administrations")
`UserGroup` is the tenant. `GroupMembership` joins `User` ↔ `UserGroup` ↔ `UserRole`. Almost every model has both `user_id` and `user_group_id`. Scoping is enforced by repositories (`UserGroupTrait::setUser/setUserGroup`), `ValidatesUserGroupTrait` on API controllers (`acceptedRoles`), and the `BelongsUser` / `BelongsUserGroup` validation rules. **Any new model or query must be scoped to the user group** — never query a model unscoped in request context.

## Code conventions (upstream's; mago/php-cs-fixer enforce them)

- Every PHP file: AGPL header comment block (filename, year, boilerplate — copy from a neighbour), then `declare(strict_types=1);`.
- Concrete controllers/actions/services are `final`; base classes `abstract`; `#[Override]` on overridden methods.
- Full type declarations everywhere; nullable written `?Type` in signatures, `Type|null` in docblocks (mago `null_pipe`).
- Logging: `use Illuminate\Support\Facades\Log;` — not `app('log')`. Helpers via facades (`Preferences::get()`, `Steam::…`, `Amount::…`).
- Money is always a numeric **string**; compare/add with `bccomp`/`bcadd` etc. Dates are `Carbon`.
- Alignment of `=>` and `=` columns is mago's output — don't fight it, run the formatter.
- Route names: `api.v1.<resource>.<action>`, web `<resource>.<action>`. Route params bind through `config/bindables.php`, not Laravel's implicit binding.
- PHPStan level 6 with `reportUnmatchedIgnoredErrors: true` — don't add `@phpstan-ignore` lines that aren't needed.
- Tests: extend `Tests\integration\TestCase`, use `$this->createAuthenticatedUser()` and `actingAs(...)`, hit the API with `application/vnd.api+json` accept headers. Filename `*Test.php`.

## Agents & skills in this repo

- `/sync-upstream` — pull the next upstream release into `custom`.
- `fork-reviewer` agent — conflict-footprint review of a diff.
- `firefly-dev` agent — implements a change following the conventions above and the isolation rules.
- `php-qa` agent — runs formatter, linters, PHPStan and PHPUnit (or reports what can't run).
